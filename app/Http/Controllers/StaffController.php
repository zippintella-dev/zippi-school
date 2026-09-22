<?php

namespace App\Http\Controllers;

use App\Models\RouteStaffAssignment;
use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolStaff;
use App\Models\SchoolTrip;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->activeSchool();
        $role = $request->get('role', 'all');

        $staff = SchoolStaff::where('school_id', $school->id)
            ->when($role !== 'all', fn ($q) => $q->where('role', $role))
            ->orderBy('role')->orderBy('name')->get();

        return $this->view('staff.index', ['staff' => $staff, 'role' => $role]);
    }

    /**
     * One crew member's record — the full contact number, every compliance
     * document with its date, the routes they crew, and their recent trips.
     *
     * ⚠ THE NUMBER IS SHOWN IN FULL HERE, AND ONLY HERE.
     *
     * `maskedPhone()` says "PART K9 — ops never sees a raw number; calls go
     * through the proxy". K9's subject is the PARENT-facing and live-board
     * payloads: a family must never be handed the driver's personal mobile, and
     * `SafetyInvariantsTest` and `ParentAppTest` both assert that.
     *
     * This screen is the transport office looking at its own employment record
     * — the same office that TYPED this number into the add-crew form. Masking
     * it back to them protects nobody, and because the call proxy K9 assumes
     * does not exist yet, it left the office with no way to reach a driver
     * mid-route. That is a safety problem of its own.
     *
     * ⚠ If a proxy is ever built, this is the screen that should start dialling
     * through it — not the screen that should go back to hiding the number.
     */
    public function show(SchoolStaff $member)
    {
        abort_unless($member->school_id === $this->activeSchool()->id, 403);

        $assignments = RouteStaffAssignment::with('route')
            ->where(fn ($q) => $q->where('driver_id', $member->id)
                                 ->orWhere('attendant_id', $member->id))
            ->get()
            ->sortBy(fn ($a) => [$a->route?->code, $a->direction]);

        $trips = SchoolTrip::with('route')
            ->where(fn ($q) => $q->where('driver_id', $member->id)
                                 ->orWhere('attendant_id', $member->id))
            ->orderByDesc('service_date')
            ->orderByDesc('scheduled_start_at')
            ->limit(20)
            ->get();

        return $this->view('staff.show', [
            'member'      => $member,
            'assignments' => $assignments,
            'trips'       => $trips,
        ]);
    }

    public function store(Request $request)
    {
        $school = $this->activeSchool();
        $data = $this->rules($request, null, $school->id);

        $member = SchoolStaff::create($data + ['school_id' => $school->id, 'status' => 'active']);

        SchoolAdminAuditLog::record('staff_created', [
            'school_id' => $school->id, 'staff_id' => $member->id,
            'payload'   => ['name' => $member->name, 'role' => $member->role],
        ]);

        return back()->with('ok', $member->name . ' added as ' . $member->role . '.');
    }

    public function update(Request $request, SchoolStaff $member)
    {
        abort_unless($member->school_id === $this->activeSchool()->id, 403);

        $member->update($this->rules($request, $member, $member->school_id));

        return back()->with('ok', $member->name . ' updated.');
    }

    public function destroy(SchoolStaff $member)
    {
        abort_unless($member->school_id === $this->activeSchool()->id, 403);

        $trips = SchoolTrip::where('driver_id', $member->id)
            ->orWhere('attendant_id', $member->id)->count();

        if ($trips > 0) {
            $member->update(['status' => 'inactive']);
            return back()->with('ok', $member->name . ' marked inactive. Trip history is retained.');
        }

        SchoolAdminAuditLog::record('staff_deleted', [
            'school_id' => $member->school_id,
            'payload'   => ['name' => $member->name, 'role' => $member->role],
        ]);
        $member->delete();

        return back()->with('ok', 'Staff member removed.');
    }

    /**
     * ⚠ A LICENCE BELONGS TO A DRIVER, AND ONLY TO A DRIVER.
     *
     * The three driver fields are hidden for an attendant in both forms, but a
     * hidden input still posts, and `role` is editable — so a driver moved to
     * attendant would otherwise keep a licence number, an expiry and a heavy
     * vehicle history on their record forever.
     *
     * That is not merely untidy. `complianceBlockers()` reads the licence only
     * for drivers, so a stale expired licence sits there contradicting the
     * "clean" badge on the same screen, and the first person to notice has to
     * work out which of the two the system believes. Strip them here, where
     * every write to this table already passes.
     */
    private function rules(Request $request, ?SchoolStaff $member, int $schoolId): array
    {
        $data = $this->validated($request, $member, $schoolId);

        if ($data['role'] === 'attendant') {
            $data['licence_no'] = null;
            $data['licence_expiry'] = null;
            $data['heavy_vehicle_years'] = null;
        }

        return $data;
    }

    private function validated(Request $request, ?SchoolStaff $member, int $schoolId): array
    {
        return $request->validate([
            'role'  => ['required', Rule::in(['driver', 'attendant'])],
            'name'  => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:24',
                         Rule::unique('school_staff', 'phone')
                             ->where('school_id', $schoolId)->ignore($member?->id)],
            'gender'     => ['nullable', 'string', 'max:12'],
            'licence_no' => ['nullable', 'string', 'max:40'],
            'licence_expiry' => ['nullable', 'date'],
            'heavy_vehicle_years' => ['nullable', 'integer', 'between:0,60'],
            'police_verification_status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
            'police_verified_on' => ['nullable', 'date'],
            'medical_fitness_expiry' => ['nullable', 'date'],
            'training_completed_on'  => ['nullable', 'date'],
        ]);
    }
}
