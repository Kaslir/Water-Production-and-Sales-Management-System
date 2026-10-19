@extends('layouts.app')
@section('page-title', 'Customer orders')
@section('content')
<div class="panel mb-3 d-flex justify-content-between"><div><strong>Order-to-payment workflow</strong><div class="tiny">Draft → reserve → dispatch → delivery → invoice → payment</div></div>@if(auth()->user()->hasRole('administrator','manager','sales_staff'))<a class="btn btn-brand" href="{{ route('packaged.orders.create') }}">New order</a>@endif</div>
<div class="panel table-responsive"><table class="table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th>Net sale</th><th></th></tr></thead><tbody>@forelse($orders as $order)<tr><td>{{ $order->order_number }}</td><td>{{ $order->customer->name }}</td><td>{{ $order->ordered_on->format('d M Y') }}</td><td>{{ ucfirst($order->status) }}</td><td>KSh {{ number_format($order->total_amount,2) }}</td><td><a href="{{ route('packaged.orders.show',$order) }}">View</a></td></tr>@empty<tr><td colspan="6">No packaged-water orders yet.</td></tr>@endforelse</tbody></table>{{ $orders->links() }}</div>
@endsection
