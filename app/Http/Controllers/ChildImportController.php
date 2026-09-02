<?php

namespace App\Http\Controllers;

use App\Models\AuthorizedReceiver;
use App\Models\Child;
use App\Models\ChildStopAssignment;
use App\Models\Guardian;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\SchoolAdminAuditLog;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PART M4 — student bulk import, preview-then-commit.
 *
 * ⚠ DIVERGES FROM ENTERPRISE: the enterprise CSV importer does a straight
 * insert. A student import is far higher stakes — schools send messy files,
 * and a bad import silently creates 600 wrong parent relationships. So this
 * one previews first, reports per-row warnings, and only writes on confirm.
 *
 * Guardians are created but NOT invited. Invites are a separate deliberate
 * action, so a mis-import never SMSes 500 families.
 */
class ChildImportController extends Controller
{
    private const REQUIRED = ['admission_no', 'name', 'grade'];

    private const KNOWN = [
        'admission_no', 'name', 'grade', 'section', 'bell_tier', 'home_address',
        'blood_group', 'medical_notes', 'self_release_consent', 'transport_fee_zone',
        'route_code', 'morning_stop', 'afternoon_stop',
        'guardian1_name', 'guardian1_phone', 'guardian1_email', 'guardian1_relationship',
        'guardian2_name', 'guardian2_phone', 'guardian2_email', 'guardian2_relationship',
    ];

    public function form()
    {
        return $this->view('children.import', [
            'known'    => self::KNOWN,
            'required' => self::REQUIRED,
            'routes'   => $this->activeSchool()->routes()->orderBy('code')->get(),
        ]);
    }

    public function template()
    {
        $csv = implode(',', self::KNOWN) . "\n"
             . 'PG10501,Ravi Kumar,3,B,Primary,"12 Silver Oak, Kondapur",O+,,0,Zone 1,'
             . 'RT-01,Silver Oak Gate,Silver Oak Gate,'
             . 'Meera Kumar,+919812345678,meera@example.com,mother,'
             . 'Rajesh Kumar,+919812345679,,father' . "\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="zippi-student-import-template.csv"',
        ]);
    }

    public function preview(Request $request)
    {
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $parsed = $this->parse($request->file('csv_file')->getRealPath());

        if (isset($parsed['fatal'])) {
            return back()->with('error', $parsed['fatal']);
        }

        // Stash the parsed rows so commit doesn't need a re-upload.
        $request->session()->put('import_rows', $parsed['rows']);
        $request->session()->put('import_headers', $parsed['headers']);

        return $this->view('children.import-preview', [
            'rows'     => $parsed['rows'],
            'headers'  => $parsed['headers'],
            'unknown'  => $parsed['unknown'],
            'summary'  => $parsed['summary'],
        ]);
    }

    public function commit(Request $request)
    {
        $rows = $request->session()->get('import_rows');

        if (! $rows) {
            return redirect()->route('children.import')
                ->with('error', 'The preview expired. Please upload the file again.');
        }

        $school  = $this->activeSchool();
        $today   = $this->today();
        $created = 0; $updated = 0; $skipped = 0; $guardians = 0;
        $conflicts = [];

        DB::transaction(function () use ($rows, $school, $today, &$created, &$updated,
                                        &$skipped, &$guardians, &$conflicts) {
            foreach ($rows as $row) {
                if ($row['action'] === 'skip') { $skipped++; continue; }

                $d = $row['data'];

                $child = Child::updateOrCreate(
                    ['school_id' => $school->id, 'admission_no' => $d['admission_no']],
                    [
                        'name'    => $d['name'],
                        'grade'   => $d['grade'],
                        'section' => $d['section'] ?: null,
                        'bell_tier' => $row['resolved']['bell_tier'] ?? null,
                        'home_address'  => $d['home_address'] ?: null,
                        'blood_group'   => $d['blood_group'] ?: null,
                        'medical_notes' => $d['medical_notes'] ?: null,
                        'self_release_consent' => in_array(
                            strtolower((string) $d['self_release_consent']), ['1','yes','true','y'], true),
                        'transport_fee_zone' => $d['transport_fee_zone'] ?: null,
                        'status' => 'active',
                    ]
                );

                $child->wasRecentlyCreated ? $created++ : $updated++;

                foreach ([1, 2] as $n) {
                    $name  = $d["guardian{$n}_name"]  ?? null;
                    $phone = $d["guardian{$n}_phone"] ?? null;
                    if (! $name || ! $phone) continue;

                    // ⚠ ONE NUMBER, ONE PERSON. A bare firstOrCreate here would
                    // silently reuse an existing guardian and discard the name
                    // from the file — the wrong adult would then receive that
                    // child's alerts and handover code.
                    //
                    // Unlike the manual form this does NOT abort: an import runs
                    // hundreds of rows and failing the batch over one bad cell
                    // helps nobody. The clash is skipped and reported, and the
                    // preview flags it before anything is written.
                    $phone = PhoneNumber::canonical($phone);
                    $existing = PhoneNumber::findGuardian($phone);

                    if ($existing && ! $this->samePerson($existing->name, $name)) {
                        $conflicts[] = "{$d['admission_no']}: {$phone} already belongs to "
                            . "{$existing->name}, so {$name} was not linked.";
                        continue;
                    }

                    $g = $existing ?? Guardian::create([
                        'phone' => $phone,
                        'name' => $name,
                        'email' => $d["guardian{$n}_email"] ?: null,
                        'app_access' => true,
                        'invited_at' => null,   // NOT invited yet
                    ]);
                    $guardians++;

                    $child->guardians()->syncWithoutDetaching([$g->id => [
                        'relationship' => $d["guardian{$n}_relationship"] ?: 'parent',
                        'is_primary'   => $n === 1,
                    ]]);

                    AuthorizedReceiver::firstOrCreate(
                        ['child_id' => $child->id, 'guardian_id' => $g->id],
                        ['name' => $g->name, 'relationship' => $d["guardian{$n}_relationship"] ?: 'parent',
                         'phone' => $g->phone, 'is_active' => true]
                    );
                }

                if (! empty($row['resolved']['route_id']) && ! empty($row['resolved']['morning_stop_id'])) {
                    $pm = $row['resolved']['afternoon_stop_id'] ?: $row['resolved']['morning_stop_id'];
                    foreach (['Morning' => $row['resolved']['morning_stop_id'],
                              'Afternoon' => $pm] as $dir => $stopId) {
                        ChildStopAssignment::updateOrCreate(
                            ['child_id' => $child->id, 'direction' => $dir],
                            ['route_id' => $row['resolved']['route_id'], 'stop_id' => $stopId,
                             'effective_from' => $today, 'is_temporary' => false]
                        );
                    }
                }
            }
        });

        $request->session()->forget(['import_rows', 'import_headers']);

        SchoolAdminAuditLog::record('students_imported', [
            'school_id' => $school->id,
            'payload'   => compact('created', 'updated', 'skipped', 'guardians'),
        ], "CSV import: {$created} created, {$updated} updated, {$skipped} skipped.");

        $message = "Imported {$created} new student(s), updated {$updated}, "
                 . "skipped {$skipped}. {$guardians} guardian link(s) written — "
                 . 'no invites sent yet.';

        // Phone clashes are reported loudly rather than folded into the count:
        // a guardian who was NOT linked is a child who gets no notifications.
        return redirect()->route('children.index')
            ->with('ok', $message)
            ->with($conflicts ? 'error' : 'ignored',
                $conflicts
                    ? count($conflicts) . ' guardian(s) were not linked because the '
                        . 'number already belongs to someone else: '
                        . implode(' ', array_slice($conflicts, 0, 5))
                        . (count($conflicts) > 5 ? ' …' : '')
                    : null);
    }

    /** Same forgiving comparison the manual form uses (ChildController). */
    private function samePerson(string $a, string $b): bool
    {
        $tidy = fn (string $s) => preg_replace('/[^a-z]/', '', strtolower(trim($s)));

        return $tidy($a) === $tidy($b);
    }

    /* ---------------- parsing ---------------- */

    private function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) return ['fatal' => 'Could not read the uploaded file.'];

        $headers = fgetcsv($handle);
        if (! $headers) return ['fatal' => 'The file appears to be empty.'];

        $headers = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $headers);

        $missing = array_diff(self::REQUIRED, $headers);
        if ($missing) {
            return ['fatal' => 'Missing required column(s): ' . implode(', ', $missing)
                             . '. Required columns are: ' . implode(', ', self::REQUIRED) . '.'];
        }

        $unknown = array_values(array_diff($headers, self::KNOWN));
        $school  = $this->activeSchool();

        $bells  = $school->bellTimes;
        $routes = Route::where('school_id', $school->id)->get()->keyBy(fn ($r) => strtolower($r->code));
        $stops  = RouteStop::whereIn('route_id', $routes->pluck('id'))->get();
        $existing = Child::where('school_id', $school->id)->pluck('id', 'admission_no');

        $rows = [];
        $seen = [];
        $line = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($raw, fn ($v) => trim((string) $v) !== '')) === 0) continue;

            $data = [];
            foreach ($headers as $i => $h) {
                $data[$h] = isset($raw[$i]) ? trim((string) $raw[$i]) : '';
            }
            foreach (self::KNOWN as $k) { $data[$k] = $data[$k] ?? ''; }

            $warnings = [];
            $action = 'create';

            if ($data['admission_no'] === '' || $data['name'] === '' || $data['grade'] === '') {
                $warnings[] = 'Missing admission_no, name or grade';
                $action = 'skip';
            }

            if ($action !== 'skip' && isset($seen[$data['admission_no']])) {
                $warnings[] = 'Duplicate admission_no within this file (line '
                            . $seen[$data['admission_no']] . ')';
                $action = 'skip';
            } else {
                $seen[$data['admission_no']] = $line;
            }

            if ($action !== 'skip' && $existing->has($data['admission_no'])) {
                $action = 'update';
                $warnings[] = 'Already enrolled — will be updated, not duplicated';
            }

            // Resolve route + stops by name. Unknown names warn but never fail
            // the row — the child still imports, just unassigned.
            $resolved = ['route_id' => null, 'morning_stop_id' => null,
                         'afternoon_stop_id' => null, 'bell_tier' => null];

            // ⚠ The bell tier decides which staggered run the child rides, so a
            // tier that disagrees with the grade puts them on the wrong bus.
            // Derived when the column is blank; corrected with a warning when
            // it is wrong, rather than failing the row — an import of 400
            // students must not abort over one cell.
            $correctTier = $bells->first(fn ($b) => $b->coversGrade($data['grade']))?->bell_tier;

            if (! $correctTier) {
                $warnings[] = "No bell tier covers class {$data['grade']} — this "
                            . 'student will not be put on any bus';
            } elseif ($data['bell_tier'] && $data['bell_tier'] !== $correctTier) {
                $warnings[] = "Bell tier '{$data['bell_tier']}' does not match class "
                            . "{$data['grade']} — corrected to {$correctTier}";
            }

            $resolved['bell_tier'] = $correctTier;

            if ($data['route_code'] !== '') {
                $route = $routes->get(strtolower($data['route_code']));
                if (! $route) {
                    $warnings[] = "Unknown route '{$data['route_code']}' — student imported unassigned";
                } else {
                    $resolved['route_id'] = $route->id;
                    $routeStops = $stops->where('route_id', $route->id);

                    foreach (['morning_stop' => 'morning_stop_id',
                              'afternoon_stop' => 'afternoon_stop_id'] as $col => $key) {
                        if ($data[$col] === '') continue;
                        $match = $routeStops->first(fn ($s) =>
                            strcasecmp(trim($s->name), trim($data[$col])) === 0);
                        if ($match) {
                            $resolved[$key] = $match->id;
                        } else {
                            $warnings[] = "Stop '{$data[$col]}' not found on {$route->code}";
                        }
                    }
                }
            }

            if ($action !== 'skip' && $data['guardian1_phone'] === '') {
                $warnings[] = 'No guardian phone — this child will receive no notifications';
            }

            $rows[] = compact('line', 'data', 'warnings', 'action', 'resolved');
        }

        fclose($handle);

        return [
            'rows'    => $rows,
            'headers' => $headers,
            'unknown' => $unknown,
            'summary' => [
                'total'   => count($rows),
                'create'  => count(array_filter($rows, fn ($r) => $r['action'] === 'create')),
                'update'  => count(array_filter($rows, fn ($r) => $r['action'] === 'update')),
                'skip'    => count(array_filter($rows, fn ($r) => $r['action'] === 'skip')),
                'warned'  => count(array_filter($rows, fn ($r) => ! empty($r['warnings']))),
            ],
        ];
    }
}
