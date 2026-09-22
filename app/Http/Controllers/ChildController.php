<?php

namespace App\Http\Controllers;

use App\Models\AuthorizedReceiver;
use App\Models\Child;
use App\Models\ChildStopAssignment;
use App\Models\Guardian;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolChildAbsence;
use App\Models\SchoolIncident;
use App\Services\TripRosterReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChildController extends Controller
{
    /**
     * The Students list — ACTIVE students only.
     *
     * ⚠ Removed students are deliberately absent here rather than greyed out.
     * This list is the school's working roster: who is on the buses. A retired
     * child mixed in is a child someone assigns a stop to by mistake. They live
     * on the Removed page, which is one click away and carries a count so they
     * are never actually lost.
     */
    public function index(Request $request)
    {
        return $this->view('children.index', $this->rosterPayload($request, 'active'));
    }

    /** Students taken off transport. Reversible from here (PART K13). */
    public function removed(Request $request)
    {
        return $this->view('children.removed', $this->rosterPayload($request, 'removed'));
    }

    /**
     * Order children by class number, portably.
     *
     * ⚠ The cast keyword is driver-specific and this is NOT cosmetic. SQLite
     * spells it INTEGER; MySQL has no INTEGER cast at all and rejects the
     * statement outright:
     *
     *     SQLSTATE[42000]: Syntax error … near 'INTEGER), `name` asc limit 30'
     *
     * Local development runs SQLite (config/database.php defaults to it) and
     * the server runs MySQL, so a hard-coded `CAST(grade AS INTEGER)` passed
     * every local test and then 500'd the Students list, the Removed list and
     * Restore on the only environments that actually serve a school. Found by
     * the MySQL leg of CI, which exists for exactly this class of bug.
     *
     * Both spellings coerce a non-numeric class name ("LKG") to 0, so the
     * ordering is unchanged — this is a portability fix, not a behaviour change.
     * Named pre-primary classes are ranked properly by App\Support\GradeLevel,
     * which is PHP-side and unaffected.
     */
    private function gradeOrder(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST(grade AS INTEGER)'
            : 'CAST(grade AS SIGNED)';
    }

    /**
     * Shared list query. `$scope` is 'active' or 'removed' — the ONLY difference
     * between the two pages, so they cannot drift apart in filtering or sorting.
     */
    private function rosterPayload(Request $request, string $scope): array
    {
        $school = $this->activeSchool();

        $children = Child::with(['guardians', 'stopAssignments.route', 'stopAssignments.stop'])
            ->where('school_id', $school->id)
            ->when($scope === 'active',
                fn ($q) => $q->where('status', 'active'),
                fn ($q) => $q->where('status', '!=', 'active'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                                       ->orWhere('admission_no', 'like', $term));
            })
            ->when($request->filled('grade'), fn ($q) => $q->where('grade', $request->grade))
            ->when($request->filled('tier'), fn ($q) => $q->where('bell_tier', $request->tier))
            ->when($request->filled('route_id'), fn ($q) => $q->whereHas(
                'stopAssignments', fn ($a) => $a->where('route_id', $request->route_id)))
            ->when($request->get('unassigned') === '1',
                fn ($q) => $q->whereDoesntHave('stopAssignments'))
            ->orderByRaw($this->gradeOrder())->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return [
            'children' => $children,
            'grades' => Child::where('school_id', $school->id)
                            ->distinct()->orderByRaw($this->gradeOrder())->pluck('grade'),
            'routes' => $school->routes()->orderBy('code')->get(),
            // See tiers() below for why this is grouped rather than DISTINCT.
            'tiers' => $school->bellTimes()
                              ->select('bell_tier')->groupBy('bell_tier')
                              ->orderByRaw('MIN(start_time)')->pluck('bell_tier'),
            'filters' => $request->only(['q', 'grade', 'tier', 'route_id', 'unassigned']),
            'total' => Child::where('school_id', $school->id)->where('status', 'active')->count(),
            'removedTotal' => Child::where('school_id', $school->id)
                                  ->where('status', '!=', 'active')->count(),
            'unassignedCount' => Child::where('school_id', $school->id)
                                    ->where('status', 'active')
                                    ->whereDoesntHave('stopAssignments')->count(),
        ];
    }

    public function create()
    {
        return $this->view('children.form', [
            'child'  => new Child(['status' => 'active']),
            'routes' => $this->activeSchool()->routes()->with('stops')->orderBy('code')->get(),
            'tiers'   => $this->tiers(),
        ]);
    }

    public function edit(Child $child)
    {
        $this->authorizeChild($child);

        return $this->view('children.form', [
            'child'  => $child,
            'routes' => $this->activeSchool()->routes()->with('stops')->orderBy('code')->get(),
            'tiers'   => $this->tiers(),
        ]);
    }

    public function store(Request $request)
    {
        $school = $this->activeSchool();
        $data   = $this->validateChild($request);

        $child = DB::transaction(function () use ($school, $data, $request) {
            $child = Child::create($data + ['school_id' => $school->id]);

            // A guardian typed on the create form is linked immediately —
            // a child with no guardian gets no notifications at all.
            if ($request->filled('guardian_name') && $request->filled('guardian_phone')) {
                $this->linkGuardian($child, $request->guardian_name,
                    $request->guardian_phone, $request->guardian_email,
                    $request->input('guardian_relationship', 'parent'), true);
            }

            $this->syncStopAssignment($child, $request);

            return $child;
        });

        SchoolAdminAuditLog::record('child_created', [
            'school_id' => $school->id, 'child_id' => $child->id,
            'payload'   => ['admission_no' => $child->admission_no],
        ]);

        return redirect()->route('children.show', $child)
            ->with('ok', $child->name . ' added.');
    }

    public function update(Request $request, Child $child)
    {
        $this->authorizeChild($child);

        $data = $this->validateChild($request, $child);

        DB::transaction(function () use ($child, $data, $request) {
            $child->update($data);
            $this->syncStopAssignment($child, $request);
        });

        SchoolAdminAuditLog::record('child_updated', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
        ]);

        return redirect()->route('children.show', $child)->with('ok', 'Student updated.');
    }

    /**
     * Retire a student from transport.
     *
     * ⚠ PART K13 — anonymise, never destroy. The journey record is what answers
     * a dispute months later ("you said she was handed over at 4:23"), so a
     * child who has ridden is retired, not deleted. `SchoolTripGenerator` skips
     * inactive children, so this genuinely takes them off the bus tomorrow.
     *
     * Reversible via restore().
     */
    public function destroy(Child $child)
    {
        $this->authorizeChild($child);

        $child->update(['status' => 'inactive']);

        SchoolAdminAuditLog::record('child_retired', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
        ], 'Marked inactive; journey history retained.');

        return redirect()->route('children.index')
            ->with('ok', $child->name . ' removed from transport. '
                . 'Journey history is retained, and you can restore them.');
    }

    /** Put a retired student back on transport. */
    public function restore(Child $child)
    {
        $this->authorizeChild($child);

        $child->update(['status' => 'active']);

        SchoolAdminAuditLog::record('child_restored', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
        ], 'Returned to active transport.');

        return redirect()->route('children.show', $child)
            ->with('ok', $child->name . ' is back on transport. '
                . 'They will be included from the next trip generation.');
    }

    /**
     * Permanently delete a student — ONLY when nothing operational is attached.
     *
     * ⚠ This is the narrow exception to PART K13, and the guard is the whole
     * point of it. K13 protects the JOURNEY record: a child who has ridden,
     * been handed over, or been marked absent has history that must outlive
     * them in the system. A row typed in error during onboarding has none —
     * there is no trail to break, and forcing a school to keep a misspelt
     * duplicate forever just teaches them to ignore the student list.
     *
     * So: any trip row, handover, absence or incident and this refuses and
     * points at retire instead. Never relax that check to "make delete work".
     */
    public function forceDestroy(Child $child)
    {
        $this->authorizeChild($child);

        $history = $this->transportHistory($child);

        if (array_sum($history) > 0) {
            $has = collect($history)->filter()->map(fn ($n, $k) => "$n $k")->implode(', ');

            return back()->with('error',
                $child->name . ' has transport history (' . $has . ') and cannot be '
                . 'deleted — that record is what answers a dispute later. '
                . 'Remove them from transport instead; the history is kept.');
        }

        $name = $child->name;
        $schoolId = $child->school_id;

        // ⚠ The identifying details go in the PAYLOAD, not just the child_id
        // column. That column is a FK with nullOnDelete, so the moment the row
        // goes the audit entry would read "child_deleted: (null)" — an entry
        // that records a deletion without recording WHAT was deleted is not an
        // audit trail. The nulling itself is correct; the payload is what has
        // to survive it.
        SchoolAdminAuditLog::record('child_deleted', [
            'school_id' => $schoolId, 'child_id' => $child->id,
            'payload' => $child->only(['id', 'admission_no', 'name', 'grade', 'bell_tier'])
                + ['guardians' => $child->guardians()->pluck('guardians.name')->all()],
        ], 'Permanently deleted — no transport history existed.');

        // Guardians, links, receivers and stop assignments cascade on the FK.
        // A guardian left with no children is cleaned up here rather than
        // lingering and holding their phone number's UNIQUE slot.
        DB::transaction(function () use ($child) {
            $guardianIds = $child->guardians()->pluck('guardians.id');

            $child->delete();

            Guardian::whereIn('id', $guardianIds)
                ->whereDoesntHave('children')
                ->delete();
        });

        return redirect()->route('children.index')
            ->with('ok', $name . ' was permanently deleted.');
    }

    public function show(Child $child)
    {
        $this->authorizeChild($child);

        $child->load(['guardians.children', 'authorizedReceivers.guardian',
                      'stopAssignments.route', 'stopAssignments.stop', 'school']);

        return $this->view('children.show', [
            'child'    => $child,
            // Drives the delete controls: a child with transport history can be
            // removed from transport but never permanently deleted (PART K13).
            'history'  => $this->transportHistory($child),
            'absences' => $child->absences()->orderByDesc('service_date')->limit(20)->get(),
            'today'    => $this->today(),
            'routes'   => $this->activeSchool()->routes()->with('stops')->orderBy('code')->get(),
        ]);
    }

    /* ---------------- Guardians ---------------- */

    public function addGuardian(Request $request, Child $child)
    {
        $this->authorizeChild($child);

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'phone'        => ['required', 'string', 'max:24'],
            'email'        => ['nullable', 'email', 'max:160'],
            'relationship' => ['required', 'string', 'max:32'],
            'is_primary'   => ['nullable', 'boolean'],
        ]);

        $this->linkGuardian($child, $data['name'], $data['phone'], $data['email'] ?? null,
            $data['relationship'], $request->boolean('is_primary'));

        return back()->with('ok', $data['name'] . ' linked as guardian.');
    }

    /**
     * Edit a guardian's own details, plus their relationship to THIS child.
     *
     * ⚠ A guardian is a shared record. Name, phone and email belong to the
     * person and change for every child they guard; relationship and "primary"
     * are per-child and live on the pivot. The form says so, because a parent
     * correcting a phone number here is changing it for the sibling too.
     */
    public function updateGuardian(Request $request, Child $child, Guardian $guardian)
    {
        $this->authorizeChild($child);

        abort_unless($child->guardians()->where('guardians.id', $guardian->id)->exists(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:24'],
            'email' => ['nullable', 'email', 'max:160'],
            'relationship' => ['required', 'string', 'max:32'],
            'is_primary' => ['nullable', 'boolean'],
            'app_access' => ['nullable', 'boolean'],
        ]);

        if (! PhoneNumber::plausible($data['phone'])) {
            return back()->withErrors([
                'phone' => 'That does not look like a mobile number. Please check it.',
            ]);
        }

        $phone = PhoneNumber::canonical($data['phone']);

        // ⚠ Same rule as linking: one number, one person. Editing must not be a
        // side door into the collision that linkGuardian() refuses.
        $clash = PhoneNumber::findGuardian($phone);

        if ($clash && $clash->id !== $guardian->id) {
            return back()->withErrors([
                'phone' => $phone . ' already belongs to ' . $clash->name
                    . '. One number can only belong to one guardian.',
            ]);
        }

        $otherChildren = $guardian->children()->where('children.id', '!=', $child->id)->count();

        DB::transaction(function () use ($guardian, $child, $data, $phone, $request) {
            $guardian->update([
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                'app_access' => $request->boolean('app_access', true),
            ]);

            if ($request->boolean('is_primary')) {
                // Only one primary per child.
                $child->guardians()->updateExistingPivot(
                    $child->guardians()->pluck('guardians.id')->all(), ['is_primary' => false]
                );
            }

            $child->guardians()->updateExistingPivot($guardian->id, [
                'relationship' => $data['relationship'],
                'is_primary' => $request->boolean('is_primary'),
            ]);

            // The receiver row carries a copy for the attendant's screen (PART
            // A7) — leaving it stale would show the old name at the kerb.
            AuthorizedReceiver::where('child_id', $child->id)
                ->where('guardian_id', $guardian->id)
                ->update([
                    'name' => $data['name'],
                    'phone' => $phone,
                    'relationship' => $data['relationship'],
                ]);
        });

        SchoolAdminAuditLog::record('guardian_updated', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
            'payload' => ['guardian_id' => $guardian->id, 'phone' => $phone],
        ]);

        return back()->with('ok', $data['name'] . ' updated.'
            . ($otherChildren > 0
                ? " Name, phone and email also changed for {$otherChildren} other "
                    . ($otherChildren === 1 ? 'student' : 'students') . ' they guard.'
                : ''));
    }

    public function removeGuardian(Child $child, Guardian $guardian)
    {
        $this->authorizeChild($child);

        if ($child->guardians()->count() <= 1) {
            return back()->with('error',
                'A child must keep at least one guardian — otherwise no one receives boarding or handover notifications.');
        }

        $child->guardians()->detach($guardian->id);
        AuthorizedReceiver::where('child_id', $child->id)
            ->where('guardian_id', $guardian->id)->delete();

        return back()->with('ok', 'Guardian unlinked.');
    }

    /* ---------------- Authorized receivers (PART A7) ---------------- */

    public function addReceiver(Request $request, Child $child)
    {
        $this->authorizeChild($child);

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'relationship' => ['required', 'string', 'max:32'],
            'phone'        => ['nullable', 'string', 'max:24'],
        ]);

        AuthorizedReceiver::create($data + ['child_id' => $child->id, 'is_active' => true]);

        SchoolAdminAuditLog::record('authorized_receiver_added', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
            'payload'   => $data,
        ]);

        return back()->with('ok', $data['name'] . ' may now collect ' . $child->name . '.');
    }

    public function removeReceiver(AuthorizedReceiver $receiver)
    {
        $child = $receiver->child;
        $this->authorizeChild($child);

        SchoolAdminAuditLog::record('authorized_receiver_removed', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
            'payload'   => ['name' => $receiver->name],
        ]);

        $receiver->delete();

        return back()->with('ok', 'Receiver removed.');
    }

    /* ---------------- Stop assignment ---------------- */

    public function assignStop(Request $request, Child $child)
    {
        $this->authorizeChild($child);

        $request->validate([
            'route_id'          => ['required', 'exists:routes,id'],
            'morning_stop_id'   => ['required', 'exists:route_stops,id'],
            'afternoon_stop_id' => ['nullable', 'exists:route_stops,id'],
        ]);

        $this->syncStopAssignment($child, $request);

        return back()->with('ok', 'Stop assignment saved.');
    }

    /* ---------------- Absence (PART A, school side) ---------------- */

    public function markAbsent(Request $request, Child $child)
    {
        $this->authorizeChild($child);

        $data = $request->validate([
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'directions'  => ['required', 'array', 'min:1'],
            'directions.*'=> [Rule::in(['Morning', 'Afternoon'])],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'reason'      => ['nullable', 'string', 'max:255'],
        ]);

        $cal = $this->calendar();

        // PART A3 — expand across SCHOOL days only. A range spanning a holiday
        // yields fewer rows, and we tell the user so rather than silently differing.
        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($data, $child, $cal, &$created, &$skipped) {
            foreach ($data['directions'] as $direction) {
                $span = $cal->expandRange($data['start_date'], $data['end_date'], $direction);
                $skipped = array_merge($skipped, $span['skipped']);

                foreach ($span['dates'] as $date) {
                    $row = SchoolChildAbsence::firstOrCreate([
                        'child_id'     => $child->id,
                        'service_date' => $date,          // raw string (PART L1)
                        'direction'    => $direction,
                    ], [
                        'bell_tier'    => $child->bell_tier,
                        'marked_by'   => 'school',
                        'marked_by_id'=> auth('web')->id(),
                        'reason_code' => $data['reason_code'] ?? null,
                        'reason'      => $data['reason'] ?? null,
                    ]);

                    if ($row->wasRecentlyCreated) $created++;

                    // PART M14 — a roster already generated for this date does
                    // not know about an absence entered afterwards.
                    app(TripRosterReconciler::class)
                        ->applyAbsence($child, $date, $direction);
                }
            }
        });

        SchoolAdminAuditLog::record('absence_marked_by_school', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
            'payload'   => $data + ['created' => $created],
        ], $data['reason'] ?? null);

        $msg = "Recorded {$created} absence row(s) for {$child->name}.";
        if ($skipped) {
            $msg .= ' Skipped ' . count($skipped) . ' non-school day(s): '
                  . implode(', ', array_slice(array_values($skipped), 0, 3)) . '.';
        }

        return back()->with('ok', $msg);
    }

    public function removeAbsence(SchoolChildAbsence $absence)
    {
        $child = $absence->child;
        $this->authorizeChild($child);

        SchoolAdminAuditLog::record('absence_removed', [
            'school_id' => $child->school_id, 'child_id' => $child->id,
            'payload'   => $absence->only(['service_date', 'direction', 'marked_by', 'reason']),
        ], 'Absence row deleted from the student record.');

        $date = $absence->service_date;        // raw string (PART L1)
        $direction = $absence->direction;

        $absence->delete();

        // PART M14 — only restores the roster row if no other absence row
        // (a parent's, say) still covers that date and direction.
        app(TripRosterReconciler::class)->clearAbsence($child, $date, $direction);

        return back()->with('ok', 'Absence removed.');
    }

    /* ---------------- helpers ---------------- */

    private function authorizeChild(Child $child): void
    {
        abort_unless($child->school_id === $this->activeSchool()->id, 403);
    }

    /**
     * The school's bell tiers, in the order the bells actually ring
     * (Senior 07:40 → Middle 08:15 → Primary 08:45).
     *
     * ⚠ Grouped, not DISTINCT, and that is a portability fix rather than a
     * preference. `SELECT DISTINCT bell_tier ... ORDER BY start_time` asks the
     * engine to order rows by a column it has just collapsed away. SQLite picks
     * an arbitrary row and answers; MySQL refuses outright:
     *
     *     SQLSTATE[HY000]: General error: 3065 Expression #1 of ORDER BY clause
     *     is not in SELECT list ... incompatible with DISTINCT
     *
     * That 500'd the Students list, the Removed list and Restore on MySQL --
     * i.e. on every environment that serves a real school, while passing every
     * local SQLite test. MIN(start_time) states the intent the DISTINCT form
     * only implied: order each tier by its earliest bell.
     *
     * ⚠ Three copies of this query exist (here, RouteController::tiers(), and
     * inline in rosterPayload()). They must stay in agreement -- fix all three
     * or none.
     */
    private function tiers(): array
    {
        return $this->activeSchool()->bellTimes()
            ->select('bell_tier')->groupBy('bell_tier')
            ->orderByRaw('MIN(start_time)')->pluck('bell_tier')->all();
    }

    /**
     * GET /children/bell-tier?grade=8 — what tier does this class ride?
     *
     * ⚠ THE FORM ASKS THE SERVER RATHER THAN WORKING IT OUT ITSELF.
     *
     * Resolving a grade to a tier means ranking the class name, and
     * GradeLevel::rank() exists because that ranking was once a one-liner that
     * read "LKG" as 0 — below Primary's grade_from of 1, so no tier matched and
     * the child was never generated onto a bus at all. A JavaScript copy of
     * that logic would be a second place for the same bug to live, drifting
     * from the first the moment somebody adds a class name to one and not the
     * other. One implementation, asked over the wire.
     */
    public function bellTierFor(Request $request)
    {
        $grade = (string) $request->query('grade', '');

        if (trim($grade) === '') {
            return response()->json(['ok' => false, 'tier' => null, 'message' => null]);
        }

        [$tier, $error] = $this->resolveBellTier($grade, null);

        if (! $tier) {
            return response()->json(['ok' => false, 'tier' => null, 'message' => $error]);
        }

        $band = $this->activeSchool()->bellTimes->first(fn ($b) => $b->bell_tier === $tier);

        return response()->json([
            'ok' => true,
            'tier' => $tier,
            'message' => 'Class ' . $grade . ' rides the ' . $tier . ' bell — '
                . 'classes ' . $band->grade_from . '–' . $band->grade_to
                . ', in at ' . substr((string) $band->start_time, 0, 5)
                . ', out at ' . substr((string) $band->end_time, 0, 5) . '.',
        ]);
    }

    /**
     * ⚠ THE BELL TIER MUST MATCH THE GRADE.
     *
     * `bell_tier` is one of the four keys, and it decides which staggered run a
     * child rides. A grade-8 student saved as "Senior" is collected for the
     * 07:40 bell instead of 08:15 — 35 minutes early — and sent home on the
     * 14:40 dismissal instead of 15:15.
     *
     * The field was validated as a free string, so any tier saved against any
     * grade. It went wrong three separate times on real data before this
     * existed. Now: derive it when blank, and reject it when the school's own
     * band for that tier does not contain the grade.
     *
     * @return array{0: ?string, 1: ?string}  [tier, error]
     */
    private function resolveBellTier(?string $grade, ?string $tier): array
    {
        $bells = $this->activeSchool()->bellTimes;

        $correct = $bells->first(fn ($b) => $b->coversGrade((string) $grade))?->bell_tier;

        // Blank is the normal case from the CSV and the quick-add form.
        if (! $tier) {
            return $correct
                ? [$correct, null]
                : [null, 'No bell tier covers class ' . $grade . '. Add or widen a '
                    . 'bell time under Settings first, or that student will never '
                    . 'be put on a bus.'];
        }

        if ($correct === null) {
            return [null, 'No bell tier covers class ' . $grade . '. Check the '
                . 'grade bands under Settings.'];
        }

        if ($tier !== $correct) {
            $band = $bells->first(fn ($b) => $b->bell_tier === $correct);

            return [null, 'Class ' . $grade . ' belongs to the ' . $correct
                . ' bell (' . $band->grade_from . '–' . $band->grade_to . ', in at '
                . substr((string) $band->start_time, 0, 5) . '), not ' . $tier
                . '. The bell tier decides which run the student rides, so it has '
                . 'to match the grade.'];
        }

        return [$tier, null];
    }

    private function validateChild(Request $request, ?Child $child = null): array
    {
        $school = $this->activeSchool();

        $data = $request->validate([
            'admission_no' => ['required', 'string', 'max:32',
                Rule::unique('children', 'admission_no')
                    ->where('school_id', $school->id)
                    ->ignore($child?->id)],
            'name'         => ['required', 'string', 'max:120'],
            'grade'        => ['required', 'string', 'max:12'],
            'section'      => ['nullable', 'string', 'max:8'],
            'bell_tier'    => ['nullable', 'string', 'max:32'],
            'home_address' => ['nullable', 'string', 'max:500'],
            'blood_group'  => ['nullable', 'string', 'max:8'],
            'medical_notes'=> ['nullable', 'string', 'max:500'],
            'self_release_consent' => ['nullable', 'boolean'],
            'transport_fee_zone'   => ['nullable', 'string', 'max:32'],
        ]);

        [$tier, $error] = $this->resolveBellTier($data['grade'] ?? null, $data['bell_tier'] ?? null);

        if ($error) {
            throw ValidationException::withMessages(['bell_tier' => $error]);
        }

        $data['bell_tier'] = $tier;

        return $data + ['self_release_consent' => $request->boolean('self_release_consent')];
    }

    /**
     * What this child has attached that PART K13 protects.
     *
     * ⚠ One definition, used by both the view (to decide whether to offer
     * permanent deletion) and forceDestroy() (to enforce it). Two copies would
     * eventually disagree, and the direction that disagreement fails is
     * "the button was there and the server refused" — or worse, the reverse.
     *
     * @return array<string,int>
     */
    private function transportHistory(Child $child): array
    {
        return [
            'trips'     => $child->tripRows()->count(),
            'handovers' => $child->handovers()->count(),
            'absences'  => $child->absences()->count(),
            'incidents' => SchoolIncident::where('child_id', $child->id)->count(),
        ];
    }

    /**
     * Link a guardian to a child, creating them if the number is new.
     *
     * ⚠ ONE NUMBER, ONE PERSON. This used to be a bare
     * `firstOrCreate(['phone' => $phone], ['name' => $name])`, which silently
     * reused the existing row and threw the new name away. Entering a second,
     * different parent on a number already in use quietly linked the FIRST
     * parent to the child — so the wrong adult received the notifications and
     * the daily handover code. That is a child-safety failure, not a data-tidiness one.
     *
     * The same person guarding two siblings is the normal case and still works:
     * a matching name on a matching number reuses the record. A DIFFERENT name
     * on that number is rejected and the conflict named.
     */
    private function linkGuardian(Child $child, string $name, string $phone,
                                  ?string $email, string $relationship, bool $primary): void
    {
        // Canonical, so +91 98765 43210 and 9876543210 are the same number
        // rather than two rows that each look unique.
        $phone = PhoneNumber::canonical($phone);

        $existing = PhoneNumber::findGuardian($phone);

        if ($existing && ! $this->samePerson($existing->name, $name)) {
            throw ValidationException::withMessages([
                'guardian_phone' => $phone . ' already belongs to ' . $existing->name
                    . '. One number can only belong to one guardian — they receive '
                    . "that child's alerts and handover code. Use a different number, "
                    . 'or correct the name to match if this is the same person.',
            ]);
        }

        $guardian = $existing ?? Guardian::create([
            'phone' => $phone,
            'name' => $name,
            'email' => $email,
            'app_access' => true,
        ]);

        if ($primary) {
            $child->guardians()->updateExistingPivot(
                $child->guardians()->pluck('guardians.id')->all(), ['is_primary' => false]
            );
        }

        $child->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $relationship, 'is_primary' => $primary],
        ]);

        // Every guardian is automatically an authorized receiver (PART E2).
        AuthorizedReceiver::firstOrCreate(
            ['child_id' => $child->id, 'guardian_id' => $guardian->id],
            ['name' => $guardian->name, 'relationship' => $relationship,
             'phone' => $guardian->phone, 'is_active' => true]
        );
    }

    /**
     * Is this the same human, allowing for how a school office types a name?
     *
     * Deliberately forgiving on case, spacing and punctuation, and strict on
     * everything else. The cost of a false "same person" is the wrong adult
     * collecting a child; the cost of a false "different person" is a school
     * user retyping a name. Those are not comparable, so this errs toward
     * asking.
     */
    private function samePerson(string $a, string $b): bool
    {
        $tidy = fn (string $s) => preg_replace('/[^a-z]/', '',
            strtolower(trim($s)));

        return $tidy($a) === $tidy($b);
    }

    private function syncStopAssignment(Child $child, Request $request): void
    {
        if (! $request->filled('route_id') || ! $request->filled('morning_stop_id')) {
            return;
        }

        $today = $this->today();
        $pm = $request->filled('afternoon_stop_id')
            ? $request->afternoon_stop_id
            : $request->morning_stop_id;

        foreach (['Morning' => $request->morning_stop_id, 'Afternoon' => $pm] as $dir => $stopId) {
            ChildStopAssignment::updateOrCreate(
                ['child_id' => $child->id, 'direction' => $dir],
                ['route_id' => $request->route_id, 'stop_id' => $stopId,
                 'effective_from' => $today, 'is_temporary' => false]
            );
        }
    }
}
