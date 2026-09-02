<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStaffAssignment extends Model
{
    protected $guarded = [];
    protected $casts = [];   // effective_from/to stay raw strings (L1)

    public function route(): BelongsTo     { return $this->belongsTo(Route::class); }
    public function driver(): BelongsTo    { return $this->belongsTo(SchoolStaff::class, 'driver_id'); }
    public function attendant(): BelongsTo { return $this->belongsTo(SchoolStaff::class, 'attendant_id'); }
    public function bus(): BelongsTo       { return $this->belongsTo(Bus::class); }
}
