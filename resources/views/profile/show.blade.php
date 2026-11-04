@extends('layouts.app')
@section('page-title', 'My profile')
@section('content')
@php
    $roleLabel = ucwords(str_replace('_', ' ', $user->role));
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($part) => strtoupper(substr($part, 0, 1)))->implode('');
    $showNameEditor = $errors->has('name');
    $showEmailEditor = $errors->has('new_email') || $errors->has('current_password');
@endphp
<style>
    .my-profile .profile-avatar{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:#f6d6da;color:#7d2530;font-size:1.2rem;font-weight:700;flex-shrink:0}
    .my-profile .profile-heading{font-size:1.12rem;font-weight:700;color:#173a57}
    .my-profile .detail-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6f7f8e;margin-bottom:4px}
    .my-profile .detail-value{font-size:.91rem;color:#173a57;overflow-wrap:anywhere}
    .my-profile .security-block + .security-block{border-top:1px solid #e4eaf0;margin-top:18px;padding-top:18px}
</style>

<div class="my-profile">
    <div class="panel mb-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="profile-avatar" aria-hidden="true">{{ $initials }}</div>
            <div class="flex-grow-1">
                <h2 class="profile-heading mb-1">{{ $user->name }}</h2>
                <div class="d-flex flex-wrap align-items-center gap-2 small text-secondary">
                    <span>{{ $roleLabel }}</span>
                    <span aria-hidden="true">&middot;</span>
                    <span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <div class="col-12 col-xl-6">
            <section class="panel" aria-labelledby="personal-information-heading">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div><h2 id="personal-information-heading" class="h6 fw-bold mb-1">Personal Information</h2><p class="small text-secondary mb-0">Your account details and access level.</p></div>
                    @if ($user->hasRole('administrator'))
                        <button class="btn btn-sm btn-outline-secondary flex-shrink-0" type="button" data-bs-toggle="collapse" data-bs-target="#profile-name-editor" aria-expanded="{{ $showNameEditor ? 'true' : 'false' }}" aria-controls="profile-name-editor">Edit Name</button>
                    @endif
                </div>
                <dl class="row g-3 mb-0">
                    <div class="col-12"><dt class="detail-label">Full name</dt><dd class="detail-value mb-0">{{ $user->name }}</dd></div>
                    <div class="col-12"><dt class="detail-label">Email address</dt><dd class="detail-value mb-0">{{ $user->email }}
                        @if ($user->email_verified_at)<span class="badge text-bg-success ms-1" aria-label="Email verified">Verified</span>@endif
                    </dd></div>
                    <div class="col-sm-6"><dt class="detail-label">Role</dt><dd class="detail-value mb-0">{{ $roleLabel }}</dd></div>
                    <div class="col-sm-6"><dt class="detail-label">Account status</dt><dd class="detail-value mb-0">{{ $user->is_active ? 'Active' : 'Inactive' }}</dd></div>
                </dl>
                @if ($user->hasRole('administrator'))
                    <div id="profile-name-editor" class="collapse {{ $showNameEditor ? 'show' : '' }} border-top mt-3 pt-3">
                        <form method="post" action="{{ route('profile.update') }}">
                            @csrf @method('patch')
                            <label class="form-label" for="profile-name">Full name</label>
                            <input class="form-control @error('name') is-invalid @enderror" id="profile-name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-brand" type="submit">Save name</button>
                                <button class="btn btn-outline-secondary" type="reset" data-bs-toggle="collapse" data-bs-target="#profile-name-editor" aria-controls="profile-name-editor">Cancel</button>
                            </div>
                        </form>
                    </div>
                @else
                    <p class="small text-muted border-top pt-3 mt-3 mb-0">Contact an administrator if you need to change your name, email address, or role.</p>
                @endif
            </section>
        </div>

        <div class="col-12 col-xl-6">
            <section class="panel" aria-labelledby="account-security-heading">
                <h2 id="account-security-heading" class="h6 fw-bold mb-1">Account Security</h2>
                <p class="small text-secondary mb-3">Manage the email used for verification and keep your password secure.</p>

                @if ($user->hasRole('administrator'))
                    <div class="security-block">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div><h3 class="h6 mb-1">Email address</h3><p class="small text-secondary mb-0">Sign-in codes are sent to your email. A new address must be verified before it replaces this one.</p></div>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#profile-email-editor" aria-expanded="{{ $showEmailEditor ? 'true' : 'false' }}" aria-controls="profile-email-editor">Change Email</button>
                        </div>
                        <div id="profile-email-editor" class="collapse {{ $showEmailEditor ? 'show' : '' }} mt-3">
                            <form method="post" action="{{ route('profile.email.request') }}">
                                @csrf
                                <label class="form-label" for="new-email">New email</label>
                                <input class="form-control @error('new_email') is-invalid @enderror" id="new-email" type="email" name="new_email" value="{{ old('new_email') }}" maxlength="255" autocomplete="email" required>
                                @error('new_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <label class="form-label mt-3" for="email-current-password">Current password</label>
                                <input class="form-control @error('current_password') is-invalid @enderror" id="email-current-password" type="password" name="current_password" autocomplete="current-password" required>
                                @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <p class="form-text mb-0">Your current password is required before a verification code is sent to the new address.</p>
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn btn-brand" type="submit">Send verification code</button>
                                    <button class="btn btn-outline-secondary" type="reset" data-bs-toggle="collapse" data-bs-target="#profile-email-editor" aria-controls="profile-email-editor">Cancel</button>
                                </div>
                            </form>
                        </div>

                        @if ($pending)
                            <div class="border rounded bg-light p-3 mt-3">
                                <h3 class="h6 mb-1">Email change awaiting verification</h3>
                                <p class="small mb-2">Pending address: <strong class="text-break">{{ $pending->new_email }}</strong></p>
                                <form method="post" action="{{ route('profile.email.verify') }}">
                                    @csrf
                                    <label class="form-label" for="email-code">Six-digit verification code</label>
                                    <input class="form-control @error('code') is-invalid @enderror" id="email-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <button class="btn btn-brand mt-3" type="submit">Verify and change email</button>
                                </form>
                                <form method="post" action="{{ route('profile.email.cancel') }}" class="mt-2">
                                    @csrf @method('delete')
                                    <button class="btn btn-link text-danger p-0" type="submit">Cancel email change</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="security-block">
                    <h3 class="h6 mb-1">Password</h3>
                    <p class="small text-secondary mb-3">Use the email reset code to choose a new password.</p>
                    <a class="btn btn-brand" href="{{ route('password.request') }}">Reset password</a>
                    <p class="small text-muted mt-3 mb-0">If you need help changing your password, contact an administrator.</p>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
