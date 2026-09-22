@extends('layouts.app')
@section('title', 'Drivers & attendants')
@section('subtitle', 'Two vehicle actors — the driver drives, the attendant marks children (PART H3)')

@section('actions')
  <form method="GET">
    <select class="input" name="role" onchange="this.form.submit()">
      <option value="all" @selected($role==='all')>All roles</option>
      <option value="driver" @selected($role==='driver')>Drivers</option>
      <option value="attendant" @selected($role==='attendant')>Attendants</option>
    </select>
  </form>
@endsection

@section('content')
<div class="note" style="margin-bottom:18px">
  <b>Why the role split matters.</b> A driver marking children is a driver looking
  at a phone while children board around the vehicle. The driver device shows
  navigation, head count and SOS — and cannot mark children at all.
</div>


<details class="panel" @if($errors->any()) open @endif>
  <summary>Add a driver or attendant
    <span class="hint">licence, police verification, medical fitness</span></summary>
  <div class="panel-body">
    @include('partials.errors')
    <form method="POST" action="{{ route('staff.store') }}">
      @csrf
      <div class="form-grid c3">
        <div class="field"><label>Role</label>
          <select class="input" name="role" required>
            <option value="driver" @selected(old('role')==='driver')>Driver</option>
            <option value="attendant" @selected(old('role')==='attendant')>Attendant</option>
          </select></div>
        <div class="field"><label>Name</label>
          <input class="input" name="name" value="{{ old('name') }}" required></div>
        <div class="field"><label>Phone</label>
          <input class="input" name="phone" value="{{ old('phone') }}" required></div>
        <div class="field"><label>Gender</label>
          <select class="input" name="gender">
            <option value="">—</option>
            <option value="female" @selected(old('gender')==='female')>Female</option>
            <option value="male" @selected(old('gender')==='male')>Male</option>
          </select>
          <div class="help">Some states mandate a female attendant.</div></div>
        {{-- ⚠ DRIVER ONLY. An attendant does not drive the bus, so a licence
             number, its expiry and a heavy-vehicle history are not facts about
             them — asking for them invites somebody to type the driver's.
             Hidden by the role toggle below; the SERVER strips them regardless,
             because a hidden input still posts. --}}
        <div class="field driver-only"><label>Licence number</label>
          <input class="input" name="licence_no" value="{{ old('licence_no') }}"></div>
        <div class="field driver-only"><label>Licence expiry</label>
          <input class="input" type="date" name="licence_expiry" value="{{ old('licence_expiry') }}"></div>
        <div class="field driver-only"><label>Heavy-vehicle years</label>
          <input class="input" type="number" name="heavy_vehicle_years" value="{{ old('heavy_vehicle_years') }}"></div>
        <div class="field"><label>Police verification</label>
          <select class="input" name="police_verification_status" required>
            <option value="pending" @selected(old('police_verification_status')==='pending')>Pending</option>
            <option value="verified" @selected(old('police_verification_status')==='verified')>Verified</option>
            <option value="rejected" @selected(old('police_verification_status')==='rejected')>Rejected</option>
          </select></div>
        <div class="field"><label>Verified on</label>
          <input class="input" type="date" name="police_verified_on" value="{{ old('police_verified_on') }}"></div>
        <div class="field"><label>Medical fitness expiry</label>
          <input class="input" type="date" name="medical_fitness_expiry" value="{{ old('medical_fitness_expiry') }}"></div>
        <div class="field"><label>Training completed</label>
          <input class="input" type="date" name="training_completed_on" value="{{ old('training_completed_on') }}"></div>
      </div>
      <div class="form-actions"><button class="btn" type="submit">Add staff member</button></div>
    </form>
  </div>
</details>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Name</th><th>Role</th><th>Phone</th><th>Experience</th>
        <th>Police verification</th><th>Compliance</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($staff as $s)
        <tr>
          <td>
            <div class="who-cell">
              <span class="avatar">{{ \Illuminate\Support\Str::of($s->name)->substr(0,1) }}</span>
              <div><div class="nm">
                  <a href="{{ route('staff.show', $s) }}">{{ $s->name }}</a></div>
                @if($s->gender)<div class="mt">{{ ucfirst($s->gender) }}</div>@endif</div>
            </div>
          </td>
          <td><span class="pill {{ $s->isDriver() ? 'info' : 'teal' }}">{{ ucfirst($s->role) }}</span></td>
          {{-- Masked in the list, full on the record. The list is often open on
               a shared screen in the transport office; the record is a
               deliberate click. --}}
          <td class="muted"><a href="{{ route('staff.show', $s) }}"
                               title="Open {{ $s->name }}'s record for the full number"
                            >{{ $s->maskedPhone() }}</a></td>
          <td class="muted">
            {{ $s->isDriver() ? $s->heavy_vehicle_years . ' yrs heavy vehicle' : '—' }}</td>
          <td>
            @if($s->police_verification_status === 'verified')
              <span class="pill ok">Verified</span>
              <div class="muted" style="font-size:11px">{{ $s->police_verified_on }}</div>
            @else
              <span class="pill danger">{{ ucfirst($s->police_verification_status) }}</span>
            @endif
          </td>
          <td>
            @php $st = $s->complianceStatus(); @endphp
            @if($st === 'ok')<span class="pill ok">OK</span>
            @elseif($st === 'expiring')<span class="pill warn">expiring</span>
            @else<span class="pill danger">action needed</span>@endif
          </td>
          <td>
            <div class="row-actions">
              <a class="btn sm" href="{{ route('staff.show', $s) }}">Details</a>
              <form method="POST" action="{{ route('staff.destroy', $s) }}"
                    onsubmit="return confirm('Remove {{ $s->name }}? If they have trip history they will be marked inactive instead.')">
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

{{-- ⚠ Driver-only fields, hidden the moment "Attendant" is chosen.
     Progressive: with JS off every field simply stays visible and the server
     still strips them, so the record is correct either way. It runs on load as
     well as on change, because a validation bounce re-renders the form with
     old('role') already set to attendant. --}}
<script>
  document.querySelectorAll('form select[name="role"]').forEach(function (sel) {
    function sync() {
      var driver = sel.value === 'driver';
      sel.form.querySelectorAll('.driver-only').forEach(function (f) {
        f.hidden = !driver;
        f.querySelectorAll('input, select').forEach(function (i) { i.disabled = !driver; });
      });
    }
    sel.addEventListener('change', sync);
    sync();
  });
</script>

@endsection
