<?php

namespace App\Http\Controllers;

use App\Models\Bus;
use App\Models\RouteStaffAssignment;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolTrip;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusController extends Controller
{
    public function index()
    {
        $school = $this->activeSchool();
        $buses = Bus::where('school_id', $school->id)->orderBy('reg_no')->get();

        return $this->view('buses.index', [
            'buses'   => $buses,
            'evCount' => $buses->where('is_ev', true)->count(),
            'seats'   => $buses->sum('capacity'),
        ]);
    }

    /**
     * One vehicle's record — its everyday crew, its trips, and its documents.
     *
     * ⚠ Crew is shown two ways because they are two different questions. The
     * everyday assignment answers "who is normally on this bus"; the trip list
     * answers "who was actually on it on the 14th". A stand-in set on the Daily
     * roster makes those differ, and a vehicle incident is investigated with the
     * second one — so the trip rows carry their own crew rather than inheriting
     * the route's default.
     */
    public function show(Bus $bus)
    {
        abort_unless($bus->school_id === $this->activeSchool()->id, 403);

        $assignments = RouteStaffAssignment::with(['route', 'driver', 'attendant'])
            ->where('bus_id', $bus->id)
            ->get()
            ->sortBy(fn ($a) => [$a->route?->code, $a->direction]);

        $trips = SchoolTrip::with(['route', 'driver', 'attendant'])
            ->where('bus_id', $bus->id)
            ->orderByDesc('service_date')
            ->orderByDesc('scheduled_start_at')
            ->limit(20)
            ->get();

        return $this->view('buses.show', [
            'bus'         => $bus,
            'assignments' => $assignments,
            'trips'       => $trips,
        ]);
    }

    public function store(Request $request)
    {
        $school = $this->activeSchool();
        $data = $this->rules($request, null, $school->id);

        $bus = Bus::create($data + ['school_id' => $school->id, 'status' => 'active']);

        SchoolAdminAuditLog::record('bus_created', [
            'school_id' => $school->id, 'payload' => ['reg_no' => $bus->reg_no],
        ]);

        return back()->with('ok', $bus->reg_no . ' added to the fleet.');
    }

    public function update(Request $request, Bus $bus)
    {
        abort_unless($bus->school_id === $this->activeSchool()->id, 403);

        $bus->update($this->rules($request, $bus, $bus->school_id));

        return back()->with('ok', $bus->reg_no . ' updated.');
    }

    public function destroy(Bus $bus)
    {
        abort_unless($bus->school_id === $this->activeSchool()->id, 403);

        $trips = SchoolTrip::where('bus_id', $bus->id)->count();
        if ($trips > 0) {
            // Journey records reference this bus; deleting would orphan history.
            $bus->update(['status' => 'retired']);
            return back()->with('ok', $bus->reg_no . ' retired. Trip history is retained.');
        }

        SchoolAdminAuditLog::record('bus_deleted', [
            'school_id' => $bus->school_id, 'payload' => ['reg_no' => $bus->reg_no],
        ]);
        $bus->delete();

        return back()->with('ok', 'Bus removed.');
    }

    private function rules(Request $request, ?Bus $bus, int $schoolId): array
    {
        $data = $request->validate([
            'reg_no'   => ['required', 'string', 'max:24',
                            Rule::unique('buses', 'reg_no')->where('school_id', $schoolId)->ignore($bus?->id)],
            'model'    => ['nullable', 'string', 'max:80'],
            'capacity' => ['required', 'integer', 'between:6,80'],
            'is_ev'    => ['nullable', 'boolean'],
            'has_camera' => ['nullable', 'boolean'],
            'gps_device_id' => ['nullable', 'string', 'max:64'],
            'fitness_expiry'        => ['nullable', 'date'],
            'permit_expiry'         => ['nullable', 'date'],
            'insurance_expiry'      => ['nullable', 'date'],
            'puc_expiry'            => ['nullable', 'date'],
            'speed_governor_expiry' => ['nullable', 'date'],
        ]);

        $data['is_ev']      = $request->boolean('is_ev');
        $data['has_camera'] = $request->boolean('has_camera');

        return $data;
    }
}
