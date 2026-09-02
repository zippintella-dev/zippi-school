<?php

namespace App\Services;

use App\Models\Child;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;

/**
 * PART M14 — reconcile an already-generated roster with absences marked after
 * generation ran.
 *
 * ⚠ WHY THIS EXISTS. `SchoolTripGenerator` drops absent children at 02:30, but
 * a parent marks absence at 06:40 — after the roster is already built. Before
 * this, that absence row existed and the fleet roster still listed the child as
 * a rider to board: the attendant waits at a kerb for a child who is at home,
 * and the parent who did everything right is told nothing went wrong.
 *
 * It is also what makes `FleetApiController::absenceFor()` fire at all — that
 * method keys off `school_trip_children.status`, so an absence that never
 * reaches the roster row is invisible to the crew no matter what the absence
 * table says.
 *
 * ⚠ ONLY `pending` ROWS MOVE. A child already boarded, handed over or arrived
 * has real operational history, and a late absence must never erase it — that
 * history is what answers a dispute months later (PART A7). Same rule as
 * SchoolTripGenerator::syncTripChildren().
 *
 * ⚠ `absent` COUNTS AS ACCOUNTED FOR. `SchoolTrip::unaccountedChildIds()` blocks
 * completion on `pending` and `boarded` only, so moving a row to `absent` is
 * what lets a trip close without weakening Invariant #2.
 */
class TripRosterReconciler
{
    /**
     * Mark the child absent on every open trip for this (service_date,
     * direction). Returns the number of roster rows moved.
     */
    public function applyAbsence(Child $child, string $date, string $direction): int
    {
        return $this->rowsFor($child, $date, $direction)
            ->where('status', 'pending')
            ->update(['status' => 'absent']);
    }

    /**
     * Undo — put the child back on the roster.
     *
     * ⚠ Only when NO absence row remains for that day and direction. A parent
     * undoing their own absence must not also cancel one the school entered,
     * and the attendant's roster-lock absence is not a parent's to reverse.
     *
     * Pass a null direction to clear both, which is what an undo from the
     * parent app does — it deletes by (child, date) without naming a direction.
     */
    public function clearAbsence(Child $child, string $date, ?string $direction = null): int
    {
        $directions = $direction ? [$direction] : ['Morning', 'Afternoon'];
        $restored = 0;

        foreach ($directions as $d) {
            $stillAbsent = SchoolChildAbsence::where('child_id', $child->id)
                ->where('service_date', $date)   // raw string (PART L1)
                ->where('direction', $d)
                ->exists();

            if ($stillAbsent) continue;

            $restored += $this->rowsFor($child, $date, $d)
                ->where('status', 'absent')
                ->update(['status' => 'pending']);
        }

        return $restored;
    }

    /**
     * Roster rows for this child on open trips for the given date and direction.
     *
     * A completed or cancelled trip is history and is never rewritten. Trip ids
     * are resolved first rather than with `whereHas`, so the UPDATE stays a
     * plain `whereIn` on every driver.
     */
    private function rowsFor(Child $child, string $date, string $direction)
    {
        $tripIds = SchoolTrip::where('school_id', $child->school_id)
            // ⚠ PART L1 — raw Y-m-d compare. A Carbon here matches ZERO rows
            // and this silently does nothing, which is the enterprise bug.
            ->where('service_date', $date)
            ->where('direction', $direction)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->pluck('id');

        return SchoolTripChild::where('child_id', $child->id)
            ->whereIn('trip_id', $tripIds ?: [0]);
    }
}
