<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PART M2 — the audit trail of the nightly trip generator.
 *
 * ⚠ PART L1: `service_date` is a DATE column and MUST stay a raw Y-m-d string.
 * Do NOT add a 'date' cast — Carbon reads it as midnight in APP_TIMEZONE and
 * re-serialises as UTC, shifting every IST date back a day.
 */
class SchoolTripGenerationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'warnings' => 'array',
        'errors' => 'array',
        'dry_run' => 'boolean',
        'forced' => 'boolean',
        // 'service_date' => 'date',   ← NEVER. See PART L1.
    ];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
