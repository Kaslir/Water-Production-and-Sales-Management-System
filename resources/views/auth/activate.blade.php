<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Activate account · AquaTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{min-height:100vh;background:linear-gradient(120deg,#8b1e2d 0 38%,#f4f7fa 38%);font-family:system-ui}.panel{max-width:480px;margin:8vh auto;background:#fff;border-radius:9px;box-shadow:0 16px 42px #6d122833;padding:34px}.brand{font-weight:700;color:#8b1e2d;font-size:1.35rem}.btn-brand{background:#b42332;color:#fff}.caption{font-size:.85rem;color:#667786}</style>
</head>
<body><main class="panel">
    <div class="brand mb-3">AquaTrack</div><h1 class="h4 fw-bold">Activate Your Account</h1>
    @if($state === 'expired')
        <div class="alert alert-warning">This invitation has expired. Please contact your system administrator to request a new invitation.</div>
    @elseif($state === 'used')
        <div class="alert alert-info">This invitation has already been used. You can sign in with your account.</div>
    @elseif($state !== 'valid')
        <div class="alert alert-danger">This invitation is invalid or has been revoked. Contact your system administrator.</div>
    @else
        <p class="caption">You have been invited to join AquaTrack. Create your password to activate your employee account.</p>
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <dl class="row small"><dt class="col-4">Name</dt><dd class="col-8">{{ $invitation->user->name }}</dd><dt class="col-4">Email</dt><dd class="col-8">{{ $invitation->user->email }}</dd><dt class="col-4">Role</dt><dd class="col-8">{{ ucwords(str_replace('_', ' ', $invitation->user->role)) }}</dd></dl>
        <form method="post" action="{{ route('invitations.accept', $invitation) }}">@csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="password" class="form-label">New password</label><div class="input-group mb-2"><input id="password" class="form-control" name="password" type="password" minlength="12" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password',this)" aria-label="Show password">Show</button></div>
            <p class="caption">Use at least 12 characters.</p>
            <label for="password-confirmation" class="form-label">Confirm password</label><div class="input-group mb-3"><input id="password-confirmation" class="form-control" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password-confirmation',this)" aria-label="Show confirmation">Show</button></div>
            <button class="btn btn-brand w-100" type="submit">Activate Account</button>
        </form>
    @endif
    <a class="d-block text-center mt-3" href="{{ route('login') }}">Back to login</a>
</main><script>function togglePassword(id,button){const field=document.getElementById(id);const visible=field.type==='text';field.type=visible?'password':'text';button.textContent=visible?'Show':'Hide';button.setAttribute('aria-label',visible?'Show password':'Hide password')}</script></body></html>
