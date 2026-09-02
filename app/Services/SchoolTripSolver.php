<?php

namespace App\Services;

use App\Models\ChildStopAssignment;
use App\Models\RouteStop;
use App\Models\SchoolTrip;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * PART M7 — solves a trip's stop times.
 *
 * The whole design in one sentence: the bell is the fixed point and everything
 * else is derived backwards from it. A morning run is solved from the school
 * arrival deadline outward to the first stop; an afternoon run is solved forward
 * from dismissal. Nothing is optimised — stop ORDER is authored and frozen
 * (PART G1), and only the TIMES are computed.
 *
 * ⚠ Do not "improve" this into a nearest-neighbour or TSP solve. The authored
 * order is a safety property: parents are told a stop position and a time, and a
 * route that silently reorders itself puts a child at a kerb at the wrong minute.
 *
 * PART M10: earlier is the safe direction to be wrong. Every fallback here is
 * conservative — slower assumed speed, longer assumed dwell.
 */
class SchoolTripSolver
{
    /** Metres. Equatorial radius is close enough at city scale. */
    private const EARTH_RADIUS_M = 6371000;

    public function __construct(private ?SchoolCalendarService $calendar = null) {}

    /**
     * Solve one trip in place and return the solved payload.
     *
     * Does NOT save — the caller decides, so a --dry-run can solve without
     * writing. Sets route_schedule, scheduled_start_at, scheduled_end_at,
     * school_arrival_deadline / school_depart_at, distance_m and duration_min.
     *
     * @return array{schedule: array, warnings: array, distance_m: int, duration_min: int}
     */
    public function solve(SchoolTrip $trip): array
    {
        $school = $trip->school ?? $trip->school()->first();
        $route = $trip->route ?? $trip->route()->first();
        $tz = $school->timezone ?: config('app.timezone');

        $stops = $route->stopsFor($trip->direction);

        if ($stops->isEmpty()) {
            // A route with no stops in this direction cannot be solved. Surfaced
            // rather than silently producing a trip with an empty schedule.
            return $this->empty('no_stops_in_direction');
        }

        // Children per stop drive dwell, so resolve them once up front.
        $childrenByStop = $this->childrenByStop($trip, $stops);

        return $trip->isMorning()
            ? $this->solveMorning($trip, $stops, $childrenByStop, $tz)
            : $this->solveAfternoon($trip, $stops, $childrenByStop, $tz, $school);
    }

    /**
     * PART M7 — backward solve.
     *
     *   school_arrival_deadline = bell_time − arrival_buffer_minutes
     *   for k in N..1:
     *       stop_k.at = next.at − leg_minutes(stop_k → next) − dwell(stop_k)
     *   depot_departure = stop_1.at − depot_leg − safety_buffer_minutes
     */
    private function solveMorning(SchoolTrip $trip, Collection $stops,
                                  array $childrenByStop, string $tz): array
    {
        $warnings = [];

        $deadline = $this->at($trip->service_date, $trip->bell_time, $tz)
            ->subMinutes((int) config('school.arrival_buffer_minutes'));

        $schedule = [];
        $distance = 0;

        // The "next" hop for the last stop is the school gate itself.
        $nextAt = $deadline->copy();
        $nextPoint = $this->schoolPoint($trip);

        // Walk the AUTHORED sequence in reverse (PART G1).
        foreach ($stops->reverse()->values() as $stop) {
            $childIds = $childrenByStop[$stop->id] ?? [];
            $dwell = $this->dwellSeconds($stop, count($childIds), 'Morning');

            $legM = $this->metres($stop, $nextPoint);
            $legMin = $this->legMinutes($legM);

            $at = $nextAt->copy()->subMinutes($legMin)->subSeconds($dwell);

            $schedule[] = [
                'seq' => (int) $stop->sequence,
                'stop_id' => $stop->id,
                'name' => $stop->name,
                'scheduled_at' => $at->toIso8601String(),
                'lat' => (float) $stop->latitude,
                'lng' => (float) $stop->longitude,
                'leg_distance_m' => $legM,       // this stop -> next hop
                'leg_minutes' => $legMin,
                'dwell_seconds' => $dwell,
                'child_ids' => $childIds,
                'manually_set' => false,
            ];

            $distance += $legM;
            $nextAt = $at;
            $nextPoint = $stop;
        }

        // Reverse back into travel order — we solved tail-first.
        $schedule = array_reverse($schedule);

        // Depot leg: buses are school-parked at pilot, so school -> first stop.
        $depotLegM = config('school.depot_at_school')
            ? $this->metres($this->schoolPoint($trip), $stops->first())
            : 0;

        $start = $nextAt->copy()
            ->subMinutes($this->legMinutes($depotLegM))
            ->subMinutes((int) config('school.safety_buffer_minutes'));

        // PART M7 — clamp, and say so. A route that cannot make the bell is a
        // human problem (split it), never something to quietly absorb.
        $floor = $this->at($trip->service_date, config('school.earliest_depot_departure'), $tz);

        if ($start->lt($floor)) {
            $warnings[] = 'deadline_too_tight';
            $start = $floor;
        }

        $trip->school_arrival_deadline = $deadline;
        $trip->scheduled_start_at = $start;
        $trip->scheduled_end_at = $deadline;
        $trip->school_depart_at = null;
        $trip->route_schedule = $schedule;
        $trip->distance_m = (int) round($distance + $depotLegM);
        $trip->duration_min = (int) max(0, round($start->diffInMinutes($deadline, false)));

        return [
            'schedule' => $schedule,
            'warnings' => $warnings,
            'distance_m' => $trip->distance_m,
            'duration_min' => $trip->duration_min,
        ];
    }

    /**
     * PART M7 — forward solve.
     *
     *   school_depart_at = dismissal + boarding_minutes(child_count)
     *   for k in 1..N:
     *       stop_k.at = previous.at + leg_minutes + dwell(previous)
     *
     * Dismissal honours a half-day override_end_time (PART C3) — a half day
     * that still solves against the normal bell sends buses an hour late.
     */
    private function solveAfternoon(SchoolTrip $trip, Collection $stops,
                                    array $childrenByStop, string $tz, $school): array
    {
        $warnings = [];

        $cal = $this->calendar ?? SchoolCalendarService::for($school);
        $dismissal = $cal->effectiveEndTime($trip->service_date, (string) $trip->bell_time);

        $childCount = count($trip->child_ids ?? []);
        $boarding = (float) config('school.boarding_base_minutes_at_school')
            + (float) config('school.boarding_minutes_per_child') * $childCount;

        $depart = $this->at($trip->service_date, $dismissal, $tz)
            ->addSeconds((int) round($boarding * 60));

        $schedule = [];
        $distance = 0;

        $prevAt = $depart->copy();
        $prevPoint = $this->schoolPoint($trip);

        foreach ($stops as $stop) {
            $childIds = $childrenByStop[$stop->id] ?? [];
            $dwell = $this->dwellSeconds($stop, count($childIds), 'Afternoon');

            $legM = $this->metres($prevPoint, $stop);
            $legMin = $this->legMinutes($legM);

            $at = $prevAt->copy()->addMinutes($legMin);

            $schedule[] = [
                'seq' => (int) $stop->sequence,
                'stop_id' => $stop->id,
                'name' => $stop->name,
                'scheduled_at' => $at->toIso8601String(),
                'lat' => (float) $stop->latitude,
                'lng' => (float) $stop->longitude,
                'leg_distance_m' => $legM,       // previous hop -> this stop
                'leg_minutes' => $legMin,
                'dwell_seconds' => $dwell,
                'child_ids' => $childIds,
                'manually_set' => false,
            ];

            $distance += $legM;

            // The bus leaves this stop only once the handovers are done.
            $prevAt = $at->copy()->addSeconds($dwell);
            $prevPoint = $stop;
        }

        $trip->school_depart_at = $depart;
        $trip->school_arrival_deadline = null;
        $trip->scheduled_start_at = $depart;
        $trip->scheduled_end_at = $prevAt;
        $trip->route_schedule = $schedule;
        $trip->distance_m = (int) round($distance);
        $trip->duration_min = (int) max(0, round($depart->diffInMinutes($prevAt, false)));

        return [
            'schedule' => $schedule,
            'warnings' => $warnings,
            'distance_m' => $trip->distance_m,
            'duration_min' => $trip->duration_min,
        ];
    }

    /* ---------------- components ---------------- */

    /**
     * PART M7 — base dwell plus per-child. Afternoon costs more per child
     * because a verified handover (Invariant #1) is slower than boarding.
     * A per-stop override wins outright — a school gate stop with a marshal
     * genuinely is faster than the formula thinks.
     */
    public function dwellSeconds(RouteStop $stop, int $childCount, string $direction): int
    {
        if ($stop->dwell_seconds_override !== null) {
            return (int) $stop->dwell_seconds_override;
        }

        $perChild = $direction === 'Morning'
            ? (int) config('school.per_child_dwell_seconds_morning')
            : (int) config('school.per_child_dwell_seconds_afternoon');

        return (int) config('school.base_dwell_seconds') + $perChild * $childCount;
    }

    /**
     * Drive time for a leg, in minutes.
     *
     * ⚠ SEAM: PART M7 wants Google Directions here (traffic-aware, best_guess,
     * 15-min-bucket cache) via DriveTimeEstimator, solving each morning leg with
     * departure_time set to the PLANNED departure — a 07:00 leg and an 08:20 leg
     * face materially different traffic. DriveTimeEstimator is not built yet, so
     * this is the documented haversine fallback: straight line × circuity ÷ a
     * deliberately low speed, which errs early (PART M10).
     */
    public function legMinutes(float $metres): int
    {
        $road = $metres * (float) config('school.road_circuity_factor');
        $speed = max(1, (float) config('school.fallback_speed_m_per_min'));

        return (int) max(1, ceil($road / $speed));
    }

    /** Great-circle metres between two lat/lng-bearing objects. */
    public function metres($from, $to): float
    {
        if (! $from || ! $to) return 0.0;

        $lat1 = deg2rad((float) $from->latitude);
        $lat2 = deg2rad((float) $to->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $to->longitude - (float) $from->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Children riding each stop on this trip, keyed by stop_id.
     * Restricted to the trip's own child_ids so absences already removed by the
     * generator stay removed — dwell must not count a child who isn't coming.
     */
    private function childrenByStop(SchoolTrip $trip, Collection $stops): array
    {
        $riding = collect($trip->child_ids ?? []);

        if ($riding->isEmpty()) {
            return [];
        }

        return ChildStopAssignment::whereIn('stop_id', $stops->pluck('id'))
            ->where('direction', $trip->direction)
            ->whereIn('child_id', $riding)
            ->get()
            ->filter(fn ($a) => $a->isEffectiveOn($trip->service_date))
            ->groupBy('stop_id')
            ->map(fn ($g) => $g->pluck('child_id')->unique()->values()->all())
            ->all();
    }

    /** The school gate, as a lat/lng-bearing object the distance helpers accept. */
    private function schoolPoint(SchoolTrip $trip): ?object
    {
        $school = $trip->school ?? $trip->school()->first();

        if ($school?->latitude === null || $school?->longitude === null) {
            return null;
        }

        return (object) ['latitude' => $school->latitude, 'longitude' => $school->longitude];
    }

    /**
     * ⚠ PART L1: $date is a raw Y-m-d string and $time is 'HH:MM' or 'HH:MM:SS'.
     * Built in the SCHOOL's timezone, never the server's (PART K4).
     */
    private function at(string $date, ?string $time, string $tz): Carbon
    {
        $time = $time ?: '00:00';

        // TIME columns come back as HH:MM:SS from MySQL and HH:MM:SS or HH:MM
        // from SQLite depending on how they were written.
        $time = substr($time, 0, 5);

        return Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
    }

    private function empty(string $warning): array
    {
        return ['schedule' => [], 'warnings' => [$warning], 'distance_m' => 0, 'duration_min' => 0];
    }
}
