<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A driver or an attendant. Authenticates on Sanctum by phone + OTP for the
 * Zippi Fleet app (Layer 3) — no password column, same as a Guardian.
 *
 * ⚠ PART P6 — MULTI-ROLE IDENTITY, WITHOUT A NEW TABLE. One phone can be an
 * attendant at Phoenix Greens and a driver at another school, and can be a
 * parent besides. `school_staff` is UNIQUE(school_id, phone), so those are
 * already separate rows keyed by the same number — which means the spec's
 * "role links" are these rows. `roleLinksFor()` is the lookup, and the Fleet
 * app makes the operator pick one before it will show a single child.
 *
 * The parent identity stays completely separate: a Guardian is a different
 * table, a different token, a different guard. One person, two logins, on
 * purpose.
 */
class SchoolStaff extends Model implements AuthenticatableContract
{
    use \Illuminate\Auth\Authenticatable;
    use \Illuminate\Foundation\Auth\Access\Authorizable;
    use \Laravel\Sanctum\HasApiTokens;

    protected $table = 'school_staff';
    protected $guarded = [];

    /**
     * ⚠ `phone` is NOT hidden here because the crew's own app shows it back to
     * them on the role picker. It is masked for every OTHER audience by
     * [maskedPhone] — an ops payload and a parent payload never carry it raw.
     */
    protected $hidden = ['fcm_token', 'remember_token'];

    protected $casts = [
        'heavy_vehicle_years' => 'integer',
    ];

    public const DOCUMENTS = [
        'licence_expiry'         => 'Licence',
        'medical_fitness_expiry' => 'Medical fitness',
    ];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    public function tripsAsDriver(): HasMany
    {
        return $this->hasMany(SchoolTrip::class, 'driver_id');
    }

    public function tripsAsAttendant(): HasMany
    {
        return $this->hasMany(SchoolTrip::class, 'attendant_id');
    }

    /**
     * PART P6 — every active staff row this phone number owns, across schools.
     *
     * ⚠ Compared canonically. `school_staff.phone` was seeded before
     * PhoneNumber::canonical existed and was never back-filled, so a stored
     * "+91 98765 40021" and a typed "9876540021" are the same person and must
     * match. Comparing raw strings here is how a driver is told their number is
     * not registered when it plainly is.
     */
    public static function roleLinksFor(string $phone)
    {
        $canonical = \App\Support\PhoneNumber::canonical($phone);

        return static::with('school')
            ->where('status', 'active')
            ->get()
            ->filter(fn ($s) => \App\Support\PhoneNumber::canonical((string) $s->phone) === $canonical)
            ->values();
    }

    /**
     * ⚠ THE ROLE SPLIT, SERVER SIDE. A driver device renders no child-marking
     * control — and this is the half that matters, because a hidden button is
     * not a permission. Every child-state endpoint in the Fleet API asks this
     * before it writes.
     */
    public function canMarkChildren(): bool
    {
        return $this->role === 'attendant';
    }

    public function isDriver(): bool    { return $this->role === 'driver'; }
    public function isAttendant(): bool { return $this->role === 'attendant'; }

    public function expiringDocuments(int $days = 30): array
    {
        $out = [];
        foreach (self::DOCUMENTS as $col => $label) {
            if (! $this->$col) continue;
            $left = (int) Carbon::today()->diffInDays(Carbon::parse($this->$col), false);
            if ($left <= $days) $out[$label] = $left;
        }
        if ($this->police_verification_status !== 'verified') {
            $out['Police verification'] = null;   // null = missing, not expiring
        }
        return $out;
    }

    /**
     * PART K15 — what BLOCKS this person from being crewed: expired, or never
     * recorded at all.
     *
     * ⚠ Police verification is first and is never merely "expiring". An adult
     * who spends an hour a day alone with other people's children either has a
     * background check on file or does not.
     *
     * ⚠ MISSING COUNTS AS BLOCKING — see the note on Bus::complianceBlockers().
     * A null date must never read as safer than a lapsed one.
     *
     * @return string[] Human sentences, ready to show an operator.
     */
    public function complianceBlockers(): array
    {
        $out = [];

        if ($this->police_verification_status !== 'verified') {
            $out[] = 'Police verification ' . ($this->police_verification_status ?: 'missing');
        }

        foreach (self::DOCUMENTS as $col => $label) {
            // Only a driver's licence is required; an attendant does not drive.
            if ($col === 'licence_expiry' && ! $this->isDriver()) continue;

            if (! $this->$col) {
                $out[] = $label . ' missing';
                continue;
            }

            $left = (int) Carbon::today()->diffInDays(Carbon::parse($this->$col), false);
            if ($left < 0) $out[] = $label . ' expired ' . abs($left) . 'd ago';
        }

        return $out;
    }

    public function complianceStatus(): string
    {
        if ($this->complianceBlockers()) return 'expired';

        $exp = $this->expiringDocuments(30);
        if (empty($exp)) return 'ok';
        $numeric = array_filter($exp, fn ($v) => $v !== null);
        if (empty($numeric)) return 'expiring';
        return min($numeric) < 0 ? 'expired' : 'expiring';
    }

    /** PART K9 — ops never sees a raw number; calls go through the proxy. */
    public function maskedPhone(): string
    {
        return $this->phone ? '•••••' . substr($this->phone, -4) : '—';
    }
}
