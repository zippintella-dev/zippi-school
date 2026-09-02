<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PART O2 — one-day stand-in. ⚠ service_date stays a raw string (L1). */
class RouteStaffOverride extends Model
{
    protected $guarded = [];
    protected $casts = [];

    public function route(): BelongsTo     { return $this->belongsTo(Route::class); }
    public function driver(): BelongsTo    { return $this->belongsTo(SchoolStaff::class, 'driver_id'); }
    public function attendant(): BelongsTo { return $this->belongsTo(SchoolStaff::class, 'attendant_id'); }
    public function bus(): BelongsTo       { return $this->belongsTo(Bus::class); }
}
