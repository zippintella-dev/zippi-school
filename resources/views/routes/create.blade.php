@extends('layouts.app')
@section('title', 'New route')
@section('subtitle', 'Stops are added next, in the order buses will drive them')

@section('actions')
  <a class="btn ghost" href="{{ route('routes.index') }}">Cancel</a>
@endsection

@section('content')
@include('partials.errors')

<div class="grid main-side">
  <div class="card">
    <div class="card-head"><h2>Route</h2></div>
    <div class="card-body">
      <form method="POST" action="{{ route('routes.store') }}">
        @csrf
        <div class="form-grid">
          <div class="field"><label>Route number</label>
            <input class="input" name="code" value="{{ old('code') }}" placeholder="3" required>
            <div class="help">Type just the number — <code>3</code> becomes
              <code>RT-03</code>, zero-padded (PART O6).</div></div>
          <div class="field"><label>Bell tier</label>
            <select class="input" name="bell_tier">
              <option value="">— none —</option>
              @foreach($tiers as $l)
                <option value="{{ $l }}" @selected(old('bell_tier') === $l)>{{ $l }}</option>
              @endforeach
            </select></div>
          <div class="field full"><label>Route name</label>
            <input class="input" name="name" value="{{ old('name') }}"
                   placeholder="Kondapur – Gachibowli" required>
            <div class="help">Use area names parents recognise.</div></div>
        </div>

        <div class="check-row">
          <input type="checkbox" id="mirror" name="afternoon_mirrors_morning" value="1"
                 @checked(old('afternoon_mirrors_morning', true))>
          <label for="mirror" style="margin:0">Afternoon runs the morning stops in reverse</label>
        </div>
        <div class="help" style="margin-bottom:14px">
          The default. Uncheck only when one-way streets or dismissal traffic
          force a genuinely different afternoon path.
        </div>

        <div class="form-actions">
          <button class="btn" type="submit">Create route</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Why order is authored</h2></div>
    <div class="card-body">
      <div class="note">
        The enterprise module re-orders stops by nearest-neighbour on every ride.
        A school cannot: parents stand at a published stop at a published time,
        term after term. Here the sequence you author is <b>frozen</b> for trip
        execution, and optimisation moves to design time (PART G1).
      </div>
    </div>
  </div>
</div>
@endsection
