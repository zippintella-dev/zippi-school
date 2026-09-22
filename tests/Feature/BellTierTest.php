<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\School;
use App\Models\SchoolBellTime;
use App\Models\User;
use App\Support\GradeLevel;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠ bell_tier decides which staggered run a child rides. A grade-8 student
 * saved as "Senior" is collected for the 07:40 bell instead of 08:15 — 35
 * minutes early — and sent home at 14:40 instead of 15:15.
 *
 * The field used to be a free string, and went wrong on real data three times.
 */
class BellTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
        $this->actingAs(User::where('role', 'zippi_admin')->firstOrFail(), 'web');

        // The school runs Nursery upward.
        SchoolBellTime::where('bell_tier', 'Primary')->update(['grade_from' => 'Nursery']);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'admission_no' => 'BT-' . uniqid(),
            'name' => 'Tier Test',
            'grade' => '8',
        ];
    }

    /* ---------------- GradeLevel ---------------- */

    public function test_named_pre_primary_classes_rank_below_class_one(): void
    {
        foreach (['Nursery', 'LKG', 'UKG', 'PP1', 'PP2', 'Pre-KG'] as $name) {
            $rank = GradeLevel::rank($name);

            $this->assertNotNull($rank, "$name should be recognised");
            $this->assertLessThan(1, $rank, "$name should rank below class 1");
        }
    }

    public function test_numbered_classes_keep_their_own_value(): void
    {
        $this->assertSame(5, GradeLevel::rank('5'));
        $this->assertSame(12, GradeLevel::rank('12'));
        $this->assertSame(7, GradeLevel::rank('Class 7'));
    }

    /** ⚠ Unknown must match nothing, never fall through to a default. */
    public function test_an_unrecognised_class_matches_no_tier(): void
    {
        $this->assertNull(GradeLevel::rank('Yr Zero'));

        $bells = School::firstOrFail()->bellTimes;

        $this->assertNull($bells->first(fn ($b) => $b->coversGrade('Yr Zero')));
    }

    /**
     * The bug this exists for: "LKG" read as 0 sat below Primary's grade_from
     * of 1, so no tier matched and the child was never generated onto a bus.
     */
    public function test_lkg_now_resolves_to_primary(): void
    {
        $bells = School::firstOrFail()->bellTimes;

        foreach (['Nursery', 'LKG', 'UKG'] as $g) {
            $this->assertSame('Primary',
                $bells->first(fn ($b) => $b->coversGrade($g))?->bell_tier,
                "$g should ride Primary");
        }
    }

    public function test_the_configured_bands_still_hold(): void
    {
        $bells = School::firstOrFail()->bellTimes;
        $tier = fn ($g) => $bells->first(fn ($b) => $b->coversGrade($g))?->bell_tier;

        foreach (['1', '5'] as $g) $this->assertSame('Primary', $tier($g));
        foreach (['6', '8'] as $g) $this->assertSame('Middle', $tier($g));
        foreach (['9', '10', '11', '12'] as $g) $this->assertSame('Senior', $tier($g));
    }

    /* ---------------- the form ---------------- */

    public function test_a_blank_tier_is_derived_from_the_grade(): void
    {
        $this->post(route('children.store'), $this->payload(['grade' => '7']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Middle', Child::latest('id')->firstOrFail()->bell_tier);
    }

    /** ⚠ The guard. */
    public function test_a_tier_that_contradicts_the_grade_is_rejected(): void
    {
        $this->post(route('children.store'),
            $this->payload(['grade' => '8', 'bell_tier' => 'Senior']))
            ->assertSessionHasErrors('bell_tier');

        $this->assertDatabaseMissing('children', ['name' => 'Tier Test']);
    }

    public function test_a_matching_tier_is_accepted(): void
    {
        $this->post(route('children.store'),
            $this->payload(['grade' => '8', 'bell_tier' => 'Middle']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Middle', Child::latest('id')->firstOrFail()->bell_tier);
    }

    public function test_editing_cannot_introduce_a_mismatch(): void
    {
        $child = Child::where('grade', '3')->firstOrFail();

        $this->put(route('children.update', $child), [
            'admission_no' => $child->admission_no,
            'name' => $child->name,
            'grade' => '3',
            'bell_tier' => 'Senior',
        ])->assertSessionHasErrors('bell_tier');

        $this->assertNotSame('Senior', $child->fresh()->bell_tier);
    }

    /** A class no bell covers must be refused, not saved tier-less. */
    public function test_a_class_with_no_covering_bell_is_refused(): void
    {
        $this->post(route('children.store'),
            $this->payload(['grade' => 'Yr Zero']))
            ->assertSessionHasErrors('bell_tier');
    }

    /** Every existing student must agree with their band. */
    public function test_every_seeded_student_matches_their_band(): void
    {
        $bells = School::firstOrFail()->bellTimes;

        foreach (Child::all() as $c) {
            $want = $bells->first(fn ($b) => $b->coversGrade((string) $c->grade))?->bell_tier;

            $this->assertSame($want, $c->bell_tier,
                "{$c->name} (class {$c->grade}) has tier {$c->bell_tier}, expected {$want}");
        }
    }

    /**
     * ⚠ THE FORM ASKS THE SERVER WHICH TIER A GRADE RIDES.
     *
     * Resolving a class name to a tier means ranking it, and GradeLevel exists
     * because that ranking once read "LKG" as 0 — below Primary's grade_from of
     * 1 — so no tier matched and the child was never generated onto a bus. A
     * JavaScript copy of that logic would be a second place for the same bug to
     * live. This endpoint is what keeps it in one place.
     */
    public function test_the_lookup_names_the_tier_and_its_bell_times(): void
    {
        $body = $this->getJson('/children/bell-tier?grade=8')
            ->assertOk()->json();

        $this->assertTrue($body['ok']);
        $this->assertSame('Middle', $body['tier']);
        // The sentence is the product: it names the band and both bells, so the
        // office can see WHY before saving rather than at 07:30.
        $this->assertStringContainsString('Middle bell', $body['message']);
        $this->assertStringContainsString('08:15', $body['message']);
    }

    public function test_the_lookup_handles_a_named_pre_primary_class(): void
    {
        // Primary covers Nursery upward in this fixture's setUp.
        $body = $this->getJson('/children/bell-tier?grade=LKG')->assertOk()->json();

        $this->assertTrue($body['ok']);
        $this->assertSame('Primary', $body['tier']);
    }

    public function test_the_lookup_says_plainly_when_no_tier_covers_the_class(): void
    {
        $body = $this->getJson('/children/bell-tier?grade=Zebra')->assertOk()->json();

        $this->assertFalse($body['ok']);
        $this->assertNull($body['tier']);
        // ⚠ Not silence. An unmatched class is a student who would never be put
        // on a bus, and the office must be told at the moment they type it.
        $this->assertStringContainsString('never', $body['message']);
    }

    public function test_an_empty_grade_asks_for_nothing(): void
    {
        $body = $this->getJson('/children/bell-tier?grade=')->assertOk()->json();

        $this->assertFalse($body['ok']);
        $this->assertNull($body['tier']);
        $this->assertNull($body['message']);
    }

    /** The form no longer offers a tier to choose — it shows the one assigned. */
    public function test_the_student_form_does_not_offer_a_tier_dropdown(): void
    {
        $html = $this->get('/children/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="bell_tier"', $html);
        $this->assertStringContainsString('tierValue', $html);
    }
}
