<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART E1 / E3 — children, guardians, authorized receivers, stop assignments.
 *
 * Key model difference from the enterprise module (see the doc's Context table, row 1):
 * the rider is NOT the app user. A child has zero logins and N guardians. Everything
 * "rider-facing" is actually guardian-facing, resolved through child_guardian_links.
 *
 * Second key difference (PART E1): children are assigned to a published STOP, not to
 * their own home coordinate. Door-to-door is modelled as a stop with one child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('admission_no', 32);
            $t->string('name');
            $t->string('grade', 8);
            $t->string('section', 8)->nullable();
            $t->string('bell_tier', 32)->nullable();        // derived from grade via bell times
            $t->string('photo_path')->nullable();
            $t->text('home_address')->nullable();          // record only, not routed on
            $t->date('date_of_birth')->nullable();
            $t->string('blood_group', 8)->nullable();
            $t->text('medical_notes')->nullable();         // shown to attendant behind a tap
            $t->boolean('self_release_consent')->default(false);  // PART A7
            $t->string('handover_code_hash')->nullable();  // PART A7 — hashed, rotates daily
            $t->date('handover_code_date')->nullable();
            $t->string('transport_fee_zone', 32)->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'admission_no']);
            $t->index(['school_id', 'grade']);
        });

        Schema::create('guardians', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 24)->unique();
            $t->string('email')->nullable();
            $t->string('photo_path')->nullable();
            $t->boolean('app_access')->default(true);
            $t->boolean('sms_fallback')->default(true);    // PART F6
            $t->string('fcm_token')->nullable();           // PART L10 — unique per guardian
            $t->json('notification_prefs')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        Schema::create('child_guardian_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $t->string('relationship', 32);                // mother|father|grandparent|other
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
            $t->unique(['child_id', 'guardian_id']);
        });

        // PART A7 — every guardian is an authorized receiver; not every receiver is a guardian.
        Schema::create('authorized_receivers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('relationship', 32);
            $t->string('phone', 24)->nullable();
            $t->string('photo_path')->nullable();          // required in practice (PART E3)
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index('child_id');
        });

        // PART E1 / O2 — a child can have a different morning and afternoon stop.
        Schema::create('child_stop_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->foreignId('route_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stop_id')->constrained('route_stops')->cascadeOnDelete();
            $t->string('direction', 16);                   // Morning | Afternoon
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->boolean('is_temporary')->default(false);   // PART N2
            $t->timestamps();
            $t->index(['child_id', 'direction']);
            $t->index(['route_id', 'direction']);
        });

        // PART J2 #6 / O2 — one-day stop or route override for a single child.
        Schema::create('child_stop_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->date('service_date');                      // raw string on the model (L1)
            $t->string('direction', 16);
            $t->foreignId('stop_id')->nullable()->constrained('route_stops')->nullOnDelete();
            $t->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['child_id', 'service_date', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_stop_overrides');
        Schema::dropIfExists('child_stop_assignments');
        Schema::dropIfExists('authorized_receivers');
        Schema::dropIfExists('child_guardian_links');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('children');
    }
};
