<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · AquaTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{min-height:100vh;background:#f4f7fa;font-family:system-ui}.panel{max-width:440px;margin:10vh auto;padding:32px;background:white;border:1px solid #e4eaf0;border-radius:8px}.brand{color:#8b1e2d;font-weight:700}.btn-brand{background:#b42332;color:white}.btn-brand:hover{background:#8f1e2b;color:white}</style>
</head>
<body>
<main class="panel">
    <div class="brand mb-4">AquaTrack</div>
    <h1 class="h4">Forgot password</h1>
    <p>Enter your account email. We will send a code to reset your password.</p>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('password.email_code') }}">@csrf
        <label class="form-label" for="email">Email</label>
        <input class="form-control mb-3" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        <button class="btn btn-brand w-100">Send reset code</button>
    </form>
    <a class="d-block mt-3" href="{{ route('login') }}">Back to login</a>
</main>
</body>
</html>
