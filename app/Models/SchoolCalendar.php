<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PART C3 — the school calendar is load-bearing, not a nicety.
 *
 * ⚠ PART L1: `date` is a DATE column and MUST stay a raw Y-m-d string.
 * Adding `'date' => 'date'` to $casts makes Carbon read it as midnight in
 * APP_TIMEZONE and re-serialize as UTC, shifting every IST date back one day.
 * That is the enterprise L1 bug. Do not re-add the cast.
 */
class SchoolCalendar extends Model
{
    protected $guarded = [];

    protected $casts = [
        'morning_trips_run'   => 'boolean',
        'afternoon_trips_run' => 'boolean',
        // 'date' => 'date',  ← NEVER. See PART L1.
    ];

    public const RUNS_TRIPS = ['school', 'half_day', 'exam', 'event'];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    public function isSchoolDay(): bool
    {
        return in_array($this->day_type, self::RUNS_TRIPS, true);
    }
}
