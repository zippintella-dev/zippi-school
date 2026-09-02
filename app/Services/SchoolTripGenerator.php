<?php

namespace App\Services;

use App\Models\ChildStopAssignment;
use App\Models\School;
use App\Models\SchoolBellTime;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolStopArrival;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\SchoolTripGenerationRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PART M2 — nightly trip generation.
 *
 * Runs at 02:30 school-local for the coming school day. Idempotent by design:
 * re-running it must never duplicate a trip or clobber an admin's edit, because
 * in practice it WILL be re-run by hand at 06:00 by someone checking whether the
 * cron fired.
 *
 * The unit of generation is (route × direction × bell tier). A bell tier is one of the school's staggered bells,
 * so one bus legitimately runs 2–3 trips per direction — the Senior run at 07:40,
 * then Middle at 08:15, then Primary at 08:45. That is the entire point of PART M
 * and the reason a route is NOT one trip per day.
 *
 * ⚠ The tier comes from the CHILDREN on the route, not from routes.bell_tier.
 * routes.bell_tier is a labelling hint; if it were authoritative a route could
 * only ever serve one tier, which contradicts the tiered model above.
 *
 * ⚠ PART L1: every date in and out of here is a raw Y-m-d string.
 */
class SchoolTripGenerator
{
    private array $warnings = [];
    private array $errors = [];
    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;

    public function __construct(private SchoolTripSolver $solver) {}

    /**
     * @return SchoolTripGenerationRun the audit row for this invocation
     */
    public function generate(School $school, string $date, bool $force = false,
                             bool $dryRun = false, string $trigger = 'cron'): SchoolTripGenerationRun
    {
        $startedAt = microtime(true);

        $this->warnings = [];
        $this->errors = [];
        $this->created = 0;
        $this->updated = 0;
        $this->skipped = 0;

        $cal = SchoolCalendarService::for($school);

        // M2 step 1 — a holiday or vacation generates nothing at all.
        if (! $cal->isSchoolDay($date)) {
            $this->warnings[] = [
                'scope' => 'calendar',
                'warning' => 'not_a_school_day',
                'detail' => $cal->describe($date),
            ];

            return $this->record($school, $date, $force, $dryRun, $trigger, $startedAt);
        }

        $routes = $school->routes()->with('stops')->where('status', 'active')->orderBy('code')->get();

        if ($routes->isEmpty()) {
            $this->warnings[] = ['scope' => 'school', 'warning' => 'no_active_routes'];

            return $this->record($school, $date, $force, $dryRun, $trigger, $startedAt);
        }

        $bells = SchoolBellTime::where('school_id', $school->id)->get();
        $crew = new RouteCrewResolver($routes->pluck('id'), $date);

        foreach (['Morning', 'Afternoon'] as $direction) {
            // M2 step 1 — a closure can suppress one direction only (half day,
            // exam morning). tripsRun() is the single source of that truth.
            if (! $cal->tripsRun($date, $direction)) {
                $this->warnings[] = [
                    'scope' => 'calendar',
                    'direction' => $direction,
                    'warning' => 'direction_suppressed',
                ];
                continue;
            }

            foreach ($routes as $route) {
                foreach ($this->tiersFor($route, $direction, $date, $bells) as $tier => $childIds) {
                    try {
                        $this->generateOne($school, $route, $direction, (string) $tier,
                            $childIds, $date, $bells, $crew, $force, $dryRun);
                    } catch (Throwable $e) {
                        $this->errors[] = [
                            'route' => $route->code,
                            'direction' => $direction,
                            'tier' => $tier,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        if (! $dryRun) {
            $this->sweepOrphanTrips($school, $date);
            $this->resequenceBuses($school, $date);
        }

        return $this->record($school, $date, $force, $dryRun, $trigger, $startedAt);
    }

    /**
     * M2 step 3 — the riding child list for one (route, direction, tier),
     * grouped by tier. Absences already recorded for that (date, direction, tier)
     * are removed here so they never reach the solver's dwell maths.
     *
     * @return array<string, int[]>  tier => child ids
     */
    private function tiersFor($route, string $direction, string $date, $bells): array
    {
        $assignments = ChildStopAssignment::with('child')
            ->where('route_id', $route->id)
            ->where('direction', $direction)
            ->get()
            ->filter(fn ($a) => $a->isEffectiveOn($date))
            ->filter(fn ($a) => $a->child && $a->child->status === 'active');

        if ($assignments->isEmpty()) {
            return [];
        }

        $absences = SchoolChildAbsence::where('service_date', $date)   // raw string (L1)
            ->where('direction', $direction)
            ->whereIn('child_id', $assignments->pluck('child_id'))
            ->get();

        $grouped = [];

        foreach ($assignments as $a) {
            $tier = $a->child->bell_tier;

            // A child with no tier set would otherwise be silently dropped from
            // every trip. Derive it from the bell band rather than lose them.
            if (! $tier) {
                $tier = $bells->first(fn ($b) => $b->coversGrade((string) $a->child->grade))?->bell_tier;

                $this->warnings[] = [
                    'scope' => 'child',
                    'route' => $route->code,
                    'child_id' => $a->child_id,
                    'warning' => $tier ? 'bell_tier_derived_from_grade' : 'no_bell_tier_and_no_matching_bell',
                    'detail' => 'grade ' . $a->child->grade . ($tier ? " → {$tier}" : ''),
                ];

                if (! $tier) continue;
            }

            // Direction-aware: a morning-only absence must not empty the afternoon.
            $isAbsent = $absences->contains(fn ($x) => $x->child_id === $a->child_id
                && (! $x->bell_tier || $x->bell_tier === $tier));

            if ($isAbsent) continue;

            $grouped[$tier][] = $a->child_id;
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $grouped);
    }

    private function generateOne(School $school, $route, string $direction, string $tier,
                                 array $childIds, string $date, $bells,
                                 RouteCrewResolver $crew, bool $force, bool $dryRun): void
    {
        if (! $childIds) {
            return;   // no riders on this tier — no trip, not an empty one
        }

        $existing = SchoolTrip::where('route_id', $route->id)
            ->where('service_date', $date)          // raw string (L1)
            ->where('direction', $direction)
            ->where('bell_tier', $tier)
            ->first();

        // M2 step 4 — an admin's edit always wins, forced or not. This is the
        // whole reason --force is not simply "delete and rebuild".
        if ($existing && $existing->admin_edited) {
            $this->skipped++;
            return;
        }

        // A trip already under way or finished is history. Never rewrite it.
        if ($existing && ! in_array($existing->status, ['scheduled', 'cancelled'], true)) {
            $this->skipped++;
            return;
        }

        if ($existing && ! $force) {
            $this->skipped++;
            return;
        }

        $bell = $bells->first(fn ($b) => $b->bell_tier === $tier);

        if (! $bell) {
            $this->warnings[] = [
                'scope' => 'route', 'route' => $route->code, 'direction' => $direction,
                'tier' => $tier, 'warning' => 'no_bell_time_for_leg',
            ];
            return;
        }

        // Morning solves back from the bell in; afternoon forward from dismissal.
        $bellTime = $direction === 'Morning' ? $bell->start_time : $bell->end_time;

        $resolved = $crew->resolve($route->id, $direction, $tier);

        // PART K15 — a bus or a crew member whose documents have expired (or were
        // never recorded) is not assigned. See withholdNonCompliant().
        $resolved = $this->withholdNonCompliant($resolved, $route, $direction, $tier);

        if (! $resolved['bus']) {
            $this->warnings[] = [
                'scope' => 'route', 'route' => $route->code, 'direction' => $direction,
                'tier' => $tier, 'warning' => 'no_bus_assigned',
            ];
        }

        if (! $resolved['attendant']) {
            // Invariant #1 lives on the attendant — they are who marks and hands
            // over each child. A trip without one cannot satisfy it.
            $this->warnings[] = [
                'scope' => 'route', 'route' => $route->code, 'direction' => $direction,
                'tier' => $tier, 'warning' => 'no_attendant_assigned',
            ];
        }

        $trip = $existing ?: new SchoolTrip;

        $trip->fill([
            'school_id' => $school->id,
            'route_id' => $route->id,
            'route_version' => $route->version ?? 1,
            'bus_id' => $resolved['bus']?->id,
            'driver_id' => $resolved['driver']?->id,
            'attendant_id' => $resolved['attendant']?->id,
            'service_date' => $date,               // raw string (L1)
            'direction' => $direction,
            'bell_tier' => $tier,
            'status' => 'scheduled',
            'child_ids' => $childIds,
            'bell_time' => $bellTime,
            'auto_generated' => true,
            'admin_edited' => false,
            'source' => 'auto',
        ]);

        $trip->setRelation('school', $school);
        $trip->setRelation('route', $route);

        // M2 step 5 — solve (PART M7).
        $solved = $this->solver->solve($trip);

        foreach ($solved['warnings'] as $w) {
            $this->warnings[] = [
                'scope' => 'trip', 'route' => $route->code, 'direction' => $direction,
                'tier' => $tier, 'warning' => $w,
            ];
        }

        if ($dryRun) {
            $existing ? $this->updated++ : $this->created++;
            return;
        }

        DB::transaction(function () use ($trip, $childIds, $solved, $existing) {
            $trip->save();

            $this->syncTripChildren($trip, $childIds, $solved['schedule']);
            $this->syncStopArrivals($trip, $solved['schedule']);

            $existing ? $this->updated++ : $this->created++;
        });
    }

    /**
     * One row per child per trip — the spine of the journey record (PART D).
     *
     * Only `pending` rows are touched. A child already boarded, absent or handed
     * over has real operational history attached, and a regeneration must not
     * erase it — that history is what answers a dispute (PART A7).
     */
    /**
     * PART K15 — refuse to assign a vehicle or a person whose paperwork is not
     * valid, and say exactly why.
     *
     * ⚠ THE NIGHT BEFORE IS THE RIGHT PLACE FOR THIS. Generation runs at 02:30,
     * which is the last moment a school can still swap a bus or call in another
     * driver before children are standing at a stop. Refusing at trip-start
     * instead would strand a loaded bus at the kerb over a lapsed PUC — the
     * failure mode would be worse than the thing it prevents.
     *
     * ⚠ THE TRIP IS STILL CREATED. Withholding the resource, not the trip, is
     * deliberate: the children are still expected, and a route that silently
     * vanishes from the board is a route nobody notices is missing. What ops
     * sees is "RT-02 Morning has no bus — Insurance expired 6d ago", which
     * names both the problem and the fix.
     *
     * ⚠ ONLY EXPIRED AND MISSING BLOCK. "Expiring in 12 days" still runs —
     * it is a warning, and stopping a bus for a document that is currently
     * valid would teach a school to ignore the board.
     */
    private function withholdNonCompliant(array $resolved, $route, string $direction, string $tier): array
    {
        foreach (['bus', 'driver', 'attendant'] as $slot) {
            $subject = $resolved[$slot] ?? null;

            if (! $subject) continue;

            $blockers = $subject->complianceBlockers();

            if (! $blockers) continue;

            $this->warnings[] = [
                'scope'     => 'route',
                'route'     => $route->code,
                'direction' => $direction,
                'tier'      => $tier,
                'warning'   => $slot . '_non_compliant',
                'subject'   => $subject->name ?? $subject->reg_no ?? (string) $subject->id,
                'blockers'  => $blockers,
            ];

            $resolved[$slot] = null;
        }

        return $resolved;
    }

    private function syncTripChildren(SchoolTrip $trip, array $childIds, array $schedule): void
    {
        $stopByChild = [];
        foreach ($schedule as $row) {
            foreach ($row['child_ids'] as $cid) {
                $stopByChild[$cid] = ['stop_id' => $row['stop_id'], 'sequence' => $row['seq']];
            }
        }

        foreach ($childIds as $childId) {
            SchoolTripChild::updateOrCreate(
                ['trip_id' => $trip->id, 'child_id' => $childId],
                [
                    'stop_id' => $stopByChild[$childId]['stop_id'] ?? null,
                    'sequence' => $stopByChild[$childId]['sequence'] ?? null,
                ]
            );
        }

        // A child moved off this route/tier since the last run leaves a stale row.
        SchoolTripChild::where('trip_id', $trip->id)
            ->whereNotIn('child_id', $childIds ?: [0])
            ->where('status', 'pending')
            ->delete();
    }

    /** Scheduled stop times, in authored sequence (PART G1). */
    private function syncStopArrivals(SchoolTrip $trip, array $schedule): void
    {
        foreach ($schedule as $row) {
            $arrival = SchoolStopArrival::firstOrNew([
                'trip_id' => $trip->id,
                'stop_id' => $row['stop_id'],
            ]);

            // Never move a stop the bus has already reached.
            if ($arrival->arrived_at) continue;

            $arrival->fill([
                'sequence' => $row['seq'],
                'scheduled_at' => Carbon::parse($row['scheduled_at']),
                'latitude' => $row['lat'],
                'longitude' => $row['lng'],
            ])->save();
        }
    }

    /**
     * M2 step 6 — sequence is the trip's order within its BUS's day, so it can
     * only be assigned once every trip for that bus exists and is solved.
     * Trips with no bus are left at 0; there is no day to order them within.
     */
    private function resequenceBuses(School $school, string $date): void
    {
        $trips = SchoolTrip::where('school_id', $school->id)
            ->where('service_date', $date)          // raw string (L1)
            ->whereNotNull('bus_id')
            ->orderBy('scheduled_start_at')
            ->get()
            ->groupBy('bus_id');

        foreach ($trips as $busTrips) {
            foreach ($busTrips->values() as $i => $trip) {
                $seq = $i + 1;
                if ((int) $trip->sequence !== $seq) {
                    $trip->forceFill(['sequence' => $seq])->save();
                }
            }
        }
    }

    /**
     * PART M14 — remove auto-generated trips that no longer have any rider.
     *
     * ⚠ Without this, removing a student leaves their trip behind forever. The
     * generator only ever iterates (route × direction × tier) combinations that
     * currently HAVE children, so a combination that has emptied out is never
     * visited again and its trip is never updated. The live board then shows a
     * bus scheduled for a child who is not on the roster — which is exactly how
     * a driver gets dispatched to a stop nobody is waiting at.
     *
     * Only untouched auto rows are swept. A trip that has STARTED, been
     * completed, or been edited by an admin is left alone: it is history or
     * someone's decision, and neither is ours to delete (PART M13 refuses to
     * delete a started trip for the same reason).
     */
    private function sweepOrphanTrips(School $school, string $date): void
    {
        $trips = SchoolTrip::where('school_id', $school->id)
            ->where('service_date', $date)          // raw string (L1)
            ->where('status', 'scheduled')
            ->where('auto_generated', true)
            ->where('admin_edited', false)
            ->get();

        foreach ($trips as $trip) {
            $riders = SchoolTripChild::where('trip_id', $trip->id)
                ->whereHas('child', fn ($q) => $q->where('status', 'active'))
                ->count();

            if ($riders > 0) continue;

            $this->warnings[] = [
                'scope' => 'trip',
                'route' => $trip->route?->code,
                'direction' => $trip->direction,
                'tier' => $trip->bell_tier,
                'warning' => 'orphan_trip_removed',
                'detail' => 'no active riders remained',
            ];

            DB::transaction(function () use ($trip) {
                SchoolTripChild::where('trip_id', $trip->id)->delete();
                SchoolStopArrival::where('trip_id', $trip->id)->delete();
                $trip->delete();
            });
        }
    }

    private function record(School $school, string $date, bool $force, bool $dryRun,
                            string $trigger, float $startedAt): SchoolTripGenerationRun
    {
        return SchoolTripGenerationRun::create([
            'school_id' => $school->id,
            'service_date' => $date,               // raw string (L1)
            'trigger' => $trigger,
            'dry_run' => $dryRun,
            'forced' => $force,
            'created_count' => $this->created,
            'updated_count' => $this->updated,
            'skipped_count' => $this->skipped,
            'warning_count' => count($this->warnings),
            'warnings' => $this->warnings ?: null,
            'errors' => $this->errors ?: null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
