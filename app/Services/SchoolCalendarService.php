<?php

namespace App\Services;

use App\Models\School;
use App\Models\SchoolCalendar;
use Carbon\Carbon;

/**
 * PART C3 — the school calendar is load-bearing.
 *
 * The enterprise module listed "holiday calendar auto-leave" as out of scope.
 * For schools it is Phase 1: the calendar is the operating rhythm. The trip
 * generator, the absence range expander, and the parent app's "next school day"
 * resolver all go through here.
 *
 * ⚠ Every date in and out of this service is a raw Y-m-d STRING (PART L1).
 * Never hand a Carbon instance to a query predicate against a DATE column.
 */
class SchoolCalendarService
{
    /** Day types on which trips run at all. */
    public const RUNS_TRIPS = ['school', 'half_day', 'exam', 'event'];

    /** Cache of calendar rows keyed by "school_id:date" for one request. */
    private array $cache = [];

    public function __construct(private ?School $school = null) {}

    public static function for(School $school): self
    {
        return new self($school);
    }

    /** Today in the school's own timezone — never the server's. (PART K4) */
    public function today(): string
    {
        $tz = $this->school?->timezone ?: config('app.timezone');
        return Carbon::now($tz)->toDateString();
    }

    public function row(string $date): ?SchoolCalendar
    {
        $key = ($this->school?->id ?? 0) . ':' . $date;

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = SchoolCalendar::query()
                ->when($this->school, fn ($q) => $q->where('school_id', $this->school->id))
                ->where('date', $date)          // raw string compare (L1)
                ->first();
        }

        return $this->cache[$key];
    }

    public function dayType(string $date): string
    {
        if ($row = $this->row($date)) {
            return $row->day_type;
        }

        // No explicit row → weekends are holidays, weekdays are school days.
        return Carbon::parse($date)->isWeekend() ? 'holiday' : 'school';
    }

    public function label(string $date): ?string
    {
        if ($row = $this->row($date)) return $row->label;
        return Carbon::parse($date)->isWeekend() ? 'Weekend' : null;
    }

    public function isSchoolDay(string $date): bool
    {
        return in_array($this->dayType($date), self::RUNS_TRIPS, true);
    }

    /** Does a trip run on this date in this direction? */
    public function tripsRun(string $date, string $direction): bool
    {
        if (! $this->isSchoolDay($date)) return false;

        $row = $this->row($date);
        if (! $row) return true;

        return $direction === 'Morning'
            ? (bool) $row->morning_trips_run
            : (bool) $row->afternoon_trips_run;
    }

    /**
     * PART A1 — "defaults to tomorrow" actually means the next SCHOOL day,
     * skipping weekends and holidays. A parent marking absence on a Friday
     * evening should land on Monday, not Saturday.
     */
    public function nextSchoolDay(?string $from = null): string
    {
        $cursor = Carbon::parse($from ?? $this->today());

        for ($i = 0; $i < 60; $i++) {
            $cursor->addDay();
            if ($this->isSchoolDay($cursor->toDateString())) {
                return $cursor->toDateString();
            }
        }

        return $cursor->toDateString();   // give up gracefully rather than loop
    }

    /**
     * PART A3 — expand a date range to actual school days only.
     * A Mon–Fri range containing a holiday yields 4 dates, not 5, and the
     * caller tells the parent so ("Absence recorded for 4 school days").
     *
     * @return array{dates: string[], skipped: array<string, string>}
     */
    public function expandRange(string $start, string $end, ?string $direction = null): array
    {
        $dates   = [];
        $skipped = [];

        $cursor = Carbon::parse($start);
        $last   = Carbon::parse($end);

        if ($last->lt($cursor)) {
            return ['dates' => [], 'skipped' => []];
        }

        // PART A3 — cap the range at 60 days (schools have month-long absences;
        // the enterprise 30-day cap is too tight here).
        $guard = 0;

        while ($cursor->lte($last) && $guard++ < 400) {
            $d = $cursor->toDateString();

            if (! $this->isSchoolDay($d)) {
                $skipped[$d] = $this->label($d) ?? ucfirst($this->dayType($d));
            } elseif ($direction && ! $this->tripsRun($d, $direction)) {
                $skipped[$d] = ($this->label($d) ?? 'No trip') . ' (no ' . strtolower($direction) . ' trip)';
            } else {
                $dates[] = $d;
            }

            $cursor->addDay();
        }

        return ['dates' => $dates, 'skipped' => $skipped];
    }

    public function schoolDaysBetween(string $start, string $end): int
    {
        return count($this->expandRange($start, $end)['dates']);
    }

    /**
     * PART C3 — a half day generates morning normally and re-solves the
     * afternoon against override_end_time.
     */
    public function effectiveEndTime(string $date, string $fallback): string
    {
        $row = $this->row($date);
        return $row?->override_end_time ?: $fallback;
    }

    /** Human summary for the dashboard header. */
    public function describe(string $date): string
    {
        $type  = $this->dayType($date);
        $label = $this->label($date);
        $pretty = Carbon::parse($date)->format('D j M Y');

        return match ($type) {
            'school'   => $pretty,
            'half_day' => $pretty . ' · Half day' . ($label ? ' (' . $label . ')' : ''),
            'exam'     => $pretty . ' · Exam day' . ($label ? ' (' . $label . ')' : ''),
            'event'    => $pretty . ' · ' . ($label ?: 'Event'),
            'vacation' => $pretty . ' · Vacation' . ($label ? ' (' . $label . ')' : ''),
            default    => $pretty . ' · ' . ($label ?: 'Holiday'),
        };
    }
}
