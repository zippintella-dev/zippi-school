<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\School;
use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WritePathTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
        $this->admin = User::where('role', 'zippi_admin')->firstOrFail();
    }

    /**
     * REGRESSION: a holiday must force both trip flags off.
     *
     * The first implementation read the checkboxes with a `true` default, so an
     * unchecked box on a holiday still stored morning_trips_run=1. That wrote
     * contradictory data (day_type=holiday with trips running) and made the
     * closure cancel nothing — buses would be dispatched into a closed school.
     */
    public function test_holiday_forces_trip_flags_off_regardless_of_checkboxes(): void
    {
        $school = School::first();
        $date   = Carbon::parse('2026-12-25')->toDateString();

        $this->actingAs($this->admin)->post('/calendar', [
            'start_date' => $date,
            'end_date'   => $date,
            'day_type'   => 'holiday',
            'label'      => 'Christmas',
            // Deliberately posting the flags as "on", as a stale form would.
            'morning_trips_run'   => '1',
            'afternoon_trips_run' => '1',
        ])->assertRedirect();

        $row = SchoolCalendar::where('school_id', $school->id)->where('date', $date)->firstOrFail();

        $this->assertSame('holiday', $row->day_type);
        $this->assertFalse((bool) $row->morning_trips_run,
            'A holiday must never store morning_trips_run=1.');
        $this->assertFalse((bool) $row->afternoon_trips_run,
            'A holiday must never store afternoon_trips_run=1.');
    }

    /** PART J2 #7 — a closure cancels generated trips in the same transaction. */
    public function test_closure_cancels_already_generated_trips(): void
    {
        $school = School::first();
        $date   = SchoolTrip::where('school_id', $school->id)->value('service_date');

        $before = SchoolTrip::where('school_id', $school->id)
            ->where('service_date', $date)
            ->whereIn('status', ['scheduled', 'started'])->count();

        $this->assertGreaterThan(0, $before, 'Seed should leave active trips to cancel.');

        $this->actingAs($this->admin)->post('/calendar', [
            'start_date' => $date, 'end_date' => $date,
            'day_type' => 'holiday', 'label' => 'Unscheduled closure',
            'cancel_existing_trips' => '1',
        ])->assertRedirect();

        $after = SchoolTrip::where('school_id', $school->id)
            ->where('service_date', $date)
            ->whereIn('status', ['scheduled', 'started'])->count();

        $this->assertSame(0, $after,
            'Every active trip on a closed day must be cancelled, or buses get dispatched into an empty school.');
    }

    /** PART A3 — a range expands over school days only, and says what it skipped. */
    public function test_absence_range_skips_holidays_and_reports_them(): void
    {
        $school  = School::first();
        $child   = Child::where('school_id', $school->id)->firstOrFail();
        $holiday = SchoolCalendar::where('school_id', $school->id)
            ->where('label', 'Ganesh Chaturthi')->firstOrFail();

        $from = Carbon::parse($holiday->date)->subDay()->toDateString();
        $to   = Carbon::parse($holiday->date)->addDay()->toDateString();

        $this->actingAs($this->admin)
            ->post("/children/{$child->id}/absence", [
                'start_date' => $from, 'end_date' => $to,
                'directions' => ['Morning'], 'reason_code' => 'travel',
            ])->assertRedirect();

        $onHoliday = SchoolChildAbsence::where('child_id', $child->id)
            ->where('service_date', $holiday->date)->count();

        $this->assertSame(0, $onHoliday,
            'No absence row may be written for a declared holiday.');
    }

    /** PART O6 — route codes normalise to a zero-padded RT- prefix. */
    public function test_route_code_is_normalised(): void
    {
        $this->actingAs($this->admin)->post('/routes', [
            'code' => '7', 'name' => 'Test Route', 'bell_tier' => 'Primary',
        ])->assertRedirect();

        $this->assertDatabaseHas('routes', ['code' => 'RT-07', 'name' => 'Test Route']);
    }

    /** PART G1 — stops append in authored order and reorder deterministically. */
    public function test_stops_append_in_sequence_and_reorder(): void
    {
        $route = Route::where('school_id', School::first()->id)->firstOrFail();
        $start = $route->stops()->count();

        foreach (['Alpha' => 17.40, 'Beta' => 17.41] as $name => $lat) {
            $this->actingAs($this->admin)->post("/routes/{$route->id}/stops", [
                'name' => $name, 'latitude' => $lat, 'longitude' => 78.30,
            ])->assertRedirect();
        }

        $alpha = RouteStop::where('route_id', $route->id)->where('name', 'Alpha')->firstOrFail();
        $beta  = RouteStop::where('route_id', $route->id)->where('name', 'Beta')->firstOrFail();

        $this->assertSame($start + 1, $alpha->sequence);
        $this->assertSame($start + 2, $beta->sequence);

        $this->actingAs($this->admin)->post("/stops/{$beta->id}/move", ['direction' => 'up']);

        $this->assertSame($start + 1, $beta->fresh()->sequence);
        $this->assertSame($start + 2, $alpha->fresh()->sequence);
    }

    /** A route with riders cannot be deleted out from under them. */
    public function test_route_with_assigned_students_cannot_be_deleted(): void
    {
        $route = Route::where('school_id', School::first()->id)->firstOrFail();

        $this->actingAs($this->admin)->delete("/routes/{$route->id}")->assertRedirect();

        $this->assertDatabaseHas('routes', ['id' => $route->id]);
    }

    /** A child must keep at least one guardian, or they get no notifications. */
    public function test_last_guardian_cannot_be_unlinked(): void
    {
        $child = Child::whereHas('guardians')->firstOrFail();

        while ($child->guardians()->count() > 1) {
            $child->guardians()->detach($child->guardians()->first()->id);
            $child->refresh();
        }

        $last = $child->guardians()->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/children/{$child->id}/guardians/{$last->id}")
            ->assertRedirect();

        $this->assertSame(1, $child->fresh()->guardians()->count(),
            'Removing the final guardian must be blocked — no guardian means no notifications at all.');
    }

    /** Retiring a student preserves the journey record (PART K13). */
    public function test_deleting_a_student_retires_rather_than_destroys(): void
    {
        $child = Child::firstOrFail();

        $this->actingAs($this->admin)->delete("/children/{$child->id}")->assertRedirect();

        $this->assertDatabaseHas('children', ['id' => $child->id, 'status' => 'inactive']);
    }

    /** PART J6 — a school user cannot reach Zippi-only overrides. */
    public function test_school_user_cannot_acknowledge_alerts(): void
    {
        $schoolUser = User::where('role', 'school_user')->firstOrFail();
        $event = \App\Models\SchoolTripEvent::firstOrFail();

        $this->actingAs($schoolUser)->post("/alerts/{$event->id}/ack")->assertForbidden();
    }
}
