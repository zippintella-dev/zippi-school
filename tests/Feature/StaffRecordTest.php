<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolStaff;
use App\Models\User;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crew record — and the line between who may see a raw crew number.
 *
 * PART K9 masks the driver's and attendant's mobile in everything a PARENT or
 * the live board can reach. It does NOT mask it from the transport office that
 * employs them and typed the number in; the office had no way to phone a driver
 * mid-route, and the call proxy K9 assumes has never been built.
 *
 * These tests pin both halves so a later change cannot quietly move the line.
 */
class StaffRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
        $this->admin = User::where('role', 'zippi_admin')->firstOrFail();
    }

    public function test_the_crew_record_shows_the_full_number_to_the_office(): void
    {
        $member = SchoolStaff::where('role', 'driver')->firstOrFail();

        $this->actingAs($this->admin)
            ->get("/staff/{$member->id}")
            ->assertOk()
            ->assertSee($member->name)
            // The whole point of the screen: a number somebody can actually ring.
            ->assertSee($member->phone)
            ->assertSee('Compliance');
    }

    /**
     * ⚠ THE FORM AND THE ENDPOINT MUST BOTH EXIST.
     *
     * `PUT staff/{member}` and StaffController@update() shipped with no form
     * anywhere in the dashboard posting to them, so compliance could never be
     * corrected once entered. Compliance EXPIRES — a licence renews annually,
     * a police check comes back, a medical certificate is reissued — and
     * PART K15 withholds a crew member whose paperwork has lapsed from every
     * trip generated afterwards. The only visible symptom is `attendant = NONE`
     * on a trip nobody realises is short-staffed, which is exactly what
     * happened on the first real onboarding.
     */
    public function test_a_crew_member_can_be_edited_from_their_record(): void
    {
        $member = SchoolStaff::where('role', 'attendant')->firstOrFail();

        $member->forceFill([
            'police_verification_status' => 'pending',
            'medical_fitness_expiry' => null,
        ])->save();

        $this->assertNotEmpty($member->fresh()->complianceBlockers());

        // The form is on the page, aimed at the endpoint that already existed.
        $this->actingAs($this->admin)
            ->get("/staff/{$member->id}")
            ->assertOk()
            ->assertSee('Edit crew member')
            ->assertSee('action="' . route('staff.update', $member) . '"', false);

        $this->actingAs($this->admin)
            ->put("/staff/{$member->id}", [
                'role' => $member->role,
                'name' => $member->name,
                'phone' => $member->phone,
                'police_verification_status' => 'verified',
                'police_verified_on' => '2026-07-01',
                'medical_fitness_expiry' => '2027-07-01',
            ])
            ->assertRedirect();

        $fresh = $member->fresh();

        $this->assertSame('verified', $fresh->police_verification_status);
        $this->assertSame('2027-07-01', (string) $fresh->medical_fitness_expiry);

        // The point of the edit: they are dispatchable again.
        $this->assertEmpty($fresh->complianceBlockers());
    }

    public function test_a_school_user_cannot_edit_another_schools_crew(): void
    {
        $other = School::create([
            'name' => 'Another School', 'code' => 'AS2',
            'timezone' => 'Asia/Kolkata', 'status' => 'active',
        ]);

        $intruder = User::create([
            'name' => 'Other Office', 'role' => 'school_user',
            'email' => 'other@school.test', 'password' => bcrypt('secret'),
            'school_id' => $other->id, 'status' => 'active',
        ]);

        $member = SchoolStaff::where('role', 'driver')->firstOrFail();

        // ⚠ update() carries its own abort_unless. Nothing proved it until now,
        // and a crew edit reachable across schools would let one school revoke
        // another's driver.
        $this->actingAs($intruder)
            ->put("/staff/{$member->id}", [
                'role' => 'driver', 'name' => 'Hijacked', 'phone' => '9000000001',
                'police_verification_status' => 'rejected',
            ])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $member->fresh()->name);
    }

    public function test_the_crew_list_still_masks(): void
    {
        $member = SchoolStaff::where('role', 'driver')->firstOrFail();

        // The list is often open on a shared screen in the transport office, so
        // the raw number stays one deliberate click away.
        $this->actingAs($this->admin)
            ->get('/staff')
            ->assertOk()
            ->assertSee($member->maskedPhone())
            ->assertDontSee($member->phone);
    }

    public function test_the_live_board_still_masks_crew_numbers(): void
    {
        // ⚠ The guarantee that must not move. Adding an unmasked screen for the
        // office must never leak into the ops payload a school user watches.
        $body = $this->actingAs($this->admin)->get('/live/data')->assertOk()->content();

        $this->assertDoesNotMatchRegularExpression('/\+9198\d{8}/', $body,
            'A raw crew number reached the live board payload.');
        $this->assertStringContainsString('phone_masked', $body);
    }

    public function test_crew_at_another_school_are_not_reachable(): void
    {
        // A real second school — school_id is a foreign key, so an invented id
        // fails the constraint rather than the authorisation check, and the
        // test would pass for the wrong reason.
        $other = \App\Models\School::create([
            'name' => 'Another School', 'code' => 'OTHER-1',
        ]);

        $member = SchoolStaff::where('role', 'driver')->firstOrFail();
        $member->forceFill(['school_id' => $other->id])->save();

        $this->actingAs($this->admin)
            ->get("/staff/{$member->id}")
            ->assertStatus(403);
    }
}
