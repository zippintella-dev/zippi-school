<?php

namespace App\Models;

use App\Support\GradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolBellTime extends Model
{
    protected $guarded = [];

    /**
     * PART L1 — effective_from / effective_to are DATE columns and stay raw
     * Y-m-d strings. Do NOT add 'date' casts here.
     */
    protected $casts = [];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    /**
     * Does this tier's grade band contain the given class?
     *
     * Both the class and the band bounds are resolved through GradeLevel, so a
     * band may be written "Nursery"–"5" and still cover LKG and UKG. Class 3
     * falls inside a band of 1–5 exactly as before.
     *
     * ⚠ An unrecognised class name matches NOTHING rather than falling through
     * to a default. This used to read the grade with
     * `filter_var(FILTER_SANITIZE_NUMBER_INT)`, which turned "LKG" into 0 and
     * silently excluded that child from every tier — and therefore from trip
     * generation. Returning false loudly is right; guessing is not.
     */
    public function coversGrade(string $grade): bool
    {
        $g = GradeLevel::rank($grade);

        if ($g === null) return false;

        $from = GradeLevel::rank($this->grade_from);
        $to   = GradeLevel::rank($this->grade_to);

        if ($from !== null && $g < $from) return false;
        if ($to   !== null && $g > $to)   return false;

        return true;
    }
}
