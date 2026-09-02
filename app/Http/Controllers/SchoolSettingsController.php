<?php

namespace App\Http\Controllers;

use App\Models\SchoolAdminAuditLog;
use App\Models\SchoolBellTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PART S1 steps 1–2 — school profile and the tiered bell schedule.
 *
 * The bell times are not cosmetic: they define bell_tier, which is a first-class
 * scheduling dimension (PART C1). A bus runs Senior at 07:40, Middle at 08:15
 * and Primary at 08:45, and every operational row is keyed by the bell tier.
 */
class SchoolSettingsController extends Controller
{
    public function edit()
    {
        $school = $this->activeSchool();

        return $this->view('settings.edit', [
            'school' => $school,
            'bells'  => $school->bellTimes()->orderBy('start_time')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $school = $this->activeSchool();

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:160'],
            'code'                   => ['required', 'string', 'max:32',
                                          Rule::unique('schools', 'code')->ignore($school->id)],
            'address'                => ['nullable', 'string', 'max:500'],
            'latitude'               => ['required', 'numeric', 'between:-90,90'],
            'longitude'              => ['required', 'numeric', 'between:-180,180'],
            'contact_phone'          => ['nullable', 'string', 'max:24'],
            'gate_geofence_radius_m' => ['required', 'integer', 'between:50,1000'],
            'stop_wait_seconds'      => ['required', 'integer', 'between:30,600'],
            'drop_wait_seconds'      => ['required', 'integer', 'between:60,900'],
            'max_speed_kmph'         => ['required', 'integer', 'between:20,80'],
            'self_release_min_grade' => ['nullable', 'string', 'max:8'],
            'require_office_approval_for_parent_collection' => ['nullable', 'boolean'],
        ]);

        $data['require_office_approval_for_parent_collection'] =
            $request->boolean('require_office_approval_for_parent_collection');

        $before = $school->only(array_keys($data));
        $school->update($data);

        SchoolAdminAuditLog::record('school_settings_updated', [
            'school_id' => $school->id,
            'payload'   => ['before' => $before, 'after' => $data],
        ]);

        return back()->with('ok', 'School settings saved.');
    }

    public function storeBellTime(Request $request)
    {
        $school = $this->activeSchool();

        $data = $request->validate([
            'bell_tier'   => ['required', 'string', 'max:32'],
            'grade_from' => ['nullable', 'string', 'max:8'],
            'grade_to'   => ['nullable', 'string', 'max:8'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time'   => ['required', 'date_format:H:i'],
        ]);

        // A school day never crosses midnight — unlike the enterprise night
        // shift, where shift_end < shift_start was the night-shift signal.
        if ($data['end_time'] <= $data['start_time']) {
            return back()->withInput()->with('error',
                'Dismissal must be after the start bell — a school day does not cross midnight.');
        }

        $data['school_id'] = $school->id;
        $data['effective_from'] = now($school->timezone)->startOfYear()->toDateString();

        SchoolBellTime::create($data);

        return back()->with('ok', "Bell time added for the {$data['bell_tier']} bell tier.");
    }

    public function destroyBellTime(SchoolBellTime $bell)
    {
        abort_unless($bell->school_id === $this->activeSchool()->id, 403);

        $tier = $bell->bell_tier;
        $bell->delete();

        return back()->with('ok', "Removed the {$tier} bell time.");
    }
}
