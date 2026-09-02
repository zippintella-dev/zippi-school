<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PART O2 — ⚠ service_date stays a raw string (L1). */
class ChildStopOverride extends Model
{
    protected $guarded = [];
    protected $casts = [];

    public function child(): BelongsTo { return $this->belongsTo(Child::class); }
    public function stop(): BelongsTo  { return $this->belongsTo(RouteStop::class, 'stop_id'); }
    public function route(): BelongsTo { return $this->belongsTo(Route::class); }
}
