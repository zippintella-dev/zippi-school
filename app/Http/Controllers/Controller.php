<?php

namespace App\Http\Controllers;

use App\Models\Bus;
use App\Models\School;
use App\Models\SchoolStaff;
use App\Models\SchoolTripEvent;
use App\Services\SchoolCalendarService;

abstract class Controller
{
    protected ?School $school = null;

    /**
     * PART J6 / S5 — every query is school-scoped. Zippi staff may pick a school
     * (defaulting to the first); a school user is pinned to their own.
     */
    protected function activeSchool(): School
    {
        if ($this->school) return $this->school;

        $user = auth('web')->user();

        $this->school = $user && ! $user->isZippi() && $user->school_id
            ? School::findOrFail($user->school_id)
            : School::query()
                ->when(request('school_id'), fn ($q) => $q->whereKey(request('school_id')))
                ->orderBy('id')
                ->firstOrFail();

        return $this->school;
    }

    protected function calendar(): SchoolCalendarService
    {
        return SchoolCalendarService::for($this->activeSchool());
    }

    /** Today in the school's timezone — never the server's (PART K4). */
    protected function today(): string
    {
        return $this->calendar()->today();
    }

    /**
     * Shared chrome: the sidebar's alert badges. Kept here so every page shows
     * the same numbers as the live board.
     */
    protected function view(string $name, array $data = [])
    {
        $school = $this->activeSchool();

        $openAlerts = SchoolTripEvent::where('school_id', $school->id)
            ->whereNull('resolved_at')
            ->whereIn('severity', ['critical', 'high'])
            ->count();

        $compliance = 0;
        foreach (Bus::where('school_id', $school->id)->get() as $b) {
            if ($b->complianceStatus() !== 'ok') $compliance++;
        }
        foreach (SchoolStaff::where('school_id', $school->id)->get() as $s) {
            if ($s->complianceStatus() !== 'ok') $compliance++;
        }

        // Sidebar badge. A school that removes a student should be able to see
        // at a glance that the list is not empty, without opening the page.
        $removedStudents = \App\Models\Child::where('school_id', $school->id)
            ->where('status', '!=', 'active')
            ->count();

        return view($name, array_merge([
            'activeSchool'   => $school,
            'navOpenAlerts'  => $openAlerts,
            'navCompliance'  => $compliance,
            'navRemovedStudents' => $removedStudents,
        ], $data));
    }
}
