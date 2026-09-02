@extends('layouts.parent')
@section('title', $child->name . ' · Zippi Parent')
@section('heading', $child->name)
@section('back', route('parent.dashboard'))

@section('content')

{{-- PART A7 — the collection code. Afternoon only, and only while the child is
     still to be handed over. Shown before anything else because in the
     afternoon it is the only thing the parent opened the app for. --}}
@if($handoverCode)
  <div class="p-code">
    <div class="p-code-label">Handover code</div>
    <div class="p-code-value">{{ $handoverCode }}</div>
    <div class="p-code-note">Show or read this to the bus attendant at the stop. It changes every day.</div>
  </div>
@endif

<div class="p-card">
  <div class="p-card-head">
    <span class="p-name" id="statusLabel">{{ $card['absent'] ? 'Marked absent' : $card['status_label'] }}</span>
  </div>

  <dl style="margin:0">
    @if($card['trip'])
      <div class="p-row"><dt>Route</dt><dd>{{ $card['trip']['route'] }}</dd></div>
      <div class="p-row"><dt>Direction</dt><dd>{{ $card['trip']['direction'] }} · {{ $card['trip']['bell_tier'] }}</dd></div>
      @if($card['stop'])
        <div class="p-row">
          <dt>{{ $card['trip']['direction'] === 'Morning' ? 'Pickup stop' : 'Drop stop' }}</dt>
          <dd>{{ $card['stop']['name'] }}</dd>
        </div>
        @if($card['stop']['scheduled_at'])
          <div class="p-row"><dt>Scheduled</dt>
            <dd id="stopEta">{{ \Carbon\Carbon::parse($card['stop']['scheduled_at'])->format('g:i A') }}</dd></div>
        @endif
      @endif
      @if($card['trip']['attendant'])
        {{-- PART K9 — name and masked number. Never the crew's raw phone. --}}
        <div class="p-row"><dt>Attendant</dt>
          <dd>{{ $card['trip']['attendant']['name'] }} · {{ $card['trip']['attendant']['phone_masked'] }}</dd></div>
      @endif
      @if($card['trip']['driver'])
        <div class="p-row"><dt>Driver</dt>
          <dd>{{ $card['trip']['driver']['name'] }} · {{ $card['trip']['driver']['phone_masked'] }}</dd></div>
      @endif
      @if($card['trip']['bus'])
        <div class="p-row"><dt>Bus</dt><dd>{{ $card['trip']['bus']['reg_no'] }}</dd></div>
      @endif
    @else
      <div class="p-row"><dt>Today</dt><dd>No trip scheduled</dd></div>
    @endif
  </dl>
</div>

{{-- Enterprise L29 — the map exists only while a trip containing this child is
     running and the child has not reached a terminal state for that leg.
     Afternoon closes for this family at handover, independent of the rest of
     the route. Server decides; the client never second-guesses it. --}}
@if($card['show_live_map'])
  <div class="p-section-title">Live</div>
  <div class="p-map" id="map"
       data-stop-lat="{{ $card['stop']['latitude'] ?? '' }}"
       data-stop-lng="{{ $card['stop']['longitude'] ?? '' }}">
    <div class="p-map-empty" id="mapEmpty">Waiting for the bus to report its position…</div>
  </div>
  <p class="p-hint" style="margin:-4px 0 14px">
    Schematic view. Live GPS accuracy depends on the bus device.
  </p>
@endif

@if($card['timeline'])
  <div class="p-section-title">Today</div>
  <div class="p-card">
    <ul class="p-timeline" id="timeline">
      @foreach($card['timeline'] as $e)
        <li>
          <span class="p-time">{{ \Carbon\Carbon::parse($e['at'])->format('g:i A') }}</span>
          <span>{{ $e['text'] }}</span>
        </li>
      @endforeach
    </ul>
  </div>
@endif

{{-- PART A1/A2 — the parent's own absence control. --}}
<div class="p-section-title">Not riding?</div>
<div class="p-card">
  @if($card['absent'])
    <p class="p-hint" style="margin:0 0 12px">
      {{ $child->name }} is marked absent today
      ({{ implode(' & ', $card['absent_directions']) }}).
    </p>
    <form method="POST" action="{{ route('parent.child.absence.undo', $child->id) }}">
      @csrf @method('DELETE')
      <input type="hidden" name="service_date" value="{{ $card['service_date'] }}">
      <button class="p-btn ghost" type="submit">Undo — {{ $child->name }} is riding</button>
    </form>
  @else
    <form method="POST" action="{{ route('parent.child.absence', $child->id) }}">
      @csrf
      <div class="p-field">
        <label for="service_date">Date</label>
        <input class="p-input" id="service_date" name="service_date" type="date"
               value="{{ $card['service_date'] }}" min="{{ $card['service_date'] }}" required>
        @error('service_date') <div class="p-err">{{ $message }}</div> @enderror
      </div>
      <div class="p-field">
        <label for="direction">Which trip</label>
        <select class="p-input" id="direction" name="direction">
          <option value="Both">Both — not riding at all</option>
          <option value="Morning">Morning only</option>
          <option value="Afternoon">Afternoon only</option>
        </select>
      </div>
      <div class="p-field">
        <label for="reason_code">Reason (optional)</label>
        <select class="p-input" id="reason_code" name="reason_code">
          <option value="">Prefer not to say</option>
          <option value="sick">Unwell</option>
          <option value="travel">Travelling</option>
          <option value="exam">Exam</option>
          <option value="activity">Activity</option>
          <option value="other">Other</option>
        </select>
      </div>
      <button class="p-btn danger" type="submit">Mark absent</button>
    </form>
  @endif
</div>

@if($child->school?->contact_phone)
  <a class="p-btn ghost" style="margin-top:12px;text-align:center;text-decoration:none;line-height:24px"
     href="tel:{{ $child->school->contact_phone }}">Call {{ $child->school->name }}</a>
@endif
@endsection

@section('scripts')
@if($card['show_live_map'])
<script>
(function () {
  var map = document.getElementById('map');
  if (!map) return;

  var URL_ = @json(route('parent.child.data', $child->id));
  var timer = null;

  function place(cls, lat, lng, glyph, bounds) {
    var el = document.createElement('div');
    el.className = 'p-marker ' + cls;
    el.textContent = glyph;
    // Schematic projection into the box, padded so markers never clip the edge.
    var x = bounds.spanLng ? (lng - bounds.minLng) / bounds.spanLng : 0.5;
    var y = bounds.spanLat ? (bounds.maxLat - lat) / bounds.spanLat : 0.5;
    el.style.left = (12 + x * 76) + '%';
    el.style.top  = (14 + y * 72) + '%';
    map.appendChild(el);
  }

  function render(d) {
    var busLat = d.trip && d.trip.bus ? d.trip.bus.latitude : null;
    var busLng = d.trip && d.trip.bus ? d.trip.bus.longitude : null;
    var stopLat = d.stop ? d.stop.latitude : null;
    var stopLng = d.stop ? d.stop.longitude : null;

    map.querySelectorAll('.p-marker').forEach(function (n) { n.remove(); });
    var empty = document.getElementById('mapEmpty');

    if (busLat == null || busLng == null) {
      if (empty) empty.style.display = 'flex';
      return;
    }
    if (empty) empty.style.display = 'none';

    var lats = [Number(busLat)], lngs = [Number(busLng)];
    if (stopLat != null) { lats.push(Number(stopLat)); lngs.push(Number(stopLng)); }

    var b = {
      minLat: Math.min.apply(null, lats), maxLat: Math.max.apply(null, lats),
      minLng: Math.min.apply(null, lngs), maxLng: Math.max.apply(null, lngs)
    };
    b.spanLat = b.maxLat - b.minLat;
    b.spanLng = b.maxLng - b.minLng;

    if (stopLat != null) place('stop', Number(stopLat), Number(stopLng), '📍', b);
    place('bus', Number(busLat), Number(busLng), '🚌', b);
  }

  function poll() {
    fetch(URL_, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var label = document.getElementById('statusLabel');
        if (label) label.textContent = d.absent ? 'Marked absent' : d.status_label;
        // The server owns this decision (L29). When it closes the map for this
        // family, reload so the whole screen matches — code, timeline and all.
        if (!d.show_live_map) { clearInterval(timer); location.reload(); return; }
        render(d);
      })
      .catch(function () {});
  }

  poll();
  timer = setInterval(poll, 10000);   // PART F6 — 10s while foregrounded

  // ⚠ PART F6 / enterprise L14, carried forward verbatim: fetch IMMEDIATELY on
  // resume. A push that arrives while backgrounded is dropped with no
  // buffering, so waiting 10s for the next tick leaves the screen stale at
  // exactly the moment the parent opens it.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') poll();
  });
})();
</script>
@endif
@endsection
