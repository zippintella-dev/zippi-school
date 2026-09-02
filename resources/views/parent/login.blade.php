@extends('layouts.parent')
@section('title', 'Sign in · Zippi Parent')
@section('heading', 'Zippi Parent')

@section('content')
<div class="p-auth">

  <div class="p-auth-brand">
    <div class="mk">🚌</div>
    <h1>Zippi Parent</h1>
    <div class="p-hint">Track your child's school bus.</div>
  </div>

  <form method="POST" action="{{ route('parent.code') }}">
    @csrf

    <div class="p-field">
      <label for="phone">Your mobile number</label>
      {{-- inputmode=tel gives the numeric keypad; autocomplete lets the phone
           fill it from the address book without the parent typing. --}}
      <input class="p-input" id="phone" name="phone" type="tel" inputmode="tel"
             autocomplete="tel" required autofocus
             placeholder="+91 98765 43210" value="{{ old('phone') }}">
      @error('phone') <div class="p-err">{{ $message }}</div> @enderror
    </div>

    <button class="p-btn" type="submit">Send me a code</button>

    <p class="p-hint" style="margin-top:14px">
      Use the number your school has on file. We'll text you a 4-digit code —
      there's no password to remember.
    </p>
  </form>
</div>
@endsection
