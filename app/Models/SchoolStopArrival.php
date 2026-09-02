<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolStopArrival extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_at'         => 'datetime',
        'arrived_at'           => 'datetime',
        'departed_at'          => 'datetime',
        'approach_notified_at' => 'datetime',
        'sequence'             => 'integer',
    ];

    public function trip(): BelongsTo { return $this->belongsTo(SchoolTrip::class, 'trip_id'); }
    public function stop(): BelongsTo { return $this->belongsTo(RouteStop::class, 'stop_id'); }

    public function driftMinutes(): ?int
    {
        if (! $this->scheduled_at || ! $this->arrived_at) return null;
        return (int) $this->scheduled_at->diffInMinutes($this->arrived_at, false);
    }
}
