<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildStopAssignment extends Model
{
    protected $guarded = [];

    /** PART L1 — effective_from / effective_to stay raw Y-m-d strings. */
    protected $casts = ['is_temporary' => 'boolean'];

    public function child(): BelongsTo { return $this->belongsTo(Child::class); }
    public function route(): BelongsTo { return $this->belongsTo(Route::class); }
    public function stop(): BelongsTo  { return $this->belongsTo(RouteStop::class, 'stop_id'); }

    public function isEffectiveOn(string $date): bool
    {
        if ($this->effective_from && $date < $this->effective_from) return false;
        if ($this->effective_to   && $date > $this->effective_to)   return false;
        return true;
    }
}
