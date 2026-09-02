<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guardian identity rules.
 *
 * ⚠ ONE NUMBER, ONE PERSON. A guardian's phone decides who receives a child's
 * boarding alerts and the daily handover code, so two different people sharing
 * one number means the wrong adult is told where a child is — and can collect
 * them. This is a safety rule, not a data-hygiene preference.
 */
class GuardianManagementTest extends TestCase
{
    use RefreshDatabase;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
        $this->actingAs(User::where('role', 'zippi_admin')->firstOrFail(), 'web');

        $this->child = Child::firstOrFail();
    }

    private function newChild(): Child
    {
        return Child::create([
            'school_id' => School::firstOrFail()->id,
            'admission_no' => 'GT-' . uniqid(),
            'name' => 'Sibling Test',
            'grade' => '5',
            'status' => 'active',
        ]);
    }

    /* ---------------- duplicate numbers ---------------- */

    public function test_a_different_person_cannot_reuse_an_existing_number(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumar', 'phone' => '9812340001',
            'relationship' => 'mother', 'is_primary' => 1,
        ])->assertSessionHasNoErrors();

        // A different person, same number.
        $this->post(route('children.guardians.add', $this->newChild()), [
            'name' => 'Rajesh Sharma', 'phone' => '9812340001',
            'relationship' => 'father',
        ])->assertSessionHasErrors('guardian_phone');

        // The original owner is untouched — the name was not overwritten.
        $this->assertSame('Meera Kumar',
            Guardian::where('phone', '9812340001')->firstOrFail()->name);
        $this->assertSame(1, Guardian::where('phone', '9812340001')->count());
    }

    /** The legitimate case: one parent, two children. Must keep working. */
    public function test_the_same_person_may_guard_two_siblings(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumar', 'phone' => '9812340002', 'relationship' => 'mother',
        ])->assertSessionHasNoErrors();

        $sibling = $this->newChild();

        $this->post(route('children.guardians.add', $sibling), [
            'name' => 'Meera Kumar', 'phone' => '9812340002', 'relationship' => 'mother',
        ])->assertSessionHasNoErrors();

        $guardian = Guardian::where('phone', '9812340002')->firstOrFail();

        $this->assertSame(1, Guardian::where('phone', '9812340002')->count());
        $this->assertSame(2, $guardian->children()->count());
    }

    /** Spacing and case are how a school office types, not a different person. */
    public function test_name_matching_tolerates_case_and_spacing(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumar', 'phone' => '9812340003', 'relationship' => 'mother',
        ]);

        $this->post(route('children.guardians.add', $this->newChild()), [
            'name' => '  meera   kumar ', 'phone' => '9812340003', 'relationship' => 'mother',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Guardian::where('phone', '9812340003')->count());
    }

    /** ⚠ Formatting must not create a second row for the same number. */
    public function test_a_differently_formatted_number_is_the_same_number(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumar', 'phone' => '9812340004', 'relationship' => 'mother',
        ]);

        $this->post(route('children.guardians.add', $this->newChild()), [
            'name' => 'Rajesh Sharma', 'phone' => '+91 98123 40004', 'relationship' => 'father',
        ])->assertSessionHasErrors('guardian_phone');

        $this->assertSame(1, Guardian::count() - Guardian::where('phone', '!=', '9812340004')->count());
    }

    /* ---------------- editing ---------------- */

    public function test_a_guardian_can_be_edited(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumr', 'phone' => '9812340005', 'relationship' => 'mother',
        ]);

        $g = Guardian::where('phone', '9812340005')->firstOrFail();

        $this->put(route('children.guardians.update', [$this->child, $g]), [
            'name' => 'Meera Kumar', 'phone' => '9812340006',
            'email' => 'meera@example.com', 'relationship' => 'guardian', 'is_primary' => 1,
        ])->assertSessionHasNoErrors();

        $g->refresh();

        $this->assertSame('Meera Kumar', $g->name);
        $this->assertSame('9812340006', $g->phone);
        $this->assertSame('meera@example.com', $g->email);
        $this->assertSame('guardian',
            $this->child->guardians()->where('guardians.id', $g->id)->firstOrFail()
                 ->pivot->relationship);
    }

    /** Editing must not be a side door into the collision that adding refuses. */
    public function test_editing_cannot_take_a_number_that_belongs_to_someone_else(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumar', 'phone' => '9812340007', 'relationship' => 'mother',
        ]);
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Rajesh Sharma', 'phone' => '9812340008', 'relationship' => 'father',
        ]);

        $rajesh = Guardian::where('phone', '9812340008')->firstOrFail();

        $this->put(route('children.guardians.update', [$this->child, $rajesh]), [
            'name' => 'Rajesh Sharma', 'phone' => '9812340007', 'relationship' => 'father',
        ])->assertSessionHasErrors('phone');

        $this->assertSame('9812340008', $rajesh->fresh()->phone);
    }

    public function test_editing_rejects_an_implausible_number(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Meera Kumar', 'phone' => '9812340009', 'relationship' => 'mother',
        ]);

        $g = Guardian::where('phone', '9812340009')->firstOrFail();

        $this->put(route('children.guardians.update', [$this->child, $g]), [
            'name' => 'Meera Kumar', 'phone' => '12345', 'relationship' => 'mother',
        ])->assertSessionHasErrors('phone');
    }

    /** Only one primary per child, or the notification fan-out is ambiguous. */
    public function test_setting_primary_clears_the_previous_one(): void
    {
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'First Parent', 'phone' => '9812340010',
            'relationship' => 'mother', 'is_primary' => 1,
        ]);
        $this->post(route('children.guardians.add', $this->child), [
            'name' => 'Second Parent', 'phone' => '9812340011', 'relationship' => 'father',
        ]);

        $second = Guardian::where('phone', '9812340011')->firstOrFail();

        $this->put(route('children.guardians.update', [$this->child, $second]), [
            'name' => 'Second Parent', 'phone' => '9812340011',
            'relationship' => 'father', 'is_primary' => 1,
        ]);

        $primaries = $this->child->guardians()->wherePivot('is_primary', true)->count();

        $this->assertSame(1, $primaries);
    }

    /** A guardian not linked to this child must not be editable through it. */
    public function test_a_guardian_of_another_child_is_not_editable_here(): void
    {
        $other = Guardian::whereDoesntHave('children',
            fn ($q) => $q->where('children.id', $this->child->id))->firstOrFail();

        $this->put(route('children.guardians.update', [$this->child, $other]), [
            'name' => 'Hijacked', 'phone' => '9812340012', 'relationship' => 'mother',
        ])->assertNotFound();

        $this->assertNotSame('Hijacked', $other->fresh()->name);
    }
}
