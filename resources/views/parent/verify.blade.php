@extends('layouts.parent')
@section('title', 'Enter your code · Zippi Parent')
@section('heading', 'Enter your code')
@section('back', route('parent.login'))

@section('content')
<div class="p-auth">

  <p class="p-hint" style="margin-bottom:16px">
    We sent a 4-digit code to <strong>{{ $phone }}</strong>.
  </p>

  <form method="POST" action="{{ route('parent.verify') }}">
    @csrf

    <div class="p-field">
      <label for="code">4-digit code</label>
      {{-- one-time-code lets iOS and Android offer the SMS code straight from
           the notification, which is the difference between a 3-second login
           and a parent switching apps to copy it. --}}
      <input class="p-input p-otp" id="code" name="code" type="text"
             inputmode="numeric" autocomplete="one-time-code"
             pattern="[0-9]*" maxlength="4" required autofocus>
      @error('code') <div class="p-err">{{ $message }}</div> @enderror
    </div>

    <button class="p-btn" type="submit">Sign in</button>
  </form>

  <form method="POST" action="{{ route('parent.resend') }}" style="margin-top:10px">
    @csrf
    <button class="p-btn ghost" type="submit">Send a new code</button>
  </form>

  @if(session('dev_otp'))
    {{-- Local development only. Gated on app()->environment('local') inside
         OtpService — there is deliberately no config flag that could turn this
         on in production (PART P1). --}}
    <div class="p-dev">Local dev only — your code is <strong>{{ session('dev_otp') }}</strong></div>
  @endif
</div>
@endsection
