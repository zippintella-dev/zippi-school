<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bus extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_ev'        => 'boolean',
        'has_camera'   => 'boolean',
        'capacity'     => 'integer',
        'speed_kmph'   => 'integer',
        'last_ping_at' => 'datetime',
    ];

    /** PART K15 — the compliance document set tracked per bus. */
    public const DOCUMENTS = [
        'fitness_expiry'        => 'Fitness certificate',
        'permit_expiry'         => 'Permit',
        'insurance_expiry'      => 'Insurance',
        'puc_expiry'            => 'PUC',
        'speed_governor_expiry' => 'Speed governor',
    ];

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    /** Returns [label => days_remaining] for anything expiring within $days. */
    public function expiringDocuments(int $days = 30): array
    {
        $out = [];
        foreach (self::DOCUMENTS as $col => $label) {
            if (! $this->$col) continue;
            $left = (int) Carbon::today()->diffInDays(Carbon::parse($this->$col), false);
            if ($left <= $days) $out[$label] = $left;
        }
        return $out;
    }

    /**
     * PART K15 — the documents that BLOCK this vehicle from being assigned:
     * expired, or never recorded at all.
     *
     * ⚠ MISSING COUNTS AS BLOCKING, and that is the whole point. The older
     * `expiringDocuments()` skips a null column (`if (! $this->$col) continue`),
     * so a bus with no insurance date whatsoever reported "OK" — greener than a
     * bus whose insurance lapsed yesterday. Once compliance gates assignment,
     * that difference is the difference between a rule and a rule with a hole
     * in it: clearing a date would be the way around it.
     *
     * @return string[] Human sentences, ready to show an operator.
     */
    public function complianceBlockers(): array
    {
        $out = [];

        foreach (self::DOCUMENTS as $col => $label) {
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

        return min($exp) < 0 ? 'expired' : 'expiring';
    }

    public function isGpsStale(int $minutes = 5): bool
    {
        return ! $this->last_ping_at || $this->last_ping_at->lt(now()->subMinutes($minutes));
    }
}
