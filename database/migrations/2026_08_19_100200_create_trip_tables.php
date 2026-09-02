<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART M1 — trips, per-child trip rows, stop arrivals, absences, handovers.
 *
 * Every operational row is keyed by (child|route, service_date, direction, bell_tier).
 * All four. Getting this wrong presents as "I marked her absent and the bus still waited."
 *
 * service_date is a raw Y-m-d string on the model — never a 'date' cast (PART L1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_staff_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('route_id')->constrained()->cascadeOnDelete();
            $t->string('direction', 16);
            $t->string('bell_tier', 32)->nullable();
            $t->foreignId('driver_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->foreignId('attendant_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->foreignId('bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
            $t->index(['route_id', 'direction']);
        });

        // PART O2 — one-day stand-in. Any of the three may be set independently.
        Schema::create('route_staff_overrides', function (Blueprint $t) {
            $t->id();
            $t->date('service_date');
            $t->foreignId('route_id')->constrained()->cascadeOnDelete();
            $t->string('direction', 16);
            $t->string('bell_tier', 32)->nullable();
            $t->foreignId('driver_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->foreignId('attendant_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->foreignId('bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['service_date', 'route_id', 'direction', 'bell_tier'], 'uniq_route_override');
        });

        Schema::create('school_trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('route_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('route_version')->default(1);
            $t->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->foreignId('attendant_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->date('service_date');
            $t->string('direction', 16);                 // Morning | Afternoon
            $t->string('bell_tier', 32)->nullable();
            $t->unsignedTinyInteger('sequence')->default(0);
            // scheduled|started|completed|cancelled|transferred|emergency_stopped
            $t->string('status', 24)->default('scheduled');
            $t->json('child_ids')->nullable();
            $t->dateTime('scheduled_start_at')->nullable();
            $t->dateTime('scheduled_end_at')->nullable();
            $t->time('bell_time')->nullable();
            $t->dateTime('school_arrival_deadline')->nullable();
            $t->dateTime('school_depart_at')->nullable();
            $t->json('route_schedule')->nullable();      // solved stops (PART M7)
            $t->dateTime('started_at')->nullable();
            $t->dateTime('arrived_at_school_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            // Invariant #3 — the sweep is blocking and evidenced (PART L3)
            $t->dateTime('sweep_verified_at')->nullable();
            $t->string('sweep_photo_path')->nullable();
            $t->decimal('sweep_lat', 10, 7)->nullable();
            $t->decimal('sweep_lng', 10, 7)->nullable();
            $t->foreignId('sweep_by_staff_id')->nullable()->constrained('school_staff')->nullOnDelete();
            // Invariant #2 — head-count reconciliation gates completion (PART L2)
            $t->unsignedInteger('headcount_reported')->nullable();
            $t->boolean('headcount_verified')->default(false);
            $t->boolean('auto_generated')->default(true);
            $t->boolean('admin_edited')->default(false);
            $t->string('source', 16)->default('auto');   // auto|admin|adhoc|legacy
            $t->foreignId('parent_trip_id')->nullable()->constrained('school_trips')->nullOnDelete();
            $t->foreignId('completed_by_admin_id')->nullable();
            $t->dateTime('geofence_override_until')->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->unsignedInteger('duration_min')->nullable();
            $t->timestamps();
            $t->index(['bus_id', 'service_date', 'sequence']);
            $t->index(['school_id', 'service_date', 'bell_tier']);
            $t->index(['route_id', 'service_date']);
        });

        // One row per child per trip — the spine of the journey record (PART D).
        Schema::create('school_trip_children', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained('school_trips')->cascadeOnDelete();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stop_id')->nullable()->constrained('route_stops')->nullOnDelete();
            $t->unsignedInteger('sequence')->nullable();
            // pending|boarded|not_at_stop|absent|arrived_at_school
            // |alighted_to_guardian|alighted_self_release|returned_to_school
            $t->string('status', 32)->default('pending');
            $t->dateTime('boarded_at')->nullable();
            $t->decimal('boarded_lat', 10, 7)->nullable();
            $t->decimal('boarded_lng', 10, 7)->nullable();
            $t->dateTime('alighted_at')->nullable();
            $t->decimal('alighted_lat', 10, 7)->nullable();
            $t->decimal('alighted_lng', 10, 7)->nullable();
            // PART L4 — every state change records actor, geo and server time
            $t->string('acted_by_type', 24)->nullable();
            $t->unsignedBigInteger('acted_by_id')->nullable();
            $t->dateTime('client_reported_at')->nullable();   // never trusted for ordering
            $t->boolean('synced_offline')->default(false);    // PART K6
            $t->unsignedTinyInteger('rating')->nullable();
            $t->string('rating_comment', 500)->nullable();
            $t->foreignId('supersedes_id')->nullable()->constrained('school_trip_children')->nullOnDelete();
            $t->string('correction_reason')->nullable();
            $t->timestamps();
            $t->unique(['trip_id', 'child_id']);
            $t->index(['child_id', 'created_at']);
        });

        Schema::create('school_stop_arrivals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained('school_trips')->cascadeOnDelete();
            $t->foreignId('stop_id')->constrained('route_stops')->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('arrived_at')->nullable();
            $t->dateTime('departed_at')->nullable();
            $t->dateTime('approach_notified_at')->nullable();  // PART F3 — fires once
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestamps();
            $t->unique(['trip_id', 'stop_id']);
            $t->index(['trip_id', 'sequence']);
        });

        Schema::create('school_child_absences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->date('service_date');                     // raw string (L1)
            $t->string('direction', 16);
            $t->string('bell_tier', 32)->nullable();
            // parent|parent_collecting|school|attendant_not_at_stop
            // |attendant_not_boarded_at_school|admin|holiday
            $t->string('marked_by', 40);
            $t->unsignedBigInteger('marked_by_id')->nullable();
            $t->string('reason_code', 32)->nullable();    // sick|travel|exam|activity|other
            $t->string('reason')->nullable();
            $t->string('approval_status', 16)->default('approved'); // PART A2a
            $t->timestamps();
            $t->index(['child_id', 'service_date']);
            $t->index(['child_id', 'direction', 'service_date']);
        });

        // PART A7 — the custody record. This is what answers a dispute.
        Schema::create('school_child_handovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained('school_trips')->cascadeOnDelete();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stop_id')->nullable()->constrained('route_stops')->nullOnDelete();
            $t->foreignId('receiver_guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $t->foreignId('receiver_authorized_id')->nullable()->constrained('authorized_receivers')->nullOnDelete();
            $t->string('receiver_name')->nullable();
            // handover_code|authorized_person|self_release|admin_voice|returned_to_school
            $t->string('verification_method', 32);
            $t->unsignedTinyInteger('code_attempts')->default(0);
            $t->boolean('verified_offline')->default(false);
            $t->string('photo_path')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->foreignId('acted_by_staff_id')->nullable()->constrained('school_staff')->nullOnDelete();
            $t->json('escalation_trail')->nullable();
            $t->timestamps();
            $t->index(['child_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_child_handovers');
        Schema::dropIfExists('school_child_absences');
        Schema::dropIfExists('school_stop_arrivals');
        Schema::dropIfExists('school_trip_children');
        Schema::dropIfExists('school_trips');
        Schema::dropIfExists('route_staff_overrides');
        Schema::dropIfExists('route_staff_assignments');
    }
};
