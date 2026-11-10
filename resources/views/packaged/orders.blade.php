@extends('layouts.app')
@section('page-title', 'Customer orders')
@section('content')
<div class="panel mb-3 d-flex justify-content-between"><div><strong>Order-to-payment workflow</strong><div class="tiny">Draft → reserve → dispatch → delivery → invoice → payment</div></div>@if(auth()->user()->hasRole('administrator','manager','sales_staff'))<a class="btn btn-brand" href="{{ route('packaged.orders.create') }}">New order</a>@endif</div>
<div class="panel p-0 overflow-hidden">@include('packaged.partials.orders-table')<div class="p-3 border-top">{{ $orders->links() }}</div></div>
@endsection
