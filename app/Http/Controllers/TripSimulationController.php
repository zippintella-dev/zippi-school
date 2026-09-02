<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolChildHandover;
use App\Models\SchoolStopArrival;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⚠ A TEST HARNESS, NOT A PRODUCT FEATURE.
 *
 * Everything here is the job of **Zippi Fleet** — the attendant's app marks a
 * child boarded, confirms arrival at the gate, verifies the handover code and
 * performs the sweep. Fleet does not exist yet, so nothing in the system writes
 * those events and the parent app has nothing to react to. This stands in for
 * it so the family-facing behaviour can actually be exercised.
 *
 * Rules it follows, deliberately:
 *
 *  - **Zippi staff only.** A school user must never be able to fabricate a
 *    boarding record; that record is what answers a dispute.
 *  - **The safety invariants are NOT bypassed.** Completing still fails while a
 *    child is unaccounted for (#2) or the sweep is missing (#3). The point of
 *    the harness is to watch those gates hold, not to route around them.
 *  - **Every action is audited** with `simulated: true` in the payload, so a
 *    fabricated event can always be told apart from a real one later.
 *
 * ⚠ Delete this controller, its routes and its panel the day Fleet ships.
 */
class TripSimulationController extends Controller
{
    /** Only Zippi staff may fabricate operational events. */
    private function authorizeSimulation(SchoolTrip $trip): void
    {
        abort_unless(auth('web')->user()?->isZippi(), 403,
            'Only Zippi staff can simulate a trip.');

        abort_unless($trip->school_id === $this->activeSchool()->id, 403);
    }

    private function audit(SchoolTrip $trip, string $action, array $payload = []): void
    {
        SchoolAdminAuditLog::record('sim_' . $action, [
            'school_id' => $trip->school_id,
            'trip_id' => $trip->id,
            // ⚠ Marks the row as fabricated. Without this a simulated boarding
            // is indistinguishable from one an attendant actually recorded.
            'payload' => $payload + ['simulated' => true],
        ], 'Simulated (Zippi Fleet not yet built).');
    }

    /** PART M5 — promote the scheduled row rather than inserting a new one. */
    public function start(SchoolTrip $trip)
    {
        $this->authorizeSimulation($trip);

        if ($trip->status !== 'scheduled') {
            return back()->with('error', 'Only a scheduled trip can be started.');
        }

        // Only one trip per bus may be running (PART M1).
        $running = SchoolTrip::where('bus_id', $trip->bus_id)
            ->where('service_date', $trip->service_date)
            ->where('status', 'started')
            ->exists();

        if ($trip->bus_id && $running) {
            return back()->with('error',
                'Another trip on this bus is already running. Only one trip per bus '
                . 'may be started at a time (PART M1).');
        }

        $trip->update(['status' => 'started', 'started_at' => now()]);

        // Park the bus at the school for a morning run, at the school for an
        // afternoon run too — either way it starts where the depot is.
        $this->moveBusTo($trip, $trip->school->latitude, $trip->school->longitude);

        $this->audit($trip, 'trip_started');

        return back()->with('ok', 'Trip started. The parent app will show it within 10 seconds.');
    }

    /**
     * Advance the bus to the next stop it has not reached, stamping the arrival.
     * This is what makes the live map move.
     */
    public function advance(SchoolTrip $trip)
    {
        $this->authorizeSimulation($trip);

        if ($trip->status !== 'started') {
            return back()->with('error', 'Start the trip first.');
        }

        $next = SchoolStopArrival::with('stop')
            ->where('trip_id', $trip->id)
            ->whereNull('arrived_at')
            ->orderBy('sequence')
            ->first();

        if (! $next) {
            return back()->with('error',
                'Every stop has been reached. Next: confirm arrival at school.');
        }

        $next->update(['arrived_at' => now(), 'departed_at' => now()]);
        $this->moveBusTo($trip, $next->latitude, $next->longitude);

        $this->audit($trip, 'stop_reached', ['stop' => $next->stop?->name]);

        return back()->with('ok', 'Bus is now at ' . ($next->stop?->name ?? 'the next stop') . '.');
    }

    /** PART F2 — mark one child boarded, with geo and server time. */
    public function board(Request $request, SchoolTrip $trip, SchoolTripChild $row)
    {
        $this->authorizeSimulation($trip);
        abort_unless($row->trip_id === $trip->id, 404);

        if ($trip->status !== 'started') {
            return back()->with('error', 'Start the trip first.');
        }

        $row->update([
            'status' => 'boarded',
            'boarded_at' => now(),
            'boarded_lat' => $trip->bus?->latitude,
            'boarded_lng' => $trip->bus?->longitude,
            'acted_by_type' => 'simulation',
            'acted_by_id' => auth('web')->id(),
        ]);

        $this->audit($trip, 'child_boarded', ['child_id' => $row->child_id]);

        return back()->with('ok', $row->child?->name . ' marked boarded.');
    }

    /** PART A6 — the morning-only outcome. Never available at a drop stop. */
    public function notAtStop(SchoolTrip $trip, SchoolTripChild $row)
    {
        $this->authorizeSimulation($trip);
        abort_unless($row->trip_id === $trip->id, 404);

        // ⚠ INVARIANT #1. A child not present at a DROP stop cannot be marked
        // no-show and left — they return to school. "Not at stop" is a morning
        // outcome only, and the harness must not offer a shortcut past that.
        if (! $trip->isMorning()) {
            return back()->with('error',
                'A child cannot be marked "not at stop" on an afternoon trip. '
                . 'If nobody is at the drop stop the child returns to school '
                . '(Invariant #1).');
        }

        $row->update(['status' => 'not_at_stop', 'acted_by_type' => 'simulation']);

        $this->audit($trip, 'child_not_at_stop', ['child_id' => $row->child_id]);

        return back()->with('ok', $row->child?->name . ' marked not at stop.');
    }

    /** PART F4 — morning. Everyone aboard transitions to arrived_at_school. */
    public function arriveAtSchool(SchoolTrip $trip)
    {
        $this->authorizeSimulation($trip);

        if (! $trip->isMorning()) {
            return back()->with('error', 'Only a morning trip arrives at school.');
        }

        DB::transaction(function () use ($trip) {
            $trip->update(['arrived_at_school_at' => now()]);

            SchoolTripChild::where('trip_id', $trip->id)
                ->where('status', 'boarded')
                ->update(['status' => 'arrived_at_school']);

            $this->moveBusTo($trip, $trip->school->latitude, $trip->school->longitude);
        });

        $this->audit($trip, 'arrived_at_school');

        return back()->with('ok',
            'Arrived at school. Every boarded child is now marked at school, and '
            . 'their live map closes (enterprise L29).');
    }

    /**
     * PART A7 — the custody record. This is what answers a dispute, so the
     * harness writes a real handover row rather than just flipping a status.
     */
    public function handover(Request $request, SchoolTrip $trip, SchoolTripChild $row)
    {
        $this->authorizeSimulation($trip);
        abort_unless($row->trip_id === $trip->id, 404);

        if ($trip->isMorning()) {
            return back()->with('error', 'Handover happens on the afternoon trip.');
        }

        $method = $request->input('method', 'handover_code');
        $child = $row->child;

        // Self-release needs BOTH signed consent and the school's minimum grade.
        if ($method === 'self_release' && ! $child?->canSelfRelease()) {
            return back()->with('error',
                $child?->name . ' cannot self-release — that needs signed consent '
                . 'AND the school\'s minimum grade (Invariant #1).');
        }

        $receiver = $child?->guardians->first();

        DB::transaction(function () use ($trip, $row, $method, $receiver) {
            $row->update([
                'status' => $method === 'self_release'
                    ? 'alighted_self_release' : 'alighted_to_guardian',
                'alighted_at' => now(),
                'alighted_lat' => $trip->bus?->latitude,
                'alighted_lng' => $trip->bus?->longitude,
                'acted_by_type' => 'simulation',
            ]);

            SchoolChildHandover::create([
                'trip_id' => $trip->id,
                'child_id' => $row->child_id,
                'stop_id' => $row->stop_id,
                'receiver_guardian_id' => $method === 'self_release' ? null : $receiver?->id,
                'receiver_name' => $method === 'self_release' ? null : $receiver?->name,
                'verification_method' => $method,
                'latitude' => $trip->bus?->latitude,
                'longitude' => $trip->bus?->longitude,
                'acted_by_staff_id' => $trip->attendant_id,
            ]);
        });

        $this->audit($trip, 'child_handed_over',
            ['child_id' => $row->child_id, 'method' => $method]);

        return back()->with('ok',
            $row->child?->name . ' handed over. Their live map and handover code '
            . 'close immediately, while the bus keeps running for everyone else.');
    }

    /** INVARIANT #3 — the sweep is blocking, timestamped and geo-stamped. */
    public function sweep(SchoolTrip $trip)
    {
        $this->authorizeSimulation($trip);

        if ($trip->status !== 'started') {
            return back()->with('error', 'The sweep happens on a running trip.');
        }

        $trip->update([
            'sweep_verified_at' => now(),
            'sweep_lat' => $trip->bus?->latitude,
            'sweep_lng' => $trip->bus?->longitude,
            'sweep_by_staff_id' => $trip->attendant_id,
        ]);

        $this->audit($trip, 'sweep_verified');

        return back()->with('ok', 'Sweep recorded. The trip can now be completed.');
    }

    /**
     * ⚠ THE INVARIANTS ARE ENFORCED HERE, NOT WAIVED.
     *
     * #2 — a trip cannot complete while a child is unaccounted for.
     * #3 — the bus must be swept first.
     *
     * The harness exists to let you watch these gates hold. Never add a
     * "force complete" that skips them.
     */
    public function complete(SchoolTrip $trip)
    {
        $this->authorizeSimulation($trip);

        if ($trip->status !== 'started') {
            return back()->with('error', 'Only a running trip can be completed.');
        }

        $trip->load('tripChildren');

        $unaccounted = $trip->unaccountedChildIds();

        if ($unaccounted) {
            $names = Child::whereIn('id', $unaccounted)->pluck('name')->implode(', ');

            return back()->with('error',
                'Cannot complete — still unaccounted for: ' . $names
                . '. Every child must reach a terminal state first (Invariant #2).');
        }

        if ($trip->sweepPending()) {
            return back()->with('error',
                'Cannot complete — the bus has not been swept (Invariant #3).');
        }

        $trip->update([
            'status' => 'completed',
            'completed_at' => now(),
            'headcount_reported' => $trip->tripChildren->whereNotIn('status', ['absent'])->count(),
            'headcount_verified' => true,
        ]);

        $this->audit($trip, 'trip_completed');

        return back()->with('ok', 'Trip completed.');
    }

    /** Put the trip back to scheduled so the same scenario can be run again. */
    public function reset(SchoolTrip $trip)
    {
        $this->authorizeSimulation($trip);

        DB::transaction(function () use ($trip) {
            SchoolChildHandover::where('trip_id', $trip->id)->delete();

            SchoolTripChild::where('trip_id', $trip->id)->update([
                'status' => 'pending', 'boarded_at' => null, 'boarded_lat' => null,
                'boarded_lng' => null, 'alighted_at' => null, 'alighted_lat' => null,
                'alighted_lng' => null, 'acted_by_type' => null, 'acted_by_id' => null,
            ]);

            SchoolStopArrival::where('trip_id', $trip->id)
                ->update(['arrived_at' => null, 'departed_at' => null]);

            $trip->update([
                'status' => 'scheduled', 'started_at' => null,
                'arrived_at_school_at' => null, 'completed_at' => null,
                'sweep_verified_at' => null, 'sweep_lat' => null, 'sweep_lng' => null,
                'sweep_by_staff_id' => null, 'headcount_reported' => null,
                'headcount_verified' => false,
            ]);

            $trip->bus?->update(['latitude' => null, 'longitude' => null, 'last_ping_at' => null]);
        });

        $this->audit($trip, 'trip_reset');

        return back()->with('ok', 'Trip reset to scheduled. Run it again from the top.');
    }

    /** The bus position is what the parent app draws on the live map. */
    private function moveBusTo(SchoolTrip $trip, $lat, $lng): void
    {
        $trip->bus?->update([
            'latitude' => $lat,
            'longitude' => $lng,
            'last_ping_at' => now(),
            'speed_kmph' => random_int(12, 34),
        ]);
    }
}
