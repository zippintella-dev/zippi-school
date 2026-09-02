<?php

namespace App\Http\Controllers;

use App\Models\Bus;
use App\Models\Child;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolIncident;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\SchoolTripEvent;

class DashboardController extends Controller
{
    public function index()
    {
        $school = $this->activeSchool();
        $cal    = $this->calendar();
        $today  = $this->today();

        $trips = SchoolTrip::with(['route', 'bus', 'driver', 'attendant'])
            ->where('school_id', $school->id)
            ->where('service_date', $today)          // raw string compare (PART L1)
            ->orderByRaw("CASE status WHEN 'started' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END")
            ->orderBy('scheduled_start_at')
            ->get();

        $tripIds = $trips->pluck('id');

        $rows = SchoolTripChild::whereIn('trip_id', $tripIds)->get();

        $counts = [
            'expected'  => $rows->whereNotIn('status', ['absent'])->count(),
            'boarded'   => $rows->whereIn('status', ['boarded', 'arrived_at_school',
                                'alighted_to_guardian', 'alighted_self_release'])->count(),
            'on_board'  => $rows->where('status', 'boarded')->count(),
            'at_school' => $rows->where('status', 'arrived_at_school')->count(),
            'absent'    => $rows->where('status', 'absent')->count(),
            'not_at_stop' => $rows->where('status', 'not_at_stop')->count(),
        ];

        // PART J3 — the exception queue. Severity, then age.
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

        $openIncidents = SchoolIncident::with('trip.route')
            ->where('school_id', $school->id)
            ->whereNull('closed_at')
            ->latest()
            ->get();

        // PART D — today's absences with reasons, the attendance signal.
        $absences = SchoolChildAbsence::with('child')
            ->whereIn('child_id', Child::where('school_id', $school->id)->pluck('id'))
            ->where('service_date', $today)
            ->get();

        $fleet = Bus::where('school_id', $school->id)->get();

        return $this->view('dashboard.index', [
            'today'          => $today,
            'dayDescription' => $cal->describe($today),
            'dayType'        => $cal->dayType($today),
            'isSchoolDay'    => $cal->isSchoolDay($today),
            'nextSchoolDay'  => $cal->nextSchoolDay($today),
            'trips'          => $trips,
            'counts'         => $counts,
            'alerts'         => $alerts,
            'openIncidents'  => $openIncidents,
            'absences'       => $absences,
            'fleet'          => $fleet,
            'childTotal'     => Child::where('school_id', $school->id)->where('status', 'active')->count(),
        ]);
    }
}
