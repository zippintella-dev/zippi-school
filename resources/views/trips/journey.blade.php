@extends('layouts.app')
@section('title', $child->name . ' · Journey record')
@section('subtitle', $child->grade . '-' . $child->section . ' · ' . $child->admission_no . ' · ' . $dayLabel)

@section('actions')
  <form method="GET" style="display:flex;gap:8px">
    <input class="input" type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">
  </form>
  <a class="btn ghost" href="{{ route('children.show', $child) }}">Student record</a>
@endsection

@section('content')

  <div class="note" style="margin-bottom:18px">
    <b>This is the child-level journey record.</b> Every state change carries the
    actor's identity, the coordinates, and server time. Nothing here is mutable —
    a correction is a new row that supersedes the old one, and both stay visible.
    It is the answer to "where was my child at 4:15", to an insurance query, and
    to a parent complaint (PART D2).
  </div>

  <div class="grid main-side">

    <div class="card">
      <div class="card-head">
        <h2>{{ \Carbon\Carbon::parse($date)->format('l j F Y') }}</h2>
        <span class="spacer"></span>
        <span class="hint">{{ $rows->count() }} tier{{ $rows->count() === 1 ? '' : 's' }}</span>
      </div>
      <div class="card-body">

        @forelse($rows as $row)
          @php
            $trip = $row->trip;
            $ho   = $handovers->get($trip->id);
            $abs  = $absences->get($trip->direction);
          @endphp

          <div style="margin-bottom:26px">
            <div style="display:flex;align-items:center;gap:9px;margin-bottom:12px">
              <span class="pill {{ $trip->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                {{ $trip->direction === 'Morning' ? 'Morning — home to school' : 'Afternoon — school to home' }}
              </span>
              <span class="pill neutral">{{ $trip->route->code }}</span>
              @if($trip->bell_tier)<span class="pill muted">{{ $trip->bell_tier }}</span>@endif
              <span class="pill {{ $row->statusTone() }}">{{ $row->statusLabel() }}</span>
            </div>

            @if($abs)
              <div class="note warn" style="margin-bottom:12px">
                <b>{{ $abs->markedByLabel() }}.</b>
                {{ $abs->reason ?: 'No reason given.' }}
                @if($abs->reason_code)
                  <span class="pill muted">{{ $abs->reason_code }}</span>
                @endif
              </div>
            @endif

            <div class="timeline">
              @if($trip->started_at)
                <div class="tl-item">
                  <div class="t">Trip started</div>
                  <div class="m">Bus {{ $trip->bus?->reg_no ?? '—' }} ·
                    driver {{ $trip->driver?->name ?? '—' }} ·
                    attendant {{ $trip->attendant?->name ?? '—' }}</div>
                  <div class="tm">{{ $trip->started_at->format('H:i:s') }}</div>
                </div>
              @endif

              @if($row->boarded_at)
                <div class="tl-item">
                  <div class="t">BOARDED — confirmed by attendant {{ $trip->attendant?->name }}</div>
                  <div class="m">{{ $row->stop?->name ?? 'Stop' }}
                    @if($row->boarded_lat)
                      · {{ number_format((float) $row->boarded_lat, 4) }},
                        {{ number_format((float) $row->boarded_lng, 4) }}
                    @endif
                  </div>
                  <div class="tm">{{ $row->boarded_at->format('H:i:s') }}</div>
                </div>
                <div class="tl-item muted">
                  <div class="t">Guardians notified</div>
                  <div class="m">Push delivered to
                    {{ $child->guardians->where('app_access', true)->count() }} device(s)</div>
                  <div class="tm">{{ $row->boarded_at->copy()->addSeconds(3)->format('H:i:s') }}</div>
                </div>
              @endif

              @if($row->status === 'not_at_stop')
                <div class="tl-item danger">
                  <div class="t">NOT AT STOP — bus waited
                    {{ $activeSchool->stop_wait_seconds }}s</div>
                  <div class="m">{{ $row->stop?->name }} · guardians notified.
                    Server enforced the minimum wait; the client timer is advisory only.</div>
                </div>
              @endif

              @if($trip->arrived_at_school_at && $row->status === 'arrived_at_school')
                <div class="tl-item">
                  <div class="t">ARRIVED AT SCHOOL</div>
                  <div class="m">Attendant confirmed disembark at the gate ·
                    {{ number_format((float) $activeSchool->latitude, 4) }},
                    {{ number_format((float) $activeSchool->longitude, 4) }}</div>
                  <div class="tm">{{ $trip->arrived_at_school_at->format('H:i:s') }}</div>
                </div>
              @endif

              @if($ho)
                <div class="tl-item">
                  <div class="t">HANDED OVER to {{ $ho->receiver_name ?? $ho->receiver?->name }}</div>
                  <div class="m">
                    method: {{ $ho->methodLabel() }}
                    @if($ho->code_attempts) · code verified on attempt {{ $ho->code_attempts }} @endif
                    @if($ho->verified_offline) · verified offline, synced later @endif
                  </div>
                  <div class="tm">{{ $ho->created_at?->format('H:i:s') }}</div>
                </div>
              @endif

              @if($trip->sweep_verified_at)
                <div class="tl-item">
                  <div class="t">Vehicle sweep confirmed by {{ $trip->attendant?->name }}</div>
                  <div class="m">Photo + geo-stamp captured at the final stop</div>
                  <div class="tm">{{ $trip->sweep_verified_at->format('H:i:s') }}</div>
                </div>
              @endif
            </div>
          </div>
        @empty
          <div class="empty">
            <div class="big">📄</div>
            <div class="t">No journey on this date</div>
            <div class="s">{{ $dayLabel }}</div>
          </div>
        @endforelse

        @if($rows->count())
          @php
            $onBus = $rows->sum(function ($r) {
              if (! $r->boarded_at) return 0;
              $end = $r->alighted_at ?? $r->trip->arrived_at_school_at ?? $r->trip->completed_at;
              return $end ? $r->boarded_at->diffInMinutes($end) : 0;
            });
          @endphp
          <div style="border-top:1px solid var(--line-soft);padding-top:13px;
                      display:flex;gap:22px;font-size:12.5px;color:var(--ink-3)">
            <span>Total on-bus time: <b style="color:var(--ink)">{{ $onBus }} min</b></span>
            <span>Deviation events: <b style="color:var(--ink)">
              {{ $rows->sum(fn($r) => $r->trip->events->where('event_type','deviation')->count()) }}</b></span>
            <span>Legs recorded: <b style="color:var(--ink)">{{ $rows->count() }}</b></span>
          </div>
        @endif
      </div>
    </div>

    <div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Student</h2></div>
        <div class="card-body">
          <div class="who-cell" style="margin-bottom:13px">
            <span class="avatar" style="width:42px;height:42px;flex:0 0 42px;font-size:14px">
              {{ \Illuminate\Support\Str::of($child->name)->substr(0,1) }}</span>
            <div>
              <div class="nm" style="font-size:15px">{{ $child->name }}</div>
              <div class="mt">{{ $child->grade }}-{{ $child->section }} ·
                {{ $child->admission_no }}</div>
            </div>
          </div>
          <dl class="kv" style="grid-template-columns:120px 1fr">
            <dt>Bell tier</dt><dd>{{ $child->bell_tier ?? '—' }}</dd>
            <dt>Blood group</dt><dd>{{ $child->blood_group ?? '—' }}</dd>
            <dt>Self release</dt>
            <dd>
              @if($child->canSelfRelease())
                <span class="pill ok">Permitted</span>
              @else
                <span class="pill muted">Not permitted</span>
              @endif
            </dd>
          </dl>
          @if($child->medical_notes)
            <div class="note warn" style="margin-top:11px">
              <b>Medical:</b> {{ $child->medical_notes }}
            </div>
          @endif
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Who may collect</h2></div>
        <div class="card-body tight">
          @foreach($child->authorizedReceivers as $ar)
            <div style="padding:9px 16px;border-bottom:1px solid var(--line-soft);
                        display:flex;align-items:center;gap:9px">
              <span class="avatar">{{ \Illuminate\Support\Str::of($ar->name)->substr(0,1) }}</span>
              <div style="min-width:0">
                <div style="font-weight:600">{{ $ar->name }}</div>
                <div class="muted" style="font-size:11.5px">
                  {{ ucfirst($ar->relationship) }}
                  @if($ar->guardian_id) · has app @else · no app, photo ID only @endif
                </div>
              </div>
            </div>
          @endforeach
        </div>
        <div class="card-body" style="border-top:1px solid var(--line-soft)">
          <div class="note" style="font-size:12px">
            A child is never released without a verified receiver. If nobody can
            be verified at the stop, the child returns to school — there is no
            "mark absent and drive on" (Invariant #1, PART A7).
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Recent days</h2></div>
        <div class="card-body tight">
          @foreach($recent as $d => $tiers)
            <a href="{{ route('children.journey', $child) }}?date={{ $d }}"
               style="display:flex;align-items:center;gap:9px;padding:8px 16px;
                      border-bottom:1px solid var(--line-soft);
                      color:inherit;text-decoration:none;
                      {{ $d === $date ? 'background:var(--teal-soft)' : '' }}">
              <span style="font-weight:{{ $d === $date ? '700' : '500' }}">
                {{ \Carbon\Carbon::parse($d)->format('D j M') }}</span>
              <span class="spacer" style="flex:1"></span>
              @foreach($tiers as $l)
                <span class="pill {{ $l->statusTone() }}" style="font-size:10.5px">
                  {{ $l->trip->direction === 'Morning' ? 'AM' : 'PM' }}</span>
              @endforeach
            </a>
          @endforeach
        </div>
      </div>
    </div>
  </div>

@endsection
