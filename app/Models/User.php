<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * PART J6 — three authorization tiers, plus the Zippi-internal ops split (PART T5).
 *
 *   zippi_admin   — all schools, all actions
 *   ops_lead      — all schools, all overrides, can close incidents
 *   ops_agent     — all schools, may acknowledge + act, not force-complete
 *   school_user   — own school only; may mark absent, close days, view records
 *   operator_user — own buses/staff, current-trip manifest only (PART K12)
 */
#[Fillable(['school_id', 'name', 'role', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = [
        'zippi_admin'   => 'Zippi admin',
        'ops_lead'      => 'Ops lead',
        'ops_agent'     => 'Ops agent',
        'school_user'   => 'School user',
        'operator_user' => 'Operator user',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    public function isZippi(): bool
    {
        return in_array($this->role, ['zippi_admin', 'ops_lead', 'ops_agent'], true);
    }

    public function isOpsLead(): bool { return in_array($this->role, ['zippi_admin', 'ops_lead'], true); }

    /** PART J6 — school users cannot force-complete a trip or override a geo-fence. */
    public function canOverrideTrips(): bool { return $this->isZippi(); }

    public function canCloseIncidents(): bool { return $this->isOpsLead(); }

    public function roleLabel(): string { return self::ROLES[$this->role] ?? $this->role; }

    /** Schools this user may see. null = all (Zippi staff). */
    public function scopedSchoolId(): ?int
    {
        return $this->isZippi() ? null : $this->school_id;
    }
}
