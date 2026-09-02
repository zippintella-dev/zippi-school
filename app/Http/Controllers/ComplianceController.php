<?php

namespace App\Http\Controllers;

use App\Models\Bus;
use App\Models\SchoolStaff;

/**
 * PART K15 — the regulatory compliance board.
 *
 * This is school-facing as well as ops-facing: the school is the legally
 * accountable party, and being handed a green/amber/red board is on its own
 * a reason to choose Zippi.
 */
class ComplianceController extends Controller
{
    public function index()
    {
        $school = $this->activeSchool();

        $buses = Bus::where('school_id', $school->id)->orderBy('reg_no')->get()
            ->map(fn (Bus $b) => [
                'subject' => $b->reg_no,
                'kind'    => 'Bus',
                'meta'    => trim(($b->model ?: '') . ($b->is_ev ? ' · Electric' : '')),
                'status'  => $b->complianceStatus(),
                'items'   => $b->expiringDocuments(30),
                'all'     => collect(Bus::DOCUMENTS)->map(fn ($label, $col) => [
                                'label' => $label, 'date' => $b->$col,
                             ])->values(),
            ]);

        $staff = SchoolStaff::where('school_id', $school->id)->orderBy('name')->get()
            ->map(fn (SchoolStaff $s) => [
                'subject' => $s->name,
                'kind'    => ucfirst($s->role),
                'meta'    => $s->role === 'driver'
                                ? ($s->heavy_vehicle_years . ' yrs heavy vehicle')
                                : ($s->gender ? ucfirst($s->gender) . ' attendant' : 'Attendant'),
                'status'  => $s->complianceStatus(),
                'items'   => $s->expiringDocuments(30),
                'all'     => collect(SchoolStaff::DOCUMENTS)->map(fn ($label, $col) => [
                                'label' => $label, 'date' => $s->$col,
                             ])->push([
                                'label' => 'Police verification',
                                'date'  => $s->police_verification_status === 'verified'
                                             ? $s->police_verified_on : null,
                             ])->values(),
            ]);

        $all = $buses->concat($staff);

        return $this->view('compliance.index', [
            'rows'     => $all->sortBy(fn ($r) => ['expired' => 0, 'expiring' => 1, 'ok' => 2][$r['status']])->values(),
            'expired'  => $all->where('status', 'expired')->count(),
            'expiring' => $all->where('status', 'expiring')->count(),
            'ok'       => $all->where('status', 'ok')->count(),
        ]);
    }
}
