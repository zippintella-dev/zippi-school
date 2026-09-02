<?php

namespace App\Http\Controllers\Parent;

use App\Models\Child;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolTripChild;
use App\Services\FamilyCardBuilder;
use App\Services\SchoolCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PART H4 — Zippi Parent: Family Dashboard → Child Live → Journey.
 *
 * ⚠ EVERY method resolves the child through the signed-in guardian's own links.
 * There is no path here that takes a child id and trusts it. Getting this wrong
 * hands any parent the live GPS position of any child in the school, which is
 * the single worst bug this product could ship.
 *
 * ⚠ PART L1: every date in and out is a raw Y-m-d string.
 */
class ParentController extends Controller
{
    public function __construct(private FamilyCardBuilder $cards) {}

    private function guardian()
    {
        return Auth::guard('guardian')->user();
    }

    /**
     * ⚠ THE AUTHORIZATION GATE. Every child lookup in this controller goes
     * through here — never Child::findOrFail(). A 404 rather than a 403 so the
     * response cannot be used to probe which children exist.
     */
    private function myChild(int $childId): Child
    {
        $child = $this->guardian()->children()
            ->with('school')
            ->firstWhere('children.id', $childId);

        if (! $child) {
            throw new NotFoundHttpException;
        }

        return $child;
    }

    /** PART H4 — the Family Dashboard: one card per child. */
    public function dashboard()
    {
        $guardian = $this->guardian();
        $children = $guardian->children()->with('school')->orderBy('name')->get();

        return view('parent.dashboard', [
            'guardian' => $guardian,
            'cards' => $children->map(fn ($c) => $this->cards->build($c)),
        ]);
    }

    /** PART H4 — Child Live. */
    public function child(int $childId)
    {
        $child = $this->myChild($childId);
        $card = $this->cards->build($child);

        return view('parent.child', [
            'child' => $child,
            'card' => $card,
            // PART A7 — the afternoon collection code, shown large. Generated
            // only when there is an afternoon trip to use it on, so we don't
            // rotate a code nobody needs.
            'handoverCode' => $card['show_handover_code'] ? $child->todaysHandoverCode() : null,
            // PART A7 (extended) — the morning boarding code, read out to the
            // attendant at the stop. Minted only when there is a morning trip
            // still to board, for the same reason: never rotate a code nobody
            // needs, and never mint one that will sit unused on a screen.
            'boardingCode' => $card['show_boarding_code'] ? $child->todaysBoardingCode() : null,
        ]);
    }

    /**
     * PART F6 — the 10-second poll. The app also re-fetches on resume, because
     * a push that arrives while backgrounded is dropped with no buffering and
     * the screen is then stale exactly when the parent opens it.
     */
    public function childData(int $childId)
    {
        return response()->json($this->cards->build($this->myChild($childId)));
    }

    /**
     * PART D — the journey record, 90 days. This is the killer feature: every
     * event with actor, coordinates and SERVER time.
     */
    public function journey(int $childId, Request $request)
    {
        $child = $this->myChild($childId);

        $from = Carbon::now($child->school?->timezone ?: config('app.timezone'))
            ->subDays(90)->toDateString();

        $rows = SchoolTripChild::with(['trip.route', 'stop'])
            ->where('child_id', $child->id)
            ->whereHas('trip', fn ($q) => $q->where('service_date', '>=', $from))
            ->get()
            ->sortByDesc(fn ($r) => $r->trip->service_date . ($r->trip->isMorning() ? '0' : '1'))
            ->groupBy(fn ($r) => $r->trip->service_date);

        $absences = SchoolChildAbsence::where('child_id', $child->id)
            ->where('service_date', '>=', $from)
            ->get()
            ->groupBy('service_date');

        return view('parent.journey', [
            'child' => $child,
            'days' => $rows,
            'absences' => $absences,
        ]);
    }

    /**
     * PART A1/A2 — a parent marks their own child absent.
     *
     * ⚠ Defaults to the next SCHOOL day, not literally tomorrow: a parent
     * marking absence on Friday evening means Monday, and landing on Saturday
     * silently records nothing.
     */
    public function markAbsent(int $childId, Request $request)
    {
        $child = $this->myChild($childId);
        $cal = SchoolCalendarService::for($child->school);

        $data = $request->validate([
            'service_date' => ['required', 'date_format:Y-m-d'],
            'direction' => ['required', 'in:Morning,Afternoon,Both'],
            'reason_code' => ['nullable', 'in:sick,travel,exam,activity,other'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $date = $data['service_date'];

        if (! $cal->isSchoolDay($date)) {
            return back()->withErrors([
                'service_date' => 'That is not a school day (' . $cal->describe($date) . ').',
            ]);
        }

        if ($date < $cal->today()) {
            return back()->withErrors(['service_date' => 'You cannot change a day that has passed.']);
        }

        $directions = $data['direction'] === 'Both'
            ? ['Morning', 'Afternoon']
            : [$data['direction']];

        foreach ($directions as $direction) {
            if (! $cal->tripsRun($date, $direction)) continue;

            // Idempotent — a parent tapping twice under pressure must not create
            // two rows (PART BF3: never throttle, make it idempotent instead).
            SchoolChildAbsence::updateOrCreate(
                ['child_id' => $child->id, 'service_date' => $date, 'direction' => $direction],
                [
                    'bell_tier' => $child->bell_tier,
                    'marked_by' => 'parent',
                    'marked_by_id' => $this->guardian()->id,
                    'reason_code' => $data['reason_code'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'approval_status' => 'approved',
                ]
            );
        }

        return back()->with('ok', $child->name . ' is marked absent for '
            . Carbon::parse($date)->format('D j M') . '.');
    }

    /** PART A2 — undo. The enterprise L1 date bug lived exactly here. */
    public function undoAbsence(int $childId, Request $request)
    {
        $child = $this->myChild($childId);
        $cal = SchoolCalendarService::for($child->school);

        $data = $request->validate([
            'service_date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($data['service_date'] < $cal->today()) {
            return back()->withErrors(['service_date' => 'You cannot change a day that has passed.']);
        }

        // ⚠ PART L1: raw string compare. A Carbon here matches zero rows and the
        // undo silently does nothing — the exact enterprise bug.
        SchoolChildAbsence::where('child_id', $child->id)
            ->where('service_date', $data['service_date'])
            ->where('marked_by', 'parent')
            ->delete();

        return back()->with('ok', 'Absence removed.');
    }

}
