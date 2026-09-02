<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PART J3 / R — the rows that populate the Control Tower exception queue. */
class SchoolTripEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'         => 'array',
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    public const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function trip(): BelongsTo   { return $this->belongsTo(SchoolTrip::class, 'trip_id'); }
    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    /** PART J3 — Critical rows require explicit, named acknowledgement. */
    public function needsAcknowledgement(): bool
    {
        return $this->severity === 'critical' && ! $this->acknowledged_at;
    }

    public function typeLabel(): string
    {
        return match ($this->event_type) {
            'deviation'         => 'Route deviation',
            'unexpected_stop'   => 'Unexpected stop',
            'overspeed'         => 'Overspeed',
            'delay'             => 'Running late',
            'gps_stale'         => 'GPS stale',
            'headcount_mismatch'=> 'Head-count mismatch',
            'sweep_missing'     => 'Sweep not verified',
            'wrong_bus_boarding'=> 'Child on wrong bus',
            'unaccounted_child' => 'Child unaccounted',
            'stop_skipped'      => 'Stop skipped',
            default             => ucfirst(str_replace('_', ' ', $this->event_type)),
        };
    }

    public function ageMinutes(): int
    {
        return (int) ($this->started_at ?? $this->created_at)->diffInMinutes(now());
    }

    public function scopeOpen($q)   { return $q->whereNull('resolved_at'); }
}
