@extends('layouts.app')
@section('title', $route->code . ' · ' . $route->name)
@section('subtitle', ($route->bell_tier ?: 'No tier') . ' · version ' . $route->version)

@section('actions')
  <a class="btn ghost" href="{{ route('routes.index') }}">← All routes</a>
@endsection

@section('content')
@include('partials.errors')

<div class="grid main-side">
  <div>
    {{-- ---------- Stop sequence ---------- --}}
    <div class="card" style="margin-bottom:16px">
      <div class="card-head">
        <h2>Stop sequence</h2><span class="spacer"></span>
        <span class="hint">morning order · afternoon
          {{ $route->afternoon_mirrors_morning ? 'mirrors it' : 'authored separately' }}</span>
      </div>
      <div class="card-body tight">
        <table class="tbl">
          <thead><tr>
            <th>#</th><th>Stop</th><th>Coordinates</th>
            <th class="num">Students</th><th>Order</th><th></th>
          </tr></thead>
          <tbody>
          @forelse($route->stops as $s)
            <tr>
              <td><span class="avatar sq" style="width:24px;height:24px;flex:0 0 24px;font-size:10px">
                {{ $s->sequence }}</span></td>
              <td>
                <details>
                  <summary style="cursor:pointer;font-weight:650">{{ $s->name }}</summary>
                  <form method="POST" action="{{ route('stops.update', $s) }}"
                        style="margin-top:9px;padding:10px;background:#FAFBFC;border-radius:8px">
                    @csrf @method('PUT')
                    <div class="form-grid">
                      <div class="field"><label>Name</label>
                        <input class="input" name="name" value="{{ $s->name }}" required></div>
                      <div class="field"><label>Landmark</label>
                        <input class="input" name="landmark" value="{{ $s->landmark }}"></div>
                      <div class="field"><label>Latitude</label>
                        <input class="input" name="latitude" value="{{ $s->latitude }}" required></div>
                      <div class="field"><label>Longitude</label>
                        <input class="input" name="longitude" value="{{ $s->longitude }}" required></div>
                    </div>
                    <button class="btn sm" type="submit">Save stop</button>
                  </form>
                </details>
                @if($s->landmark)
                  <div class="muted" style="font-size:11.5px">{{ $s->landmark }}</div>
                @endif
              </td>
              <td class="muted" style="font-size:12px">
                {{ number_format((float) $s->latitude, 5) }}, {{ number_format((float) $s->longitude, 5) }}</td>
              <td class="num">{{ $stopCounts[$s->id] ?? 0 }}</td>
              <td>
                <div class="row-actions">
                  @if(! $loop->first)
                    <form method="POST" action="{{ route('stops.move', $s) }}">
                      @csrf <input type="hidden" name="direction" value="up">
                      <button class="icon-btn" title="Move earlier">↑</button>
                    </form>
                  @endif
                  @if(! $loop->last)
                    <form method="POST" action="{{ route('stops.move', $s) }}">
                      @csrf <input type="hidden" name="direction" value="down">
                      <button class="icon-btn" title="Move later">↓</button>
                    </form>
                  @endif
                </div>
              </td>
              <td>
                <form method="POST" action="{{ route('stops.destroy', $s) }}"
                      onsubmit="return confirm('Remove {{ $s->name }} from {{ $route->code }}?')">
                  @csrf @method('DELETE')
                  <button class="icon-btn danger" title="Remove stop">×</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="6"><div class="empty"><div class="big">📍</div>
              <div class="t">No stops yet</div>
              <div class="s">Add them below, in the order the bus will drive them.</div>
            </div></td></tr>
          @endforelse
          <tr style="background:#FAFBFC">
            <td>🏫</td><td><b>School gate</b></td>
            <td class="muted" style="font-size:12px">
              {{ number_format((float) $activeSchool->latitude, 5) }},
              {{ number_format((float) $activeSchool->longitude, 5) }}</td>
            <td class="num">—</td><td></td><td></td>
          </tr>
          </tbody>
        </table>
      </div>

      <div class="card-body" style="border-top:1px solid var(--line-soft)">
        <div class="sectitle">Add a stop</div>
        <form method="POST" action="{{ route('stops.store', $route) }}">
          @csrf
          <div class="form-grid c3">
            <div class="field"><label>Stop name</label>
              <input class="input" name="name" placeholder="Silver Oak Gate" required>
              <div class="help">Use a name parents recognise, not a coordinate.</div></div>
            <div class="field"><label>Latitude</label>
              <input class="input" name="latitude" placeholder="17.4645" required></div>
            <div class="field"><label>Longitude</label>
              <input class="input" name="longitude" placeholder="78.3652" required></div>
          </div>
          <button class="btn sm" type="submit">Add stop to end of route</button>
        </form>
      </div>
    </div>

    {{-- ---------- Route settings ---------- --}}
    <details class="panel">
      <summary>Route settings <span class="hint">code, name, bell tier, mirroring</span></summary>
      <div class="panel-body">
        <form method="POST" action="{{ route('routes.update', $route) }}">
          @csrf @method('PUT')
          <div class="form-grid c3">
            <div class="field"><label>Route number</label>
              <input class="input" name="code" value="{{ $route->code }}" required></div>
            <div class="field"><label>Name</label>
              <input class="input" name="name" value="{{ $route->name }}" required></div>
            <div class="field"><label>Bell tier</label>
              <select class="input" name="bell_tier">
                <option value="">— none —</option>
                @foreach($tiers as $l)
                  <option value="{{ $l }}" @selected($route->bell_tier === $l)>{{ $l }}</option>
                @endforeach
              </select></div>
          </div>
          <div class="check-row">
            <input type="checkbox" id="mirror" name="afternoon_mirrors_morning" value="1"
                   @checked($route->afternoon_mirrors_morning)>
            <label for="mirror" style="margin:0">Afternoon runs the morning stops in reverse</label>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Save route</button>
          </div>
        </form>

        <form method="POST" action="{{ route('routes.destroy', $route) }}" style="margin-top:12px"
              onsubmit="return confirm('Delete {{ $route->code }}? This only works if no students are assigned.')">
          @csrf @method('DELETE')
          <button class="btn danger sm" type="submit">Delete route</button>
          <span class="help" style="display:inline;margin-left:8px">
            Blocked while students are still assigned.</span>
        </form>
      </div>
    </details>
  </div>

  <div>
    {{-- ---------- Default crew ---------- --}}
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>Default crew</h2><span class="spacer"></span>
        <span class="hint">every day</span></div>
      <div class="card-body">
        <form method="POST" action="{{ route('routes.crew', $route) }}">
          @csrf
          @foreach(['Morning' => 'morning', 'Afternoon' => 'afternoon'] as $dir => $prefix)
            @php $a = $route->staffAssignments->firstWhere('direction', $dir); @endphp
            <div class="sectitle" style="margin-top:{{ $loop->first ? '0' : '16px' }}">
              <span class="pill {{ $dir === 'Morning' ? 'morning' : 'afternoon' }}">{{ $dir }}</span>
            </div>
            <div class="field"><label>Driver</label>
              <select class="input" name="{{ $prefix }}_driver_id">
                <option value="">— none —</option>
                @foreach($drivers as $d)
                  <option value="{{ $d->id }}" @selected($a?->driver_id == $d->id)>{{ $d->name }}</option>
                @endforeach
              </select></div>
            <div class="field"><label>Attendant</label>
              <select class="input" name="{{ $prefix }}_attendant_id">
                <option value="">— none —</option>
                @foreach($attendants as $t)
                  <option value="{{ $t->id }}" @selected($a?->attendant_id == $t->id)>{{ $t->name }}</option>
                @endforeach
              </select></div>
            <div class="field"><label>Bus</label>
              <select class="input" name="{{ $prefix }}_bus_id">
                <option value="">— none —</option>
                @foreach($buses as $b)
                  <option value="{{ $b->id }}" @selected($a?->bus_id == $b->id)>
                    {{ $b->reg_no }}{{ $b->is_ev ? ' · EV' : '' }}</option>
                @endforeach
              </select></div>
          @endforeach

          <button class="btn" type="submit" style="width:100%;justify-content:center">Save crew</button>
        </form>

        <div class="note" style="margin-top:13px;font-size:12px">
          This is the <b>default</b>, applying every day. A one-day stand-in is
          set on the Daily roster and reverts automatically the next day (PART O).
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Re-sequencing</h2></div>
      <div class="card-body">
        <div class="note warn" style="font-size:12px">
          In production, changing stop order or times requires an
          <b>effective-from date</b>, creates a new route version, leaves
          already-generated trips intact, and notifies affected guardians with
          old and new times. Versioning is what keeps historical journey records
          accurate — a March record must render against March's stop times
          (PART G3). The arrows reorder; the versioning wrapper lands with the
          trip generator.
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
