@extends('layouts.app')
@section('title', 'Buses')
@section('subtitle', $buses->count() . ' vehicles · ' . $evCount . ' electric · ' . $seats . ' seats')

@section('content')
<div class="grid c4" style="margin-bottom:18px">
  <div class="stat ok"><span class="rail"></span><div class="label">Electric</div>
    <div class="value">{{ $evCount }}<small> / {{ $buses->count() }}</small></div>
    <div class="foot">the sustainability story (PART S3)</div></div>
  <div class="stat"><span class="rail"></span><div class="label">Total seats</div>
    <div class="value">{{ $seats }}</div>
    <div class="foot">capacity enforced at the tap (PART L17)</div></div>
  <div class="stat"><span class="rail"></span><div class="label">GPS reporting</div>
    <div class="value">{{ $buses->filter(fn($b) => ! $b->isGpsStale())->count() }}</div>
    <div class="foot">of {{ $buses->count() }} vehicles</div></div>
  <div class="stat {{ $buses->filter(fn($b) => $b->complianceStatus() !== 'ok')->count() ? 'danger' : 'ok' }}">
    <span class="rail"></span><div class="label">Compliance flags</div>
    <div class="value">{{ $buses->filter(fn($b) => $b->complianceStatus() !== 'ok')->count() }}</div>
    <div class="foot">documents expired or expiring</div></div>
</div>


<details class="panel" @if($errors->any()) open @endif>
  <summary>Add a bus <span class="hint">registration, capacity, document expiries</span></summary>
  <div class="panel-body">
    @include('partials.errors')
    <form method="POST" action="{{ route('buses.store') }}">
      @csrf
      <div class="form-grid c3">
        <div class="field"><label>Registration</label>
          <input class="input" name="reg_no" value="{{ old('reg_no') }}" placeholder="TS09UB1234" required></div>
        <div class="field"><label>Model</label>
          <input class="input" name="model" value="{{ old('model') }}"></div>
        <div class="field"><label>Capacity</label>
          <input class="input" type="number" name="capacity" value="{{ old('capacity', 42) }}" required>
          <div class="help">Enforced at the boarding tap, not just reported (PART L17).</div></div>
        <div class="field"><label>Fitness expiry</label>
          <input class="input" type="date" name="fitness_expiry" value="{{ old('fitness_expiry') }}"></div>
        <div class="field"><label>Permit expiry</label>
          <input class="input" type="date" name="permit_expiry" value="{{ old('permit_expiry') }}"></div>
        <div class="field"><label>Insurance expiry</label>
          <input class="input" type="date" name="insurance_expiry" value="{{ old('insurance_expiry') }}"></div>
        <div class="field"><label>PUC expiry</label>
          <input class="input" type="date" name="puc_expiry" value="{{ old('puc_expiry') }}"></div>
        <div class="field"><label>Speed governor expiry</label>
          <input class="input" type="date" name="speed_governor_expiry" value="{{ old('speed_governor_expiry') }}"></div>
        <div class="field"><label>GPS device ID</label>
          <input class="input" name="gps_device_id" value="{{ old('gps_device_id') }}"></div>
      </div>
      <div class="check-row">
        <input type="checkbox" id="ev" name="is_ev" value="1" @checked(old('is_ev'))>
        <label for="ev" style="margin:0">Electric</label>
      </div>
      <div class="check-row">
        <input type="checkbox" id="cam" name="has_camera" value="1" @checked(old('has_camera', true))>
        <label for="cam" style="margin:0">Camera fitted</label>
      </div>
      <div class="form-actions"><button class="btn" type="submit">Add bus</button></div>
    </form>
  </div>
</details>

<div class="card">
  <div class="card-head"><h2>Fleet</h2></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Registration</th><th>Model</th><th class="num">Seats</th>
        <th>Power</th><th>GPS</th><th>Compliance</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($buses as $b)
        <tr>
          <td><a href="{{ route('buses.show', $b) }}"><b>{{ $b->reg_no }}</b></a></td>
          <td class="muted">{{ $b->model }}</td>
          <td class="num">{{ $b->capacity }}</td>
          <td>@if($b->is_ev)<span class="pill ok">Electric</span>
              @else<span class="pill muted">Diesel</span>@endif</td>
          <td>@if($b->isGpsStale())<span class="pill warn">stale</span>
              @else<span class="pill ok">{{ $b->last_ping_at?->diffForHumans(null, true) }} ago</span>@endif</td>
          <td>
            @php $st = $b->complianceStatus(); @endphp
            @if($st === 'ok')<span class="pill ok">OK</span>
            @elseif($st === 'expiring')<span class="pill warn">expiring</span>
            @else<span class="pill danger">expired</span>@endif
            @foreach($b->expiringDocuments(30) as $label => $days)
              <div class="muted" style="font-size:11px">
                {{ $label }}: {{ $days < 0 ? abs($days) . 'd overdue' : $days . 'd left' }}</div>
            @endforeach
          </td>
          <td>
            <div class="row-actions">
              <a class="btn sm" href="{{ route('buses.show', $b) }}">Details</a>
              <form method="POST" action="{{ route('buses.destroy', $b) }}"
                    onsubmit="return confirm('Remove {{ $b->reg_no }}? If it has trip history it will be retired instead.')">
                @csrf @method('DELETE')
                <button class="icon-btn danger" title="Remove">×</button>
              </form>
            </div>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
