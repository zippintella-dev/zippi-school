<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PART A — absence rows are keyed by (child, service_date, direction, bell_tier).
 *
 * ⚠ PART L1: `service_date` stays a raw Y-m-d string. No 'date' cast — that is
 * exactly what made undo-leave delete zero rows in the enterprise module.
 */
class SchoolChildAbsence extends Model
{
    protected $guarded = [];
    protected $casts = [];

    public function child(): BelongsTo { return $this->belongsTo(Child::class); }

    public function bannerTone(): string
    {
        return match ($this->marked_by) {
            'parent'                        => 'warn',
            'parent_collecting'             => 'info',
            'attendant_not_at_stop',
            'attendant_not_boarded_at_school' => 'amber',
            'holiday'                       => 'ok',
            default                         => 'muted',
        };
    }

    public function markedByLabel(): string
    {
        return match ($this->marked_by) {
            'parent'                          => 'Parent',
            'parent_collecting'               => 'Parent collecting',
            'school'                          => 'School',
            'admin'                           => 'Zippi ops',
            'attendant_not_at_stop'           => 'Not at stop',
            'attendant_not_boarded_at_school' => 'Did not board',
            'holiday'                         => 'Holiday',
            default                           => ucfirst($this->marked_by),
        };
    }

    /** PART A4 — only parent-created rows are undoable by a parent. */
    public function isUndoableByParent(): bool
    {
        return $this->marked_by === 'parent' || $this->marked_by === 'parent_collecting';
    }
}
