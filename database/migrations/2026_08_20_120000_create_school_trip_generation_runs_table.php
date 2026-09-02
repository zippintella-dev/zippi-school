<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART M2 — one row per `school:generate-trips` invocation, with counts and errors.
 *
 * The generator runs unattended at 02:30. When a route silently produces no trip,
 * the question at 07:15 is "did the generator run, and what did it think?" — this
 * table is the only thing that can answer it after the fact.
 *
 * ⚠ PART L1: service_date is a DATE column and stays a raw Y-m-d string on the
 * model. No 'date' cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_trip_generation_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $t->date('service_date');                 // raw string on the model (L1)
            $t->string('trigger', 16)->default('cron');   // cron | manual | jit
            $t->boolean('dry_run')->default(false);
            $t->boolean('forced')->default(false);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->unsignedInteger('warning_count')->default(0);
            $t->json('warnings')->nullable();         // [{route, direction, tier, warning}]
            $t->json('errors')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_trip_generation_runs');
    }
};
