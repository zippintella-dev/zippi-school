<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $guarded = [];

    protected $casts = [
        'require_office_approval_for_parent_collection' => 'boolean',
        'gate_geofence_radius_m' => 'integer',
        'stop_wait_seconds'      => 'integer',
        'drop_wait_seconds'      => 'integer',
        'max_speed_kmph'         => 'integer',
    ];

    public function bellTimes(): HasMany { return $this->hasMany(SchoolBellTime::class); }
    public function calendars(): HasMany { return $this->hasMany(SchoolCalendar::class); }
    public function routes(): HasMany    { return $this->hasMany(Route::class); }
    public function buses(): HasMany     { return $this->hasMany(Bus::class); }
    public function staff(): HasMany     { return $this->hasMany(SchoolStaff::class); }
    public function children(): HasMany  { return $this->hasMany(Child::class); }
    public function trips(): HasMany     { return $this->hasMany(SchoolTrip::class); }

    public function drivers(): HasMany
    {
        return $this->hasMany(SchoolStaff::class)->where('role', 'driver');
    }

    public function attendants(): HasMany
    {
        return $this->hasMany(SchoolStaff::class)->where('role', 'attendant');
    }
}
