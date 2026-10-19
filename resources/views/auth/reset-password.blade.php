<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password · AquaTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{min-height:100vh;background:#f4f7fa;font-family:system-ui}.panel{max-width:440px;margin:8vh auto;padding:32px;background:white;border:1px solid #e4eaf0;border-radius:8px}.brand{color:#8b1e2d;font-weight:700}.btn-brand{background:#b42332;color:white}.btn-brand:hover{background:#8f1e2b;color:white}</style>
</head>
<body>
<main class="panel">
    <div class="brand mb-4">AquaTrack</div>
    <h1 class="h4">Reset password</h1>
    <p>Enter the six-digit code sent to your email and choose a new password.</p>
    @if(session('status'))<div class="alert alert-info">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('password.reset_code') }}">@csrf
        <label class="form-label" for="email">Email</label>
        <input class="form-control mb-3" id="email" name="email" type="email" value="{{ old('email') }}" required>
        <label class="form-label" for="code">Reset code</label>
        <input class="form-control mb-3" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
        <label class="form-label" for="password">New password (at least 12 characters)</label>
        <input class="form-control mb-3" id="password" name="password" type="password" minlength="12" required>
        <label class="form-label" for="password_confirmation">Confirm new password</label>
        <input class="form-control mb-3" id="password_confirmation" name="password_confirmation" type="password" minlength="12" required>
        <button class="btn btn-brand w-100">Reset password</button>
    </form>
    <a class="d-block mt-3" href="{{ route('password.request') }}">Request another code</a>
</main>
</body>
</html>
