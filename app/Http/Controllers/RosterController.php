<?php

namespace App\Http\Controllers;

use App\Models\ChildStopAssignment;
use App\Models\Route;
use App\Models\RouteStaffAssignment;
use App\Models\RouteStaffOverride;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolChildAbsence;
use App\Services\RouteCrewResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PART O5 — the Daily Roster. Per date: who rides, on which bus, with which
 * driver and attendant, grouped by route. Two tiers of staffing: a default
 * (every day) and a one-day stand-in that auto-reverts.
 */
class RosterController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->activeSchool();
        $date   = $request->get('date', $this->today());
        $cal    = $this->calendar();

        $routes = Route::with(['stops'])->where('school_id', $school->id)
            ->orderBy('code')->get();

        // PART O3 — bulk-preloaded for the date, no N+1. The override ?? default
        // fallback chain lives in the resolver so the trip generator and this
        // screen cannot drift apart.
        $crew = new RouteCrewResolver($routes->pluck('id'), $date);

        // ⚠ ACTIVE CHILDREN ONLY, and this must match SchoolTripGenerator.
        //
        // A retired child keeps their stop assignment — PART K13 retains the
        // journey record, and Restore has to put them back on the same stop.
        // The generator has always filtered them out (`$a->child->status ===
        // 'active'`), but this screen did not, so a school that retired its
        // roster saw 220 riders here while the buses were built for 1.
        //
        // A roster that disagrees with what the bus will actually carry is
        // worse than no roster: it is the sheet somebody counts heads against.
        $assignments = ChildStopAssignment::with(['child', 'stop'])
            ->whereIn('route_id', $routes->pluck('id'))
            ->where('direction', 'Morning')
            ->whereHas('child', fn ($q) => $q->where('status', 'active'))
            ->get();

        // Direction-aware: a morning-only absence blanks only the morning column.
        $absences = SchoolChildAbsence::where('service_date', $date)
            ->whereIn('child_id', $assignments->pluck('child_id'))
            ->get()
            ->groupBy('child_id');

        $groups = $routes->map(function (Route $route) use ($assignments, $absences, $crew, $date) {
            $rows = $assignments->where('route_id', $route->id)
                ->filter(fn ($a) => $a->isEffectiveOn($date))
                ->sortBy([
                    fn ($a, $b) => ($a->stop?->sequence ?? 99) <=> ($b->stop?->sequence ?? 99),
                    fn ($a, $b) => strcmp((string) $a->child?->name, (string) $b->child?->name),
                ])
                ->values();

            $eff = [];
            foreach (['Morning', 'Afternoon'] as $dir) {
                $eff[$dir] = $crew->resolve($route->id, $dir);
            }

            return [
                'route'    => $route,
                'rows'     => $rows,
                'staff'    => $eff,
                'absences' => $absences,
            ];
        });

        return $this->view('roster.index', [
            'date'        => $date,
            'dayLabel'    => $cal->describe($date),
            'isSchoolDay' => $cal->isSchoolDay($date),
            'groups'      => $groups,
            'drivers'     => $school->drivers()->where('status', 'active')->orderBy('name')->get(),
            'attendants'  => $school->attendants()->where('status', 'active')->orderBy('name')->get(),
            'buses'       => $school->buses()->where('status', 'active')->orderBy('reg_no')->get(),
        ]);
    }

    /**
     * PART O5 — one Save per route card. Sets the DEFAULT crew (every day) and
     * sets/clears the day's stand-in override (blank = revert to default).
     *
     * The two tiers are deliberate: swapping the evening attendant for one
     * Tuesday must not permanently change the route.
     */
    public function saveStaff(Request $request)
    {
        $school = $this->activeSchool();

        $request->validate([
            'route_id'     => ['required', 'exists:routes,id'],
            'service_date' => ['required', 'date'],
            'direction'    => ['required', Rule::in(['Morning', 'Afternoon'])],
            'scope'        => ['required', Rule::in(['default', 'override'])],
            'driver_id'    => ['nullable', 'exists:school_staff,id'],
            'attendant_id' => ['nullable', 'exists:school_staff,id'],
            'bus_id'       => ['nullable', 'exists:buses,id'],
        ]);

        $route = Route::where('school_id', $school->id)->findOrFail($request->route_id);
        $date  = Carbon::parse($request->service_date)->toDateString();

        $fields = [
            'driver_id'    => $request->input('driver_id') ?: null,
            'attendant_id' => $request->input('attendant_id') ?: null,
            'bus_id'       => $request->input('bus_id') ?: null,
        ];

        if ($request->scope === 'default') {
            RouteStaffAssignment::updateOrCreate(
                ['route_id' => $route->id, 'direction' => $request->direction],
                $fields + ['bell_tier' => $route->bell_tier, 'effective_from' => $this->today()]
            );
            $msg = "Default {$request->direction} crew saved for {$route->code}.";
        } else {
            // All three blank → the stand-in is cleared and the default applies again.
            if (! array_filter($fields)) {
                RouteStaffOverride::where('service_date', $date)
                    ->where('route_id', $route->id)
                    ->where('direction', $request->direction)
                    ->delete();
                $msg = "Stand-in cleared for {$route->code} — reverts to the default crew.";
            } else {
                RouteStaffOverride::updateOrCreate(
                    ['service_date' => $date, 'route_id' => $route->id,
                     'direction' => $request->direction, 'bell_tier' => $route->bell_tier],
                    $fields
                );
                $msg = "Stand-in set for {$route->code} on "
                     . Carbon::parse($date)->format('j M') . ' only. It reverts the next day.';
            }
        }

        SchoolAdminAuditLog::record('roster_crew_saved', [
            'school_id' => $school->id,
            'payload'   => ['route' => $route->code, 'date' => $date,
                            'direction' => $request->direction,
                            'scope' => $request->scope] + $fields,
        ]);

        return back()->with('ok', $msg);
    }
}
