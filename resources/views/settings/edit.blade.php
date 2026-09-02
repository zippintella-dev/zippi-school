@extends('layouts.app')
@section('title', 'School settings')
@section('subtitle', 'Profile, geo-fences and the tiered bell schedule')

@section('content')
@include('partials.errors')

<div class="grid main-side">
  <div>
    <form method="POST" action="{{ route('settings.update') }}">
      @csrf @method('PUT')
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Profile</h2></div>
        <div class="card-body">
          <div class="form-grid">
            <div class="field"><label>School name</label>
              <input class="input" name="name" value="{{ old('name', $school->name) }}" required></div>
            <div class="field"><label>Code</label>
              <input class="input" name="code" value="{{ old('code', $school->code) }}" required></div>
            <div class="field full"><label>Address</label>
              <input class="input" name="address" value="{{ old('address', $school->address) }}"></div>
            <div class="field"><label>Contact phone</label>
              <input class="input" name="contact_phone" value="{{ old('contact_phone', $school->contact_phone) }}"></div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>School gate location</h2></div>
        <div class="card-body">
          <div class="note" style="margin-bottom:14px">
            Drop the pin at the <b>actual gate</b>, not the centre of the campus.
            Arrival confirmation uses a geo-fence around this point, so a pin in
            the wrong place means attendants cannot confirm arrival (PART E3).
          </div>
          <div class="form-grid c3">
            <div class="field"><label>Latitude</label>
              <input class="input" name="latitude" value="{{ old('latitude', $school->latitude) }}" required></div>
            <div class="field"><label>Longitude</label>
              <input class="input" name="longitude" value="{{ old('longitude', $school->longitude) }}" required></div>
            <div class="field"><label>Gate geo-fence (m)</label>
              <input class="input" type="number" name="gate_geofence_radius_m"
                     value="{{ old('gate_geofence_radius_m', $school->gate_geofence_radius_m) }}" required></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Safety thresholds</h2></div>
        <div class="card-body">
          <div class="form-grid c3">
            <div class="field"><label>Stop wait (seconds)</label>
              <input class="input" type="number" name="stop_wait_seconds"
                     value="{{ old('stop_wait_seconds', $school->stop_wait_seconds) }}" required>
              <div class="help">How long the bus waits at a morning stop before a child
                can be marked not-at-stop. 120s default — a bus with 40 children
                aboard cannot wait 6 minutes per stop (PART A6).</div></div>

            <div class="field"><label>Drop wait (seconds)</label>
              <input class="input" type="number" name="drop_wait_seconds"
                     value="{{ old('drop_wait_seconds', $school->drop_wait_seconds) }}" required>
              <div class="help">How long the escalation ladder runs before a child
                returns to school. It never ends in leaving the child (PART A7).</div></div>

            <div class="field"><label>Speed limit (km/h)</label>
              <input class="input" type="number" name="max_speed_kmph"
                     value="{{ old('max_speed_kmph', $school->max_speed_kmph) }}" required>
              <div class="help">School buses are legally limited in most Indian
                states, commonly 40 km/h (PART R3).</div></div>
          </div>

          <div class="form-grid">
            <div class="field"><label>Self-release minimum grade</label>
              <input class="input" name="self_release_min_grade"
                     value="{{ old('self_release_min_grade', $school->self_release_min_grade) }}"
                     placeholder="e.g. 8 — leave blank to forbid entirely">
              <div class="help">A child may only leave the bus unaccompanied if
                they are at or above this grade <b>and</b> the school holds a
                signed consent. Both are required (Invariant #1).</div></div>

            <div class="field" style="padding-top:22px">
              <div class="check-row">
                <input type="checkbox" id="oa" name="require_office_approval_for_parent_collection" value="1"
                  @checked(old('require_office_approval_for_parent_collection', $school->require_office_approval_for_parent_collection))>
                <label for="oa" style="margin:0">Office must approve "I'm collecting them"</label>
              </div>
              <div class="help">When on, a parent's afternoon collection request
                stays pending until office staff approve, and the child stays on
                the boarding list until then (PART A2a).</div>
            </div>
          </div>

          <div class="form-actions">
            <button class="btn" type="submit">Save settings</button>
          </div>
        </div>
      </div>
    </form>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Bell times</h2><span class="spacer"></span>
        <span class="pill teal">{{ $bells->count() }} tiers</span></div>
      <div class="card-body tight">
        @forelse($bells as $b)
          <div style="padding:11px 16px;border-bottom:1px solid var(--line-soft);
                      display:flex;align-items:center;gap:9px">
            <div style="flex:1;min-width:0">
              <div style="font-weight:650">{{ $b->bell_tier }}</div>
              <div class="muted" style="font-size:11.5px">
                Grades {{ $b->grade_from ?? '—' }}–{{ $b->grade_to ?? '—' }} ·
                {{ \Carbon\Carbon::parse($b->start_time)->format('g:i A') }}
                to {{ \Carbon\Carbon::parse($b->end_time)->format('g:i A') }}
              </div>
            </div>
            <form method="POST" action="{{ route('bells.destroy', $b) }}"
                  onsubmit="return confirm('Remove the {{ $b->bell_tier }} bell time?')">
              @csrf @method('DELETE')
              <button class="icon-btn danger" title="Remove">×</button>
            </form>
          </div>
        @empty
          <div class="empty"><div class="big">🔔</div>
            <div class="t">No bell times yet</div>
            <div class="s">Add one per tier below.</div></div>
        @endforelse
      </div>
      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <form method="POST" action="{{ route('bells.store') }}">
          @csrf
          <div class="form-grid">
            <div class="field"><label>Tier name</label>
              <input class="input" name="bell_tier" placeholder="Primary" required></div>
            <div class="field"><label>Grades</label>
              <div style="display:flex;gap:6px">
                <input class="input" name="grade_from" placeholder="1">
                <input class="input" name="grade_to" placeholder="5">
              </div></div>
            <div class="field"><label>Start bell</label>
              <input class="input" type="time" name="start_time" required></div>
            <div class="field"><label>Dismissal</label>
              <input class="input" type="time" name="end_time" required></div>
          </div>
          <button class="btn sm" type="submit">Add bell time</button>
        </form>

        <div class="note" style="margin-top:13px;font-size:12px">
          <b>Legs are load-bearing.</b> One bus runs Senior, Middle and Primary
          as three separate trips with different children and different bells.
          Every operational row is keyed by the bell tier (PART C1).
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
