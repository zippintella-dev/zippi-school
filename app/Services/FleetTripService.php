<?php

namespace App\Services;

use App\Models\Child;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolChildHandover;
use App\Models\SchoolIncident;
use App\Models\SchoolStaff;
use App\Models\SchoolStopArrival;
use App\Models\SchoolTrip;
use App\Models\SchoolTripChild;
use App\Models\SchoolTripEvent;
use Illuminate\Support\Facades\DB;

/**
 * A refusal the crew is meant to read.
 *
 * ⚠ PART P7 — `getMessage()` is rendered VERBATIM by the Fleet app. It must say
 * WHY and WHAT NEXT, and it must name children rather than count them:
 * "Cannot complete: Aarav Mehta is still on board" is a person somebody goes and
 * finds; "1 child unaccounted" is a number somebody dismisses.
 */
class FleetDenied extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $children = [],
    ) {
        parent::__construct($message);
    }
}

/**
 * Zippi Fleet (Layer 3) — the write side of the platform, and the authority for
 * the four safety invariants.
 *
 * ⚠ THIS CLASS IS THE GATE, NOT THE APP. The Flutter app enforces the same
 * rules locally so a crew member is told BEFORE they tap rather than after —
 * but a phone can be old, offline, rooted, or running a build from March. Every
 * rule below is re-decided here, from the database, on every write. If the two
 * ever disagree, this one is right.
 *
 * ⚠ AND IT IS THE SAME DATABASE THE OTHER TWO LAYERS READ. There is no sync
 * step, no message queue and no second copy of a child's state:
 *
 *   · Layer 2 (the dashboard / Control Tower) reads `school_trips`,
 *     `school_trip_children` and `school_trip_events` directly, so a boarding
 *     marked here is on the live board on its next poll.
 *   · Layer 1 (Zippi Parent) reads the same rows through FamilyCardBuilder, so
 *     the same boarding reaches the family within their 10-second poll.
 *
 * That is deliberate and it is why the linking work was small. Anything that
 * introduces a separate fleet-side store of child state re-opens the question
 * "which copy is right about where this child is", and there is no acceptable
 * answer to it.
 */
class FleetTripService
{
    /* ================================================================= */
    /* Guards                                                            */
    /* ================================================================= */

    /**
     * Is this crew member actually on this trip today?
     *
     * ⚠ THE AUTHORIZATION GATE, and the counterpart of the Parent API's
     * `myChild()`. Every endpoint resolves the trip through here rather than
     * trusting an id in a URL. Without it, any signed-in crew member could read
     * the full roster — names, classes, photographs, stops — of every child in
     * every school on the platform.
     *
     * 404, not 403, so the response cannot be used to probe which trips exist.
     */
    public function authorizeTrip(SchoolTrip $trip, SchoolStaff $staff): SchoolTrip
    {
        $mine = $trip->driver_id === $staff->id || $trip->attendant_id === $staff->id;

        if (! $mine || $trip->school_id !== $staff->school_id) {
            throw new FleetDenied('That trip is not on your board today.', 404);
        }

        return $trip;
    }

    /**
     * ⚠ THE ROLE SPLIT. A driver marking children is a driver looking at a
     * phone while children board around a vehicle. The Fleet app renders no
     * such control on a driver device; this is why that is a guarantee and not
     * a styling decision.
     */
    public function assertMayMarkChildren(SchoolStaff $staff): void
    {
        if (! $staff->canMarkChildren()) {
            throw new FleetDenied(
                'Driver devices cannot mark children. Hand this to the attendant.',
                403,
            );
        }
    }

    /**
     * ⚠ INVARIANT #4's LOCK. While an SOS is open on this trip, no child state
     * change is accepted — not boarding, not handover, not "not at stop".
     *
     * The minutes after an emergency are exactly when a frightened person taps
     * at a phone, and a panicked mis-tap sequence in a custody record is worse
     * than a gap in one. Only ops can close the incident; there is deliberately
     * no crew-side release.
     */
    public function assertNotLockedBySos(SchoolTrip $trip): void
    {
        $open = SchoolIncident::where('trip_id', $trip->id)
            ->where('incident_type', 'like', 'sos_%')
            ->whereNull('closed_at')
            ->first();

        if ($open) {
            throw new FleetDenied(
                'Child records are locked while the SOS is open. '
                . 'Ops release the lock once they have resolved it.',
                423,
            );
        }
    }

    private function assertRunning(SchoolTrip $trip): void
    {
        if ($trip->status !== 'started') {
            throw new FleetDenied(
                $trip->status === 'completed'
                    ? 'This trip is already complete.'
                    : 'This trip has not been started yet.',
            );
        }
    }

    /* ================================================================= */
    /* 4 · pre-trip checklist and the swipe                              */
    /* ================================================================= */

    /**
     * PART L3 — each tick timestamped into the trip record.
     *
     * Idempotent by key: re-sending a tick keeps the FIRST server time. A crew
     * member re-syncing after a dead zone must not overwrite the moment they
     * actually checked the fire extinguisher with the moment their signal came
     * back.
     */
    public function recordChecklist(SchoolTrip $trip, SchoolStaff $staff, array $items): SchoolTrip
    {
        $existing = collect($trip->pretrip_checklist ?? [])->keyBy('key');

        $merged = collect($items)->map(function (array $item) use ($existing) {
            $prior = $existing->get($item['key']);

            return [
                'key' => $item['key'],
                'label' => $item['label'] ?? $prior['label'] ?? $item['key'],
                'ticked_at' => $prior['ticked_at'] ?? now()->toIso8601String(),
            ];
        })->values()->all();

        $trip->forceFill(['pretrip_checklist' => $merged])->save();

        return $trip->refresh();
    }

    /** How many checks this school requires before a bus may move. */
    public const REQUIRED_CHECKS = 4;

    /**
     * ⚠ Begin Trip pushes "the bus has left" to every family on the roster.
     * Three things gate it: the checklist, the trip not being complete, and —
     * the one that matters operationally — it not already running.
     */
    public function start(
        SchoolTrip $trip,
        SchoolStaff $staff,
        ?float $lat = null,
        ?float $lng = null,
    ): SchoolTrip {
        if ($trip->status === 'completed' || $trip->status === 'cancelled') {
            throw new FleetDenied('This trip is ' . $trip->statusLabel() . '.');
        }

        // ⚠ ONE TRIP PER BUS MAY BE STARTED AT A TIME, and only once. The trip
        // is shared state — either device may start it — so this is the point
        // where the second device is told to join rather than fork the journey
        // record and send a second "the bus has left".
        if ($trip->status === 'started') {
            $who = $trip->startedBy?->name ?? 'another device';

            throw new FleetDenied(
                "This trip is already running — {$who} started it at "
                . $trip->started_at?->timezone($this->tz($trip))->format('g:i A')
                . '. Open it to join, or call ops if that looks wrong.',
                409,
            );
        }

        $ticked = count($trip->pretrip_checklist ?? []);

        if ($ticked < self::REQUIRED_CHECKS) {
            throw new FleetDenied(
                'Finish the pre-trip checklist before the bus moves — '
                . "{$ticked} of " . self::REQUIRED_CHECKS . ' ticked.',
            );
        }

        $trip->forceFill([
            'status' => 'started',
            'started_at' => now(),
            'started_by_staff_id' => $staff->id,
            'started_lat' => $lat,
            'started_lng' => $lng,
        ])->save();

        return $trip->refresh();
    }

    /* ================================================================= */
    /* 5 · stops                                                          */
    /* ================================================================= */

    public function reachStop(SchoolTrip $trip, int $stopId, ?float $lat, ?float $lng): SchoolStopArrival
    {
        $this->assertRunning($trip);

        $arrival = SchoolStopArrival::firstOrNew([
            'trip_id' => $trip->id,
            'stop_id' => $stopId,
        ]);

        // ⚠ The FIRST arrival time stands. The stop wait counts from when the
        // bus actually got there, so a re-tap must not restart a child's
        // 120 seconds — that is how a bus leaves before someone's window ran.
        if (! $arrival->arrived_at) {
            $arrival->fill([
                'sequence' => $arrival->sequence ?: $this->sequenceOf($trip, $stopId),
                'arrived_at' => now(),
                'latitude' => $lat,
                'longitude' => $lng,
            ])->save();
        }

        return $arrival->refresh();
    }

    /**
     * ⚠ INVARIANT #1 AT A KERB, and the two directions fail differently.
     *
     *   · Morning — a child still `pending` has not boarded and has not been
     *     marked not-at-stop. Driving off leaves them at a kerb with nobody
     *     told.
     *   · Afternoon — a child still `boarded` has not been handed to anyone.
     *     Driving off carries them past their own stop and the handover that
     *     should have happened there never will.
     */
    public function departStop(SchoolTrip $trip, int $stopId): SchoolStopArrival
    {
        $this->assertRunning($trip);

        $blocking = $trip->isMorning() ? 'pending' : 'boarded';

        $open = SchoolTripChild::with('child')
            ->where('trip_id', $trip->id)
            ->where('stop_id', $stopId)
            ->where('status', $blocking)
            ->get();

        if ($open->isNotEmpty()) {
            $names = $this->nameList($open->pluck('child.name')->all());
            $verb = $open->count() === 1 ? 'has' : 'have';
            $what = $trip->isMorning() ? 'boarded' : 'been handed over';

            throw new FleetDenied(
                "Cannot depart: {$names} {$verb} not {$what}.",
                422,
                $open->pluck('child_id')->all(),
            );
        }

        $arrival = SchoolStopArrival::firstOrNew([
            'trip_id' => $trip->id,
            'stop_id' => $stopId,
        ]);

        $arrival->fill([
            'sequence' => $arrival->sequence ?: $this->sequenceOf($trip, $stopId),
            'arrived_at' => $arrival->arrived_at ?? now(),
            'departed_at' => now(),
        ])->save();

        return $arrival->refresh();
    }

    /* ================================================================= */
    /* 6 · marking children                                               */
    /* ================================================================= */

    public function board(
        SchoolTrip $trip,
        int $childId,
        SchoolStaff $staff,
        ?float $lat = null,
        ?float $lng = null,
        ?string $clientReportedAt = null,
        bool $offline = false,
    ): SchoolTripChild {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);
        $this->assertRunning($trip);

        $row = $this->row($trip, $childId);

        // ⚠ IDEMPOTENT, NOT THROTTLED. Enterprise BF3: never throttle an action
        // an operator must repeat under pressure. A double-tap while 22 children
        // board is not an error, and an attendant fighting a rate limiter is a
        // safety problem — so a second board of an already-boarded child is a
        // no-op that returns the same row.
        if ($row->status === 'boarded') {
            return $row;
        }

        if ($row->status !== 'pending') {
            throw new FleetDenied(
                $row->child->name . ' is already marked ' . $row->statusLabel() . '.',
            );
        }

        $row->forceFill([
            'status' => 'boarded',
            'boarded_at' => now(),
            'boarded_lat' => $lat,
            'boarded_lng' => $lng,
            'acted_by_type' => 'attendant',
            'acted_by_id' => $staff->id,
            // ⚠ Stored, never used for ordering (PART L4). A phone with a wrong
            // clock must not be able to reorder a custody record.
            'client_reported_at' => $clientReportedAt,
            'synced_offline' => $offline,
        ])->save();

        return $row->refresh();
    }

    /** The mis-tap window, in seconds. */
    public const UNDO_SECONDS = 90;

    /**
     * ⚠ A WINDOW, NOT A TOGGLE. Past 90 seconds the bus has moved and "this
     * child is not on board" is a different claim entirely — one that needs an
     * ops override with an audit entry, not a tap at a kerb.
     */
    public function undoBoard(SchoolTrip $trip, int $childId, SchoolStaff $staff): SchoolTripChild
    {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);

        $row = $this->row($trip, $childId);

        if ($row->status !== 'boarded') {
            throw new FleetDenied($row->child->name . ' is not marked boarded.');
        }

        if (! $row->boarded_at || $row->boarded_at->diffInSeconds(now()) > self::UNDO_SECONDS) {
            throw new FleetDenied(
                'The undo window has closed. Ask ops to correct '
                . $row->child->name . "'s record — the change is audited.",
            );
        }

        $row->forceFill([
            'status' => 'pending',
            'boarded_at' => null,
            'boarded_lat' => null,
            'boarded_lng' => null,
            'acted_by_type' => 'attendant',
            'acted_by_id' => $staff->id,
        ])->save();

        return $row->refresh();
    }

    /**
     * ⚠ MORNING ONLY, AND ONLY AFTER THE SCHOOL'S WAIT. The single largest
     * difference between the two directions.
     *
     * In the morning a child who does not appear is still at home with their
     * guardian, and the bus leaving is safe. In the afternoon the child is ON
     * THE BUS, and "not at stop" would mean putting them out at a kerb where
     * nobody came — which is Invariant #1 broken. There is no afternoon
     * equivalent and there must never be one; the way out is the escalation
     * ladder and then *Return to school*.
     */
    public function notAtStop(SchoolTrip $trip, int $childId, SchoolStaff $staff): SchoolTripChild
    {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);
        $this->assertRunning($trip);

        $row = $this->row($trip, $childId);

        if (! $trip->isMorning()) {
            throw new FleetDenied(
                'There is no "not at stop" on an afternoon trip. '
                . $row->child->name . ' is on the bus — verify a receiver, '
                . 'or return to school.',
            );
        }

        if ($row->status !== 'pending') {
            throw new FleetDenied(
                $row->child->name . ' is already marked ' . $row->statusLabel() . '.',
            );
        }

        $wait = (int) ($trip->school?->stop_wait_seconds ?: 120);

        $arrival = SchoolStopArrival::where('trip_id', $trip->id)
            ->where('stop_id', $row->stop_id)
            ->first();

        if (! $arrival?->arrived_at) {
            throw new FleetDenied('Mark the bus as arrived at the stop first.');
        }

        $waited = $arrival->arrived_at->diffInSeconds(now());

        if ($waited < $wait) {
            $left = (int) ceil($wait - $waited);

            throw new FleetDenied(
                "The bus waits the full {$wait} seconds. {$left} to go.",
            );
        }

        DB::transaction(function () use ($row, $trip, $staff) {
            $row->forceFill([
                'status' => 'not_at_stop',
                'acted_by_type' => 'attendant',
                'acted_by_id' => $staff->id,
            ])->save();

            // The office and the guardians hear about this because of THIS row,
            // not because of the trip-child status — an absence is what the
            // dashboard's roster and the parent's card both read.
            SchoolChildAbsence::firstOrCreate([
                'child_id' => $row->child_id,
                'service_date' => $trip->service_date,   // raw string (L1)
                'direction' => $trip->direction,
                'bell_tier' => $trip->bell_tier,
            ], [
                'marked_by' => 'attendant_not_at_stop',
                'marked_by_id' => $staff->id,
                'reason' => 'Not at the stop when the bus arrived',
            ]);
        });

        return $row->refresh();
    }

    /* ================================================================= */
    /* 7 · head count                                                     */
    /* ================================================================= */

    /**
     * PART F2 — the wrong-row catcher, and the only check that finds it.
     *
     * A mis-tap marks a child boarded who is not on the bus. Nothing else in
     * the flow disagrees: the roster is self-consistent, the parent has had a
     * boarding notification, the header count matches the marks. Only a
     * physical count of actual children does.
     *
     * ⚠ A mismatch is recorded as an exception on the Control Tower queue even
     * though the crew will recount. An attendant who counts 17, recounts, and
     * gets 18 has still had a moment worth someone looking at.
     */
    public function headCount(SchoolTrip $trip, int $counted, SchoolStaff $staff): SchoolTrip
    {
        $this->assertMayMarkChildren($staff);
        $this->assertRunning($trip);

        $expected = $trip->tripChildren()->where('status', 'boarded')->count();
        $matched = $counted === $expected;

        $trip->forceFill([
            'headcount_reported' => $counted,
            'headcount_verified' => $matched,
        ])->save();

        if (! $matched) {
            SchoolTripEvent::create([
                'trip_id' => $trip->id,
                'school_id' => $trip->school_id,
                'event_type' => 'headcount_mismatch',
                'severity' => 'critical',
                'detail' => "Attendant marked {$expected} boarded and counted {$counted}.",
                'payload' => ['marked' => $expected, 'counted' => $counted],
                'started_at' => now(),
            ]);
        }

        return $trip->refresh();
    }

    /* ================================================================= */
    /* 8 · arrival at school (morning)                                    */
    /* ================================================================= */

    /**
     * ⚠ MORNING CUSTODY GOES CHILD → INSTITUTION, so there is no receiver code
     * here. The bus delivers children to a school, which does not have to prove
     * its identity to receive a pupil. The receiver check belongs to the
     * afternoon (PART A7) — moving it here would leave the drop with no
     * verification anywhere.
     */
    public function disembark(SchoolTrip $trip, SchoolStaff $staff): SchoolTrip
    {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);
        $this->assertRunning($trip);

        if (! $trip->isMorning()) {
            throw new FleetDenied('Disembark at school is a morning action.');
        }

        // ⚠ The head count gates this, not the other way round. Letting a bus
        // arrive and disembark on an unreconciled count throws away the one
        // moment the wrong-row mis-tap was still findable.
        if (! $trip->headcount_verified) {
            throw new FleetDenied(
                'Do the head count first. It is the only check that catches a '
                . 'child marked boarded who is not on the bus.',
            );
        }

        DB::transaction(function () use ($trip, $staff) {
            $trip->tripChildren()->where('status', 'boarded')->update([
                'status' => 'arrived_at_school',
                'acted_by_type' => 'attendant',
                'acted_by_id' => $staff->id,
                'updated_at' => now(),
            ]);

            $trip->forceFill(['arrived_at_school_at' => now()])->save();
        });

        return $trip->refresh();
    }

    /* ================================================================= */
    /* 9 · afternoon boarding at the school gate                          */
    /* ================================================================= */

    public function boardAtSchool(SchoolTrip $trip, int $childId, SchoolStaff $staff): SchoolTripChild
    {
        if ($trip->isMorning()) {
            throw new FleetDenied('School-gate boarding is an afternoon action.');
        }

        if ($trip->roster_locked_at) {
            throw new FleetDenied('The roster is locked and the bus has left. Ops can reopen it.');
        }

        return $this->board($trip, $childId, $staff);
    }

    /**
     * ⚠ UN-BOARDED CHILDREN BECOME ABSENT *AND THE SCHOOL OFFICE IS TOLD*. The
     * notification is the point of the action, not a side effect: an expected
     * child who did not come out to the bus is somewhere on school premises,
     * and that is the school's problem to resolve before the bus leaves — not a
     * row that quietly greys out.
     *
     * Unlocks when every child is resolved, or at the scheduled departure.
     */
    public function lockInRoster(SchoolTrip $trip, SchoolStaff $staff): array
    {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);
        $this->assertRunning($trip);

        if ($trip->isMorning()) {
            throw new FleetDenied('Roster lock-in is an afternoon action.');
        }

        if ($trip->roster_locked_at) {
            throw new FleetDenied('The roster is already locked.');
        }

        $pending = SchoolTripChild::with('child')
            ->where('trip_id', $trip->id)
            ->where('status', 'pending')
            ->get();

        $due = $trip->scheduled_start_at && now()->gte($trip->scheduled_start_at);

        if ($pending->isNotEmpty() && ! $due) {
            throw new FleetDenied(
                'Not everyone is resolved yet, and it is not departure time. '
                . 'Wait, or mark the rest.',
            );
        }

        DB::transaction(function () use ($pending, $trip, $staff) {
            foreach ($pending as $row) {
                $row->forceFill([
                    'status' => 'absent',
                    'acted_by_type' => 'attendant',
                    'acted_by_id' => $staff->id,
                ])->save();

                SchoolChildAbsence::firstOrCreate([
                    'child_id' => $row->child_id,
                    'service_date' => $trip->service_date,
                    'direction' => $trip->direction,
                    'bell_tier' => $trip->bell_tier,
                ], [
                    'marked_by' => 'attendant_not_boarded_at_school',
                    'marked_by_id' => $staff->id,
                    'reason' => 'Did not board at the school gate',
                ]);
            }

            if ($pending->isNotEmpty()) {
                // ⚠ This is how the office is told. The Control Tower queue is
                // the school's working surface; a row here is a person looking.
                SchoolTripEvent::create([
                    'trip_id' => $trip->id,
                    'school_id' => $trip->school_id,
                    'event_type' => 'unaccounted_child',
                    'severity' => 'high',
                    'detail' => $pending->count() . ' expected '
                        . ($pending->count() === 1 ? 'child' : 'children')
                        . ' did not board at the school gate: '
                        . $this->nameList($pending->pluck('child.name')->all()) . '.',
                    'payload' => ['child_ids' => $pending->pluck('child_id')->all()],
                    'started_at' => now(),
                ]);
            }

            $trip->forceFill([
                'roster_locked_at' => now(),
                'school_depart_at' => now(),
            ])->save();
        });

        return [
            'marked_absent' => $pending->count(),
            'names' => $pending->pluck('child.name')->all(),
        ];
    }

    /* ================================================================= */
    /* 10 · the drop stop — Invariant #1                                  */
    /* ================================================================= */

    /**
     * ⚠ INVARIANT #1, THE WHOLE OF IT. Exactly three ways a child leaves this
     * bus at a kerb:
     *
     *   handover_code      — the 4-digit code from the guardian's Parent app
     *   authorized_person  — a face the family approved in advance
     *   self_release       — signed consent AND the school's minimum grade
     *
     * There is no fourth, there is no `skip`, and there is no parameter that
     * means "we could not verify but let them off anyway". If you are here to
     * add one, the answer to that case is [escalate] and then [returnToSchool].
     */
    public function handover(
        SchoolTrip $trip,
        int $childId,
        SchoolStaff $staff,
        string $method,
        ?string $code = null,
        ?int $receiverId = null,
        ?float $lat = null,
        ?float $lng = null,
        bool $offline = false,
    ): SchoolTripChild {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);
        $this->assertRunning($trip);

        if ($trip->isMorning()) {
            throw new FleetDenied('Handover is an afternoon action.');
        }

        $row = $this->row($trip, $childId);
        $child = $row->child;

        if ($row->status !== 'boarded') {
            throw new FleetDenied(
                $child->name . ' is not on the bus — they are marked '
                . $row->statusLabel() . '.',
            );
        }

        $receiverName = null;
        $receiverGuardianId = null;
        $receiverAuthorizedId = null;
        $attempts = 0;
        $status = 'alighted_to_guardian';

        switch ($method) {
            case 'handover_code':
                // ⚠ The comparison is against a HASH the server holds. The
                // plaintext lives in the cache for the day so the parent can
                // read it out at the kerb, and it never travels to this device.
                $attempts = (int) $this->codeAttempts($trip, $child->id);

                if ($attempts >= 5) {
                    throw new FleetDenied(
                        'The code is locked after 5 wrong attempts. Use an '
                        . 'authorized person, or call ops.',
                        423,
                    );
                }

                if (! $code || ! $child->verifyHandoverCode($code)) {
                    $this->recordFailedCode($trip, $child->id);
                    $left = 5 - ($attempts + 1);

                    throw new FleetDenied(
                        $left > 0
                            ? "That code is wrong · {$left} "
                              . ($left === 1 ? 'attempt' : 'attempts') . ' left'
                            : 'The code is locked after 5 wrong attempts. Use an '
                              . 'authorized person, or call ops.',
                        $left > 0 ? 422 : 423,
                    );
                }

                $guardian = $child->guardians->first();
                $receiverGuardianId = $guardian?->id;
                $receiverName = $guardian?->name ?? 'Guardian at the stop';
                break;

            case 'authorized_person':
                $receiver = $child->authorizedReceivers()
                    ->where('is_active', true)
                    ->find($receiverId);

                if (! $receiver) {
                    throw new FleetDenied(
                        'That person is not on ' . $child->name . "'s approved list.",
                    );
                }

                $receiverAuthorizedId = $receiver->id;
                $receiverName = $receiver->name . ' · ' . $receiver->relationship;
                break;

            case 'self_release':
                // ⚠ Re-decided here from the CHILD and the SCHOOL, never from
                // anything the app sent. `canSelfRelease()` requires both a
                // signed consent and the school's minimum grade; an app that
                // re-derived it from a grade number would drift from the
                // school's rule, and the drift would be a child let off alone.
                if (! $child->canSelfRelease()) {
                    throw new FleetDenied(
                        $child->name . ' has no signed self-release consent on file.',
                        403,
                    );
                }

                $status = 'alighted_self_release';
                $receiverName = 'Self-release · consent on file';
                break;

            default:
                throw new FleetDenied('Unknown verification method.');
        }

        DB::transaction(function () use (
            $row, $trip, $child, $staff, $method, $status, $receiverName,
            $receiverGuardianId, $receiverAuthorizedId, $attempts, $lat, $lng, $offline
        ) {
            $row->forceFill([
                'status' => $status,
                'alighted_at' => now(),
                'alighted_lat' => $lat,
                'alighted_lng' => $lng,
                'escalation_started_at' => null,
                'acted_by_type' => 'attendant',
                'acted_by_id' => $staff->id,
                'synced_offline' => $offline,
            ])->save();

            // PART A7 — the custody record. This is what answers a dispute.
            SchoolChildHandover::create([
                'trip_id' => $trip->id,
                'child_id' => $child->id,
                'stop_id' => $row->stop_id,
                'receiver_guardian_id' => $receiverGuardianId,
                'receiver_authorized_id' => $receiverAuthorizedId,
                'receiver_name' => $receiverName,
                'verification_method' => $method,
                'code_attempts' => $attempts,
                'verified_offline' => $offline,
                'latitude' => $lat,
                'longitude' => $lng,
                'acted_by_staff_id' => $staff->id,
                'escalation_trail' => $row->escalation_started_at
                    ? ['escalated_at' => $row->escalation_started_at->toIso8601String()]
                    : null,
            ]);

            $this->clearCodeAttempts($trip, $child->id);
        });

        return $row->refresh();
    }

    /* ================================================================= */
    /* 11 · the escalation ladder                                         */
    /* ================================================================= */

    /** Nobody is here. The child stays on the bus and the clock starts. */
    public function escalate(SchoolTrip $trip, int $childId, SchoolStaff $staff): SchoolTripChild
    {
        $this->assertMayMarkChildren($staff);
        $this->assertRunning($trip);

        $row = $this->row($trip, $childId);

        if ($row->status !== 'boarded') {
            throw new FleetDenied($row->child->name . ' is not on the bus.');
        }

        if (! $row->escalation_started_at) {
            $row->forceFill(['escalation_started_at' => now()])->save();

            SchoolTripEvent::create([
                'trip_id' => $trip->id,
                'school_id' => $trip->school_id,
                'event_type' => 'unaccounted_child',
                'severity' => 'critical',
                'detail' => 'Nobody at the drop stop for ' . $row->child->name
                    . '. The bus is waiting.',
                'payload' => ['child_id' => $row->child_id, 'stop_id' => $row->stop_id],
                'started_at' => now(),
            ]);
        }

        return $row->refresh();
    }

    /**
     * ⚠ INVARIANT #1's ONLY REMAINING EXIT, and it is not available early.
     *
     * Until the school's drop-wait window has run, the bus waits — a guardian
     * two streets away in Hyderabad traffic is the ordinary case, and driving
     * off with their child is not a neutral act.
     */
    public function returnToSchool(SchoolTrip $trip, int $childId, SchoolStaff $staff): SchoolTripChild
    {
        $this->assertMayMarkChildren($staff);
        $this->assertNotLockedBySos($trip);
        $this->assertRunning($trip);

        $row = $this->row($trip, $childId);

        if (! $row->escalation_started_at) {
            throw new FleetDenied(
                'Start the escalation first — the guardians have to be called '
                . 'before the bus gives up.',
            );
        }

        $window = (int) ($trip->school?->drop_wait_seconds ?: 180);
        $waited = $row->escalation_started_at->diffInSeconds(now());

        if ($waited < $window) {
            $left = (int) ceil($window - $waited);

            throw new FleetDenied(
                'The bus waits the full ' . (int) round($window / 60)
                . " minutes first. {$left} seconds to go.",
            );
        }

        DB::transaction(function () use ($row, $trip, $staff) {
            $row->forceFill([
                'status' => 'returned_to_school',
                'acted_by_type' => 'attendant',
                'acted_by_id' => $staff->id,
            ])->save();

            SchoolChildHandover::create([
                'trip_id' => $trip->id,
                'child_id' => $row->child_id,
                'stop_id' => $row->stop_id,
                'receiver_name' => 'Returned to school — nobody could be verified',
                'verification_method' => 'returned_to_school',
                'acted_by_staff_id' => $staff->id,
                'escalation_trail' => [
                    'escalated_at' => $row->escalation_started_at->toIso8601String(),
                    'returned_at' => now()->toIso8601String(),
                ],
            ]);

            SchoolIncident::create([
                'school_id' => $trip->school_id,
                'trip_id' => $trip->id,
                'child_id' => $row->child_id,
                'incident_type' => 'child_returned_to_school',
                'severity' => 'critical',
                'raised_by_type' => 'attendant',
                'raised_by_id' => $staff->id,
                'description' => 'Nobody could be verified at the drop stop. '
                    . $row->child->name . ' is returning to school on the bus.',
            ]);
        });

        return $row->refresh();
    }

    /* ================================================================= */
    /* 12 · the sweep — Invariant #3                                      */
    /* ================================================================= */

    /**
     * ⚠ INVARIANT #3. Timestamped, geo-stamped, photo-backed, and impossible to
     * pre-tap: it refuses until the bus is inside the school's gate geofence.
     *
     * The failure this prevents is a child asleep in a back seat and a bus
     * parked for the day. It has happened, in more than one country. A sweep
     * ticked at the second-to-last stop out of habit catches nothing, which is
     * why the geofence is the gate rather than a nudge.
     */
    public function sweep(
        SchoolTrip $trip,
        SchoolStaff $staff,
        ?string $photoPath,
        ?float $lat,
        ?float $lng,
    ): SchoolTrip {
        $this->assertRunning($trip);

        if (! $photoPath) {
            throw new FleetDenied(
                'The sweep needs a photo of the empty aisle, taken from the back.',
            );
        }

        $school = $trip->school;

        if ($school?->latitude && $school?->longitude) {
            if ($lat === null || $lng === null) {
                throw new FleetDenied(
                    'The sweep needs your location. Turn location on and try again.',
                );
            }

            $metres = $this->haversineMetres(
                (float) $school->latitude, (float) $school->longitude, $lat, $lng,
            );

            $radius = (int) ($school->gate_geofence_radius_m ?: 200);

            if ($metres > $radius) {
                throw new FleetDenied(sprintf(
                    'The sweep unlocks at the school gate. The bus is %.1f km away. '
                    . 'It cannot be ticked early — that is the point.',
                    $metres / 1000,
                ));
            }
        }

        $trip->forceFill([
            'sweep_verified_at' => now(),
            'sweep_photo_path' => $photoPath,
            'sweep_lat' => $lat,
            'sweep_lng' => $lng,
            'sweep_by_staff_id' => $staff->id,
        ])->save();

        return $trip->refresh();
    }

    /* ================================================================= */
    /* 13 · completion — Invariant #2                                     */
    /* ================================================================= */

    /**
     * ⚠ INVARIANTS #2 AND #3, TOGETHER, AND THIS IS THE GATE.
     *
     * It names the children rather than counting them. The 422 body carries the
     * ids so the app can offer a button straight to the child instead of making
     * somebody hunt for them.
     */
    public function complete(SchoolTrip $trip, SchoolStaff $staff): SchoolTrip
    {
        $this->assertRunning($trip);

        if ($trip->sweepPending()) {
            throw new FleetDenied(
                'Cannot complete: the bus has not been swept. Walk to the back '
                . 'and confirm every seat is empty.',
            );
        }

        $open = SchoolTripChild::with('child')
            ->where('trip_id', $trip->id)
            ->whereIn('status', ['pending', 'boarded'])
            ->get();

        if ($open->isNotEmpty()) {
            $onBus = $open->where('status', 'boarded');
            $missing = $open->where('status', 'pending');

            $parts = [];

            if ($onBus->isNotEmpty()) {
                $parts[] = $this->nameList($onBus->pluck('child.name')->all())
                    . ' ' . ($onBus->count() === 1 ? 'is' : 'are') . ' still on board';
            }

            if ($missing->isNotEmpty()) {
                $parts[] = $this->nameList($missing->pluck('child.name')->all())
                    . ' ' . ($missing->count() === 1 ? 'is' : 'are') . ' never accounted for';
            }

            throw new FleetDenied(
                'Cannot complete: ' . implode(', and ', $parts)
                . '. Every child must be handed over, absent, or returned to '
                . 'school before the trip can close.',
                422,
                $open->pluck('child_id')->all(),
            );
        }

        $trip->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        return $trip->refresh();
    }

    /* ================================================================= */
    /* 15 · SOS                                                           */
    /* ================================================================= */

    public function raiseSos(
        SchoolTrip $trip,
        SchoolStaff $staff,
        string $type,
        bool $drill = false,
        ?float $lat = null,
        ?float $lng = null,
    ): SchoolIncident {
        $allowed = ['accident', 'breakdown', 'medical', 'security', 'fire', 'other'];

        if (! in_array($type, $allowed, true)) {
            throw new FleetDenied('Unknown emergency type.');
        }

        return SchoolIncident::create([
            'school_id' => $trip->school_id,
            'trip_id' => $trip->id,
            'incident_type' => 'sos_' . $type,
            'severity' => 'critical',
            // ⚠ PART Q5 — a drill is banded, logged, dispatches nobody, and
            // still locks child records. A drill that behaves differently from
            // the real thing trains the wrong reflex.
            'is_drill' => $drill,
            'raised_by_type' => $staff->role,
            'raised_by_id' => $staff->id,
            'description' => ucfirst($type) . ' reported from the vehicle app'
                . ($drill ? ' (DRILL)' : ''),
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    /* ================================================================= */
    /* Bus position — the Layer 1 live map                                */
    /* ================================================================= */

    /**
     * ⚠ THIS IS WHAT MAKES THE PARENT'S MAP MOVE. `buses.latitude/longitude`
     * is the single position of record; FamilyCardBuilder reads it and decides
     * per family whether it may leave the server at all (enterprise L29).
     *
     * The crew's device is the GPS until a hardware tracker exists — so an app
     * in the background is a bus that appears to have stopped. That is a known
     * limitation and the reason `last_ping_at` is rendered as "may be out of
     * date" on the parent side rather than silently drawn as live.
     */
    public function ping(SchoolTrip $trip, float $lat, float $lng, ?int $speedKmph = null): void
    {
        $bus = $trip->bus;

        if (! $bus) {
            return;
        }

        $bus->forceFill([
            'latitude' => $lat,
            'longitude' => $lng,
            'speed_kmph' => $speedKmph,
            'last_ping_at' => now(),
        ])->save();

        // PART R3 — overspeed is an exception row, not a popup the driver
        // dismisses. The Control Tower is where a repeated pattern shows up.
        $limit = (int) ($trip->school?->max_speed_kmph ?: 40);

        if ($speedKmph !== null && $speedKmph > $limit) {
            $recent = SchoolTripEvent::where('trip_id', $trip->id)
                ->where('event_type', 'overspeed')
                ->where('started_at', '>=', now()->subMinutes(5))
                ->exists();

            if (! $recent) {
                SchoolTripEvent::create([
                    'trip_id' => $trip->id,
                    'school_id' => $trip->school_id,
                    'event_type' => 'overspeed',
                    'severity' => 'high',
                    'detail' => "{$speedKmph} km/h against a {$limit} km/h limit.",
                    'payload' => ['speed_kmph' => $speedKmph, 'limit' => $limit],
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'started_at' => now(),
                ]);
            }
        }
    }

    /* ================================================================= */
    /* helpers                                                            */
    /* ================================================================= */

    private function row(SchoolTrip $trip, int $childId): SchoolTripChild
    {
        $row = SchoolTripChild::with('child')
            ->where('trip_id', $trip->id)
            ->where('child_id', $childId)
            ->first();

        if (! $row) {
            // 404 rather than 403 so the response cannot be used to probe which
            // children exist at a school.
            throw new FleetDenied('That child is not on this trip.', 404);
        }

        return $row;
    }

    private function sequenceOf(SchoolTrip $trip, int $stopId): int
    {
        // ⚠ The solver writes `seq`, not `sequence` (SchoolTripSolver). Reading
        // the wrong key silently produces 0 for every stop, which collapses the
        // stop-arrival ordering the Control Tower reads.
        foreach ($trip->route_schedule ?? [] as $i => $s) {
            if ((int) ($s['stop_id'] ?? 0) === $stopId) {
                return (int) ($s['seq'] ?? $i + 1);
            }
        }

        return 0;
    }

    private function tz(SchoolTrip $trip): string
    {
        return $trip->school?->timezone ?: config('app.timezone');
    }

    /** "Aarav Mehta", "Aarav Mehta and Zoya Khan", "A, B and C". */
    private function nameList(array $names): string
    {
        $names = array_values(array_filter($names));

        if (count($names) <= 1) return $names[0] ?? 'A child';
        if (count($names) === 2) return $names[0] . ' and ' . $names[1];

        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * Wrong-code attempts, per child per day.
     *
     * ⚠ Kept in the cache, not on the trip row, and keyed by the DAY rather
     * than by the trip: the code itself rotates daily, so the lockout should
     * follow the code. It is also why a crew member cannot clear a lockout by
     * closing the app.
     */
    private function codeKey(SchoolTrip $trip, int $childId): string
    {
        return "fleet-code-attempts:{$trip->service_date}:{$childId}";
    }

    private function codeAttempts(SchoolTrip $trip, int $childId): int
    {
        return (int) \Cache::get($this->codeKey($trip, $childId), 0);
    }

    private function recordFailedCode(SchoolTrip $trip, int $childId): void
    {
        $key = $this->codeKey($trip, $childId);
        \Cache::put($key, $this->codeAttempts($trip, $childId) + 1, now()->endOfDay());
    }

    private function clearCodeAttempts(SchoolTrip $trip, int $childId): void
    {
        \Cache::forget($this->codeKey($trip, $childId));
    }

    /** Metres between two points. Same formula the trip solver falls back on. */
    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
