<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    protected $guarded = [];

    protected $casts = [
        'afternoon_mirrors_morning' => 'boolean',
        'version' => 'integer',
    ];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('sequence');
    }
    public function trips(): HasMany { return $this->hasMany(SchoolTrip::class); }
    public function staffAssignments(): HasMany { return $this->hasMany(RouteStaffAssignment::class); }

    /**
     * PART G1 — stops render in AUTHORED sequence, never nearest-neighbour.
     * When the afternoon mirrors the morning, we reverse the morning list
     * rather than storing a duplicate set.
     */
    public function stopsFor(string $direction)
    {
        $own = $this->stops->where('direction', $direction)->values();
        if ($own->isNotEmpty()) return $own;

        if ($direction === 'Afternoon' && $this->afternoon_mirrors_morning) {
            return $this->stops->where('direction', 'Morning')->reverse()->values();
        }

        return collect();
    }

    public function childCount(): int
    {
        return ChildStopAssignment::where('route_id', $this->id)
            ->where('direction', 'Morning')->distinct('child_id')->count('child_id');
    }
}
