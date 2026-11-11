@extends('layouts.app')
@section('page-title', $user->exists ? 'Edit employee' : 'Add employee')
@section('content')
@php
    $pending = $user->exists && ! $user->is_active && ! $user->activated_at && $user->employeeInvitations()->exists();
    $roleOptions = $user->exists && ! $pending ? \App\Models\User::ASSIGNABLE_ROLES : \App\Models\User::EMPLOYEE_ROLES;
    if ($user->exists && ! in_array($user->role, $roleOptions, true)) {
        $roleOptions[] = $user->role;
    }
@endphp
<div class="row g-3"><div class="col-lg-8"><div class="panel">
    <h1 class="h5 mb-2">{{ $user->exists ? 'Edit employee details' : 'Invite an employee' }}</h1>
    <p class="small text-muted">{{ $user->exists ? ($pending ? 'Changing the email address invalidates the old invitation and sends a new one.' : 'Update the account details and access below.') : 'The employee will receive an email and create their own password.' }}</p>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">@csrf @if($user->exists)@method('PUT')@endif
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="employee-name">Full name *</label><input class="form-control" id="employee-name" name="name" value="{{ old('name', $user->name) }}" required></div>
            <div class="col-md-6"><label class="form-label" for="employee-email">Email address *</label><input class="form-control" id="employee-email" name="email" type="email" value="{{ old('email', $user->email) }}" required></div>
            <div class="col-md-6"><label class="form-label" for="employee-phone">Phone number *</label><input class="form-control" id="employee-phone" name="phone_number" value="{{ old('phone_number', $user->phone_number) }}" placeholder="0712345678" maxlength="10" required></div>
            <div class="col-md-6"><label class="form-label" for="employee-role">Employee role *</label><select class="form-select" id="employee-role" name="role" required>@foreach($roleOptions as $role)<option value="{{ $role }}" @selected(old('role', $user->role ?: 'sales_staff') === $role)>{{ ucwords(str_replace('_', ' ', $role)) }}</option>@endforeach</select></div>
            @if($user->exists && ! $pending)
                <div class="col-md-6"><label class="form-label" for="employee-status">Account status *</label><select class="form-select" id="employee-status" name="is_active"><option value="1" @selected((string) old('is_active', $user->is_active) === '1')>Active</option><option value="0" @selected((string) old('is_active', $user->is_active) === '0')>Deactivated</option></select></div>
                <div class="col-md-6"><label class="form-label" for="employee-password">New password (optional)</label><input class="form-control" id="employee-password" name="password" type="password" minlength="12" autocomplete="new-password"></div>
                <div class="col-md-6"><label class="form-label" for="employee-password-confirmation">Confirm new password</label><input class="form-control" id="employee-password-confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password"></div>
            @endif
        </div>
        <div class="border-top mt-4 pt-3"><button class="btn btn-brand">{{ $user->exists ? 'Save changes' : 'Send Invitation' }}</button><a class="btn btn-light ms-1" href="{{ route('users.index') }}">Cancel</a></div>
    </form>
</div></div><div class="col-lg-4"><div class="panel"><strong class="small">Employee access</strong><p class="small text-muted mt-2 mb-0">Assign the role needed for this employee's work. New accounts remain inactive until the employee accepts the emailed invitation. Invitations expire after 24 hours.</p></div></div></div>
@endsection
