<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART C2 / E1 — schools, bell times, calendar, routes, stops, buses, staff.
 *
 * NOTE ON DATE COLUMNS (PART L1 — carried forward from enterprise L1):
 * Every plain DATE column here (school_calendars.date, service_date, effective_from/to)
 * MUST stay a raw Y-m-d string on the Eloquent model. Do NOT add a 'date' cast — it
 * forces Carbon to read the value as midnight in APP_TIMEZONE and re-serialize as UTC,
 * shifting every IST date back one calendar day and breaking undo/delete predicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 32)->unique();
            $t->text('address')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();   // school gate
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('timezone', 64)->default('Asia/Kolkata');
            $t->unsignedInteger('gate_geofence_radius_m')->default(200);
            $t->string('self_release_min_grade', 8)->nullable();
            $t->boolean('require_office_approval_for_parent_collection')->default(false);
            $t->unsignedInteger('stop_wait_seconds')->default(120);   // PART A6
            $t->unsignedInteger('drop_wait_seconds')->default(180);   // PART A7
            $t->unsignedInteger('max_speed_kmph')->default(40);       // PART R3
            $t->string('contact_phone', 24)->nullable();
            $t->string('logo_path')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        // PART C2 — tiered bell times. One row per (bell tier, grade range).
        Schema::create('school_bell_times', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('bell_tier', 32);                 // Senior | Middle | Primary
            $t->string('grade_from', 8)->nullable();
            $t->string('grade_to', 8)->nullable();
            $t->time('start_time');                     // bell in
            $t->time('end_time');                       // dismissal
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'bell_tier']);
        });

        // PART C3 — the calendar is load-bearing, not optional.
        Schema::create('school_calendars', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->date('date');                           // raw string on the model
            $t->string('day_type', 16);                 // school|holiday|half_day|exam|event|vacation
            $t->string('label')->nullable();
            $t->boolean('morning_trips_run')->default(true);
            $t->boolean('afternoon_trips_run')->default(true);
            $t->time('override_end_time')->nullable();  // half-day dismissal
            $t->timestamps();
            $t->unique(['school_id', 'date']);
        });

        // PART G — routes carry an authored, published stop sequence.
        Schema::create('routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('code', 16);                     // RT-03
            $t->string('name');
            $t->string('bell_tier', 32)->nullable();     // which bell tier it serves
            $t->boolean('afternoon_mirrors_morning')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->text('encoded_polyline')->nullable();   // PART R1 corridor
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'code']);
        });

        Schema::create('route_stops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('route_id')->constrained()->cascadeOnDelete();
            $t->string('direction', 16)->default('Morning'); // Morning|Afternoon
            $t->unsignedInteger('sequence');                 // authored, frozen (PART G1)
            $t->string('name');
            $t->string('landmark')->nullable();
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->unsignedInteger('dwell_seconds_override')->nullable();
            $t->time('scheduled_at')->nullable();            // solved (PART M7)
            $t->timestamps();
            $t->index(['route_id', 'direction', 'sequence']);
        });

        // PART E3 / K15 — bus master + compliance document expiries.
        Schema::create('buses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('reg_no', 24);
            $t->string('model')->nullable();
            $t->unsignedInteger('capacity')->default(42);
            $t->boolean('is_ev')->default(false);
            $t->string('gps_device_id', 64)->nullable();
            $t->boolean('has_camera')->default(false);
            $t->date('fitness_expiry')->nullable();
            $t->date('permit_expiry')->nullable();
            $t->date('insurance_expiry')->nullable();
            $t->date('puc_expiry')->nullable();
            $t->date('speed_governor_expiry')->nullable();
            $t->date('first_aid_checked_on')->nullable();
            $t->date('extinguisher_checked_on')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();      // last known ping
            $t->decimal('longitude', 10, 7)->nullable();
            $t->unsignedInteger('speed_kmph')->nullable();
            $t->timestamp('last_ping_at')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'reg_no']);
        });

        // Drivers and attendants share one table, split by role (PART H3).
        Schema::create('school_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16);                     // driver | attendant
            $t->string('name');
            $t->string('phone', 24);
            $t->string('gender', 12)->nullable();       // K15 — female attendant mandates
            $t->string('photo_path')->nullable();
            $t->string('licence_no', 40)->nullable();
            $t->date('licence_expiry')->nullable();
            $t->unsignedInteger('heavy_vehicle_years')->nullable();
            $t->string('police_verification_status', 16)->default('pending');
            $t->date('police_verified_on')->nullable();
            $t->date('medical_fitness_expiry')->nullable();
            $t->date('training_completed_on')->nullable();
            $t->string('fcm_token')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->index(['school_id', 'role']);
            $t->unique(['school_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_staff');
        Schema::dropIfExists('buses');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('school_calendars');
        Schema::dropIfExists('school_bell_times');
        Schema::dropIfExists('schools');
    }
};
