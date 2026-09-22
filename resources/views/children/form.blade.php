@extends('layouts.app')
@section('title', $child->exists ? 'Edit ' . $child->name : 'Add student')
@section('subtitle', $child->exists ? $child->admission_no : 'A child has zero logins and N guardians (PART E1)')

@section('actions')
  @if($child->exists)
    <a class="btn ghost" href="{{ route('children.show', $child) }}#guardians">Guardians</a>
  @endif
  <a class="btn ghost" href="{{ $child->exists ? route('children.show', $child) : route('children.index') }}">Cancel</a>
@endsection

@section('content')
@include('partials.errors')

@php $am = $child->exists ? $child->assignmentFor('Morning') : null; @endphp

<form method="POST"
      action="{{ $child->exists ? route('children.update', $child) : route('children.store') }}">
  @csrf
  @if($child->exists) @method('PUT') @endif

  <div class="grid main-side">
    <div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Student</h2></div>
        <div class="card-body">
          <div class="form-grid">
            <div class="field"><label>Admission number</label>
              <input class="input" name="admission_no"
                     value="{{ old('admission_no', $child->admission_no) }}" required></div>
            <div class="field"><label>Full name</label>
              <input class="input" name="name" value="{{ old('name', $child->name) }}" required></div>
            <div class="field"><label>Grade</label>
              <input class="input" name="grade" value="{{ old('grade', $child->grade) }}" required></div>
            <div class="field"><label>Section</label>
              <input class="input" name="section" value="{{ old('section', $child->section) }}"></div>
            {{-- ⚠ ASSIGNED FROM THE GRADE, NOT CHOSEN.
                 This was a free select, and bell_tier decides which staggered
                 run a child rides: a grade-8 student saved as "Senior" is
                 collected for the 07:40 bell instead of 08:15 — 35 minutes
                 early — and sent home on the 14:40 dismissal instead of 15:15.
                 It went wrong three times on real data.

                 The server already rejects a tier that contradicts the grade,
                 so every option in that dropdown was either the derived value
                 or a validation error waiting to happen. A control whose only
                 outcomes are "same as automatic" and "refused" should not be a
                 control. It now shows what the school's own bell bands assign,
                 live, and posts nothing. --}}
            <div class="field"><label>Bell tier</label>
              <div class="input" id="tierBox" style="display:flex;align-items:center;min-height:42px">
                <span id="tierValue" class="muted">— enter a grade —</span>
              </div>
              <div class="help" id="tierHelp">Set by the grade bands under
                <a href="{{ route('settings') }}">Settings</a>.</div>
            </div>
            <div class="field"><label>Fee zone</label>
              <input class="input" name="transport_fee_zone"
                     value="{{ old('transport_fee_zone', $child->transport_fee_zone) }}"></div>
            <div class="field full"><label>Home address</label>
              <input class="input" name="home_address" value="{{ old('home_address', $child->home_address) }}">
              <div class="help">Record only. Routing uses the assigned <b>stop</b>,
                never the home coordinate — door-to-door is modelled as a stop
                with one child, so there is only ever one routing path (PART E1).</div></div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Safety &amp; medical</h2></div>
        <div class="card-body">
          <div class="form-grid">
            <div class="field"><label>Blood group</label>
              <input class="input" name="blood_group" value="{{ old('blood_group', $child->blood_group) }}"></div>
            <div class="field" style="padding-top:22px">
              <div class="check-row">
                <input type="checkbox" id="sr" name="self_release_consent" value="1"
                       @checked(old('self_release_consent', $child->self_release_consent))>
                <label for="sr" style="margin:0">Signed self-release consent on file</label>
              </div>
              <div class="help">Also requires the grade to be at or above the
                school's minimum ({{ $activeSchool->self_release_min_grade ?? 'not set' }}).
                Both are needed (Invariant #1).</div>
            </div>
            <div class="field full"><label>Medical notes</label>
              <textarea class="input" name="medical_notes" rows="2">{{ old('medical_notes', $child->medical_notes) }}</textarea>
              <div class="help">Shown to the attendant behind a deliberate tap —
                never on the default list, where it could be shoulder-surfed.</div></div>
          </div>
        </div>
      </div>

      @unless($child->exists)
        <div class="card">
          <div class="card-head"><h2>Primary guardian</h2><span class="spacer"></span>
            <span class="hint">more can be added after saving</span></div>
          <div class="card-body">
            <div class="note warn" style="margin-bottom:14px">
              A child with no guardian receives <b>no notifications at all</b> —
              no boarding confirmation, no handover, no SOS. Add at least one.
            </div>
            <div class="form-grid">
              <div class="field"><label>Name</label>
                <input class="input" name="guardian_name" value="{{ old('guardian_name') }}"></div>
              <div class="field"><label>Phone</label>
                <input class="input" name="guardian_phone" value="{{ old('guardian_phone') }}"
                       placeholder="+9198…"></div>
              <div class="field"><label>Email</label>
                <input class="input" name="guardian_email" value="{{ old('guardian_email') }}"></div>
              <div class="field"><label>Relationship</label>
                <select class="input" name="guardian_relationship">
                  @foreach(['mother','father','grandparent','guardian','other'] as $r)
                    <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                  @endforeach
                </select></div>
            </div>
          </div>
        </div>
      @endunless
    </div>

    <div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Stop assignment</h2></div>
        <div class="card-body">
          <div class="field"><label>Route</label>
            <select class="input" name="route_id" id="routeSel">
              <option value="">— unassigned —</option>
              @foreach($routes as $r)
                <option value="{{ $r->id }}" @selected(old('route_id', $am?->route_id) == $r->id)>
                  {{ $r->code }} — {{ $r->name }}</option>
              @endforeach
            </select></div>

          <div class="field"><label>Morning stop</label>
            <select class="input" name="morning_stop_id" id="amStop">
              <option value="">— select a route first —</option>
            </select></div>

          <div class="field"><label>Afternoon stop</label>
            <select class="input" name="afternoon_stop_id" id="pmStop">
              <option value="">— same as morning —</option>
            </select>
            <div class="help">A child can legitimately have a different afternoon
              stop — dropped at a grandparent's, for instance.</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <button class="btn" type="submit" style="width:100%;justify-content:center">
            {{ $child->exists ? 'Save changes' : 'Add student' }}</button>
        </div>
      </div>

      {{-- Guardians are NOT edited here on purpose: a guardian is a shared
           record across siblings, and burying it inside this child's form
           would imply the change is local to this child. It lives on the
           record page. This card exists because "Edit" is where people
           reasonably look for it first. --}}
      @if($child->exists)
        <div class="card" style="margin-top:16px">
          <div class="card-head"><h2>Guardians</h2><span class="spacer"></span>
            <span class="pill {{ $child->guardians->count() ? 'teal' : 'danger' }}">
              {{ $child->guardians->count() }}</span></div>
          <div class="card-body tight">
            @forelse($child->guardians as $g)
              <div style="padding:9px 16px;border-bottom:1px solid var(--line-soft);
                          display:flex;align-items:center;gap:9px">
                <span class="avatar">{{ \Illuminate\Support\Str::of($g->name)->substr(0,1) }}</span>
                <div style="min-width:0;flex:1">
                  <div style="font-weight:600;font-size:12.5px">{{ $g->name }}
                    @if($g->pivot->is_primary)<span class="pill teal">primary</span>@endif</div>
                  <div class="muted" style="font-size:11.5px">
                    {{ ucfirst($g->pivot->relationship) }} · {{ $g->maskedPhone() }}</div>
                </div>
              </div>
            @empty
              <div class="note danger" style="margin:14px;font-size:12px">
                <b>No guardian linked.</b> This child receives no boarding
                confirmation, no handover notification, and no SOS alert.
              </div>
            @endforelse
          </div>
          <div class="card-body" style="border-top:1px solid var(--line-soft)">
            <a class="btn sm ghost" style="width:100%;justify-content:center"
               href="{{ route('children.show', $child) }}#guardians">
              Edit guardians →</a>
            <div class="help" style="margin-top:9px">
              Name, phone and email belong to the guardian and are shared with any
              sibling they also guard, so they are edited on the student record.
            </div>
          </div>
        </div>
      @endif
    </div>
  </div>
</form>

<script>
  // Stop lists per route, so the stop dropdowns filter without a round trip.
  var STOPS = @json($routes->mapWithKeys(fn($r) => [$r->id => $r->stops->map(fn($s) => [
      'id' => $s->id, 'label' => $s->sequence . '. ' . $s->name,
  ])->values()]));

  var routeSel = document.getElementById('routeSel');
  var amStop   = document.getElementById('amStop');
  var pmStop   = document.getElementById('pmStop');
  var preAm    = @json(old('morning_stop_id', $am?->stop_id));
  var prePm    = @json(old('afternoon_stop_id', $child->exists ? $child->assignmentFor('Afternoon')?->stop_id : null));

  function fill() {
    var list = STOPS[routeSel.value] || [];
    amStop.innerHTML = '<option value="">— select a stop —</option>';
    pmStop.innerHTML = '<option value="">— same as morning —</option>';
    list.forEach(function (s) {
      amStop.add(new Option(s.label, s.id, false, String(s.id) === String(preAm)));
      pmStop.add(new Option(s.label, s.id, false, String(s.id) === String(prePm)));
    });
  }
  routeSel.addEventListener('change', function () { preAm = prePm = null; fill(); });
  fill();
</script>

{{-- Live tier preview. The SERVER decides — see ChildController::bellTierFor().
     Ranking a class name is what GradeLevel exists for, and a JavaScript copy
     of it would be a second place for the LKG bug to live. --}}
<script>
  (function () {
    var grade = document.querySelector('input[name="grade"]');
    var value = document.getElementById('tierValue');
    var help  = document.getElementById('tierHelp');
    if (!grade || !value) return;

    var timer = null, last = null;

    function show(text, cls) {
      value.textContent = text;
      value.className = cls;
    }

    function lookup() {
      var g = grade.value.trim();
      if (g === last) return;
      last = g;

      if (g === '') { show('— enter a grade —', 'muted'); help.textContent = ''; return; }

      fetch('{{ route('children.bellTier') }}?grade=' + encodeURIComponent(g),
            { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          show(d.tier || 'No tier covers this class', d.ok ? '' : 'danger');
          // ⚠ The server's sentence verbatim: it names the band and the bell
          // times, and when nothing matches it says the student will never be
          // put on a bus — which is the whole point of showing this here rather
          // than letting them find out at 07:30.
          help.textContent = d.message || '';
        })
        .catch(function () { /* offline: the server still assigns on save */ });
    }

    grade.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(lookup, 250);
    });
    lookup();
  })();
</script>

@endsection
