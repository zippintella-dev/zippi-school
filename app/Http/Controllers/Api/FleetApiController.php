<?php

namespace App\Http\Controllers\Api;

use App\Models\SchoolStopArrival;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Services\FleetDenied;
use App\Services\FleetTripService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Zippi Fleet mobile API (Layer 3) — the write side of the platform.
 *
 * ⚠ EVERY trip lookup goes through `FleetTripService::authorizeTrip()`, which
 * resolves against the crew member the TOKEN belongs to. There is no endpoint
 * here that accepts a trip id and trusts it. Getting this wrong hands any
 * signed-in driver the full roster — names, classes, photographs, home stops —
 * of every child on the platform.
 *
 * ⚠ EVERY refusal is a `FleetDenied` whose message the app renders VERBATIM,
 * and which names children rather than counting them (PART P7). The
 * `child_ids` in the body are what let the app offer a button straight to the
 * child instead of making somebody hunt.
 *
 * ⚠ NOTHING HERE DECIDES A SAFETY RULE. The rules live in FleetTripService so
 * that the four invariants have one enforcement point rather than one per
 * endpoint. This class is transport: authorize, validate, delegate, shape.
 */
class FleetApiController extends Controller
{
    public function __construct(private FleetTripService $fleet) {}

    /* ================================================================= */
    /* Read                                                               */
    /* ================================================================= */

    /**
     * GET /api/fleet/me — who this token is, and what they are on today.
     *
     * The token identifies a ROLE (see FleetAuthApiController), so this is the
     * one assignment rather than a list.
     */
    public function me(Request $request): JsonResponse
    {
        $staff = $request->user();
        $school = $staff->school;

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => $staff->role,
                'can_mark_children' => $staff->canMarkChildren(),
            ],
            'school' => [
                'id' => $school?->id,
                'name' => $school?->name,
                'timezone' => $school?->timezone,
                // ⚠ The app draws countdowns from these; the server still
                // decides. A phone showing 120 seconds while the server holds
                // 90 is a cosmetic bug, not a safety one — but they should
                // agree, so they come from one place.
                'stop_wait_seconds' => (int) ($school?->stop_wait_seconds ?: 120),
                'drop_wait_seconds' => (int) ($school?->drop_wait_seconds ?: 180),
                'max_speed_kmph' => (int) ($school?->max_speed_kmph ?: 40),
                'gate_geofence_radius_m' => (int) ($school?->gate_geofence_radius_m ?: 200),
            ],
        ]);
    }

    /**
     * GET /api/fleet/duties — today's board for this crew member.
     *
     * ⚠ Read-only, and the app cannot start anything from it. Beginning a trip
     * needs the checklist and a deliberate swipe; a start control on a list of
     * four trips is a control that starts the wrong one, and starting the wrong
     * one tells 22 families their bus has left when it has not.
     */
    public function duties(Request $request): JsonResponse
    {
        $staff = $request->user();
        $tz = $staff->school?->timezone ?: config('app.timezone');

        // ⚠ PART L1 — a raw Y-m-d string, compared as a string. Never a Carbon
        // instance and never a 'date' cast: that shifts every IST date back one
        // calendar day and the crew's board comes back empty.
        $date = $request->query('date') ?: Carbon::now($tz)->toDateString();

        $trips = SchoolTrip::with(['route', 'bus', 'driver', 'attendant', 'school'])
            ->where('service_date', $date)
            ->where(fn ($q) => $q
                ->where('driver_id', $staff->id)
                ->orWhere('attendant_id', $staff->id))
            ->orderBy('scheduled_start_at')
            ->get();

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'service_date' => $date,
            'trips' => $trips->map(fn ($t) => $this->tripCard($t, $tz))->values(),
        ]);
    }

    /**
     * GET /api/fleet/trips/{trip} — the whole trip: stops in authored order,
     * children, and each child's verification options.
     */
    public function show(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $trip->load([
            'route', 'bus', 'school', 'driver', 'attendant', 'startedBy',
            'stopArrivals',
            // ⚠ EXPLICITLY ORDERED, and not for tidiness. The app derives each
            // child's avatar tint from their POSITION in this list so that two
            // siblings at one stop never render the same colour — the Mehta
            // case screen 6 exists for. An unordered result set would let the
            // database hand back a different order between two polls, and the
            // colours would shuffle under the attendant's thumb mid-boarding.
            'tripChildren' => fn ($q) => $q
                ->orderBy('sequence')
                ->orderBy('stop_id')
                ->orderBy('id'),
            'tripChildren.child.guardians',
            'tripChildren.child.authorizedReceivers',
            'tripChildren.stop',
        ]);

        $tz = $trip->school?->timezone ?: config('app.timezone');

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'trip' => $this->tripCard($trip, $tz) + [
                'pretrip_checklist' => $trip->pretrip_checklist ?? [],
                'headcount_reported' => $trip->headcount_reported,
                'headcount_verified' => (bool) $trip->headcount_verified,
                'arrived_at_school_at' => $trip->arrived_at_school_at?->toIso8601String(),
                'roster_locked_at' => $trip->roster_locked_at?->toIso8601String(),
                'sweep_verified_at' => $trip->sweep_verified_at?->toIso8601String(),
                'completed_at' => $trip->completed_at?->toIso8601String(),
                // ⚠ Invariant #4 — the app renders the lock and refuses to
                // queue writes while this is true. The server refuses anyway.
                'sos_locked' => $trip->sosLocked(),
                'stops' => $this->stops($trip),
                'children' => $trip->tripChildren
                    ->map(fn ($row) => $this->childRow($row))
                    ->values(),
            ],
        ]);
    }

    /* ================================================================= */
    /* Write — 4 · checklist and the swipe                                */
    /* ================================================================= */

    public function checklist(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'string', 'max:40'],
            'items.*.label' => ['nullable', 'string', 'max:120'],
        ]);

        $trip = $this->fleet->recordChecklist($trip, $staff, $data['items']);

        return response()->json([
            'status' => true,
            'pretrip_checklist' => $trip->pretrip_checklist ?? [],
        ]);
    }

    public function start(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $trip = $this->fleet->start(
            $trip, $staff, $data['latitude'] ?? null, $data['longitude'] ?? null,
        );

        return $this->show($request, $trip);
    }

    /* ================================================================= */
    /* Write — 5 · stops                                                  */
    /* ================================================================= */

    public function reachStop(Request $request, SchoolTrip $trip, int $stop): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->fleet->reachStop($trip, $stop, $data['latitude'] ?? null, $data['longitude'] ?? null);

        return $this->show($request, $trip);
    }

    public function departStop(Request $request, SchoolTrip $trip, int $stop): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $this->fleet->departStop($trip, $stop);

        return $this->show($request, $trip);
    }

    /* ================================================================= */
    /* Write — 6 · marking children                                       */
    /* ================================================================= */

    public function board(Request $request, SchoolTrip $trip, int $child): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'client_reported_at' => ['nullable', 'date'],
            'offline' => ['nullable', 'boolean'],
        ]);

        $trip->isMorning()
            ? $this->fleet->board(
                $trip, $child, $staff,
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['client_reported_at'] ?? null,
                (bool) ($data['offline'] ?? false),
            )
            : $this->fleet->boardAtSchool($trip, $child, $staff);

        return $this->show($request, $trip);
    }

    public function undoBoard(Request $request, SchoolTrip $trip, int $child): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $this->fleet->undoBoard($trip, $child, $staff);

        return $this->show($request, $trip);
    }

    public function notAtStop(Request $request, SchoolTrip $trip, int $child): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $this->fleet->notAtStop($trip, $child, $staff);

        return $this->show($request, $trip);
    }

    /* ================================================================= */
    /* Write — 7 · head count, 8 · arrival, 9 · lock-in                    */
    /* ================================================================= */

    public function headCount(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'counted' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $trip = $this->fleet->headCount($trip, $data['counted'], $staff);

        return response()->json([
            'status' => true,
            'matched' => (bool) $trip->headcount_verified,
            'counted' => (int) $trip->headcount_reported,
            'marked' => $trip->tripChildren()->where('status', 'boarded')->count(),
            'message' => $trip->headcount_verified
                ? 'Counts match. The trip can go on to school.'
                : 'Counts do not match. Check each face against the bus before going on.',
        ]);
    }

    public function disembark(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $this->fleet->disembark($trip, $staff);

        return $this->show($request, $trip);
    }

    public function lockIn(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $result = $this->fleet->lockInRoster($trip, $staff);

        return response()->json([
            'status' => true,
            'marked_absent' => $result['marked_absent'],
            'message' => $result['marked_absent'] === 0
                ? 'Roster locked. Every child is accounted for.'
                : 'Roster locked. ' . $result['marked_absent']
                  . ' marked absent, school office notified.',
        ]);
    }

    /* ================================================================= */
    /* Write — 10 · handover, 11 · escalation                             */
    /* ================================================================= */

    public function handover(Request $request, SchoolTrip $trip, int $child): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'method' => ['required', 'in:handover_code,authorized_person,self_release'],
            // ⚠ Never logged and never echoed back. It is a live release code.
            'code' => ['nullable', 'string', 'max:8'],
            'authorized_receiver_id' => ['nullable', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'offline' => ['nullable', 'boolean'],
        ]);

        $this->fleet->handover(
            $trip, $child, $staff,
            $data['method'],
            $data['code'] ?? null,
            $data['authorized_receiver_id'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            (bool) ($data['offline'] ?? false),
        );

        return $this->show($request, $trip);
    }

    public function escalate(Request $request, SchoolTrip $trip, int $child): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $row = $this->fleet->escalate($trip, $child, $staff);

        $window = (int) ($trip->school?->drop_wait_seconds ?: 180);

        return response()->json([
            'status' => true,
            'escalation_started_at' => $row->escalation_started_at?->toIso8601String(),
            'window_seconds' => $window,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function returnToSchool(Request $request, SchoolTrip $trip, int $child): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $this->fleet->returnToSchool($trip, $child, $staff);

        return $this->show($request, $trip);
    }

    /* ================================================================= */
    /* Write — 12 · sweep, 13 · complete                                  */
    /* ================================================================= */

    public function sweep(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:6144'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // ⚠ Stored on the private disk. A sweep photo is the inside of a bus
        // that carries named children; it must not be reachable by URL guess.
        $path = $request->file('photo')->store("sweeps/{$trip->service_date}", 'local');

        $trip = $this->fleet->sweep(
            $trip, $staff, $path,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
        );

        return response()->json([
            'status' => true,
            'sweep_verified_at' => $trip->sweep_verified_at?->toIso8601String(),
            'message' => 'Bus swept. Photo, place and time are on the trip record.',
        ]);
    }

    public function complete(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $this->fleet->complete($trip, $staff);

        return $this->show($request, $trip);
    }

    /* ================================================================= */
    /* Write — 15 · SOS, and the position ping                            */
    /* ================================================================= */

    public function sos(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'type' => ['required', 'in:accident,breakdown,medical,security,fire,other'],
            'drill' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $incident = $this->fleet->raiseSos(
            $trip, $staff, $data['type'],
            (bool) ($data['drill'] ?? false),
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
        );

        return response()->json([
            'status' => true,
            'incident_id' => $incident->id,
            'is_drill' => (bool) $incident->is_drill,
            'fired_at' => $incident->created_at->toIso8601String(),
            'message' => $incident->is_drill
                ? 'Drill logged. No help is dispatched. Child records are locked.'
                : 'Control room and school alerted. Child records are locked '
                  . 'until ops resolve this.',
        ]);
    }

    /**
     * POST /api/fleet/trips/{trip}/ping — the vehicle's position.
     *
     * ⚠ THIS IS THE LINK TO THE PARENT APP'S LIVE MAP. It writes
     * `buses.latitude/longitude/last_ping_at`, which is the single position of
     * record; FamilyCardBuilder then decides, per family, whether that position
     * may leave the server at all (enterprise L29). The crew's device never
     * decides who can see the bus.
     *
     * ⚠ Deliberately NOT throttled. Enterprise BF3: never throttle something a
     * device must repeat under pressure. A dropped ping is a bus that appears
     * to have stopped moving on 22 parents' screens.
     */
    public function ping(Request $request, SchoolTrip $trip): JsonResponse
    {
        $staff = $request->user();
        $this->fleet->authorizeTrip($trip, $staff);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed_kmph' => ['nullable', 'integer', 'min:0', 'max:200'],
        ]);

        $this->fleet->ping(
            $trip, (float) $data['latitude'], (float) $data['longitude'],
            isset($data['speed_kmph']) ? (int) $data['speed_kmph'] : null,
        );

        return response()->json(['status' => true]);
    }

    /* ================================================================= */
    /* Payload shaping                                                    */
    /* ================================================================= */

    private function tripCard(SchoolTrip $trip, string $tz): array
    {
        return [
            'id' => $trip->id,
            'route_code' => $trip->route?->code,
            'route_name' => $trip->route?->name,
            'direction' => $trip->direction,               // Morning | Afternoon
            // ⚠ THE BOUNDARY RENAME. Both specs call this `trip_leg`; the
            // codebase calls it `bell_tier` because "leg" already means the hop
            // between two stops. Both names ship, and the app reads bell_tier.
            'bell_tier' => $trip->bell_tier,
            'trip_leg' => $trip->bell_tier,
            'school_name' => $trip->school?->name,
            'status' => $trip->status,
            'status_label' => $trip->statusLabel(),
            'child_count' => $trip->tripChildren()->count(),
            'stop_count' => count($trip->route_schedule ?? []),
            'scheduled_start_at' => $trip->scheduled_start_at?->toIso8601String(),
            'scheduled_start_label' => $trip->scheduled_start_at
                ?->timezone($tz)->format('g:i A'),
            'bell_time' => $trip->bell_time ? substr($trip->bell_time, 0, 5) : null,
            'started_at' => $trip->started_at?->toIso8601String(),
            'started_at_label' => $trip->started_at?->timezone($tz)->format('g:i A'),
            'started_by' => $trip->startedBy?->name,
            'bus' => $trip->bus ? [
                'id' => $trip->bus->id,
                'reg_no' => $trip->bus->reg_no,
            ] : null,
            // ⚠ PART K9 — the crew see each other's names and MASKED numbers.
            // Calls go through the proxy; a raw number in a payload is a number
            // in a call history forever.
            'driver' => $trip->driver ? [
                'name' => $trip->driver->name,
                'phone_masked' => $trip->driver->maskedPhone(),
            ] : null,
            'attendant' => $trip->attendant ? [
                'name' => $trip->attendant->name,
                'phone_masked' => $trip->attendant->maskedPhone(),
            ] : null,
        ];
    }

    /**
     * Stops in AUTHORED order.
     *
     * ⚠ Straight off `route_schedule`, which the solver wrote in driving order
     * and froze (PART G1/M7). Never re-sorted by distance, never by arrival
     * time — sorting this by anything else puts a bus on a road nobody signed
     * off on.
     */
    private function stops(SchoolTrip $trip): array
    {
        $arrivals = $trip->stopArrivals->keyBy('stop_id');

        $schedule = $trip->route_schedule ?? [];

        // The school is the LAST stop in the morning and the FIRST in the
        // afternoon. Identified by id rather than by comparing whole array
        // values, which breaks the moment the solver adds a key.
        $schoolStopId = $trip->isMorning()
            ? (int) ($schedule[count($schedule) - 1]['stop_id'] ?? 0)
            : (int) ($schedule[0]['stop_id'] ?? 0);

        return collect($schedule)->map(function (array $s) use ($arrivals, $schoolStopId) {
            /** @var SchoolStopArrival|null $a */
            $a = $arrivals->get($s['stop_id']);

            return [
                'stop_id' => (int) $s['stop_id'],
                'sequence' => (int) ($s['seq'] ?? 0),
                'name' => $s['name'] ?? '',
                'scheduled_at' => $s['scheduled_at'] ?? null,
                'latitude' => (float) ($s['lat'] ?? 0),
                'longitude' => (float) ($s['lng'] ?? 0),
                'child_count' => count($s['child_ids'] ?? []),
                'reached_at' => $a?->arrived_at?->toIso8601String(),
                'departed_at' => $a?->departed_at?->toIso8601String(),
                'is_school' => (int) $s['stop_id'] === $schoolStopId,
            ];
        })->values()->all();
    }

    private function childRow(SchoolTripChild $row): array
    {
        $child = $row->child;
        $absence = $this->absenceFor($row);

        return [
            'child_id' => $child->id,
            'name' => $child->name,
            'class' => trim($child->grade . ($child->section ? '-' . $child->section : '')),
            // ⚠ SIBLING DISAMBIGUATION. Two Mehtas, same surname, similar face
            // at 56dp on a bouncing phone. The photo and this line are what
            // stop the adjacent-row mis-tap; an empty detail is a row that can
            // be tapped by mistake.
            'detail' => $child->distinguishing_detail ?? null,
            'photo_url' => $child->photo_path
                ? url('/storage/' . $child->photo_path)
                : null,
            'stop_id' => $row->stop_id,
            'stop_name' => $row->stop?->name,
            // ⚠ `parent_collecting` is a DISPLAY status, not a stored one. In
            // the database the child is `absent` with an absence row marked
            // `parent_collecting`, which is correct — they are not travelling.
            // But an attendant at the school gate must not read that row as
            // "absent" and wait for someone who is already in a car, so the
            // two are separated here. The app never writes this value back.
            'status' => $absence?->marked_by === 'parent_collecting'
                ? 'parent_collecting'
                : $row->status,
            'status_label' => $absence?->marked_by === 'parent_collecting'
                ? 'Parent collecting'
                : $row->statusLabel(),
            'boarded_at' => $row->boarded_at?->toIso8601String(),
            'alighted_at' => $row->alighted_at?->toIso8601String(),
            'escalation_started_at' => $row->escalation_started_at?->toIso8601String(),
            'note' => $absence?->reason,
            // ⚠ PART E2 — shown to the attendant BEHIND A TAP, never on the
            // roster row. A child's asthma is not something to display to a bus
            // full of classmates, but it is something the attendant must be
            // able to reach in seconds.
            'medical_notes' => $child->medical_notes,
            // ⚠ RESOLVED BY THE SERVER, SHIPPED AS ONE BOOLEAN. The app must
            // never re-derive self-release from a grade number — a local rule
            // that drifts from the school's is a child let off a bus alone.
            // When this is false the app does not render the option AT ALL.
            'may_self_release' => $child->canSelfRelease(),
            'authorized_receivers' => $child->authorizedReceivers
                ->where('is_active', true)
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'relationship' => $r->relationship,
                    'photo_url' => $r->photo_path ? url('/storage/' . $r->photo_path) : null,
                ])->values(),
            // ⚠ NOT the handover code, and never the handover code. The
            // attendant's device posts what was typed; the server compares it
            // against a hash. A code in this payload is a code in a proxy log.
        ];
    }

    /**
     * Today's absence row for this child on this direction, if any.
     *
     * ⚠ `service_date` is compared as a RAW STRING (PART L1). Passing a Carbon
     * instance here shifts every IST date back one calendar day and the note
     * silently disappears from the roster.
     */
    private function absenceFor(SchoolTripChild $row): ?\App\Models\SchoolChildAbsence
    {
        if (! in_array($row->status, ['absent', 'not_at_stop'], true)) {
            return null;
        }

        return \App\Models\SchoolChildAbsence::where('child_id', $row->child_id)
            ->where('service_date', $row->trip->service_date)
            ->where('direction', $row->trip->direction)
            ->first();
    }
}
