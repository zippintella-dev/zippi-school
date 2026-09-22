<?php

namespace App\Http\Controllers\Api;

use App\Models\Child;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTripChild;
use App\Services\FamilyCardBuilder;
use App\Services\SchoolCalendarService;
use App\Services\TripRosterReconciler;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Zippi Parent mobile API (PART H4 / F6).
 *
 * ⚠ EVERY child lookup goes through myChild(), which resolves via the
 * authenticated guardian's OWN links. There is no endpoint here that accepts a
 * child id and trusts it. Getting this wrong hands any parent the live GPS
 * position of any child in the school.
 *
 * The live-map and masking rules are NOT decided here — they live in
 * FamilyCardBuilder so the web app and the mobile app can never disagree about
 * when a bus position is allowed to leave the server.
 */
class ParentApiController extends Controller
{
    public function __construct(private FamilyCardBuilder $cards) {}

    /**
     * GET /api/parent/family-dashboard — polled every 10 s while foregrounded,
     * and immediately on resume (PART F6 / enterprise L14).
     */
    public function familyDashboard(Request $request): JsonResponse
    {
        $guardian = $request->user();

        $children = $guardian->children()->with('school')->orderBy('name')->get();

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'guardian' => [
                'id' => $guardian->id,
                'name' => $guardian->name,
                'phone' => $guardian->phone,
            ],
            'children' => $children->map(fn ($c) => $this->cards->build($c))->values(),
        ]);
    }

    /** GET /api/parent/child/{child} */
    public function child(Request $request, int $child): JsonResponse
    {
        $model = $this->myChild($request, $child);
        $card = $this->cards->build($model);

        // ⚠ THE DIGITS APPEAR ONLY ONCE THE BUS IS ACTUALLY RUNNING.
        //
        // The show_* flags say which code is RELEVANT to this leg; they are what
        // puts the control on the family card. Whether the plaintext is minted
        // is a separate question, and the answer is: not until the trip has
        // started.
        //
        // A live 4-digit code that can release a child sat on a parent's screen
        // from the moment the afternoon leg became current — most of the school
        // day — which is a long window for somebody to read it over a shoulder,
        // photograph it, or pass it on. The parent app has carried the right
        // sentence for this state all along ("The code appears once the
        // afternoon trip is under way"); only the server disagreed with it.
        //
        // It also stops a code being rotated for a trip that never runs.
        $running = ($card['trip']['status'] ?? null) === 'started';

        $card['handover_code'] = $card['show_handover_code'] && $running
            ? $model->todaysHandoverCode()
            : null;

        // PART A7 (extended) — the morning boarding code. Same rule, and the two
        // are never both non-null: show_handover_code and show_boarding_code are
        // mutually exclusive on the trip's direction.
        $card['boarding_code'] = $card['show_boarding_code'] && $running
            ? $model->todaysBoardingCode()
            : null;

        return response()->json(['status' => true, 'child' => $card]);
    }

    /** GET /api/parent/child/{child}/journey — PART D, 90 days. */
    public function journey(Request $request, int $child): JsonResponse
    {
        $model = $this->myChild($request, $child);

        $from = Carbon::now($model->school?->timezone ?: config('app.timezone'))
            ->subDays(90)->toDateString();

        $rows = SchoolTripChild::with(['trip.route', 'stop'])
            ->where('child_id', $model->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', '>=', $from))
            ->get();

        $absences = SchoolChildAbsence::where('child_id', $model->id)
            ->where('service_date', '>=', $from)
            ->get()
            ->groupBy('service_date');

        $days = $rows->groupBy(fn ($r) => $r->trip->service_date)
            ->map(function ($group, $date) use ($absences) {
                return [
                    'service_date' => $date,
                    'absent_directions' => $absences->get($date)?->pluck('direction')->unique()->values()->all() ?? [],
                    'trips' => $group->sortBy(fn ($r) => $r->trip->isMorning() ? 0 : 1)
                        ->map(fn ($r) => [
                            'direction' => $r->trip->direction,
                            'bell_tier' => $r->trip->bell_tier,
                            'route' => $r->trip->route?->code,
                            'status' => $r->status,
                            'stop' => $r->stop?->name,
                            'boarded_at' => $r->boarded_at?->toIso8601String(),
                            'arrived_at_school_at' => $r->status === 'arrived_at_school'
                                ? $r->trip->arrived_at_school_at?->toIso8601String() : null,
                            'alighted_at' => $r->alighted_at?->toIso8601String(),
                        ])->values(),
                ];
            })
            ->sortKeysDesc()
            ->values();

        return response()->json(['status' => true, 'days' => $days]);
    }

    /** POST /api/parent/child/{child}/absence — PART A1/A2. */
    public function markAbsent(Request $request, int $child): JsonResponse
    {
        $model = $this->myChild($request, $child);
        $cal = SchoolCalendarService::for($model->school);

        $data = $request->validate([
            'service_date' => ['required', 'date_format:Y-m-d'],
            'direction' => ['required', 'in:Morning,Afternoon,Both'],
            'reason_code' => ['nullable', 'in:sick,travel,exam,activity,other'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $date = $data['service_date'];

        // PART P7 — the app renders `message` verbatim, so it must be a sentence.
        if (! $cal->isSchoolDay($date)) {
            return response()->json(['status' => false,
                'message' => 'That is not a school day (' . $cal->describe($date) . ').'], 422);
        }

        if ($date < $cal->today()) {
            return response()->json(['status' => false,
                'message' => 'You cannot change a day that has passed.'], 422);
        }

        $directions = $data['direction'] === 'Both' ? ['Morning', 'Afternoon'] : [$data['direction']];

        foreach ($directions as $direction) {
            if (! $cal->tripsRun($date, $direction)) continue;

            // Idempotent by (child, date, direction) — a parent tapping twice
            // under pressure must not create two rows (BF3: never throttle an
            // action someone repeats under pressure; make it idempotent).
            SchoolChildAbsence::updateOrCreate(
                ['child_id' => $model->id, 'service_date' => $date, 'direction' => $direction],
                [
                    'bell_tier' => $model->bell_tier,
                    'marked_by' => 'parent',
                    'marked_by_id' => $request->user()->id,
                    'reason_code' => $data['reason_code'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'approval_status' => 'approved',
                ]
            );

            // PART M14 — the roster for today is already built, so the absence
            // row alone is invisible to the crew. Without this the attendant
            // still sees the child as a rider to board.
            app(TripRosterReconciler::class)->applyAbsence($model, $date, $direction);
        }

        return response()->json(['status' => true,
            'message' => $model->name . ' is marked absent for '
                . Carbon::parse($date)->format('D j M') . '.']);
    }

    /** DELETE /api/parent/child/{child}/absence — PART A2. */
    public function undoAbsence(Request $request, int $child): JsonResponse
    {
        $model = $this->myChild($request, $child);
        $cal = SchoolCalendarService::for($model->school);

        $data = $request->validate(['service_date' => ['required', 'date_format:Y-m-d']]);

        if ($data['service_date'] < $cal->today()) {
            return response()->json(['status' => false,
                'message' => 'You cannot change a day that has passed.'], 422);
        }

        // ⚠ PART L1 — raw string compare. A Carbon here matches ZERO rows and
        // the undo silently does nothing. That is the enterprise bug verbatim.
        SchoolChildAbsence::where('child_id', $model->id)
            ->where('service_date', $data['service_date'])
            ->where('marked_by', 'parent')
            ->delete();

        // PART M14 — put the child back on the roster, but only for directions
        // with no absence row left. A parent undo must not cancel the school's.
        app(TripRosterReconciler::class)
            ->clearAbsence($model, $data['service_date']);

        return response()->json(['status' => true, 'message' => 'Absence removed.']);
    }

    /**
     * ⚠ THE AUTHORIZATION GATE. 404 rather than 403 so the response cannot be
     * used to probe which children exist.
     */
    private function myChild(Request $request, int $childId): Child
    {
        $child = $request->user()->children()
            ->with('school')
            ->firstWhere('children.id', $childId);

        if (! $child) {
            throw new NotFoundHttpException;
        }

        return $child;
    }
}
