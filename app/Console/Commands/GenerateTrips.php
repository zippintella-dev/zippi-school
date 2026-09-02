<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\SchoolCalendarService;
use App\Services\SchoolTripGenerator;
use Illuminate\Console\Command;

/**
 * PART M2 — nightly trip generation.
 *
 * Scheduled at 02:30 school-local for the coming school day. Idempotent: safe to
 * re-run by hand at 06:00 when someone wants to check the cron actually fired.
 *
 *   php artisan school:generate-trips                 # today, every school
 *   php artisan school:generate-trips 2026-08-24      # a specific service date
 *   php artisan school:generate-trips --tomorrow      # what the 02:30 cron does
 *   php artisan school:generate-trips --dry-run       # solve, report, write nothing
 *   php artisan school:generate-trips --force         # re-solve existing auto trips
 *
 * --force re-solves auto-generated trips. It does NOT touch a trip an admin has
 * edited, nor one that has already started — those are skipped unconditionally.
 */
class GenerateTrips extends Command
{
    protected $signature = 'school:generate-trips
        {date? : Service date (Y-m-d). Defaults to today in the school timezone.}
        {--school= : Limit to one school id or code}
        {--tomorrow : Generate for the next calendar day (what the 02:30 cron does)}
        {--dry-run : Solve and report without writing anything}
        {--force : Re-solve existing auto-generated trips}';

    protected $description = 'Generate and solve school trips for a service date (PART M2)';

    public function handle(SchoolTripGenerator $generator): int
    {
        $schools = $this->schools();

        if ($schools->isEmpty()) {
            $this->error('No matching active school.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $failed = false;

        foreach ($schools as $school) {
            // ⚠ PART K4 / L1 — "today" is resolved in the SCHOOL's timezone and
            // stays a raw Y-m-d string. A server in UTC must not generate an
            // IST school's trips for yesterday.
            $cal = SchoolCalendarService::for($school);

            $date = $this->argument('date')
                ?: ($this->option('tomorrow')
                    ? \Carbon\Carbon::parse($cal->today())->addDay()->toDateString()
                    : $cal->today());

            $this->line('');
            $this->info($school->name . ' — ' . $cal->describe($date)
                . ($dryRun ? '  [dry run]' : '') . ($force ? '  [forced]' : ''));

            $run = $generator->generate($school, $date, $force, $dryRun,
                $this->option('tomorrow') ? 'cron' : 'manual');

            $this->report($run);

            if ($run->errors) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function schools()
    {
        $q = School::where('status', 'active');

        if ($opt = $this->option('school')) {
            $q->where(fn ($w) => $w->where('id', $opt)->orWhere('code', $opt));
        }

        return $q->orderBy('id')->get();
    }

    private function report($run): void
    {
        $this->table(
            ['created', 'updated', 'skipped', 'warnings', 'errors', 'ms'],
            [[
                $run->created_count,
                $run->updated_count,
                $run->skipped_count,
                $run->warning_count,
                count($run->errors ?? []),
                $run->duration_ms,
            ]]
        );

        // Warnings are the point of this command. A route that silently produced
        // no trip is the failure mode that strands children, so print every one
        // rather than a count — nobody goes looking in the runs table at 07:15.
        foreach ($run->warnings ?? [] as $w) {
            $where = implode(' ', array_filter([
                $w['route'] ?? null,
                $w['direction'] ?? null,
                $w['tier'] ?? null,
            ]));

            $line = trim(($where ?: $w['scope'] ?? '') . '  ' . ($w['warning'] ?? ''))
                . (isset($w['detail']) ? '  (' . $w['detail'] . ')' : '');

            // deadline_too_tight means the route cannot physically make the bell.
            ($w['warning'] ?? '') === 'deadline_too_tight'
                ? $this->error('  ⚠ ' . $line)
                : $this->warn('  · ' . $line);
        }

        foreach ($run->errors ?? [] as $e) {
            $this->error('  ✖ ' . ($e['route'] ?? '') . ' ' . ($e['direction'] ?? '')
                . ' ' . ($e['tier'] ?? '') . ' — ' . ($e['error'] ?? ''));
        }
    }
}
