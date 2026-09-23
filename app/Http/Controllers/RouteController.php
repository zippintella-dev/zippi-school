<?php

namespace App\Http\Controllers;

use App\Models\ChildStopAssignment;
use App\Models\Route;
use App\Models\RouteStaffAssignment;
use App\Models\RouteStop;
use App\Models\SchoolAdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PART G — routes carry an AUTHORED, published stop sequence.
 *
 * Sequence is edited here at design time and frozen for trip execution.
 * Nothing in this controller is reachable from the trip pipeline.
 */
class RouteController extends Controller
{
    public function index()
    {
        $school = $this->activeSchool();

        $routes = Route::with(['stops', 'staffAssignments.driver',
                               'staffAssignments.attendant', 'staffAssignments.bus'])
            ->where('school_id', $school->id)->orderBy('code')->get();

        $counts = ChildStopAssignment::whereIn('route_id', $routes->pluck('id'))
            ->where('direction', 'Morning')
            ->selectRaw('route_id, COUNT(DISTINCT child_id) as c')
            ->groupBy('route_id')->pluck('c', 'route_id');

        return $this->view('routes.index', [
            'routes' => $routes,
            'counts' => $counts,
            'tiers'   => $this->tiers(),
        ]);
    }

    public function create()
    {
        return $this->view('routes.create', ['tiers' => $this->tiers()]);
    }

    public function store(Request $request)
    {
        $school = $this->activeSchool();

        $data = $request->validate([
            'code'     => ['required', 'string', 'max:16',
                            Rule::unique('routes', 'code')->where('school_id', $school->id)],
            'name'     => ['required', 'string', 'max:120'],
            'bell_tier' => ['nullable', 'string', 'max:32'],
            'afternoon_mirrors_morning' => ['nullable', 'boolean'],
        ]);

        $data['code'] = $this->normalizeCode($data['code']);
        $data['afternoon_mirrors_morning'] = $request->boolean('afternoon_mirrors_morning', true);
        $data['school_id'] = $school->id;
        $data['status'] = 'active';

        $route = Route::create($data);

        SchoolAdminAuditLog::record('route_created', [
            'school_id' => $school->id, 'payload' => $data,
        ]);

        return redirect()->route('routes.show', $route)
            ->with('ok', $route->code . ' created. Add its stops in order below.');
    }

    public function show(Route $route)
    {
        $this->authorizeRoute($route);

        $route->load(['stops', 'staffAssignments.driver',
                      'staffAssignments.attendant', 'staffAssignments.bus']);

        $school = $this->activeSchool();

        // ⚠ WHO, not just how many. The stop sequence has carried a rider COUNT
        // since it was written, and a number is exactly as far as it went: no
        // way to see which children stand at that kerb, and no way to move one.
        // Assignment existed only from the child's own record, which is the
        // wrong way round when the question is "who is on RT-03?" — the
        // question a transport office asks when a route fills up, a bus is
        // swapped, or a stop is moved.
        $riders = ChildStopAssignment::with('child')
            ->where('route_id', $route->id)
            ->where('direction', 'Morning')
            ->get()
            ->filter(fn ($a) => $a->child && $a->child->status === 'active')
            ->groupBy('stop_id');

        // The afternoon leg, only where it differs — a child dropped at a
        // different kerb from the one they board at is the case somebody needs
        // to see, and the common case is silence.
        $afternoon = ChildStopAssignment::where('route_id', $route->id)
            ->where('direction', 'Afternoon')
            ->pluck('stop_id', 'child_id');

        return $this->view('routes.show', [
            'route'      => $route,
            'riders'     => $riders,
            'afternoon'  => $afternoon,
            'stopNames'  => $route->stops->pluck('name', 'id'),
            // Every active child at this school, for the assign control. A child
            // already on another route is INCLUDED deliberately: moving one is
            // the operation, and hiding them would make it impossible.
            'assignable' => $school->children()
                              ->where('status', 'active')
                              ->orderBy('name')->get(['id', 'name', 'grade', 'admission_no']),
            'stopCounts' => ChildStopAssignment::where('route_id', $route->id)
                              ->where('direction', 'Morning')
                              ->selectRaw('stop_id, COUNT(*) as c')
                              ->groupBy('stop_id')->pluck('c', 'stop_id'),
            'today'      => $this->today(),
            'tiers'       => $this->tiers(),
            'drivers'    => $school->drivers()->orderBy('name')->get(),
            'attendants' => $school->attendants()->orderBy('name')->get(),
            'buses'      => $school->buses()->orderBy('reg_no')->get(),
        ]);
    }

    public function update(Request $request, Route $route)
    {
        $this->authorizeRoute($route);

        $data = $request->validate([
            'code'     => ['required', 'string', 'max:16',
                            Rule::unique('routes', 'code')
                                ->where('school_id', $route->school_id)->ignore($route->id)],
            'name'     => ['required', 'string', 'max:120'],
            'bell_tier' => ['nullable', 'string', 'max:32'],
            'afternoon_mirrors_morning' => ['nullable', 'boolean'],
        ]);

        $data['code'] = $this->normalizeCode($data['code']);
        $data['afternoon_mirrors_morning'] = $request->boolean('afternoon_mirrors_morning');

        $route->update($data);

        return back()->with('ok', 'Route updated.');
    }

    public function destroy(Route $route)
    {
        $this->authorizeRoute($route);

        $riders = ChildStopAssignment::where('route_id', $route->id)->distinct('child_id')->count('child_id');

        if ($riders > 0) {
            return back()->with('error',
                "{$route->code} still has {$riders} student(s) assigned. Reassign them before deleting the route.");
        }

        SchoolAdminAuditLog::record('route_deleted', [
            'school_id' => $route->school_id,
            'payload'   => $route->only(['code', 'name', 'bell_tier']),
        ], 'Route deleted with no assigned students.');

        $route->delete();

        return redirect()->route('routes.index')->with('ok', 'Route deleted.');
    }

    /* ---------------- stops ---------------- */

    public function storeStop(Request $request, Route $route)
    {
        $this->authorizeRoute($route);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'landmark'  => ['nullable', 'string', 'max:160'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $data['route_id']  = $route->id;
        $data['direction'] = 'Morning';
        $data['sequence']  = (int) $route->stops()->where('direction', 'Morning')->max('sequence') + 1;

        RouteStop::create($data);

        return back()->with('ok', "Added {$data['name']} as stop {$data['sequence']}.");
    }

    public function updateStop(Request $request, RouteStop $stop)
    {
        $this->authorizeRoute($stop->route);

        $stop->update($request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'landmark'  => ['nullable', 'string', 'max:160'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]));

        return back()->with('ok', 'Stop updated.');
    }

    public function destroyStop(RouteStop $stop)
    {
        $route = $stop->route;
        $this->authorizeRoute($route);

        $riders = ChildStopAssignment::where('stop_id', $stop->id)->count();
        if ($riders > 0) {
            return back()->with('error',
                "{$stop->name} still has {$riders} student(s) assigned. Move them to another stop first.");
        }

        DB::transaction(function () use ($stop, $route) {
            $seq = $stop->sequence;
            $stop->delete();
            // Close the gap so the sequence stays contiguous.
            RouteStop::where('route_id', $route->id)->where('direction', 'Morning')
                ->where('sequence', '>', $seq)->decrement('sequence');
        });

        return back()->with('ok', 'Stop removed and sequence closed up.');
    }

    /**
     * PART G3 — re-sequencing is a deliberate act. In production this requires
     * an effective-from date, creates a new route version, and notifies affected
     * guardians with old and new times. Here it swaps two adjacent stops; the
     * versioning wrapper lands with the trip generator.
     */
    public function moveStop(Request $request, RouteStop $stop)
    {
        $route = $stop->route;
        $this->authorizeRoute($route);

        $dir = $request->input('direction') === 'up' ? -1 : 1;
        $target = $stop->sequence + $dir;

        $swap = RouteStop::where('route_id', $route->id)
            ->where('direction', 'Morning')->where('sequence', $target)->first();

        if (! $swap) {
            return back()->with('error', 'That stop is already at the end of the route.');
        }

        DB::transaction(function () use ($stop, $swap) {
            $a = $stop->sequence; $b = $swap->sequence;
            // Park one out of range first — sequence has no unique index today,
            // but keeping the swap collision-free costs nothing and survives one.
            $stop->update(['sequence' => 0]);
            $swap->update(['sequence' => $a]);
            $stop->update(['sequence' => $b]);
        });

        SchoolAdminAuditLog::record('route_stop_resequenced', [
            'school_id' => $route->school_id,
            'payload'   => ['route' => $route->code, 'stop' => $stop->name,
                            'from' => $stop->sequence, 'to' => $target],
        ], 'Design-time re-sequence.');

        return back()->with('ok', 'Stop order changed. Parents on this route would be notified in production (PART G3).');
    }

    /* ---------------- crew ---------------- */

    public function saveCrew(Request $request, Route $route)
    {
        $this->authorizeRoute($route);

        $request->validate([
            'morning_driver_id'      => ['nullable', 'exists:school_staff,id'],
            'morning_attendant_id'   => ['nullable', 'exists:school_staff,id'],
            'morning_bus_id'         => ['nullable', 'exists:buses,id'],
            'afternoon_driver_id'    => ['nullable', 'exists:school_staff,id'],
            'afternoon_attendant_id' => ['nullable', 'exists:school_staff,id'],
            'afternoon_bus_id'       => ['nullable', 'exists:buses,id'],
        ]);

        foreach (['Morning' => 'morning', 'Afternoon' => 'afternoon'] as $dir => $prefix) {
            RouteStaffAssignment::updateOrCreate(
                ['route_id' => $route->id, 'direction' => $dir],
                [
                    'bell_tier'     => $route->bell_tier,
                    'driver_id'    => $request->input("{$prefix}_driver_id") ?: null,
                    'attendant_id' => $request->input("{$prefix}_attendant_id") ?: null,
                    'bus_id'       => $request->input("{$prefix}_bus_id") ?: null,
                    'effective_from' => $this->today(),
                ]
            );
        }

        SchoolAdminAuditLog::record('route_crew_updated', [
            'school_id' => $route->school_id,
            'payload'   => ['route' => $route->code] + $request->only([
                'morning_driver_id','morning_attendant_id','morning_bus_id',
                'afternoon_driver_id','afternoon_attendant_id','afternoon_bus_id']),
        ]);

        return back()->with('ok', 'Default crew saved for ' . $route->code . '.');
    }

    /* ---------------- helpers ---------------- */

    private function authorizeRoute(Route $route): void
    {
        abort_unless($route->school_id === $this->activeSchool()->id, 403);
    }

    /**
     * Bell tiers in bell order. Grouped rather than DISTINCT because MySQL
     * rejects `SELECT DISTINCT bell_tier ... ORDER BY start_time` with error
     * 3065 -- see the full explanation on ChildController::tiers(), which must
     * stay in agreement with this.
     */
    private function tiers(): array
    {
        return $this->activeSchool()->bellTimes()
            ->select('bell_tier')->groupBy('bell_tier')
            ->orderByRaw('MIN(start_time)')->pluck('bell_tier')->all();
    }

    /** PART O6 — fixed RT- prefix, zero-padded. Typing "3" yields "RT-03". */
    private function normalizeCode(string $code): string
    {
        $digits = preg_replace('/\D/', '', $code);
        return $digits !== '' ? 'RT-' . str_pad($digits, 2, '0', STR_PAD_LEFT) : strtoupper($code);
    }
}
