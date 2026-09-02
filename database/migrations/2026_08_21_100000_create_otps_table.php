<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART P1–P4 — OTP login for the mobile apps.
 *
 * The parent (and later the driver and attendant) has no password at all. A
 * guardian is identified by their phone number and authenticated by a one-time
 * code. That is the only credential in the family-facing product.
 *
 * ⚠ PART P1: codes are `random_int(1000, 9999)`. There is NO static 1234 in any
 * environment reachable from the internet, staging included.
 *
 * ⚠ The code is stored HASHED. A leaked database read must not hand an attacker
 * a live login for every parent in the school.
 *
 * `attempts` / `locked_until` implement P3 (5 wrong tries → 1-minute lockout).
 * P4's send throttle (3 per 2 minutes) is a RateLimiter concern, not a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otps', function (Blueprint $t) {
            $t->id();
            // Who is logging in. 'guardian' now; 'staff' when Zippi Fleet lands.
            $t->string('identity_type', 16)->default('guardian');
            $t->string('identity', 191);              // phone or email
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['identity_type', 'identity', 'consumed_at']);
        });

        // "Keep me signed in" for the parent app. Without it a parent re-does
        // the OTP dance every time the session lapses, and the app they check
        // twice a day becomes the app they stop opening.
        Schema::table('guardians', function (Blueprint $t) {
            $t->rememberToken()->after('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $t) {
            $t->dropColumn('remember_token');
        });

        Schema::dropIfExists('otps');
    }
};
