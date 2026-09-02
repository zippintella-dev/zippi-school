<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART A7 (extended) — a MORNING BOARDING CODE, alongside the afternoon
 * handover code rather than instead of it.
 *
 * ⚠ READ THIS BEFORE CHANGING ANYTHING HERE.
 *
 * The afternoon handover code is a RELEASE check: custody goes school →
 * individual, at a kerb, to whoever turns up. It is the receiver verification
 * behind Invariant #1 and it is not moving. CLAUDE.md records that relocating it
 * to the morning was asked for and refused once already, because it would leave
 * the afternoon drop with no receiver verification anywhere.
 *
 * This is the ADD that the same note sanctions. The morning risk is a different
 * one — the wrong child boards, or boards the wrong bus — and until now it was
 * covered only by the attendant's photo roster.
 *
 * ⚠ WHY A SECOND CODE AND NOT THE SAME ONE.
 *
 * Reusing the handover code would have been less schema and a worse system. The
 * morning code is READ ALOUD AT A PUBLIC KERB every single day, in front of the
 * other families at that stop. If it were the same value, every morning boarding
 * would broadcast that afternoon's release code to everyone within earshot — and
 * the release code is the one thing standing between a child and a stranger.
 *
 * So: two independent values, two independent hashes, both rotating daily.
 * Compromising the code that is spoken out loud must not compromise the one that
 * is not.
 *
 * ⚠ PART L1: boarding_code_date is a DATE column and stays a raw Y-m-d string on
 * the model. Never add a 'date' cast — Carbon would read it as midnight in
 * APP_TIMEZONE, re-serialise as UTC, and shift it back a calendar day, so the
 * code would silently be treated as stale every morning before 05:30 IST.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $t) {
            $t->string('boarding_code_hash')->nullable()->after('handover_code_date');
            $t->date('boarding_code_date')->nullable()->after('boarding_code_hash');
        });

        Schema::table('school_trip_children', function (Blueprint $t) {
            // How this boarding was verified: boarding_code | roster_photo.
            //
            // NULL means the row predates this migration or is not yet boarded.
            // It deliberately does NOT mean "unverified" — a null and a
            // 'roster_photo' are different claims and ops needs to tell them
            // apart when reading an old journey record.
            $t->string('boarding_verification', 32)->nullable()->after('boarded_lng');

            // Wrong codes entered before this boarding succeeded. Mirrors
            // school_child_handovers.code_attempts, and exists for the same
            // reason: a boarding that took four attempts is a fact somebody may
            // need to explain later.
            $t->unsignedTinyInteger('boarding_code_attempts')->default(0)->after('boarding_verification');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $t) {
            $t->dropColumn(['boarding_code_hash', 'boarding_code_date']);
        });

        Schema::table('school_trip_children', function (Blueprint $t) {
            $t->dropColumn(['boarding_verification', 'boarding_code_attempts']);
        });
    }
};
