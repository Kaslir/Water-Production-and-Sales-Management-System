@extends('layouts.app')
@section('page-title', 'My profile')
@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="panel h-100">
            <h2 class="h6 fw-bold mb-3">Account details</h2>
            @if ($user->hasRole('administrator'))
            <form method="post" action="{{ route('profile.update') }}">
                @csrf @method('patch')
                <label class="form-label" for="profile-name">Full name</label>
                <input class="form-control mb-3" id="profile-name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255">
                <button class="btn btn-brand mb-3" type="submit">Save name</button>
            </form>
            @else
                <label class="form-label">Full name</label>
                <p class="mb-3">{{ $user->name }}</p>
            @endif
                <label class="form-label">Current email</label>
                <p class="mb-3">{{ $user->email }}</p>
                <label class="form-label">Role</label>
                <p class="mb-3 text-capitalize">{{ str_replace('_', ' ', $user->role) }}</p>
                <label class="form-label">Account status</label>
                <p class="mb-3">{{ $user->is_active ? 'Active' : 'Inactive' }}</p>
            @unless ($user->hasRole('administrator'))
                <p class="small text-muted mb-0">Contact an administrator if you need to change your name, email address, or role.</p>
            @endunless
        </div>
    </div>
    @if ($user->hasRole('administrator'))
    <div class="col-12 col-xl-6">
        <div class="panel h-100">
            <h2 class="h6 fw-bold mb-2">Change email address</h2>
            <p class="small text-muted">Your sign-in codes go to your email. Verify the new address before it replaces your current one.</p>
            <form method="post" action="{{ route('profile.email.request') }}">
                @csrf
                <label class="form-label" for="new-email">New email</label>
                <input class="form-control mb-3" id="new-email" type="email" name="new_email" value="{{ old('new_email') }}" required>
                <label class="form-label" for="email-current-password">Current password</label>
                <input class="form-control mb-3" id="email-current-password" type="password" name="current_password" autocomplete="current-password" required>
                <button class="btn btn-brand" type="submit">Send verification code</button>
            </form>
            @if ($pending)
                <hr>
                <p class="small mb-2">Pending address: <strong>{{ $pending->new_email }}</strong></p>
                <form method="post" action="{{ route('profile.email.verify') }}">
                    @csrf
                    <label class="form-label" for="email-code">Six-digit verification code</label>
                    <input class="form-control mb-3" id="email-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    <button class="btn btn-brand" type="submit">Verify and change email</button>
                </form>
                <form method="post" action="{{ route('profile.email.cancel') }}" class="mt-2">
                    @csrf @method('delete')
                    <button class="btn btn-link text-danger p-0" type="submit">Cancel email change</button>
                </form>
            @endif
        </div>
    </div>
    @endif
    <div class="col-12 col-xl-6">
        <div class="panel">
            <h2 class="h6 fw-bold mb-2">Password</h2>
            <p class="small text-muted">Use the email reset code to choose a new password.</p>
            <a class="btn btn-brand" href="{{ route('password.request') }}">Reset password</a>
            <p class="small text-muted mt-3 mb-0">If you need help changing your password, contact an administrator.</p>
        </div>
    </div>
</div>
@endsection
