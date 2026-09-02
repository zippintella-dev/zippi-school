<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PART D — the spine of the child journey record. One row per child per trip. */
class SchoolTripChild extends Model
{
    protected $table = 'school_trip_children';
    protected $guarded = [];

    protected $casts = [
        'boarded_at'            => 'datetime',
        'alighted_at'           => 'datetime',
        // ⚠ Invariant #1's clock — set when nobody is at a drop stop.
        'escalation_started_at' => 'datetime',
        'client_reported_at' => 'datetime',
        'synced_offline'     => 'boolean',
        'rating'             => 'integer',
    ];

    /** Terminal states — PART L15's me_done gate. */
    public const TERMINAL = [
        'absent', 'not_at_stop', 'arrived_at_school',
        'alighted_to_guardian', 'alighted_self_release', 'returned_to_school',
    ];

    public function trip(): BelongsTo  { return $this->belongsTo(SchoolTrip::class, 'trip_id'); }
    public function child(): BelongsTo { return $this->belongsTo(Child::class); }
    public function stop(): BelongsTo  { return $this->belongsTo(RouteStop::class, 'stop_id'); }

    public function isDone(): bool { return in_array($this->status, self::TERMINAL, true); }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'               => 'Waiting',
            'boarded'               => 'On board',
            'not_at_stop'           => 'Not at stop',
            'absent'                => 'Absent',
            'arrived_at_school'     => 'At school',
            'alighted_to_guardian'  => 'Handed over',
            'alighted_self_release' => 'Self release',
            'returned_to_school'    => 'Returned to school',
            default                 => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'boarded', 'arrived_at_school', 'alighted_to_guardian',
            'alighted_self_release'                 => 'ok',
            'pending'                               => 'neutral',
            'absent'                                => 'muted',
            'not_at_stop'                           => 'warn',
            'returned_to_school'                    => 'danger',
            default                                 => 'neutral',
        };
    }
}
