<?php

namespace App\Services;

use App\Models\Child;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use Carbon\Carbon;

/**
 * One child's current state, for every family-facing surface.
 *
 * ⚠ THIS IS THE ONLY PLACE THE LIVE-MAP RULE IS DECIDED. The web app, the JSON
 * poll and the Flutter app all read from here. Duplicating this logic per client
 * is how one surface keeps showing a bus after the child is off it — so if you
 * are about to copy `showMap` into a controller, don't.
 *
 * The rule (enterprise L29):
 *
 *   terminalForMe = status in [absent, not_at_stop, alighted*, returned_to_school]
 *   showLiveMap   = trip is running && !terminalForMe
 *                   && !(morning && already arrived at school)
 *
 * Morning keeps the map until the bus reaches school. Afternoon closes it for
 * THIS family the moment their child is handed over, regardless of what the
 * rest of the route is still doing.
 *
 * ⚠ When the map is closed the bus coordinates are OMITTED from the payload —
 * not merely hidden by the client. A client-side check alone means the bus
 * position is on the device and one bug away from being visible.
 */
class FamilyCardBuilder
{
    /** Statuses after which this family's live map closes. */
    public const TERMINAL = [
        'absent', 'not_at_stop', 'alighted_to_guardian',
        'alighted_self_release', 'returned_to_school',
    ];

    public function build(Child $child): array
    {
        $tz = $child->school?->timezone ?: config('app.timezone');
        $today = Carbon::now($tz)->toDateString();

        $rows = SchoolTripChild::with(['trip.route', 'trip.bus', 'trip.driver', 'trip.attendant', 'stop'])
            ->where('child_id', $child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $today))
            ->get();

        // The trip that matters now: one that's running, else the next
        // scheduled, else the most recent — so an evening card still shows the
        // afternoon run rather than reverting to the finished morning one.
        $row = $rows->first(fn ($r) => $r->trip->status === 'started')
            ?? $rows->sortBy(fn ($r) => $r->trip->scheduled_start_at)
                    ->first(fn ($r) => $r->trip->status === 'scheduled')
            ?? $rows->sortByDesc(fn ($r) => $r->trip->scheduled_start_at)->first();

        $trip = $row?->trip;

        $absence = SchoolChildAbsence::where('child_id', $child->id)
            ->where('service_date', $today)      // raw string (L1)
            ->get();

        $terminal = $row && in_array($row->status, self::TERMINAL, true);

        $showMap = $trip
            && $trip->status === 'started'
            && ! $terminal
            && ! ($trip->isMorning() && $row->status === 'arrived_at_school');

        return [
            'child_id' => $child->id,
            'name' => $child->name,
            'grade' => $child->grade,
            'bell_tier' => $child->bell_tier,
            'school' => $child->school?->name,
            'school_phone' => $child->school?->contact_phone,
            'service_date' => $today,
            'has_active_trip' => (bool) ($trip && $trip->status === 'started'),
            'show_live_map' => (bool) $showMap,
            'status' => $row?->status,
            // ⚠ A child taken off transport must SAY so.
            //
            // A retired child keeps their guardian links — PART K13 retains the
            // journey record, and a parent whose child left transport in March
            // must still be able to read what happened in February. So the card
            // is still built. But it used to fall through to "No trip today",
            // which is exactly what a holiday says: a family whose child had
            // been removed from the bus saw a normal-looking card and had no
            // way to tell. The history stays; the pretence that they are still
            // riding does not.
            'on_transport' => $child->status === 'active',
            'status_label' => $child->status === 'active'
                ? $this->statusLabel($row?->status, $trip)
                : 'Not on school transport',
            'absent' => $absence->isNotEmpty(),
            'absent_directions' => $absence->pluck('direction')->values()->all(),
            // PART A7 — afternoon only, and only while the child is still to be
            // collected. A code left on screen after handover is just noise.
            'show_handover_code' => (bool) ($trip && ! $trip->isMorning()
                && ! $terminal && $absence->isEmpty()),
            // PART A7 (extended) — the MORNING boarding code, and a different
            // value from the handover code above. Never both at once: the two
            // conditions are mutually exclusive on isMorning().
            //
            // Shown only while the child is still to be picked up. The moment
            // they board it has done its job, and a live code left on a screen
            // all day is just a longer window for somebody to read it over a
            // shoulder. 'pending' is the test rather than !terminal because
            // 'boarded' is not a terminal status but is exactly when this
            // should disappear.
            'show_boarding_code' => (bool) ($trip && $trip->isMorning()
                && $absence->isEmpty()
                && (! $row || $row->status === 'pending')),
            'trip' => $trip ? [
                'id' => $trip->id,
                'direction' => $trip->direction,
                'bell_tier' => $trip->bell_tier,
                'status' => $trip->status,
                'route' => trim($trip->route?->code . ' · ' . $trip->route?->name, ' ·'),
                'scheduled_start_at' => $trip->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $trip->scheduled_end_at?->toIso8601String(),
                'bell_time' => $trip->bell_time ? substr($trip->bell_time, 0, 5) : null,
                // ⚠ PART K9 — a parent gets a name and a masked number, never
                // the crew's raw phone.
                'driver' => $trip->driver ? [
                    'name' => $trip->driver->name,
                    'phone_masked' => $trip->driver->maskedPhone(),
                ] : null,
                'attendant' => $trip->attendant ? [
                    'name' => $trip->attendant->name,
                    'phone_masked' => $trip->attendant->maskedPhone(),
                ] : null,
                'bus' => $trip->bus ? [
                    'reg_no' => $trip->bus->reg_no,
                    'latitude' => $showMap ? (float) $trip->bus->latitude : null,
                    'longitude' => $showMap ? (float) $trip->bus->longitude : null,
                    'last_ping_at' => $showMap ? $trip->bus->last_ping_at?->toIso8601String() : null,
                ] : null,
            ] : null,
            'stop' => $row?->stop ? [
                'name' => $row->stop->name,
                'latitude' => (float) $row->stop->latitude,
                'longitude' => (float) $row->stop->longitude,
                'scheduled_at' => $this->stopTime($trip, $row->stop->id),
            ] : null,
            'timeline' => $this->timeline($rows),
        ];
    }

    /** This child's own stop time, out of the solved schedule (PART M7). */
    private function stopTime(?SchoolTrip $trip, int $stopId): ?string
    {
        foreach ($trip?->route_schedule ?? [] as $s) {
            if ((int) $s['stop_id'] === $stopId) {
                return Carbon::parse($s['scheduled_at'])->toIso8601String();
            }
        }

        return null;
    }

    /** PART D — today's events in SERVER-time order. Client time is never trusted. */
    private function timeline($rows): array
    {
        $events = [];

        foreach ($rows as $r) {
            $dir = $r->trip->direction;

            if ($r->boarded_at) {
                $events[] = ['at' => $r->boarded_at->toIso8601String(), 'direction' => $dir,
                             'text' => 'Boarded the bus' . ($r->stop ? ' at ' . $r->stop->name : '')];
            }

            if ($r->trip->arrived_at_school_at && $r->status === 'arrived_at_school') {
                $events[] = ['at' => $r->trip->arrived_at_school_at->toIso8601String(),
                             'direction' => $dir, 'text' => 'Reached school'];
            }

            if ($r->alighted_at) {
                $events[] = ['at' => $r->alighted_at->toIso8601String(), 'direction' => $dir,
                             'text' => match ($r->status) {
                                 'alighted_self_release' => 'Got off the bus'
                                     . ($r->stop ? ' at ' . $r->stop->name : ''),
                                 'returned_to_school' => 'Returned to school — nobody at the stop',
                                 default => 'Handed over' . ($r->stop ? ' at ' . $r->stop->name : ''),
                             }];
            }

            if ($r->status === 'not_at_stop') {
                $events[] = ['at' => $r->updated_at->toIso8601String(), 'direction' => $dir,
                             'text' => 'Not at the stop when the bus arrived'];
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    private function statusLabel(?string $status, ?SchoolTrip $trip): string
    {
        if (! $trip) return 'No trip today';

        return match ($status) {
            'boarded' => 'On the bus',
            'arrived_at_school' => 'At school',
            'alighted_to_guardian' => 'Handed over',
            'alighted_self_release' => 'Got off at the stop',
            'returned_to_school' => 'Returned to school',
            'not_at_stop' => 'Was not at the stop',
            'absent' => 'Marked absent',
            default => $trip->status === 'started' ? 'Bus is on the way' : 'Not started yet',
        };
    }
}
