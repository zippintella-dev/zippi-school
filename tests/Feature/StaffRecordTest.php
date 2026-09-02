<?php

namespace Tests\Feature;

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
