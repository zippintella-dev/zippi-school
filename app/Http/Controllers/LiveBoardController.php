<?php

namespace App\Http\Controllers;

use App\Models\Bus;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolIncident;
use App\Models\SchoolTrip;
use App\Models\SchoolTripEvent;
use Illuminate\Http\Request;

/**
 * PART B — the live board, and PART T4 — the exception queue that fronts it.
 *
 * Design rule from the spec: the QUEUE is the primary UI, not the map. An ops
 * person responsible for 40 buses cannot watch a map; they work a list.
 */
class LiveBoardController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->activeSchool();
        $today  = $this->today();

        $direction = $request->get('direction', 'All');
        $tier       = $request->get('tier', 'All');

        $trips = SchoolTrip::with(['route.stops', 'bus', 'driver', 'attendant',
                                   'tripChildren', 'stopArrivals.stop'])
            ->where('school_id', $school->id)
            ->where('service_date', $today)
            ->when($direction !== 'All', fn ($q) => $q->where('direction', $direction))
            ->when($tier !== 'All', fn ($q) => $q->where('bell_tier', $tier))
            ->orderByRaw("CASE status WHEN 'started' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END")
            ->orderBy('scheduled_start_at')
            ->get();

        $alerts = SchoolTripEvent::with('trip.route')
            ->where('school_id', $school->id)
            ->whereNull('resolved_at')
            ->get()
            ->sortBy([
                fn ($a, $b) => (SchoolTripEvent::SEVERITY_ORDER[$a->severity] ?? 9)
                            <=> (SchoolTripEvent::SEVERITY_ORDER[$b->severity] ?? 9),
                fn ($a, $b) => $b->ageMinutes() <=> $a->ageMinutes(),
            ])
            ->values();

        $tiers = SchoolTrip::where('school_id', $school->id)
            ->where('service_date', $today)
            ->whereNotNull('bell_tier')
            ->distinct()->pluck('bell_tier')->sort()->values();

        return $this->view('live.index', [
            'today'     => $today,
            'dayLabel'  => $this->calendar()->describe($today),
            'trips'     => $trips,
            'alerts'    => $alerts,
            'tiers'      => $tiers,
            'direction' => $direction,
            'tier'       => $tier,
            'incidents' => SchoolIncident::where('school_id', $school->id)
                            ->whereNull('closed_at')->get(),
        ]);
    }

    /** PART B3 — the 8-second diff poll behind the board. */
    public function data()
    {
        $school = $this->activeSchool();
        $today  = $this->today();

        $trips = SchoolTrip::with(['route', 'bus', 'driver', 'attendant', 'tripChildren'])
            ->where('school_id', $school->id)
            ->where('service_date', $today)
            ->get()
            ->map(function (SchoolTrip $t) {
                $rows = $t->tripChildren;

                return [
                    'trip_id'   => $t->id,
                    'route'     => ['code' => $t->route?->code, 'name' => $t->route?->name],
                    'direction' => $t->direction,
                    'bell_tier'  => $t->bell_tier,
                    'status'    => $t->status,
                    // PART K9 / B4 — guardian and staff numbers are MASKED here.
                    'driver'    => $t->driver ? [
                        'name' => $t->driver->name, 'phone_masked' => $t->driver->maskedPhone(),
                    ] : null,
                    'attendant' => $t->attendant ? [
                        'name' => $t->attendant->name, 'phone_masked' => $t->attendant->maskedPhone(),
                    ] : null,
                    'bus' => $t->bus ? [
                        'reg_no' => $t->bus->reg_no, 'is_ev' => $t->bus->is_ev,
                        'latitude' => $t->bus->latitude, 'longitude' => $t->bus->longitude,
                        'speed_kmph' => $t->bus->speed_kmph,
                        'last_ping_at' => $t->bus->last_ping_at?->toIso8601String(),
                        'gps_stale' => $t->bus->isGpsStale(),
                    ] : null,
                    'progress' => [
                        'on_board'  => $rows->where('status', 'boarded')->count(),
                        'expected'  => $rows->whereNotIn('status', ['absent'])->count(),
                        'absent'    => $rows->where('status', 'absent')->count(),
                        'at_school' => $rows->where('status', 'arrived_at_school')->count(),
                    ],
                    'flags' => [
                        'sweep_pending'     => $t->sweepPending(),
                        'unaccounted_child' => $t->status === 'started'
                                               && count($t->unaccountedChildIds()) > 0,
                    ],
                ];
            });

        return response()->json(['service_date' => $today, 'trips' => $trips]);
    }

    /**
     * PART J3 / T4 — a Critical row requires explicit, NAMED acknowledgement.
     * An alert nobody acknowledged is the failure this whole layer prevents.
     */
    public function acknowledge(Request $request, SchoolTripEvent $event)
    {
        abort_unless(auth('web')->user()->isZippi(), 403);

        $event->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => auth('web')->id(),
        ]);

        SchoolAdminAuditLog::record('alert_acknowledged', [
            'school_id' => $event->school_id,
            'trip_id'   => $event->trip_id,
            'payload'   => ['event_type' => $event->event_type, 'severity' => $event->severity],
        ], $request->input('notes'));

        return back()->with('ok', 'Acknowledged by ' . auth('web')->user()->name . '.');
    }
}
