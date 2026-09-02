@extends('layouts.app')
@section('title', 'Live board')
@section('subtitle', $dayLabel)

@section('actions')
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <select class="input" name="direction" onchange="this.form.submit()" style="min-width:120px">
      @foreach(['All','Morning','Afternoon'] as $d)
        <option value="{{ $d }}" @selected($direction === $d)>{{ $d }}</option>
      @endforeach
    </select>
    <select class="input" name="tier" onchange="this.form.submit()" style="min-width:110px">
      <option value="All" @selected($tier === 'All')>All tiers</option>
      @foreach($tiers as $l)
        <option value="{{ $l }}" @selected($tier === $l)>{{ $l }}</option>
      @endforeach
    </select>
  </form>
@endsection

@section('content')

  {{-- ============ THE QUEUE IS THE PRIMARY UI (PART B2 / T4) ============ --}}
  <div class="card" style="margin-bottom:18px">
    <div class="card-head">
      <h2>Needs attention</h2>
      <span class="pill {{ $alerts->count() ? 'danger' : 'ok' }}">{{ $alerts->count() }}</span>
      <span class="spacer"></span>
      <span class="hint">
        An ops person watching 40 buses works a queue, not a map — so this sits above it.
      </span>
    </div>
    <div class="card-body tight">
      <div class="queue">
        @forelse($alerts as $a)
          <div class="queue-row">
            <span class="sev {{ $a->severity }}"></span>
            <div class="qmain">
              <div class="qtitle">
                {{ $a->typeLabel() }}
                @if($a->trip?->route)
                  <span class="muted" style="font-weight:400">· {{ $a->trip->route->code }}</span>
                @endif
                @if($a->driver_reason)
                  <span class="pill muted">{{ str_replace('_',' ', $a->driver_reason) }}</span>
                @endif
              </div>
              <div class="qmeta">{{ $a->detail }}</div>
            </div>

            <div style="text-align:right;display:flex;align-items:center;gap:10px">
              <div>
                <div class="pill {{ $a->severity === 'critical' ? 'critical'
                                    : ($a->severity === 'high' ? 'danger' : 'warn') }}">
                  {{ ucfirst($a->severity) }}</div>
                <div class="qage" style="margin-top:3px">{{ $a->ageMinutes() }} min old</div>
              </div>

              @if($a->acknowledged_at)
                <span class="pill ok">Acknowledged</span>
              @elseif(auth()->user()->isZippi())
                <form method="POST" action="{{ route('alerts.ack', $a) }}">@csrf
                  <button class="btn sm ghost" type="submit">Acknowledge</button>
                </form>
              @endif
              @if($a->trip)
                <a class="btn sm ghost" href="{{ route('trips.show', $a->trip) }}">Open</a>
              @endif
            </div>
          </div>
        @empty
          <div class="empty">
            <div class="big">✓</div>
            <div class="t">Nothing needs attention</div>
            <div class="s">All buses within corridor, on schedule, no open exceptions.</div>
          </div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="grid main-side">

    {{-- ============ Trip cards ============ --}}
    <div class="card">
      <div class="card-head">
        <h2>Trips</h2><span class="spacer"></span>
        <span class="hint">polls every 8s · <span id="poll-state">live</span></span>
      </div>
      <div class="card-body tight" id="trip-list">
        @forelse($trips as $trip)
          @php
            $rows = $trip->tripChildren;
            $expected = $rows->whereNotIn('status','absent')->count();
            $done = $rows->whereIn('status',
                      ['boarded','arrived_at_school','alighted_to_guardian','alighted_self_release'])->count();
            $pct = $expected > 0 ? round($done / $expected * 100) : 0;
            $nextStop = $trip->stopArrivals->firstWhere('arrived_at', null);
          @endphp
          <div class="trip">
            <span class="dir-strip {{ $trip->direction }}"></span>
            <div class="body">
              <div class="row1">
                <span class="code">{{ $trip->route->code }}</span>
                <span class="rname">{{ $trip->route->name }}</span>
                <span class="pill {{ $trip->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $trip->direction }}</span>
                @if($trip->bell_tier)<span class="pill neutral">{{ $trip->bell_tier }}</span>@endif

                @if($trip->status === 'started')
                  <span class="pill teal live">Now running</span>
                @elseif($trip->status === 'completed')
                  <span class="pill ok">Done</span>
                @else
                  <span class="pill muted">Up next</span>
                @endif

                @if($trip->bus?->isGpsStale() && $trip->status === 'started')
                  <span class="pill danger">GPS stale</span>
                @endif
                @if($trip->sweepPending())
                  <span class="pill danger">Sweep pending</span>
                @endif

                <span class="spacer"></span>
                <a class="btn sm ghost" href="{{ route('trips.show', $trip) }}">Detail</a>
              </div>

              <div class="row2">
                <span><b>{{ $done }}</b> of {{ $expected }} students</span>
                @if($rows->where('status','absent')->count())
                  <span class="muted">{{ $rows->where('status','absent')->count() }} absent</span>
                @endif
                <span class="muted">
                  Bus {{ $trip->bus?->reg_no ?? '—' }}
                  @if($trip->bus?->is_ev)<span class="pill ok" style="margin-left:4px">EV</span>@endif
                </span>
                @if($trip->status === 'started' && $trip->bus?->speed_kmph)
                  <span class="{{ $trip->bus->speed_kmph > $activeSchool->max_speed_kmph ? '' : 'muted' }}"
                        style="{{ $trip->bus->speed_kmph > $activeSchool->max_speed_kmph ? 'color:var(--danger);font-weight:650' : '' }}">
                    {{ $trip->bus->speed_kmph }} km/h
                  </span>
                @endif
              </div>

              <div class="row2" style="margin-top:4px">
                {{-- PART K9 — ops sees masked numbers; calls go via the proxy --}}
                <span class="muted">🚍 {{ $trip->driver?->name ?? 'No driver' }}
                  ({{ $trip->driver?->maskedPhone() ?? '—' }})</span>
                <span class="muted">👤 {{ $trip->attendant?->name ?? 'No attendant' }}
                  ({{ $trip->attendant?->maskedPhone() ?? '—' }})</span>
                @if($nextStop && $trip->status === 'started')
                  <span>Next: <b>{{ $nextStop->stop?->name }}</b>
                    @if($nextStop->scheduled_at)
                      <span class="muted">· sched {{ $nextStop->scheduled_at->format('g:i A') }}</span>
                    @endif
                  </span>
                @endif
              </div>

              <div class="prog"><i style="width: {{ $pct }}%"></i></div>
            </div>
          </div>
        @empty
          <div class="empty">
            <div class="big">🚌</div>
            <div class="t">No trips match this filter</div>
            <div class="s">{{ $dayLabel }}</div>
          </div>
        @endforelse
      </div>
    </div>

    {{-- ============ Map (secondary, by design) ============ --}}
    <div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-head">
          <h2>Map</h2><span class="spacer"></span>
          <span class="hint">secondary view</span>
        </div>
        <div class="card-body">
          @php
            // Simple normalised plot of live buses around the school gate.
            $running = $trips->where('status','started')->filter(fn($t) => $t->bus?->latitude);
            $lats = $running->map(fn($t) => (float) $t->bus->latitude)
                      ->push((float) $activeSchool->latitude);
            $lngs = $running->map(fn($t) => (float) $t->bus->longitude)
                      ->push((float) $activeSchool->longitude);
            $minLat = $lats->min(); $maxLat = $lats->max();
            $minLng = $lngs->min(); $maxLng = $lngs->max();
            $spanLat = max(0.0001, $maxLat - $minLat);
            $spanLng = max(0.0001, $maxLng - $minLng);
            $pos = function ($lat, $lng) use ($minLat,$minLng,$spanLat,$spanLng) {
              $x = 8 + (($lng - $minLng) / $spanLng) * 84;
              $y = 88 - (($lat - $minLat) / $spanLat) * 76;
              return "left: {$x}%; top: {$y}%;";
            };
          @endphp

          <div class="map-canvas">
            <div class="map-pin" style="{{ $pos((float) $activeSchool->latitude, (float) $activeSchool->longitude) }}">
              <span class="bubble sch">🏫 School</span>
            </div>
            @foreach($running as $t)
              <div class="map-pin {{ $t->bus->speed_kmph > $activeSchool->max_speed_kmph ? 'late' : '' }}"
                   style="{{ $pos((float) $t->bus->latitude, (float) $t->bus->longitude) }}">
                <span class="bubble bus">🚌 {{ $t->route->code }}</span>
              </div>
            @endforeach
            <div class="map-note">
              {{ $running->count() }} bus{{ $running->count() === 1 ? '' : 'es' }} live ·
              schematic plot (Google Maps + Firebase RTDB in production)
            </div>
          </div>

          <div class="legend" style="margin-top:11px">
            <span><i style="background:var(--teal)"></i>Within limit</span>
            <span><i style="background:var(--danger)"></i>Over {{ $activeSchool->max_speed_kmph }} km/h</span>
            <span><i style="background:var(--ink)"></i>School gate</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Board summary</h2></div>
        <div class="card-body">
          <dl class="kv">
            <dt>Trips today</dt><dd>{{ $trips->count() }}</dd>
            <dt>Running now</dt><dd>{{ $trips->where('status','started')->count() }}</dd>
            <dt>Students on board</dt>
            <dd>{{ $trips->sum(fn($t) => $t->tripChildren->where('status','boarded')->count()) }}</dd>
            <dt>Open incidents</dt><dd>{{ $incidents->count() }}</dd>
            <dt>Critical unacked</dt>
            <dd>{{ $alerts->where('severity','critical')->whereNull('acknowledged_at')->count() }}</dd>
          </dl>

          <div class="note" style="margin-top:13px">
            <b>Acknowledgement is named.</b> A critical row follows the ops user
            across navigation until a person claims it — an alert firing into an
            empty room is the failure this layer exists to prevent.
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // PART B3 — 8-second diff poll. Kept deliberately simple: it refreshes the
    // counts and flags, and full re-render happens on navigation.
    (function () {
      var el = document.getElementById('poll-state');
      var tick = 0;
      setInterval(function () {
        fetch(@json(route('live.data')), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            tick++;
            el.textContent = 'live · ' + d.trips.length + ' trips · poll ' + tick;
          })
          .catch(function () { el.textContent = 'reconnecting…'; });
      }, 8000);
    })();
  </script>

@endsection
