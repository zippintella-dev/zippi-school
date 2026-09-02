<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PART M1 — one execution of a route, one direction, one bell tier, one service_date.
 *
 * ⚠ PART L1: `service_date` is a DATE column and MUST stay a raw Y-m-d string.
 * Do NOT add 'service_date' => 'date' to $casts. That was the enterprise L1 bug:
 * Carbon reads it as midnight in APP_TIMEZONE, re-serializes as UTC, and every
 * IST date shifts back one calendar day — breaking every whereDate() predicate.
 * Datetime columns below keep their casts; only DATE columns are bare strings.
 */
class SchoolTrip extends Model
{
    protected $guarded = [];

    protected $casts = [
        'child_ids'               => 'array',
        'route_schedule'          => 'array',
        'pretrip_checklist'       => 'array',   // PART L3 — one timestamp per tick
        'roster_locked_at'        => 'datetime',
        'scheduled_start_at'      => 'datetime',
        'scheduled_end_at'        => 'datetime',
        'school_arrival_deadline' => 'datetime',
        'school_depart_at'        => 'datetime',
        'started_at'              => 'datetime',
        'arrived_at_school_at'    => 'datetime',
        'completed_at'            => 'datetime',
        'sweep_verified_at'       => 'datetime',
        'geofence_override_until' => 'datetime',
        'auto_generated'          => 'boolean',
        'admin_edited'            => 'boolean',
        'headcount_verified'      => 'boolean',
        // 'service_date' => 'date',   ← NEVER. See PART L1.
    ];

    public function school(): BelongsTo    { return $this->belongsTo(School::class); }
    public function route(): BelongsTo     { return $this->belongsTo(Route::class); }
    public function bus(): BelongsTo       { return $this->belongsTo(Bus::class); }
    public function driver(): BelongsTo    { return $this->belongsTo(SchoolStaff::class, 'driver_id'); }
    public function attendant(): BelongsTo { return $this->belongsTo(SchoolStaff::class, 'attendant_id'); }

    /** Who pressed Begin — either device may, and only one may. */
    public function startedBy(): BelongsTo { return $this->belongsTo(SchoolStaff::class, 'started_by_staff_id'); }

    public function tripChildren(): HasMany { return $this->hasMany(SchoolTripChild::class, 'trip_id'); }
    public function stopArrivals(): HasMany
    {
        return $this->hasMany(SchoolStopArrival::class, 'trip_id')->orderBy('sequence');
    }
    public function handovers(): HasMany { return $this->hasMany(SchoolChildHandover::class, 'trip_id'); }
    public function events(): HasMany    { return $this->hasMany(SchoolTripEvent::class, 'trip_id'); }
    public function incidents(): HasMany { return $this->hasMany(SchoolIncident::class, 'trip_id'); }

    /**
     * ⚠ INVARIANT #4's LOCK — an open SOS on this trip freezes every child
     * state change until ops close it. Mirrors FleetTripService, which is the
     * enforcement point; this is for read surfaces that need to SAY so.
     */
    public function sosLocked(): bool
    {
        return $this->incidents()
            ->where('incident_type', 'like', 'sos_%')
            ->whereNull('closed_at')
            ->exists();
    }

    public function isActive(): bool  { return $this->status === 'started'; }
    public function isMorning(): bool { return $this->direction === 'Morning'; }

    public function onBoardCount(): int
    {
        return $this->tripChildren->whereIn('status', ['boarded'])->count();
    }

    public function expectedCount(): int
    {
        return $this->tripChildren->whereNotIn('status', ['absent'])->count();
    }

    /**
     * CRITICAL SAFETY INVARIANT #2 (PART L2) — a trip cannot complete while a
     * child is unaccounted for. Returns the child ids that block completion.
     */
    public function unaccountedChildIds(): array
    {
        $blocking = $this->isMorning()
            ? ['pending', 'boarded']
            : ['pending', 'boarded'];

        return $this->tripChildren
            ->whereIn('status', $blocking)
            ->pluck('child_id')
            ->all();
    }

    /** CRITICAL SAFETY INVARIANT #3 (PART L3) — the sweep is blocking. */
    public function sweepPending(): bool
    {
        return $this->status === 'started' && ! $this->sweep_verified_at;
    }

    public function delayMinutes(): ?int
    {
        if (! $this->scheduled_end_at) return null;
        $ref = $this->arrived_at_school_at ?? $this->completed_at ?? now();
        return (int) $this->scheduled_end_at->diffInMinutes($ref, false);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'scheduled'         => 'Up next',
            'started'           => 'Now running',
            'completed'         => 'Done',
            'cancelled'         => 'Cancelled',
            'transferred'       => 'Moved',
            'emergency_stopped' => 'Stopped',
            default             => ucfirst($this->status),
        };
    }
}
