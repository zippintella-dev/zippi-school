<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolChildHandover;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use Illuminate\Http\Request;

/** PART D — trips list, trip detail, and the child journey record. */
class TripController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->activeSchool();

        $trips = SchoolTrip::with(['route', 'bus', 'driver', 'attendant', 'tripChildren'])
            ->where('school_id', $school->id)
            ->when($request->filled('from'), fn ($q) => $q->where('service_date', '>=', $request->from))
            ->when($request->filled('to'),   fn ($q) => $q->where('service_date', '<=', $request->to))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->direction))
            ->when($request->filled('status'),    fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('route_id'),  fn ($q) => $q->where('route_id', $request->route_id))
            ->orderByDesc('service_date')
            ->orderBy('scheduled_start_at')
            ->paginate(25)
            ->withQueryString();

        return $this->view('trips.index', [
            'trips'  => $trips,
            'routes' => $school->routes()->orderBy('code')->get(),
            'filters'=> $request->only(['from','to','direction','status','route_id']),
        ]);
    }

    public function show(SchoolTrip $trip)
    {
        abort_unless($trip->school_id === $this->activeSchool()->id, 403);

        $trip->load([
            'route', 'bus', 'driver', 'attendant',
            'stopArrivals.stop',
            'tripChildren.child', 'tripChildren.stop',
            'handovers.child', 'handovers.receiver', 'handovers.staff',
            'events',
        ]);

        // Group children under their stop so the page mirrors the attendant's
        // mental model — stop first, then children (PART A8).
        $byStop = $trip->tripChildren->groupBy('stop_id');

        $audit = SchoolAdminAuditLog::where('trip_id', $trip->id)
            ->orderBy('created_at')->get();

        return $this->view('trips.show', [
            'trip'   => $trip,
            'byStop' => $byStop,
            'audit'  => $audit,
            'unaccounted' => $trip->unaccountedChildIds(),
        ]);
    }

    /**
     * PART D2 — the child journey record. This is the killer feature: for any
     * child on any day, every event with timestamp, coordinates and the identity
     * of whoever acted.
     */
    public function journey(Request $request, Child $child)
    {
        abort_unless($child->school_id === $this->activeSchool()->id, 403);

        $date = $request->get('date', $this->today());

        $rows = SchoolTripChild::with(['trip.route', 'trip.driver', 'trip.attendant', 'stop'])
            ->where('child_id', $child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $date))
            ->get()
            ->sortBy(fn ($r) => $r->trip->direction === 'Morning' ? 0 : 1);

        $handovers = SchoolChildHandover::with(['receiver', 'staff', 'stop', 'trip'])
            ->where('child_id', $child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', $date))
            ->get()
            ->keyBy('trip_id');

        $absences = SchoolChildAbsence::where('child_id', $child->id)
            ->where('service_date', $date)->get()->keyBy('direction');

        // The last 30 school days, for the date strip.
        $recent = SchoolTripChild::with('trip')
            ->where('child_id', $child->id)
            ->get()
            ->groupBy(fn ($r) => $r->trip->service_date)
            ->sortKeysDesc()
            ->take(30);

        $child->load(['guardians', 'authorizedReceivers', 'stopAssignments.stop',
                      'stopAssignments.route', 'school']);

        return $this->view('trips.journey', [
            'child'     => $child,
            'date'      => $date,
            'dayLabel'  => $this->calendar()->describe($date),
            'rows'      => $rows,
            'handovers' => $handovers,
            'absences'  => $absences,
            'recent'    => $recent,
        ]);
    }
}
