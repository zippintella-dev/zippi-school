<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns Zippi Fleet (Layer 3) writes that no earlier surface needed.
 *
 * Everything else the vehicle app records already had a home — boarding,
 * handovers, sweeps, head counts, stop arrivals and SOS incidents were all in
 * the PART M1/J3 tables from the start. These two were not, because until the
 * crew had an app there was nobody to write them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_trips', function (Blueprint $t) {
            /**
             * PART L3 — the pre-trip checklist, one timestamp per item.
             *
             * ⚠ Stored as evidence, not as a boolean. "The checklist was done"
             * is not a useful answer to "was the fire extinguisher aboard on
             * the 14th"; four ticks each with a server time is. Shape:
             *   [{"key":"first_aid","label":"…","ticked_at":"2026-08-22T06:52:11+05:30"}]
             */
            $t->json('pretrip_checklist')->nullable()->after('route_schedule');

            /**
             * PART L2 — who pressed Begin, and from where.
             *
             * Either device may start a trip and only one may; recording the
             * actor is what makes "it started itself" answerable.
             */
            $t->foreignId('started_by_staff_id')->nullable()->after('started_at')
                ->constrained('school_staff')->nullOnDelete();
            $t->decimal('started_lat', 10, 7)->nullable()->after('started_by_staff_id');
            $t->decimal('started_lng', 10, 7)->nullable()->after('started_lat');

            /** Afternoon: the moment the roster was frozen and the bus left. */
            $t->dateTime('roster_locked_at')->nullable()->after('school_depart_at');
        });

        Schema::table('children', function (Blueprint $t) {
            /**
             * ⚠ THE ANTI-MIS-TAP FIELD. "Blue name tag", "Red name tag ·
             * Aarav's sister", "Wears glasses".
             *
             * The single most common error in the vehicle app is tapping the
             * adjacent row and marking the wrong child, and the case it is
             * worst in is siblings: same surname, adjacent rows, similar faces
             * at 56dp on a phone bouncing in a moving bus. A photo and a class
             * are not always enough — this line is what the attendant reads.
             *
             * Nullable because a school onboarding 200 children will not fill
             * it on day one; the Fleet roster then shows the class alone, which
             * is a weaker row.
             */
            $t->string('distinguishing_detail', 120)->nullable()->after('photo_path');
        });

        Schema::table('school_trip_children', function (Blueprint $t) {
            /**
             * ⚠ INVARIANT #1's CLOCK. Set when the attendant reports that nobody
             * is at a drop stop; the escalation ladder and the point at which
             * *Return to school* unlocks are both read from it.
             *
             * It lives on the trip-child row rather than on the handover row
             * because at this moment there IS no handover — that is the whole
             * problem — and a handover row with a null verification_method
             * would be a custody record asserting nothing.
             */
            $t->dateTime('escalation_started_at')->nullable()->after('alighted_lng');
        });
    }

    public function down(): void
    {
        Schema::table('school_trip_children', function (Blueprint $t) {
            $t->dropColumn('escalation_started_at');
        });

        Schema::table('children', function (Blueprint $t) {
            $t->dropColumn('distinguishing_detail');
        });

        Schema::table('school_trips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('started_by_staff_id');
            $t->dropColumn([
                'pretrip_checklist', 'started_lat', 'started_lng', 'roster_locked_at',
            ]);
        });
    }
};
