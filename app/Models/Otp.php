<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PART P1–P3 — a single one-time code. See OtpService for the rules around it.
 */
class Otp extends Model
{
    protected $guarded = [];

    /** The plaintext code never lives on the model — only its hash. */
    protected $hidden = ['code_hash'];

    protected $casts = [
        'locked_until' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function isUsable(): bool
    {
        return ! $this->consumed_at && ! $this->isExpired() && ! $this->isLocked();
    }
}
