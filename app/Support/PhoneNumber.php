<?php

namespace App\Support;

use App\Models\Guardian;

/**
 * One canonical form for a phone number, used for BOTH the OTP identity and the
 * guardian lookup.
 *
 * WHY THIS EXISTS: a school's records carry the same number written every way —
 * `+91 98765 43210`, `009198…`, `098765…`, and a bare ten digits. The parent
 * app sends whatever its `+91` prefix produces. If the OTP is keyed on one
 * spelling and the guardian row is stored under another, the code verifies
 * correctly and the account lookup then fails — the parent is told "this number
 * is not registered" for a number that plainly is.
 *
 * That is exactly what happened: the redesigned login screen began sending
 * `+911234567890` for a guardian stored as `1234567890`.
 *
 * ⚠ PART P2 — do NOT normalise with `'91' . ltrim($mobile, '91')`. `ltrim`
 * treats `'91'` as a character SET and eats leading 9s and 1s, producing a
 * number the SMS gateway accepts and never delivers.
 */
class PhoneNumber
{
    /** Indian mobile numbers are ten digits. */
    private const NATIONAL_LENGTH = 10;

    /** International prefix, country code, trunk prefix — stripped in that order. */
    private const PREFIXES = ['00', '91', '0'];

    /**
     * Digits only, with any international, country or trunk prefix removed.
     *
     *   +91 98765 43210 → 9876543210
     *   00919876543210  → 9876543210
     *   09876543210     → 9876543210
     *   9876543210      → 9876543210
     *
     * ⚠ A prefix is only stripped when at least ten digits SURVIVE. Without
     * that guard, the perfectly ordinary number 9123456789 would have its
     * leading "91" eaten and become an eight-digit number belonging to nobody —
     * the same class of bug as PART P2's `ltrim($mobile, '91')`, arrived at from
     * the other direction.
     *
     * ⚠ Nothing is truncated. An over-long number is returned as-is so
     * plausible() can reject it, rather than being silently reshaped into a
     * different, valid-looking number the caller never typed.
     */
    public static function canonical(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        foreach (self::PREFIXES as $prefix) {
            if (! str_starts_with($digits, $prefix)) continue;

            $rest = substr($digits, strlen($prefix));

            if (strlen($rest) >= self::NATIONAL_LENGTH) {
                $digits = $rest;
            }
        }

        return $digits;
    }

    /**
     * A number is plausible when it canonicalises to exactly ten digits.
     *
     * ⚠ Check this on the RAW input, BEFORE canonicalising. Canonicalising
     * first would reduce a mistyped 14-digit number to a valid-looking ten and
     * dispatch an OTP to a number nobody entered — worse than simply refusing.
     */
    public static function plausible(string $raw): bool
    {
        return strlen(self::canonical($raw)) === self::NATIONAL_LENGTH;
    }

    /**
     * The guardian whose stored number is the same number, however either side
     * happens to be written.
     *
     * ⚠ Returns null when more than one guardian canonicalises to the same
     * value. Guessing between them could hand a parent another family's
     * children, which is the single worst thing this app could do. An ambiguous
     * roster is the school's data problem to fix, not something to paper over.
     */
    public static function findGuardian(string $raw): ?Guardian
    {
        $canonical = self::canonical($raw);

        if ($canonical === '') return null;

        // Narrow in SQL, then compare canonically in PHP — SQLite and MySQL
        // cannot both strip punctuation in a portable, index-friendly way.
        $matches = Guardian::where('phone', 'like', '%' . $canonical)
            ->get()
            ->filter(fn (Guardian $g) => self::canonical((string) $g->phone) === $canonical)
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
