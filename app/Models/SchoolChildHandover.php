<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PART A7 — the custody record. This is what answers a dispute. */
class SchoolChildHandover extends Model
{
    protected $guarded = [];

    protected $casts = [
        'escalation_trail'  => 'array',
        'verified_offline'  => 'boolean',
        'code_attempts'     => 'integer',
    ];

    public function trip(): BelongsTo     { return $this->belongsTo(SchoolTrip::class, 'trip_id'); }
    public function child(): BelongsTo    { return $this->belongsTo(Child::class); }
    public function stop(): BelongsTo     { return $this->belongsTo(RouteStop::class, 'stop_id'); }
    public function receiver(): BelongsTo { return $this->belongsTo(Guardian::class, 'receiver_guardian_id'); }
    public function staff(): BelongsTo    { return $this->belongsTo(SchoolStaff::class, 'acted_by_staff_id'); }

    public function methodLabel(): string
    {
        return match ($this->verification_method) {
            'handover_code'      => 'Handover code',
            'authorized_person'  => 'Authorized person',
            'self_release'       => 'Self release',
            'admin_voice'        => 'Verified by ops (voice)',
            'returned_to_school' => 'Returned to school',
            default              => ucfirst(str_replace('_', ' ', $this->verification_method)),
        };
    }
}
