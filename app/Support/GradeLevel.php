<?php

namespace App\Support;

/**
 * Turns a class name into a sortable rank, so a bell tier can span named
 * pre-primary classes as well as numbered ones.
 *
 * WHY THIS EXISTS: `bell_tier` decides which staggered run a child rides, and
 * it is resolved by asking each tier whether it covers the child's grade. That
 * comparison used to be `(int) filter_var($grade, FILTER_SANITIZE_NUMBER_INT)`,
 * which reads "LKG" as 0 — below Primary's `grade_from = 1`, so **no tier
 * matched at all**. A child in LKG would have a stop, appear on the roster, and
 * simply never be generated onto a bus. The generator warns, but a warning in a
 * log is not something anyone reads at 07:30.
 *
 * Numbered classes keep their own value (class 5 → 5) so nothing about the
 * existing bands changes. Pre-primary sits BELOW 1 on a negative scale, in the
 * order schools actually run them.
 *
 * ⚠ An unrecognised name returns null and matches nothing — deliberately. A
 * class this does not know about must surface as "no tier" rather than be
 * guessed into the wrong bus. Add the name here instead.
 */
class GradeLevel
{
    /**
     * Pre-primary, lowest first. Aliases are generous on purpose: the cost of
     * an unknown name is a child left off a bus, the cost of an extra alias is
     * nothing.
     */
    private const NAMED = [
        'playgroup' => -4,
        'playgroupclass' => -4,
        'prekg' => -3,
        'prek' => -3,
        'nursery' => -2,
        'lkg' => -1,
        'pp1' => -1,
        'kg1' => -1,
        'ukg' => 0,
        'pp2' => 0,
        'kg2' => 0,
        'kg' => 0,
        'kindergarten' => 0,
    ];

    /** @return int|null null when the class name is not recognised */
    public static function rank(?string $grade): ?int
    {
        if ($grade === null) return null;

        $raw = trim($grade);
        if ($raw === '') return null;

        // A plain number is its own rank — class 5 is 5, and stays 5.
        if (preg_match('/^\d+$/', $raw)) {
            return (int) $raw;
        }

        $key = preg_replace('/[^a-z0-9]/', '', strtolower($raw));

        if (isset(self::NAMED[$key])) {
            return self::NAMED[$key];
        }

        // "Class 7", "Grade 7", "7th", "VII-B" → take the digits if there are any.
        if (preg_match('/\d+/', $raw, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    /** Human label for the lowest class the school runs, used in settings copy. */
    public static function isPrePrimary(?string $grade): bool
    {
        $rank = self::rank($grade);

        return $rank !== null && $rank < 1;
    }
}
