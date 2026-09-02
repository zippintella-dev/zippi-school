<?php

namespace App\Services;

use App\Models\RouteStaffAssignment;
use App\Models\RouteStaffOverride;
use Illuminate\Support\Collection;

/**
 * PART O3 — resolves the crew and bus actually operating a route on a date.
 *
 * Two tiers: an everyday DEFAULT (route_staff_assignments) and a one-day
 * STAND-IN (route_staff_overrides) that auto-reverts. Swapping the evening
 * attendant for one Tuesday must not permanently change the route.
 *
 * ⚠ The `?? ` fallback chain lives ONLY here. It was duplicated between the
 * roster screen and the trip generator, which is exactly how the two drift
 * apart and a bus gets dispatched with last week's driver on the manifest.
 * Both callers now go through resolve().
 *
 * Each of driver / attendant / bus overrides INDEPENDENTLY — a stand-in driver
 * on the regular bus with the regular attendant is the common case, so a
 * partially-filled override row must not blank the other two.
 *
 * ⚠ PART L1: $date is a raw Y-m-d string. Never hand a Carbon here.
 */
class RouteCrewResolver
{
    private Collection $defaults;
    private Collection $overrides;

    /**
     * Bulk-preloaded for a set of routes on one date — no N+1 (PART O3).
     *
     * @param  iterable<int>  $routeIds
     */
    public function __construct(iterable $routeIds, private string $date)
    {
        $ids = collect($routeIds)->all();

        $this->overrides = RouteStaffOverride::with(['driver', 'attendant', 'bus'])
            ->where('service_date', $this->date)     // raw string compare (L1)
            ->whereIn('route_id', $ids)
            ->get()
            ->groupBy(fn ($o) => $o->route_id . '|' . $o->direction);

        $this->defaults = RouteStaffAssignment::with(['driver', 'attendant', 'bus'])
            ->whereIn('route_id', $ids)
            ->get()
            ->groupBy(fn ($a) => $a->route_id . '|' . $a->direction);
    }

    /**
     * @return array{driver: ?\App\Models\SchoolStaff, attendant: ?\App\Models\SchoolStaff,
     *               bus: ?\App\Models\Bus, is_override: bool}
     */
    public function resolve(int $routeId, string $direction, ?string $tier = null): array
    {
        // A tier-specific row wins over the route-wide one: a school may staff the
        // 07:40 Senior run differently from the 08:45 Primary run on the same bus.
        $ovr = $this->pick($this->overrides, $routeId, $direction, $tier);
        $def = $this->pick($this->defaults, $routeId, $direction, $tier);

        return [
            'driver' => $ovr?->driver ?? $def?->driver,
            'attendant' => $ovr?->attendant ?? $def?->attendant,
            'bus' => $ovr?->bus ?? $def?->bus,
            'is_override' => (bool) ($ovr?->driver_id || $ovr?->attendant_id || $ovr?->bus_id),
        ];
    }

    /**
     * Three tiers, most specific first:
     *
     *   1. a row tagged with this exact tier — the school staffs this tier differently
     *   2. a row with no tier at all — the route's everyday crew, whatever tier runs
     *   3. the route's sole row for this direction, whatever tier it carries
     *
     * Tier 3 exists because a crew is assigned to a ROUTE, and the tier tag on the
     * row is usually inherited from routes.bell_tier rather than deliberately set.
     * Without it, a route whose children sit in a different tier than its label
     * generates trips with no bus, no driver and no attendant — and a trip with
     * no attendant cannot satisfy Invariant #1. Falling back to the one crew that
     * demonstrably works this route is right; guessing between several is not, so
     * tier 3 applies only when exactly one candidate exists.
     */
    private function pick(Collection $groups, int $routeId, string $direction, ?string $tier)
    {
        $rows = $groups->get($routeId . '|' . $direction);

        if (! $rows || $rows->isEmpty()) {
            return null;
        }

        if ($tier !== null && ($exact = $rows->firstWhere('bell_tier', $tier))) {
            return $exact;
        }

        if ($routeWide = $rows->first(fn ($r) => $r->bell_tier === null)) {
            return $routeWide;
        }

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
