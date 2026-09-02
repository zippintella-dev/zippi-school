<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildStopAssignment;
use App\Models\RouteStaffOverride;
use App\Models\School;
use App\Models\SchoolCalendar;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\SchoolTripGenerationRun;
use App\Services\SchoolTripGenerator;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PART M2 / M7 — the trip generator and the backward-from-the-bell solver.
 *
 * The seeder already writes trips for today, so every test here generates for a
 * FUTURE school day. That keeps the seeded mid-flight demo state (started /
 * completed trips) out of the way and matches how the generator really runs:
 * at 02:30 for the day ahead.
 */
class TripGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);

        $this->school = School::first();

        // Next weekday with no calendar row of its own, so it resolves to a
        // plain school day. The seeder only writes rows around today.
        $cursor = Carbon::parse(Carbon::now('Asia/Kolkata')->toDateString())->addDays(30);
        while ($cursor->isWeekend()) {
            $cursor->addDay();
        }
        $this->date = $cursor->toDateString();

        SchoolCalendar::where('school_id', $this->school->id)->where('date', $this->date)->delete();
    }

    private function generate(bool $force = false, bool $dryRun = false): SchoolTripGenerationRun
    {
        return app(SchoolTripGenerator::class)
            ->generate($this->school, $this->date, $force, $dryRun);
    }

    private function trips()
    {
        return SchoolTrip::where('service_date', $this->date);
    }

    /** PART M2 — the core promise: routes in, solved trips out. */
    public function test_it_generates_a_trip_per_route_direction_and_leg(): void
    {
        $run = $this->generate();

        $this->assertGreaterThan(0, $run->created_count);
        $this->assertEmpty($run->errors ?? []);

        // Every generated trip is keyed by all four (route, date, direction, bell tier).
        foreach ($this->trips()->get() as $trip) {
            $this->assertNotNull($trip->route_id);
            $this->assertSame($this->date, $trip->service_date);
            $this->assertContains($trip->direction, ['Morning', 'Afternoon']);
            $this->assertNotEmpty($trip->bell_tier);
            $this->assertNotEmpty($trip->route_schedule, 'A generated trip must carry a solved schedule.');
        }

        $this->assertSame(
            $this->trips()->count(),
            $this->trips()->distinct()->count(\DB::raw('route_id || direction || bell_tier')),
            'Trips must be unique on (route, direction, bell tier) for one service date.'
        );
    }

    /**
     * PART M2 — idempotent. This gets re-run by hand at 06:00 by whoever is
     * checking the 02:30 cron actually fired; a second run must not duplicate.
     */
    public function test_regenerating_is_idempotent(): void
    {
        $first = $this->generate();
        $count = $this->trips()->count();

        $second = $this->generate();

        $this->assertSame($count, $this->trips()->count(), 'A re-run duplicated trips.');
        $this->assertSame(0, $second->created_count);
        $this->assertSame($first->created_count, $second->skipped_count);
    }

    /** PART M2 step 4 — an admin's edit outranks the generator, forced or not. */
    public function test_force_does_not_overwrite_an_admin_edited_trip(): void
    {
        $this->generate();

        $trip = $this->trips()->first();
        $trip->update(['admin_edited' => true, 'scheduled_start_at' => Carbon::parse($this->date . ' 04:00')]);

        $this->generate(force: true);

        $this->assertTrue($trip->fresh()->admin_edited);
        $this->assertSame('04:00', $trip->fresh()->scheduled_start_at->format('H:i'),
            'A forced run overwrote an admin-edited trip (PART M2 step 4).');
    }

    /** PART M2 — --force re-solves ordinary auto rows. */
    public function test_force_resolves_auto_generated_trips(): void
    {
        $this->generate();

        $trip = $this->trips()->where('admin_edited', false)->first();
        $trip->update(['scheduled_start_at' => Carbon::parse($this->date . ' 04:00')]);

        $run = $this->generate(force: true);

        $this->assertGreaterThan(0, $run->updated_count);
        $this->assertNotSame('04:00', $trip->fresh()->scheduled_start_at->format('H:i'));
    }

    /** PART M2 step 1 — a holiday generates nothing at all. */
    public function test_a_holiday_generates_no_trips(): void
    {
        SchoolCalendar::updateOrCreate(
            ['school_id' => $this->school->id, 'date' => $this->date],
            ['day_type' => 'holiday', 'label' => 'Test holiday',
             'morning_trips_run' => false, 'afternoon_trips_run' => false]
        );

        $run = $this->generate();

        $this->assertSame(0, $this->trips()->count());
        $this->assertSame(0, $run->created_count);
        $this->assertContains('not_a_school_day', array_column($run->warnings ?? [], 'warning'));
    }

    /** PART C3 — a closure can suppress one direction without killing the other. */
    public function test_a_suppressed_direction_generates_only_the_other(): void
    {
        SchoolCalendar::updateOrCreate(
            ['school_id' => $this->school->id, 'date' => $this->date],
            ['day_type' => 'school', 'morning_trips_run' => true, 'afternoon_trips_run' => false]
        );

        $this->generate();

        $this->assertGreaterThan(0, $this->trips()->where('direction', 'Morning')->count());
        $this->assertSame(0, $this->trips()->where('direction', 'Afternoon')->count());
    }

    /** PART M2 step 3 — an absence recorded before generation keeps the child off. */
    public function test_an_absent_child_is_left_out_of_the_trip(): void
    {
        $assignment = ChildStopAssignment::where('direction', 'Morning')->firstOrFail();
        $childId = $assignment->child_id;

        SchoolChildAbsence::create([
            'child_id' => $childId,
            'service_date' => $this->date,
            'direction' => 'Morning',
            'marked_by' => 'parent',
        ]);

        $this->generate();

        $morning = $this->trips()->where('route_id', $assignment->route_id)
            ->where('direction', 'Morning')->get();

        foreach ($morning as $trip) {
            $this->assertNotContains($childId, $trip->child_ids,
                'An absent child was still put on the morning trip.');
        }

        // Direction-aware: the afternoon trip must be unaffected.
        $afternoon = $this->trips()->where('route_id', $assignment->route_id)
            ->where('direction', 'Afternoon')->get()
            ->flatMap(fn ($t) => $t->child_ids)->all();

        $this->assertContains($childId, $afternoon,
            'A morning-only absence wrongly removed the child from the afternoon trip.');
    }

    /** PART M7 — morning solves backward: arrival lands on bell − arrival_buffer. */
    public function test_morning_arrival_deadline_is_the_bell_minus_the_buffer(): void
    {
        $this->generate();

        $buffer = (int) config('school.arrival_buffer_minutes');

        foreach ($this->trips()->where('direction', 'Morning')->get() as $trip) {
            $bell = Carbon::parse($this->date . ' ' . substr($trip->bell_time, 0, 5), 'Asia/Kolkata');

            $this->assertSame(
                $bell->copy()->subMinutes($buffer)->format('H:i'),
                $trip->school_arrival_deadline->format('H:i'),
                'Morning deadline must be bell − arrival_buffer_minutes (PART M7/M10).'
            );

            $this->assertTrue($trip->scheduled_start_at->lt($trip->school_arrival_deadline),
                'The bus must depart before it is due at school.');
        }
    }

    /** PART G1 — stop ORDER is authored and frozen; only the times are solved. */
    public function test_the_solved_schedule_keeps_the_authored_stop_order(): void
    {
        $this->generate();

        foreach ($this->trips()->get() as $trip) {
            $times = array_map(fn ($s) => Carbon::parse($s['scheduled_at'])->timestamp,
                $trip->route_schedule);

            $sorted = $times;
            sort($sorted);

            $this->assertSame($sorted, $times,
                'Solved stop times must increase along the authored sequence (PART G1).');
        }
    }

    /** PART M7 — dwell is base + per-child, and the afternoon costs more. */
    public function test_dwell_grows_with_children_and_is_longer_in_the_afternoon(): void
    {
        $this->generate();

        $morning = $this->trips()->where('direction', 'Morning')->get()
            ->flatMap(fn ($t) => $t->route_schedule);

        $base = (int) config('school.base_dwell_seconds');
        $perChild = (int) config('school.per_child_dwell_seconds_morning');

        foreach ($morning as $stop) {
            $this->assertSame($base + $perChild * count($stop['child_ids']), $stop['dwell_seconds']);
        }

        // Invariant #1 makes a verified handover slower than boarding.
        $this->assertGreaterThan(
            (int) config('school.per_child_dwell_seconds_morning'),
            (int) config('school.per_child_dwell_seconds_afternoon')
        );
    }

    /** PART M1 / M2 step 6 — sequence orders a bus's trips through its own day. */
    public function test_sequence_orders_each_buses_trips_by_start_time(): void
    {
        $this->generate();

        $byBus = $this->trips()->whereNotNull('bus_id')->get()->groupBy('bus_id');

        $this->assertNotEmpty($byBus, 'No trip resolved a bus — the crew resolver is not matching.');

        foreach ($byBus as $trips) {
            $ordered = $trips->sortBy('sequence')->values();

            $this->assertSame(range(1, $ordered->count()), $ordered->pluck('sequence')->map('intval')->all(),
                'A bus\'s trips must be sequenced 1..N with no gaps.');

            for ($i = 1; $i < $ordered->count(); $i++) {
                $this->assertTrue(
                    $ordered[$i - 1]->scheduled_start_at->lte($ordered[$i]->scheduled_start_at),
                    'sequence must follow solved start time.'
                );
            }
        }
    }

    /** PART O3 — a one-day stand-in must reach the generated trip. */
    public function test_a_one_day_crew_override_lands_on_the_generated_trip(): void
    {
        $trip = null;
        $this->generate();
        $trip = $this->trips()->where('direction', 'Morning')->whereNotNull('bus_id')->firstOrFail();

        $standIn = \App\Models\SchoolStaff::where('role', 'driver')
            ->where('id', '!=', $trip->driver_id)->firstOrFail();

        RouteStaffOverride::create([
            'service_date' => $this->date,
            'route_id' => $trip->route_id,
            'direction' => 'Morning',
            'driver_id' => $standIn->id,
            'reason' => 'Test stand-in',
        ]);

        $this->generate(force: true);

        $this->assertSame($standIn->id, $trip->fresh()->driver_id,
            'A one-day override did not reach the generated trip (PART O3).');
    }

    /** PART D — one trip-child row per rider, the spine of the journey record. */
    public function test_it_writes_one_trip_child_row_per_rider(): void
    {
        $this->generate();

        foreach ($this->trips()->get() as $trip) {
            $rows = SchoolTripChild::where('trip_id', $trip->id)->get();

            $this->assertSame(count($trip->child_ids), $rows->count());
            $this->assertEqualsCanonicalizing($trip->child_ids, $rows->pluck('child_id')->all());

            foreach ($rows as $row) {
                $this->assertSame('pending', $row->status);
                $this->assertNotNull($row->stop_id, 'A rider must be pinned to a published stop (PART E1).');
            }
        }
    }

    /** A regenerate must not erase operational history already recorded. */
    public function test_regenerating_preserves_a_child_already_boarded(): void
    {
        $this->generate();

        $trip = $this->trips()->where('direction', 'Morning')->firstOrFail();
        $row = SchoolTripChild::where('trip_id', $trip->id)->firstOrFail();
        $row->update(['status' => 'boarded', 'boarded_at' => now()]);

        $this->generate(force: true);

        $this->assertSame('boarded', $row->fresh()->status,
            'A forced regenerate wiped a child who had already boarded.');
        $this->assertNotNull($row->fresh()->boarded_at);
    }

    /** PART M2 — --dry-run reports what it would do and writes no trip. */
    public function test_dry_run_writes_nothing(): void
    {
        $run = $this->generate(dryRun: true);

        $this->assertGreaterThan(0, $run->created_count, 'A dry run must still report what it would create.');
        $this->assertSame(0, $this->trips()->count(), 'A dry run wrote trips.');
        $this->assertTrue($run->dry_run);
    }

    /** PART L1 — the date rule, on the new table and the trips it writes. */
    public function test_service_dates_stay_raw_strings(): void
    {
        $run = $this->generate();

        $this->assertIsString($run->service_date);
        $this->assertSame($this->date, $run->service_date);
        $this->assertIsString($this->trips()->first()->service_date);
        $this->assertSame($this->date, $this->trips()->first()->service_date);
    }

    /** PART M2 — every invocation leaves an audit row, including a no-op one. */
    public function test_every_invocation_records_a_run_row(): void
    {
        $before = SchoolTripGenerationRun::count();

        $this->generate();
        $this->generate();

        $this->assertSame($before + 2, SchoolTripGenerationRun::count());
    }

    /**
     * PART M7 — a route that cannot physically make its bell must CLAMP and SHOUT.
     *
     * This is the one warning that means a human has to act (split the route).
     * Silently solving a 03:00 departure, or silently absorbing the overrun into
     * a late arrival, both end with children missing the bell — so the clamp and
     * the warning are tested together.
     */
    public function test_an_impossible_bell_clamps_and_warns(): void
    {
        // A bell far too early for any route to reach from the depot.
        \App\Models\SchoolBellTime::where('school_id', $this->school->id)
            ->update(['start_time' => '05:10']);

        $run = $this->generate();

        $this->assertContains('deadline_too_tight', array_column($run->warnings ?? [], 'warning'),
            'An unreachable bell must raise deadline_too_tight (PART M7).');

        $floor = Carbon::parse($this->date . ' ' . config('school.earliest_depot_departure'), 'Asia/Kolkata');

        foreach ($this->trips()->where('direction', 'Morning')->get() as $trip) {
            $this->assertTrue($trip->scheduled_start_at->gte($floor),
                'A clamped trip must never depart before the sanity floor.');
        }
    }

    /**
     * PART M — the tiered model: one route serving two grade bands must produce
     * two trips with two different bells, not one merged trip.
     */
    public function test_one_route_with_two_grade_bands_produces_two_legs(): void
    {
        $assignment = ChildStopAssignment::where('direction', 'Morning')->firstOrFail();

        $existing = Child::findOrFail($assignment->child_id);

        // A second child on the same stop, in a different bell band.
        $otherLeg = $existing->bell_tier === 'Senior' ? 'Primary' : 'Senior';
        $otherGrade = $otherLeg === 'Senior' ? '10' : '2';

        $newChild = Child::create([
            'school_id' => $this->school->id,
            'admission_no' => 'TEST-LEG-1',
            'name' => 'Two Leg Test',
            'grade' => $otherGrade,
            'bell_tier' => $otherLeg,
            'status' => 'active',
        ]);

        ChildStopAssignment::create([
            'child_id' => $newChild->id,
            'route_id' => $assignment->route_id,
            'stop_id' => $assignment->stop_id,
            'direction' => 'Morning',
        ]);

        $this->generate();

        $tiers = $this->trips()->where('route_id', $assignment->route_id)
            ->where('direction', 'Morning')->pluck('bell_tier')->unique();

        $this->assertGreaterThanOrEqual(2, $tiers->count(),
            'A route serving two grade bands must generate one trip per band (PART M).');

        $bells = $this->trips()->where('route_id', $assignment->route_id)
            ->where('direction', 'Morning')->pluck('bell_time')->unique();

        $this->assertGreaterThanOrEqual(2, $bells->count(),
            'Each bell tier must carry its own bell time.');
    }
}
