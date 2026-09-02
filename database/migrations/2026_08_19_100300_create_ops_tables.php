<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART J3/J4, Q, R — the Control Tower's operational spine:
 * exception events, incidents, and the append-only audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PART R — deviation, unexpected stop, overspeed, delay. Feeds the queue (J3).
        Schema::create('school_trip_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained('school_trips')->cascadeOnDelete();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            // deviation|unexpected_stop|overspeed|delay|gps_stale|headcount_mismatch
            // |sweep_missing|wrong_bus_boarding|unaccounted_child|stop_skipped
            $t->string('event_type', 40);
            $t->string('severity', 12);                 // critical|high|medium|low
            $t->text('detail')->nullable();
            $t->json('payload')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->string('driver_reason', 64)->nullable(); // PART R1 annotation
            // PART J3 — Critical rows require named acknowledgement
            $t->dateTime('acknowledged_at')->nullable();
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'severity', 'acknowledged_at']);
            $t->index(['trip_id', 'event_type']);
        });

        // PART Q / A7 — incidents survive forever; closure requires a narrative.
        Schema::create('school_incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('trip_id')->nullable()->constrained('school_trips')->nullOnDelete();
            $t->foreignId('child_id')->nullable()->constrained()->nullOnDelete();
            // sos_accident|sos_breakdown|sos_medical|sos_security|sos_fire|sos_other
            // |child_returned_to_school|unaccounted_child|sweep_not_performed|other
            $t->string('incident_type', 40);
            $t->string('severity', 12)->default('high');
            $t->boolean('is_drill')->default(false);     // PART Q5
            $t->boolean('is_silent')->default(false);    // PART Q4
            $t->string('raised_by_type', 24)->nullable();
            $t->unsignedBigInteger('raised_by_id')->nullable();
            $t->text('description')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->dateTime('acknowledged_at')->nullable();
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->text('resolution_narrative')->nullable(); // required to close
            $t->dateTime('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'closed_at']);
        });

        /**
         * PART J4 / K1 — APPEND ONLY.
         * Enforced three ways: DB grant (INSERT only in production), no edit UI,
         * and a model-level save() guard that throws on update.
         */
        Schema::create('school_admin_audit_log', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 24);               // admin|school_user|system
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('action', 64);
            $t->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('trip_id')->nullable()->constrained('school_trips')->nullOnDelete();
            $t->foreignId('child_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('staff_id')->nullable();
            $t->json('payload')->nullable();
            $t->text('notes')->nullable();              // required for high-impact actions
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();    // no updated_at — rows never change
            $t->index(['school_id', 'created_at']);
            $t->index(['trip_id']);
            $t->index(['child_id']);
        });

        // Users get a school scope + role (PART J6).
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('school_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->string('role', 24)->default('school_user')->after('name');
            // zippi_admin | ops_agent | ops_lead | school_user | operator_user
            $t->string('phone', 24)->nullable()->after('email');
            $t->string('status', 16)->default('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('school_id');
            $t->dropColumn(['role', 'phone', 'status']);
        });
        Schema::dropIfExists('school_admin_audit_log');
        Schema::dropIfExists('school_incidents');
        Schema::dropIfExists('school_trip_events');
    }
};
