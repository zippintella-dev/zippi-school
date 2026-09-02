@extends('layouts.app')
@section('title', $trip->route->code . ' · ' . $trip->direction)
@section('subtitle', \Carbon\Carbon::parse($trip->service_date)->format('D j M Y') .
    ($trip->bell_tier ? ' · ' . $trip->bell_tier . ' tier' : ''))

@section('actions')
  <a class="btn ghost" href="{{ route('trips.index') }}">← All trips</a>
@endsection

@section('content')


{{-- ═══════════════════════════════════════════════════════════════════════
     ⚠ TEST HARNESS — stands in for Zippi Fleet (Layer 3), which is not built.

     Everything here is the attendant's job in the real product. It exists so
     the parent app can actually be exercised end to end. Zippi staff only,
     every action audit-logged as simulated, and the safety invariants are
     enforced rather than bypassed — completing still fails while a child is
     unaccounted for or the sweep is missing.

     Delete this block, TripSimulationController and its routes when Fleet ships.
     ═══════════════════════════════════════════════════════════════════════ --}}
@if(auth('web')->user()?->isZippi())
  @php
    $simRows   = $trip->tripChildren;
    $simNext   = $trip->stopArrivals->firstWhere('arrived_at', null);
    $simDone   = $trip->stopArrivals->whereNotNull('arrived_at')->count();
    $simTotal  = $trip->stopArrivals->count();
  @endphp

  <div class="card" style="margin-bottom:16px;border-color:#F3DFB8;background:#FFFDF7">
    <div class="card-head">
      <h2 style="color:var(--warn)">⚗ Simulate this trip</h2>
      <span class="spacer"></span>
      <span class="pill warn">Zippi Fleet not built</span>
    </div>

    <div class="card-body">
      <p class="hint" style="margin:0 0 14px">
        Stands in for the attendant's app so you can watch the parent app react.
        Every action is recorded as <b>simulated</b> in the audit trail, and the
        safety invariants still apply. The parent app polls every 10 seconds.
      </p>

      {{-- Step 1 — run state --}}
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        @if($trip->status === 'scheduled')
          <form method="POST" action="{{ route('sim.start', $trip) }}">@csrf
            <button class="btn" type="submit">1 · Start trip</button></form>
        @elseif($trip->status === 'started')
          <form method="POST" action="{{ route('sim.advance', $trip) }}">@csrf
            <button class="btn" type="submit" @disabled(! $simNext)>
              2 · Move bus to {{ $simNext?->stop?->name ?? 'next stop' }}</button></form>
          <span class="pill teal">{{ $simDone }}/{{ $simTotal }} stops reached</span>

          @if($trip->isMorning())
            <form method="POST" action="{{ route('sim.arrive', $trip) }}">@csrf
              <button class="btn ghost" type="submit">4 · Arrive at school</button></form>
          @endif

          <form method="POST" action="{{ route('sim.sweep', $trip) }}">@csrf
            <button class="btn ghost" type="submit"
              @disabled($trip->sweep_verified_at !== null)>
              5 · {{ $trip->sweep_verified_at ? 'Swept ✓' : 'Record sweep' }}</button></form>

          <form method="POST" action="{{ route('sim.complete', $trip) }}">@csrf
            <button class="btn" type="submit">6 · Complete trip</button></form>
        @else
          <span class="pill ok">Trip {{ $trip->status }}</span>
        @endif

        <span class="spacer"></span>
        <form method="POST" action="{{ route('sim.reset', $trip) }}"
              onsubmit="return confirm('Reset this trip to scheduled and clear all simulated events?')">
          @csrf
          <button class="btn sm ghost" type="submit">Reset</button></form>
      </div>
    </div>

    {{-- Step 3 — per child --}}
    @if($trip->status === 'started')
      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <div class="sectitle">3 · Mark each child</div>
        <table class="tbl">
          <thead><tr><th>Child</th><th>Stop</th><th>State</th><th></th></tr></thead>
          <tbody>
          @foreach($simRows as $row)
            <tr>
              <td><b>{{ $row->child?->name }}</b></td>
              <td class="muted">{{ $row->stop?->name ?? '—' }}</td>
              <td><span class="pill {{ $row->status === 'pending' ? 'muted' : 'teal' }}">
                {{ str_replace('_', ' ', $row->status) }}</span></td>
              <td style="white-space:nowrap">
                @if($row->status === 'pending')
                  <form method="POST" action="{{ route('sim.board', [$trip, $row]) }}" style="display:inline">
                    @csrf <button class="btn sm" type="submit">Board</button></form>
                  @if($trip->isMorning())
                    <form method="POST" action="{{ route('sim.notatstop', [$trip, $row]) }}" style="display:inline">
                      @csrf <button class="btn sm ghost" type="submit">Not at stop</button></form>
                  @endif
                @elseif($row->status === 'boarded' && ! $trip->isMorning())
                  <form method="POST" action="{{ route('sim.handover', [$trip, $row]) }}" style="display:inline">
                    @csrf <input type="hidden" name="method" value="handover_code">
                    <button class="btn sm" type="submit">Hand over</button></form>
                  <form method="POST" action="{{ route('sim.handover', [$trip, $row]) }}" style="display:inline">
                    @csrf <input type="hidden" name="method" value="self_release">
                    <button class="btn sm ghost" type="submit">Self-release</button></form>
                @else
                  <span class="muted">—</span>
                @endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
        <div class="help" style="margin-top:10px">
          A child cannot be marked "not at stop" on an <b>afternoon</b> trip — if
          nobody is at the drop stop they return to school (Invariant #1).
        </div>
      </div>
    @endif
  </div>
@endif

  {{-- ===== Safety invariant banners ===== --}}
  @if(count($unaccounted) && $trip->status === 'started')
    <div class="note danger" style="margin-bottom:16px">
      <b>{{ count($unaccounted) }} child{{ count($unaccounted) === 1 ? '' : 'ren' }} unaccounted.</b>
      This trip cannot be completed until each is resolved — <code>/trip-complete</code>
      returns 422 with these ids. (Critical Safety Invariant #2, PART L2.)
    </div>
  @endif

  @if($trip->sweepPending())
    <div class="note danger" style="margin-bottom:16px">
      <b>Vehicle sweep not confirmed.</b> The attendant must walk the bus and
      capture a geo-stamped photo before the trip closes. It cannot be skipped
      or pre-tapped. (Critical Safety Invariant #3, PART L3.)
    </div>
  @elseif($trip->status === 'completed' && ! $trip->sweep_verified_at)
    <div class="note danger" style="margin-bottom:16px">
      <b>Completed without a verified sweep.</b> This trip was force-completed;
      an incident has been raised and the attendant's record is flagged.
    </div>
  @endif

  <div class="grid c4" style="margin-bottom:18px">
    @php
      $rows = $trip->tripChildren;
      $expected = $rows->whereNotIn('status','absent')->count();
      $boarded  = $rows->whereIn('status',['boarded','arrived_at_school',
                     'alighted_to_guardian','alighted_self_release'])->count();
    @endphp
    <div class="stat"><span class="rail"></span>
      <div class="label">Students</div>
      <div class="value">{{ $boarded }}<small> / {{ $expected }}</small></div>
      <div class="foot">{{ $rows->where('status','absent')->count() }} absent ·
        {{ $rows->where('status','not_at_stop')->count() }} missed stop</div>
    </div>
    <div class="stat {{ $trip->sweep_verified_at ? 'ok' : 'danger' }}"><span class="rail"></span>
      <div class="label">Vehicle sweep</div>
      <div class="value" style="font-size:19px;padding-top:6px">
        {{ $trip->sweep_verified_at ? 'Verified' : 'Pending' }}</div>
      <div class="foot">{{ $trip->sweep_verified_at?->format('g:i A') ?? 'blocks completion' }}</div>
    </div>
    <div class="stat {{ $trip->headcount_verified ? 'ok' : 'warn' }}"><span class="rail"></span>
      <div class="label">Head count</div>
      <div class="value" style="font-size:19px;padding-top:6px">
        {{ $trip->headcount_reported !== null ? $trip->headcount_reported . ' counted' : 'Not taken' }}</div>
      <div class="foot">{{ $trip->headcount_verified ? 'reconciled' : 'unreconciled' }}</div>
    </div>
    <div class="stat"><span class="rail"></span>
      <div class="label">Schedule</div>
      <div class="value" style="font-size:19px;padding-top:6px">
        @if($trip->delayMinutes() !== null)
          {{ $trip->delayMinutes() > 0 ? '+' . $trip->delayMinutes() . ' min' : 'On time' }}
        @else — @endif
      </div>
      <div class="foot">bell {{ $trip->bell_time ? \Carbon\Carbon::parse($trip->bell_time)->format('g:i A') : '—' }}</div>
    </div>
  </div>

  <div class="grid main-side">

    {{-- ===== Stops, children grouped under each (PART A8) ===== --}}
    <div class="card">
      <div class="card-head">
        <h2>Stops &amp; students</h2><span class="spacer"></span>
        {{-- The student pills below open that child's journey record for this
             date — morning and afternoon together. It was already a link, but
             styled exactly like a static label, so nobody found it. --}}
        <span class="hint">tap a student for their AM/PM journey · authored sequence, never re-ordered (PART G1)</span>
      </div>
      <div class="card-body tight">
        @foreach($trip->stopArrivals as $arr)
          @php $kids = $byStop->get($arr->stop_id, collect()); @endphp
          <div style="padding:13px 16px;border-bottom:1px solid var(--line-soft)">
            <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
              <span class="avatar sq">{{ $arr->sequence }}</span>
              <b>{{ $arr->stop?->name }}</b>
              @if($arr->arrived_at)
                <span class="pill ok">Arrived {{ $arr->arrived_at->format('g:i A') }}</span>
                @if($arr->driftMinutes() !== null && abs($arr->driftMinutes()) > 5)
                  <span class="pill warn">{{ $arr->driftMinutes() > 0 ? '+' : '' }}{{ $arr->driftMinutes() }} min</span>
                @endif
              @else
                <span class="pill muted">Not reached</span>
              @endif
              <span class="spacer"></span>
              <span class="muted" style="font-size:12px">
                sched {{ $arr->scheduled_at?->format('g:i A') ?? '—' }} ·
                {{ $kids->count() }} child{{ $kids->count() === 1 ? '' : 'ren' }}
              </span>
            </div>

            @if($kids->count())
              <div style="margin-top:9px;display:flex;flex-wrap:wrap;gap:7px">
                @foreach($kids as $k)
                  <a href="{{ route('children.journey', $k->child) }}?date={{ $trip->service_date }}"
                     class="pill-link"
                     title="Open {{ $k->child?->name }}'s journey record for this day — morning and afternoon">
                    <span class="pill {{ $k->statusTone() }}">
                      {{ $k->child?->name }}
                      <span style="opacity:.65">· {{ $k->statusLabel() }}</span>
                      <span aria-hidden="true" style="opacity:.5;margin-left:2px">→</span>
                    </span>
                  </a>
                @endforeach
              </div>
            @endif
          </div>
        @endforeach
      </div>
    </div>

    <div>
      {{-- ===== Crew & vehicle ===== --}}
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Crew &amp; vehicle</h2></div>
        <div class="card-body">
          <dl class="kv">
            <dt>Route</dt><dd>{{ $trip->route->code }} — {{ $trip->route->name }}</dd>
            <dt>Bus</dt>
            <dd>{{ $trip->bus?->reg_no ?? '—' }}
              @if($trip->bus?->is_ev)<span class="pill ok">EV</span>@endif</dd>
            <dt>Driver</dt>
            <dd>{{ $trip->driver?->name ?? '—' }}
              <span class="muted">{{ $trip->driver?->maskedPhone() }}</span></dd>
            <dt>Attendant</dt>
            <dd>{{ $trip->attendant?->name ?? '—' }}
              <span class="muted">{{ $trip->attendant?->maskedPhone() }}</span></dd>
            <dt>Started</dt><dd>{{ $trip->started_at?->format('g:i A') ?? '—' }}</dd>
            <dt>At school</dt><dd>{{ $trip->arrived_at_school_at?->format('g:i A') ?? '—' }}</dd>
            <dt>Completed</dt><dd>{{ $trip->completed_at?->format('g:i A') ?? '—' }}</dd>
            <dt>Distance</dt>
            <dd>{{ $trip->distance_m ? round($trip->distance_m / 1000, 1) . ' km' : '—' }}</dd>
          </dl>
        </div>
      </div>

      {{-- ===== Handovers (PART A7) ===== --}}
      @if($trip->handovers->count())
        <div class="card" style="margin-bottom:16px">
          <div class="card-head"><h2>Handovers</h2></div>
          <div class="card-body tight">
            @foreach($trip->handovers as $h)
              <div style="padding:10px 16px;border-bottom:1px solid var(--line-soft)">
                <div style="font-weight:650">{{ $h->child?->name }}</div>
                <div class="muted" style="font-size:12px">
                  {{ $h->methodLabel() }} → {{ $h->receiver_name ?? $h->receiver?->name ?? '—' }}
                  @if($h->code_attempts > 1) · {{ $h->code_attempts }} attempts @endif
                  @if($h->verified_offline) · <span class="pill muted">offline</span> @endif
                </div>
              </div>
            @endforeach
          </div>
        </div>
      @endif

      {{-- ===== Exceptions ===== --}}
      @if($trip->events->count())
        <div class="card" style="margin-bottom:16px">
          <div class="card-head"><h2>Exceptions</h2></div>
          <div class="card-body tight">
            @foreach($trip->events as $e)
              <div class="queue-row">
                <span class="sev {{ $e->severity }}"></span>
                <div class="qmain">
                  <div class="qtitle">{{ $e->typeLabel() }}</div>
                  <div class="qmeta">{{ $e->detail }}</div>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      @endif

      {{-- ===== Audit trail (PART J4) ===== --}}
      <div class="card">
        <div class="card-head">
          <h2>Audit trail</h2><span class="spacer"></span>
          <span class="pill muted">append-only</span>
        </div>
        <div class="card-body">
          @forelse($audit as $a)
            <div class="tl-item">
              <div class="t">{{ $a->actionLabel() }}</div>
              <div class="m">{{ $a->actor_name }} · {{ $a->created_at?->format('g:i A') }}</div>
              @if($a->notes)<div class="m">"{{ $a->notes }}"</div>@endif
            </div>
          @empty
            <div class="muted" style="font-size:12.5px">
              No admin overrides on this trip. Any override would appear here —
              and could never be edited or removed afterwards (PART K1).
            </div>
          @endforelse
        </div>
      </div>
    </div>
  </div>

@endsection
