<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PART Q / A7 — incidents live forever; closing one requires a narrative. */
class SchoolIncident extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_drill'        => 'boolean',
        'is_silent'       => 'boolean',
        'acknowledged_at' => 'datetime',
        'closed_at'       => 'datetime',
    ];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function trip(): BelongsTo   { return $this->belongsTo(SchoolTrip::class, 'trip_id'); }
    public function child(): BelongsTo  { return $this->belongsTo(Child::class); }

    public function isOpen(): bool { return ! $this->closed_at; }

    public function typeLabel(): string
    {
        return match ($this->incident_type) {
            'sos_accident'             => 'SOS — Accident',
            'sos_breakdown'            => 'SOS — Breakdown',
            'sos_medical'              => 'SOS — Medical',
            'sos_security'             => 'SOS — Security',
            'sos_fire'                 => 'SOS — Fire',
            'sos_other'                => 'SOS — Other',
            'child_returned_to_school' => 'Child returned to school',
            'unaccounted_child'        => 'Child unaccounted',
            'sweep_not_performed'      => 'Sweep not performed',
            default                    => ucfirst(str_replace('_', ' ', $this->incident_type)),
        };
    }
}
