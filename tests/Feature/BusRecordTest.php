<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\School;
use App\Models\SchoolTrip;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The vehicle record — its crew, its trips, and why it may not be running. */
class BusRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
        $this->admin = User::where('role', 'zippi_admin')->firstOrFail();
    }

    public function test_the_record_shows_the_crew_and_the_trips(): void
    {
        $trip = SchoolTrip::whereNotNull('bus_id')->whereNotNull('attendant_id')->firstOrFail();
        $bus  = Bus::findOrFail($trip->bus_id);

        $this->actingAs($this->admin)
            ->get("/buses/{$bus->id}")
            ->assertOk()
            ->assertSee($bus->reg_no)
            ->assertSee('Everyday crew')
            ->assertSee('Trips')
            // The crew who were actually on board, not just the route default.
            ->assertSee($trip->attendant->name)
            ->assertSee('Documents');
    }

    public function test_a_blocked_vehicle_says_so_and_names_the_document(): void
    {
        $bus = Bus::firstOrFail();
        $bus->forceFill(['insurance_expiry' => Carbon::today()->subDays(6)->toDateString()])->save();

        // PART K15 — the page must explain why generation will skip it, in the
        // words an operator can act on rather than a red pill alone.
        $this->actingAs($this->admin)
            ->get("/buses/{$bus->id}")
            ->assertOk()
            ->assertSee('will not be assigned to trips')
            ->assertSee('Insurance expired 6d ago');
    }

    public function test_a_bus_at_another_school_is_not_reachable(): void
    {
        $other = School::create(['name' => 'Another School', 'code' => 'OTHER-2']);

        $bus = Bus::firstOrFail();
        $bus->forceFill(['school_id' => $other->id])->save();

        $this->actingAs($this->admin)
            ->get("/buses/{$bus->id}")
            ->assertStatus(403);
    }
}
