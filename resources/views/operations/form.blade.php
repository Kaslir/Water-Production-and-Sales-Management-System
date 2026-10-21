@extends('layouts.app')
@section('page-title', 'Add customer')
@section('content')
<div class="row"><div class="col-lg-8"><div class="panel"><form method="post" action="{{ route('operations.store', ['type' => 'customers']) }}">@csrf
    <label class="form-label">Customer or company name</label><input class="form-control mb-3" name="name" value="{{ old('name') }}" required>
    <label class="form-label">Phone number</label><input class="form-control mb-3" name="phone_number" value="{{ old('phone_number') }}" required>
    <label class="form-label">Email</label><input class="form-control mb-3" name="email" type="email" value="{{ old('email') }}">
    <label class="form-label">Delivery address</label><input class="form-control mb-3" name="address" value="{{ old('address') }}">
    <label class="form-label">Contact person</label><input class="form-control mb-3" name="contact_person" value="{{ old('contact_person') }}">
    <button class="btn btn-brand">Save customer</button><a class="btn btn-light ms-1" href="{{ route('customers.index') }}">Cancel</a>
</form></div></div></div>
@endsection
