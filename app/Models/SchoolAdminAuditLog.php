<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * PART J4 / K1 — APPEND ONLY.
 *
 * Three defences, all required:
 *   1. DB grant — INSERT only on this table for the app role (production).
 *   2. No admin UI that edits or deletes a row.
 *   3. This model-level guard.
 *
 * The point: the admin who performed an action cannot erase the record of it —
 * including the record of a deletion they performed.
 */
class SchoolAdminAuditLog extends Model
{
    protected $table = 'school_admin_audit_log';
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('school_admin_audit_log is append-only (PART K1).');
        });

        static::deleting(function () {
            throw new LogicException('school_admin_audit_log is append-only (PART K1).');
        });

        static::creating(function (self $row) {
            $row->created_at ??= now();
        });
    }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function trip(): BelongsTo   { return $this->belongsTo(SchoolTrip::class, 'trip_id'); }
    public function child(): BelongsTo  { return $this->belongsTo(Child::class); }

    /** Every override goes through here. High-impact actions require notes. */
    public static function record(
        string $action,
        array $context = [],
        ?string $notes = null,
        ?\App\Models\User $actor = null,
    ): self {
        $actor ??= auth()->user();

        return static::create([
            'actor_type' => $actor?->role ?? 'system',
            'actor_id'   => $actor?->id,
            'actor_name' => $actor?->name,
            'action'     => $action,
            'school_id'  => $context['school_id'] ?? $actor?->school_id,
            'trip_id'    => $context['trip_id']   ?? null,
            'child_id'   => $context['child_id']  ?? null,
            'staff_id'   => $context['staff_id']  ?? null,
            'payload'    => $context['payload']   ?? null,
            'notes'      => $notes,
            'ip'         => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
        ]);
    }

    public function actionLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->action));
    }
}
