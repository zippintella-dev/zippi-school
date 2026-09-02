@extends('layouts.app')
@section('title', $child->name)
@section('subtitle', $child->grade . '-' . $child->section . ' · ' . $child->admission_no)

@section('actions')
  <a class="btn ghost" href="{{ route('children.edit', $child) }}">Edit</a>
  <a class="btn" href="{{ route('children.journey', $child) }}">Journey record →</a>
@endsection

@section('content')
@include('partials.errors')

@if($child->status !== 'active')
  <div class="note warn" style="margin-bottom:16px">
    <b>This student has been removed from transport.</b> They are excluded from
    routes, rosters and trip generation. The journey history is retained (PART K13).
    <form method="POST" action="{{ route('children.restore', $child) }}" style="margin-top:10px">
      @csrf
      <button class="btn sm" type="submit">Put back on transport</button>
    </form>
  </div>
@endif

<div class="grid main-side">
  <div>
    {{-- ---------- Assignment ---------- --}}
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>Transport assignment</h2></div>
      <div class="card-body tight">
        @if($child->stopAssignments->count())
          <table class="tbl">
            <thead><tr><th>Direction</th><th>Route</th><th>Stop</th><th>Effective</th></tr></thead>
            <tbody>
            @foreach($child->stopAssignments as $a)
              <tr>
                <td><span class="pill {{ $a->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $a->direction }}</span></td>
                <td><b>{{ $a->route?->code }}</b> <span class="muted">{{ $a->route?->name }}</span></td>
                <td>{{ $a->stop?->name }}
                  <span class="muted">· stop {{ $a->stop?->sequence }}</span></td>
                <td class="muted">{{ $a->effective_from ?? 'always' }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        @else
          <div class="empty"><div class="big">📍</div>
            <div class="t">No stop assigned</div>
            <div class="s">This student won't appear on any route, roster or trip.</div></div>
        @endif
      </div>

      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <div class="sectitle">Assign a stop</div>
        <form method="POST" action="{{ route('children.assign', $child) }}">
          @csrf
          @php $am = $child->assignmentFor('Morning'); $pm = $child->assignmentFor('Afternoon'); @endphp
          <div class="form-grid c3">
            <div class="field"><label>Route</label>
              <select class="input" name="route_id" id="routeSel" required>
                <option value="">— select —</option>
                @foreach($routes as $r)
                  <option value="{{ $r->id }}" @selected($am?->route_id == $r->id)>{{ $r->code }}</option>
                @endforeach
              </select></div>
            <div class="field"><label>Morning stop</label>
              <select class="input" name="morning_stop_id" id="amStop" required></select></div>
            <div class="field"><label>Afternoon stop</label>
              <select class="input" name="afternoon_stop_id" id="pmStop"></select></div>
          </div>
          <button class="btn sm" type="submit">Save assignment</button>
        </form>
      </div>
    </div>

    {{-- ---------- Absences ---------- --}}
    <div class="card">
      <div class="card-head"><h2>Absence history</h2><span class="spacer"></span>
        <span class="hint">reason codes feed the attendance export</span></div>
      <div class="card-body tight">
        <table class="tbl">
          <thead><tr><th>Date</th><th>Direction</th><th>Marked by</th><th>Reason</th><th></th></tr></thead>
          <tbody>
          @forelse($absences as $ab)
            <tr>
              <td>{{ \Carbon\Carbon::parse($ab->service_date)->format('D j M Y') }}</td>
              <td><span class="pill {{ $ab->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                {{ $ab->direction }}</span></td>
              <td><span class="pill {{ $ab->bannerTone() }}">{{ $ab->markedByLabel() }}</span></td>
              <td class="muted">{{ $ab->reason ?: '—' }}</td>
              <td>
                <form method="POST" action="{{ route('absences.remove', $ab) }}"
                      onsubmit="return confirm('Remove this absence? The deletion is audit-logged.')">
                  @csrf @method('DELETE')
                  <button class="icon-btn danger" title="Remove">×</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="muted" style="padding:18px;text-align:center">
              No absences recorded.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>

      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <div class="sectitle">Mark absent</div>
        <form method="POST" action="{{ route('children.absence', $child) }}">
          @csrf
          <div class="form-grid c3">
            <div class="field"><label>From</label>
              <input class="input" type="date" name="start_date" value="{{ $today }}" required></div>
            <div class="field"><label>To</label>
              <input class="input" type="date" name="end_date" value="{{ $today }}" required></div>
            <div class="field"><label>Reason code</label>
              <select class="input" name="reason_code">
                @foreach(['sick','travel','exam','activity','other'] as $rc)
                  <option value="{{ $rc }}">{{ ucfirst($rc) }}</option>
                @endforeach
              </select></div>
          </div>
          <div class="check-row">
            <input type="checkbox" id="dm" name="directions[]" value="Morning" checked>
            <label for="dm" style="margin:0">Morning — home to school</label>
          </div>
          <div class="check-row">
            <input type="checkbox" id="da" name="directions[]" value="Afternoon" checked>
            <label for="da" style="margin:0">Afternoon — school to home</label>
          </div>
          <div class="field"><label>Note</label>
            <input class="input" name="reason" placeholder="Optional"></div>
          <div class="help" style="margin-bottom:10px">
            A range covering a holiday creates fewer rows than days — non-school
            days are skipped and reported back, never silently included (PART A3).
          </div>
          <button class="btn sm" type="submit">Record absence</button>
        </form>
      </div>
    </div>
  </div>

  <div>
    {{-- ---------- Guardians ---------- --}}
    <div class="card" id="guardians" style="margin-bottom:16px;scroll-margin-top:20px">
      <div class="card-head"><h2>Guardians</h2><span class="spacer"></span>
        <span class="pill {{ $child->guardians->count() ? 'teal' : 'danger' }}">
          {{ $child->guardians->count() }}</span></div>
      <div class="card-body tight">
        @forelse($child->guardians as $g)
          @php $others = $g->children->where('id', '!=', $child->id)->count(); @endphp
          <div style="padding:10px 16px;border-bottom:1px solid var(--line-soft)">
            <div style="display:flex;align-items:center;gap:9px">
              <span class="avatar">{{ \Illuminate\Support\Str::of($g->name)->substr(0,1) }}</span>
              <div style="min-width:0;flex:1">
                <div style="font-weight:600">{{ $g->name }}
                  @if($g->pivot->is_primary)<span class="pill teal">primary</span>@endif</div>
                <div class="muted" style="font-size:11.5px">
                  {{ ucfirst($g->pivot->relationship) }} · {{ $g->maskedPhone() }}</div>
              </div>
              @if($g->app_access)<span class="pill ok">app</span>@endif
              <form method="POST" action="{{ route('children.guardians.remove', [$child, $g]) }}"
                    onsubmit="return confirm('Unlink {{ addslashes($g->name) }} from {{ addslashes($child->name) }}?')">
                @csrf @method('DELETE')
                <button class="icon-btn danger" title="Unlink">×</button>
              </form>
            </div>

            {{-- Edit. Collapsed so the list stays scannable; the full number is
                 shown here on purpose — this is the screen where a school user
                 corrects it, and they cannot fix a number they cannot read. --}}
            <details style="margin-top:8px">
              <summary style="cursor:pointer;font-size:12px;color:var(--teal-dark);font-weight:650">
                Edit details</summary>
              <form method="POST" action="{{ route('children.guardians.update', [$child, $g]) }}"
                    style="margin-top:10px">
                @csrf @method('PUT')
                <div class="field"><label>Name</label>
                  <input class="input" name="name" value="{{ old('name', $g->name) }}" required></div>
                <div class="field"><label>Phone</label>
                  <input class="input" name="phone" value="{{ old('phone', $g->phone) }}" required></div>
                <div class="field"><label>Email</label>
                  <input class="input" type="email" name="email" value="{{ old('email', $g->email) }}"></div>
                <div class="field"><label>Relationship to {{ $child->name }}</label>
                  <select class="input" name="relationship" required>
                    @foreach(['mother','father','grandparent','guardian','other'] as $r)
                      <option value="{{ $r }}" @selected($g->pivot->relationship === $r)>{{ ucfirst($r) }}</option>
                    @endforeach
                  </select></div>
                <div class="check-row">
                  <input type="checkbox" id="pri-{{ $g->id }}" name="is_primary" value="1"
                         @checked($g->pivot->is_primary)>
                  <label for="pri-{{ $g->id }}" style="margin:0">Primary contact</label>
                </div>
                <div class="check-row">
                  <input type="checkbox" id="app-{{ $g->id }}" name="app_access" value="1"
                         @checked($g->app_access)>
                  <label for="app-{{ $g->id }}" style="margin:0">Can use the parent app</label>
                </div>
                @if($others > 0)
                  <div class="note warn" style="font-size:12px;margin:8px 0">
                    {{ $g->name }} also guards <b>{{ $others }}</b> other
                    {{ $others === 1 ? 'student' : 'students' }}. Name, phone and email
                    are shared — changing them here changes them there too.
                    Relationship and primary apply only to {{ $child->name }}.
                  </div>
                @endif
                <button class="btn sm" type="submit">Save guardian</button>
              </form>
            </details>
          </div>
        @empty
          <div class="note danger" style="margin:14px">
            <b>No guardian linked.</b> This child receives no boarding
            confirmation, no handover notification, and no SOS alert.
          </div>
        @endforelse
      </div>
      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <details>
          <summary style="cursor:pointer;font-weight:650;font-size:12.5px">+ Link a guardian</summary>
          <form method="POST" action="{{ route('children.guardians.add', $child) }}" style="margin-top:11px">
            @csrf
            <div class="field"><label>Name</label>
              <input class="input" name="name" required></div>
            <div class="field"><label>Phone</label>
              <input class="input" name="phone" placeholder="+9198…" required></div>
            <div class="field"><label>Email</label>
              <input class="input" type="email" name="email"></div>
            <div class="field"><label>Relationship</label>
              <select class="input" name="relationship" required>
                @foreach(['mother','father','grandparent','guardian','other'] as $r)
                  <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                @endforeach
              </select></div>
            <div class="check-row">
              <input type="checkbox" id="pri" name="is_primary" value="1">
              <label for="pri" style="margin:0">Primary contact</label>
            </div>
            <button class="btn sm" type="submit">Link guardian</button>
          </form>
        </details>
        <div class="note" style="margin-top:11px;font-size:12px">
          Numbers are masked everywhere ops can see them. Staff and guardians
          reach each other through a call proxy (PART K9).
        </div>
      </div>
    </div>

    {{-- ---------- Authorized receivers ---------- --}}
    <div class="card">
      <div class="card-head"><h2>Who may collect</h2></div>
      <div class="card-body tight">
        @foreach($child->authorizedReceivers as $ar)
          <div style="padding:10px 16px;border-bottom:1px solid var(--line-soft);
                      display:flex;align-items:center;gap:9px">
            <span class="avatar">{{ \Illuminate\Support\Str::of($ar->name)->substr(0,1) }}</span>
            <div style="min-width:0;flex:1">
              <div style="font-weight:600">{{ $ar->name }}</div>
              <div class="muted" style="font-size:11.5px">
                {{ ucfirst($ar->relationship) }}
                @if(! $ar->guardian_id) · no app — photo ID at the stop @endif</div>
            </div>
            <form method="POST" action="{{ route('receivers.remove', $ar) }}"
                  onsubmit="return confirm('Remove {{ $ar->name }} from the collect list?')">
              @csrf @method('DELETE')
              <button class="icon-btn danger" title="Remove">×</button>
            </form>
          </div>
        @endforeach
      </div>
      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <details>
          <summary style="cursor:pointer;font-weight:650;font-size:12.5px">+ Add a receiver</summary>
          <form method="POST" action="{{ route('children.receivers.add', $child) }}" style="margin-top:11px">
            @csrf
            <div class="field"><label>Name</label><input class="input" name="name" required></div>
            <div class="field"><label>Relationship</label>
              <input class="input" name="relationship" placeholder="grandparent" required></div>
            <div class="field"><label>Phone</label><input class="input" name="phone"></div>
            <button class="btn sm" type="submit">Add receiver</button>
          </form>
        </details>
        <div class="note" style="margin-top:11px;font-size:12px">
          A child is never released without a verified receiver. If nobody at the
          stop can be verified, the child returns to school — there is no
          "mark absent and drive on" (Invariant #1, PART A7).
        </div>
      </div>
    </div>
  </div>
</div>

{{-- ── Removing a student ───────────────────────────────────────────────
     Two different actions, deliberately not one button:

     • Remove from transport  — reversible, keeps the journey record. This is
       what a school wants 99% of the time (child left, changed to private
       transport, moved schools).
     • Delete permanently     — only offered when NOTHING operational is
       attached, i.e. a row typed in error during onboarding. PART K13 protects
       the journey record; it does not protect a typo.                      --}}
<div class="card" style="margin-top:20px;border-color:#F5CFCF">
  <div class="card-head">
    <h2 style="color:var(--danger)">Removing this student</h2>
  </div>
  <div class="card-body">

    @php $hasHistory = array_sum($history) > 0; @endphp

    @if($child->status === 'active')
      <p class="hint" style="margin:0 0 12px">
        Takes {{ $child->name }} off routes, rosters and trip generation from the
        next generation onwards. The journey record is kept, and you can put them
        back at any time.
      </p>
      <form method="POST" action="{{ route('children.destroy', $child) }}"
            onsubmit="return confirm('Remove {{ addslashes($child->name) }} from transport? You can put them back later.')">
        @csrf @method('DELETE')
        <button class="btn danger" type="submit">Remove from transport</button>
      </form>
    @else
      <p class="hint" style="margin:0 0 12px">
        {{ $child->name }} is already off transport.
      </p>
      <form method="POST" action="{{ route('children.restore', $child) }}">
        @csrf
        <button class="btn" type="submit">Put back on transport</button>
      </form>
    @endif

    <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">

    @if($hasHistory)
      <p class="hint" style="margin:0">
        <b>Permanent deletion is not available.</b>
        {{ $child->name }} has transport history —
        @php
          $bits = collect($history)->filter()->map(fn ($n, $k) => $n . ' ' . $k)->implode(', ');
        @endphp
        {{ $bits }}. That record is what answers a dispute months later, so it
        cannot be destroyed (PART K13). Remove them from transport instead.
      </p>
    @else
      <p class="hint" style="margin:0 0 12px">
        {{ $child->name }} has never ridden, been marked absent or appeared in an
        incident, so there is no journey record to protect. If this row was added
        by mistake you can delete it outright. Guardians linked to no other
        student are removed too. <b>This cannot be undone.</b>
      </p>
      <form method="POST" action="{{ route('children.force-destroy', $child) }}"
            onsubmit="return confirm('Permanently delete {{ addslashes($child->name) }}? This cannot be undone.')">
        @csrf @method('DELETE')
        <button class="btn danger" type="submit">Delete permanently</button>
      </form>
    @endif

  </div>
</div>

<script>
  var STOPS = @json($routes->mapWithKeys(fn($r) => [$r->id => $r->stops->map(fn($s) => [
      'id' => $s->id, 'label' => $s->sequence . '. ' . $s->name,
  ])->values()]));

  var routeSel = document.getElementById('routeSel');
  var amStop = document.getElementById('amStop');
  var pmStop = document.getElementById('pmStop');
  var preAm = @json($child->assignmentFor('Morning')?->stop_id);
  var prePm = @json($child->assignmentFor('Afternoon')?->stop_id);

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
@endsection
