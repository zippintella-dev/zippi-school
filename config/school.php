<?php

/**
 * PART M7 — solver, geofence and alerting constants.
 *
 * These are the numbers the enterprise module learned the hard way, retargeted
 * to schools. They are config rather than constants because a school with a
 * 3 km catchment and a school with a 30 km one need different buffers.
 *
 * The bias throughout is EARLY. PART M10: the deadline targets the child inside
 * the school before the bell, not at the gate as it rings — so every fallback
 * here is deliberately conservative (slower assumed speed, longer assumed dwell)
 * and errs toward the bus leaving early rather than arriving late.
 */
return [

    /*
    |---------------------------------------------------------------------
    | TEST MODE — friction only. NOT a safety switch.
    |---------------------------------------------------------------------
    |
    | `SCHOOL_TEST_MODE=true` in .env relaxes the things that make a walkthrough
    | from a desk impossible: the OTP send throttle and code TTL. The wait
    | windows and the gate geofence are per-tenant columns on `schools`, so those
    | are relaxed there rather than here.
    |
    | ⚠ WHAT IT DOES NOT DO, AND MUST NEVER DO. The four invariants are not
    | reachable from this flag:
    |   1. a child is never released without a verified receiver
    |   2. a trip cannot complete while a child is unaccounted for
    |   3. the bus is swept before the trip closes
    |   4. every override is audited
    | The sweep still has to be performed, photographed and timestamped — test
    | mode only stops it refusing on DISTANCE. If a future change wants to skip a
    | sweep, or release a child without a code, it does not belong behind this
    | flag; it does not belong in the codebase.
    |
    | ⚠ Defaults to FALSE. Every environment that has not deliberately opted in
    | runs the production numbers, and `php artisan test` never sets it.
    */

    'test_mode' => env('SCHOOL_TEST_MODE', false),

    /*
    |---------------------------------------------------------------------
    | Solve buffers (PART M7)
    |---------------------------------------------------------------------
    */

    // Bell minus this = school_arrival_deadline. The child is IN the school.
    'arrival_buffer_minutes' => 10,

    // Slack in front of the first stop, absorbed before the route begins.
    'safety_buffer_minutes' => 5,

    /*
    |---------------------------------------------------------------------
    | Dwell (PART M7)
    |---------------------------------------------------------------------
    | Time the bus is stationary at a stop. Afternoon per-child dwell is
    | higher than morning on purpose: a handover to a verified receiver
    | (Invariant #1) takes materially longer than a child climbing aboard.
    */

    'base_dwell_seconds' => 20,
    'per_child_dwell_seconds_morning' => 8,
    'per_child_dwell_seconds_afternoon' => 12,

    // Afternoon only — loading the whole bus at the school gate after dismissal.
    'boarding_base_minutes_at_school' => 5,
    'boarding_minutes_per_child' => 0.25,

    /*
    |---------------------------------------------------------------------
    | Leg time estimation (PART M7)
    |---------------------------------------------------------------------
    | Google Directions is the intended source (traffic-aware, best_guess,
    | 15-minute-bucket cache — enterprise M15). DriveTimeEstimator is not
    | built yet, so the solver uses the haversine fallback below and marks
    | each leg with its source. See SchoolTripSolver::legMinutes().
    */

    'use_google_eta' => false,          // flip when DriveTimeEstimator lands
    'traffic_model' => 'best_guess',
    'eta_cache_seconds' => 900,

    // Straight-line distance is multiplied by this to approximate road distance.
    'road_circuity_factor' => 1.5,

    // 300 m/min = 18 km/h. Low on purpose — Hyderabad school-run traffic.
    'fallback_speed_m_per_min' => 300,

    /*
    |---------------------------------------------------------------------
    | Sanity floor (PART M7)
    |---------------------------------------------------------------------
    | A backward solve that lands the depot departure before this clamps and
    | raises warning='deadline_too_tight'. It means the route physically
    | cannot make the bell and a human must split it — so it must be loud,
    | never silently absorbed.
    */

    'earliest_depot_departure' => '05:00',

    /*
    |---------------------------------------------------------------------
    | Depot (PART M7)
    |---------------------------------------------------------------------
    | Buses are school-parked at pilot, so the depot leg is school -> stop 1.
    | Set false once buses carry their own depot coordinates.
    */

    'depot_at_school' => true,

    /*
    |---------------------------------------------------------------------
    | Waiting (PART A6 / A7)
    |---------------------------------------------------------------------
    | Mirrored on the schools table so a school can override per-tenant.
    */

    'stop_wait_seconds' => 120,
    'drop_wait_seconds' => 180,

    /*
    |---------------------------------------------------------------------
    | Geofences & alerting (PART F3 / R3)
    |---------------------------------------------------------------------
    */

    'approach_radius_m' => 1200,
    'approach_eta_minutes' => 5,
    'stop_geofence_m' => 150,
    'school_geofence_m' => 200,
    'sweep_geofence_m' => 300,
    'delay_alert_minutes' => 10,
    'deviation_threshold_m' => 500,
    'deviation_sustain_seconds' => 90,
    'schedule_drift_amber_minutes' => 5,
];
