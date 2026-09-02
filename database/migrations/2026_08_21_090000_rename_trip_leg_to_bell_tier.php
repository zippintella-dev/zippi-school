<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the old `trip_leg` column to `bell_tier` on every table that carried it.
 *
 * WHY: "leg" already means something else in transport — the hop between two
 * stops — and the codebase used it in BOTH senses. `leg_minutes` inside
 * route_schedule is the hop; the old `trip_leg` was the bell-time tier. Two
 * meanings, one word, in the same JSON payload. `bell_tier` says what it
 * actually is: which staggered bell this run serves (Senior 07:40 / Middle
 * 08:15 / Primary 08:45). `leg` now unambiguously means the stop-to-stop hop.
 *
 * ⚠ DIVERGES FROM THE SPEC's vocabulary. Both specs say `trip_leg`, including
 * the PART M3 API contract. When the mobile API is built, map bell_tier back to
 * the spec's name AT THE BOUNDARY so the published contract still matches; do
 * not reintroduce the old name inside the app.
 *
 * The four keys are unchanged in meaning:
 *   (child|route, service_date, direction, bell_tier)
 *
 * On a FRESH install the create-table migrations already declare `bell_tier`, so
 * every table fails the guard below and this migration is a no-op. It only does
 * work on a database created before the rename.
 */
return new class extends Migration
{
    private const TABLES = [
        'children',
        'school_trips',
        'school_bell_times',
        'routes',
        'route_staff_assignments',
        'route_staff_overrides',
        'school_child_absences',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'trip_leg')) continue;

            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('trip_leg', 'bell_tier');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'bell_tier')) continue;

            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('bell_tier', 'trip_leg');
            });
        }
    }
};
