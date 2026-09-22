@extends('layouts.app')
@section('title', $member->name)
@section('subtitle', ucfirst($member->role) . ' · ' . $member->school?->name)

@section('actions')
  <a class="btn" href="{{ route('staff.index') }}">← All crew</a>
@endsection

@section('content')

@if($member->status !== 'active')
  <div class="note warn" style="margin-bottom:18px">
    <b>Inactive.</b> {{ $member->name }} is no longer on the active crew list and
    will not be assigned to new trips. Their trip history is retained.
  </div>
@endif

<div class="grid c2">

  {{-- ---------------------------------------------------------------- --}}
  <div class="col">
    <div class="card">
      <div class="card-head"><h2>Contact</h2></div>
      <div class="card-body">
        <table class="tbl">
          <tbody>
            <tr>
              <td class="muted" style="width:38%">Phone</td>
              <td>
                {{-- ⚠ Full number, deliberately. This is the office's own
                     employment record — see StaffController::show(). The
                     PARENT app and the live board still show •••••1234, and
                     tests assert it. --}}
                <b style="font-size:15px;letter-spacing:.2px">{{ $member->phone }}</b>
                <a class="btn sm" style="margin-left:10px"
                   href="tel:{{ preg_replace('/[^0-9+]/', '', $member->phone) }}">Call</a>
              </td>
            </tr>
            <tr><td class="muted">Role</td>
              <td><span class="pill {{ $member->isDriver() ? 'info' : 'teal' }}">
                {{ ucfirst($member->role) }}</span>
                <span class="muted" style="margin-left:6px">
                  {{ $member->canMarkChildren()
                     ? 'may mark children'
                     : 'cannot mark children — navigation, head count and SOS only' }}</span>
              </td></tr>
            @if($member->gender)
              <tr><td class="muted">Gender</td><td>{{ ucfirst($member->gender) }}</td></tr>
            @endif
            @if($member->isDriver())
              <tr><td class="muted">Experience</td>
                <td>{{ $member->heavy_vehicle_years ?? 0 }} yrs heavy vehicle</td></tr>
              <tr><td class="muted">Licence</td>
                <td>{{ $member->licence_no ?: '—' }}</td></tr>
            @endif
            <tr><td class="muted">Status</td>
              <td>{{ ucfirst($member->status) }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="margin-top:18px">
      <div class="card-head"><h2>Compliance</h2><span class="spacer"></span>
        @php $st = $member->complianceStatus(); @endphp
        @if($st === 'ok')<span class="pill ok">OK</span>
        @elseif($st === 'expiring')<span class="pill warn">expiring</span>
        @else<span class="pill danger">action needed</span>@endif
      </div>
      <div class="card-body tight">
        <table class="tbl">
          <thead><tr><th>Document</th><th>Date</th><th>State</th></tr></thead>
          <tbody>
            <tr>
              <td>Police verification</td>
              <td class="muted">{{ $member->police_verified_on ?: '—' }}</td>
              <td>
                @if($member->police_verification_status === 'verified')
                  <span class="pill ok">Verified</span>
                @else
                  <span class="pill danger">{{ ucfirst($member->police_verification_status) }}</span>
                @endif
              </td>
            </tr>
            @foreach(\App\Models\SchoolStaff::DOCUMENTS as $col => $label)
              @php
                $val  = $member->$col;
                $left = $val ? (int) \Carbon\Carbon::today()
                          ->diffInDays(\Carbon\Carbon::parse($val), false) : null;
              @endphp
              <tr>
                <td>{{ $label }}</td>
                <td class="muted">{{ $val ?: '—' }}</td>
                <td>
                  @if($val === null)<span class="pill danger">missing</span>
                  @elseif($left < 0)<span class="pill danger">expired {{ abs($left) }}d ago</span>
                  @elseif($left <= 30)<span class="pill warn">{{ $left }}d left</span>
                  @else<span class="pill ok">valid</span>@endif
                </td>
              </tr>
            @endforeach
            <tr>
              <td>Training completed</td>
              <td class="muted">{{ $member->training_completed_on ?: '—' }}</td>
              <td>@if($member->training_completed_on)<span class="pill ok">done</span>
                  @else<span class="pill warn">not recorded</span>@endif</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    {{-- ----------------------------------------------------------------
         ⚠ THE EDIT FORM EXISTS BECAUSE COMPLIANCE EXPIRES.

         StaffController@update() and PUT staff/{member} were both here from
         the start, and nothing in the dashboard posted to them — so a licence
         that lapsed, a police check that came back, or a medical certificate
         renewed in March could never be recorded. PART K15 then withholds that
         crew member from every trip generated afterwards, and the only symptom
         is `attendant = NONE` on a trip nobody realises is short-staffed.

         That is not a hypothetical: it happened on the first real onboarding,
         and the attendant simply never appeared on the bus.
    ---------------------------------------------------------------- --}}
    <div class="card">
      <div class="card-head"><h2>Edit crew member</h2></div>
      <div class="card-body">
        <form method="POST" action="{{ route('staff.update', $member) }}">
          @csrf @method('PUT')

          <div class="field"><label>Name</label>
            <input class="input" name="name" required
                   value="{{ old('name', $member->name) }}"></div>

          <div class="field"><label>Role</label>
            <select class="input" name="role" required>
              @foreach(['driver' => 'Driver', 'attendant' => 'Attendant'] as $v => $label)
                <option value="{{ $v }}" @selected(old('role', $member->role) === $v)>{{ $label }}</option>
              @endforeach
            </select>
            {{-- ⚠ Changing this changes what their device can do. The token is
                 minted against this row, so an attendant demoted to driver
                 loses every child-marking control at their next sign-in. --}}
            <div class="hint">A driver device renders no child-marking control anywhere.</div>
          </div>

          <div class="field"><label>Phone</label>
            <input class="input" name="phone" required
                   value="{{ old('phone', $member->phone) }}">
            {{-- ⚠ This number IS the login. UNIQUE(school_id, phone), and the
                 Fleet token is minted against this row. --}}
            <div class="hint">This is how they sign in to Zippi Fleet.</div>
          </div>

          <div class="field"><label>Gender</label>
            <input class="input" name="gender"
                   value="{{ old('gender', $member->gender) }}"></div>

          <h3 style="margin:22px 0 10px;font-size:13px;letter-spacing:.4px"
              class="muted">LICENCE</h3>

          <div class="field"><label>Licence number</label>
            <input class="input" name="licence_no"
                   value="{{ old('licence_no', $member->licence_no) }}"></div>

          <div class="field"><label>Licence expiry</label>
            <input class="input" type="date" name="licence_expiry"
                   value="{{ old('licence_expiry', $member->licence_expiry) }}"></div>

          <div class="field"><label>Heavy vehicle experience (years)</label>
            <input class="input" type="number" min="0" max="60" name="heavy_vehicle_years"
                   value="{{ old('heavy_vehicle_years', $member->heavy_vehicle_years) }}"></div>

          <h3 style="margin:22px 0 10px;font-size:13px;letter-spacing:.4px"
              class="muted">CLEARANCES</h3>

          <div class="field"><label>Police verification</label>
            <select class="input" name="police_verification_status" required>
              @foreach(['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $v => $label)
                <option value="{{ $v }}"
                  @selected(old('police_verification_status', $member->police_verification_status) === $v)>{{ $label }}</option>
              @endforeach
            </select></div>

          <div class="field"><label>Police verified on</label>
            <input class="input" type="date" name="police_verified_on"
                   value="{{ old('police_verified_on', $member->police_verified_on) }}"></div>

          <div class="field"><label>Medical fitness expiry</label>
            <input class="input" type="date" name="medical_fitness_expiry"
                   value="{{ old('medical_fitness_expiry', $member->medical_fitness_expiry) }}"></div>

          <div class="field"><label>Training completed on</label>
            <input class="input" type="date" name="training_completed_on"
                   value="{{ old('training_completed_on', $member->training_completed_on) }}"></div>

          <button class="btn primary" type="submit">Save changes</button>
          <div class="hint" style="margin-top:10px">
            Trips already generated keep the crew they were solved with.
            Run <code>school:generate-trips --force</code> to re-solve today's.
          </div>
        </form>
      </div>
    </div>

  </div>

  {{-- ---------------------------------------------------------------- --}}
  <div class="col">
    <div class="card">
      <div class="card-head"><h2>Everyday assignments</h2><span class="spacer"></span>
        <span class="hint">a stand-in for one day is set on the Daily roster</span>
      </div>
      <div class="card-body tight">
        @if($assignments->count())
          <table class="tbl">
            <thead><tr><th>Route</th><th>Direction</th><th>Tier</th><th>As</th></tr></thead>
            <tbody>
            @foreach($assignments as $a)
              <tr>
                <td><b>{{ $a->route?->code }}</b>
                  <span class="muted">{{ $a->route?->name }}</span></td>
                <td><span class="pill {{ $a->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $a->direction }}</span></td>
                <td class="muted">{{ $a->bell_tier ?: '—' }}</td>
                <td class="muted">
                  {{ $a->driver_id === $member->id ? 'Driver' : 'Attendant' }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        @else
          <div class="empty"><div class="big">🚌</div>
            <div class="t">No everyday route</div>
            <div class="s">{{ $member->name }} isn't the default crew on any route yet.</div></div>
        @endif
      </div>
    </div>

    <div class="card" style="margin-top:18px">
      <div class="card-head"><h2>Recent trips</h2><span class="spacer"></span>
        <span class="hint">last {{ $trips->count() }}</span>
      </div>
      <div class="card-body tight">
        @if($trips->count())
          <table class="tbl">
            <thead><tr><th>Date</th><th>Route</th><th>Direction</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($trips as $t)
              <tr>
                <td class="muted">{{ $t->service_date }}</td>
                <td><a href="{{ route('trips.show', $t) }}">
                  <b>{{ $t->route?->code }}</b></a>
                  <span class="muted">{{ $t->bell_tier }}</span></td>
                <td><span class="pill {{ $t->direction === 'Morning' ? 'morning' : 'afternoon' }}">
                  {{ $t->direction }}</span></td>
                <td>
                  @if($t->status === 'completed')<span class="pill ok">Done</span>
                  @elseif($t->status === 'started')<span class="pill teal">Running</span>
                  @else<span class="pill">{{ ucfirst($t->status) }}</span>@endif
                  @if($t->status === 'completed' && ! $t->sweep_verified_at)
                    <span class="pill danger">no sweep</span>
                  @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        @else
          <div class="empty"><div class="big">🗓</div>
            <div class="t">No trips yet</div>
            <div class="s">Trips appear here once {{ $member->name }} crews one.</div></div>
        @endif
      </div>
    </div>
  </div>

</div>
@endsection
