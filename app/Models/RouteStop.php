<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteStop extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sequence'  => 'integer',
        'latitude'  => 'string',
        'longitude' => 'string',
    ];

    public function route(): BelongsTo { return $this->belongsTo(Route::class); }
    public function childAssignments(): HasMany { return $this->hasMany(ChildStopAssignment::class, 'stop_id'); }

    public function childCount(string $direction = 'Morning'): int
    {
        return $this->childAssignments()->where('direction', $direction)->count();
    }
}
