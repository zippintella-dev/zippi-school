<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The rider. Note: a Child has ZERO logins — see the doc's Context table row 1.
 * Everything "rider-facing" resolves to guardians through child_guardian_links.
 */
class Child extends Model
{
    protected $guarded = [];

    protected $hidden = ['handover_code_hash', 'boarding_code_hash'];

    protected $casts = [
        'self_release_consent' => 'boolean',
        // 'handover_code_date' / 'boarding_code_date' / date_of_birth stay raw
        // strings (PART L1)
    ];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'child_guardian_links')
            ->withPivot(['relationship', 'is_primary'])
            ->withTimestamps();
    }

    public function authorizedReceivers(): HasMany { return $this->hasMany(AuthorizedReceiver::class); }
    public function stopAssignments(): HasMany    { return $this->hasMany(ChildStopAssignment::class); }
    public function absences(): HasMany           { return $this->hasMany(SchoolChildAbsence::class); }
    public function tripRows(): HasMany           { return $this->hasMany(SchoolTripChild::class); }
    public function handovers(): HasMany          { return $this->hasMany(SchoolChildHandover::class); }

    public function primaryGuardian(): ?Guardian
    {
        return $this->guardians->firstWhere('pivot.is_primary', true) ?? $this->guardians->first();
    }

    public function assignmentFor(string $direction): ?ChildStopAssignment
    {
        return $this->stopAssignments->firstWhere('direction', $direction);
    }

    public function displayName(): string
    {
        return $this->name . ' (' . $this->grade . ($this->section ? '-' . $this->section : '') . ')';
    }

    /**
     * PART A7 — the day's handover code, in plaintext, for the guardian's eyes.
     *
     * The DB stores only a HASH: the attendant's app verifies against it, and a
     * database read must not hand someone a working collection code for every
     * child in the school. But the guardian has to actually SEE the code, so the
     * plaintext lives in the cache for the day only, alongside the stored hash.
     *
     * Rotates daily (`handover_code_date`). If the cache is cleared mid-day the
     * code is reissued — hash and plaintext together, so the attendant's check
     * and the parent's screen never disagree.
     *
     * ⚠ PART L1: handover_code_date is a DATE column and stays a raw Y-m-d string.
     * ⚠ PART K9: never put this in an ops-facing payload or a push body.
     */
    public function todaysHandoverCode(): string
    {
        $today = \Carbon\Carbon::now($this->school?->timezone ?: config('app.timezone'))
            ->toDateString();

        $key = "handover-code:{$this->id}:{$today}";

        if ($this->handover_code_date === $today && ($cached = \Cache::get($key))) {
            return $cached;
        }

        $code = (string) random_int(1000, 9999);      // PART P1 — never static

        $this->forceFill([
            'handover_code_hash' => \Hash::make($code),
            'handover_code_date' => $today,           // raw string (L1)
        ])->save();

        \Cache::put($key, $code, \Carbon\Carbon::parse($today . ' 23:59:59'));

        return $code;
    }

    /** PART A7 — what the attendant's app will call to check a code at the stop. */
    public function verifyHandoverCode(string $code): bool
    {
        return $this->handover_code_hash
            && \Hash::check($code, $this->handover_code_hash);
    }

    /**
     * PART A7 (extended) — the day's MORNING BOARDING code.
     *
     * ⚠ A DIFFERENT VALUE FROM todaysHandoverCode(), deliberately, and the two
     * must never be merged into one.
     *
     * This code is read aloud at a public kerb every morning, in front of the
     * other families waiting at that stop. The handover code is the receiver
     * verification behind Invariant #1 — the one thing between a child and a
     * stranger at the afternoon drop. If they were the same value, every morning
     * boarding would broadcast that afternoon's release code to everyone within
     * earshot.
     *
     * Same storage shape as the handover code otherwise: hash in the database,
     * plaintext in the cache for the day so the guardian can actually read it,
     * rotating daily. Reissued together if the cache is cleared mid-day, so the
     * attendant's check and the parent's screen never disagree.
     *
     * ⚠ PART L1: boarding_code_date is a DATE column and stays a raw Y-m-d string.
     * ⚠ PART K9: never put this in an ops-facing payload or a push body.
     */
    public function todaysBoardingCode(): string
    {
        $today = \Carbon\Carbon::now($this->school?->timezone ?: config('app.timezone'))
            ->toDateString();

        $key = "boarding-code:{$this->id}:{$today}";

        if ($this->boarding_code_date === $today && ($cached = \Cache::get($key))) {
            return $cached;
        }

        $code = (string) random_int(1000, 9999);      // PART P1 — never static

        $this->forceFill([
            'boarding_code_hash' => \Hash::make($code),
            'boarding_code_date' => $today,           // raw string (L1)
        ])->save();

        \Cache::put($key, $code, \Carbon\Carbon::parse($today . ' 23:59:59'));

        return $code;
    }

    /** What the attendant's app calls to check a boarding code at the stop. */
    public function verifyBoardingCode(string $code): bool
    {
        return $this->boarding_code_hash
            && \Hash::check($code, $this->boarding_code_hash);
    }

    /**
     * PART A7 — self-release is gated on BOTH a signed consent and the school's
     * minimum grade. Either one missing blocks it.
     */
    public function canSelfRelease(): bool
    {
        if (! $this->self_release_consent) return false;
        $min = $this->school?->self_release_min_grade;
        if (! $min) return false;

        return (int) filter_var($this->grade, FILTER_SANITIZE_NUMBER_INT)
             >= (int) filter_var($min, FILTER_SANITIZE_NUMBER_INT);
    }
}
