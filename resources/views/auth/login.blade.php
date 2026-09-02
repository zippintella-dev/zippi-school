<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · Zippi School Mobility</title>
  <link rel="stylesheet" href="{{ asset('css/zippi.css') }}">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚌</text></svg>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="mark"><span class="dot">Z</span><h1>Zippi School Mobility</h1></div>
    <div class="sub">Safe · Smart · Sustainable school transport</div>

    @if($errors->any())
      <div class="flash err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login.post') }}">
      @csrf
      <div class="field">
        <label for="email">Email</label>
        <input class="input" type="email" id="email" name="email"
               value="{{ old('email', 'ops@zippi.in') }}" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input class="input" type="password" id="password" name="password"
               value="password" required>
      </div>
      <button class="btn" type="submit">Sign in</button>
    </form>

    <div class="demo-creds">
      <div><b>Demo accounts</b> — password <code>password</code></div>
      <div class="row"><span>Zippi admin</span><code>ops@zippi.in</code></div>
      <div class="row"><span>Ops lead</span><code>lead@zippi.in</code></div>
      <div class="row"><span>School user</span><code>transport@phoenixgreens.edu.in</code></div>
    </div>
  </div>
</div>
</body>
</html>
