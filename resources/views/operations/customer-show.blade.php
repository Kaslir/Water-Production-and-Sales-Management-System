@extends('layouts.app')
@section('page-title', $customer->name)
@section('content')
<div class="toolbar mb-3 justify-content-between">
    <a href="{{ route('customers.index') }}">&larr; Back to customers</a>
    <a class="btn btn-brand btn-sm" href="{{ route('packaged.orders.create') }}">Create order</a>
</div>
<div class="panel mb-3">
    <h5>{{ $customer->name }}</h5>
    <div class="row g-3 small">
        <div class="col-md-3"><strong>Customer ID</strong><br>{{ $customer->customer_id }}</div>
        <div class="col-md-3"><strong>Phone number</strong><br>{{ $customer->phone_number }}</div>
        <div class="col-md-3"><strong>Email</strong><br>{{ $customer->email ?: 'Not provided' }}</div>
        <div class="col-md-3"><strong>Contact person</strong><br>{{ $customer->contact_person ?: 'Not provided' }}</div>
        <div class="col-12"><strong>Address</strong><br>{{ $customer->address ?: 'Not provided' }}</div>
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="metric"><span class="metric-label">ORDERS PLACED</span><span class="metric-value">{{ number_format($orderCount) }}</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label">NET INVOICED</span><span class="metric-value">KSh {{ number_format($netInvoiced, 2) }}</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label">CASH COLLECTED</span><span class="metric-value">KSh {{ number_format($cashCollected, 2) }}</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label">BALANCE DUE</span><span class="metric-value">KSh {{ number_format($balanceDue, 2) }}</span></div></div>
</div>
<p class="tiny">Balance due is net invoiced value after credits and payments. A negative amount means credit is owed to the customer.</p>
<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">Order history</strong><span class="tiny">{{ number_format($orderCount) }} orders</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th class="px-3">Order</th><th>Ordered</th><th>Cases</th><th>Delivery address</th><th>Order value</th><th>Order status</th><th>Invoice</th></tr></thead>
        <tbody>@forelse($orders as $order)<tr>
            <td class="px-3"><a href="{{ route('packaged.orders.show', $order) }}">{{ $order->order_number }}</a></td>
            <td>{{ $order->ordered_on->format('d M Y') }}</td>
            <td>{{ number_format($order->items->sum('cases_ordered')) }}</td>
            <td>{{ $order->delivery_address }}</td>
            <td>KSh {{ number_format($order->total_amount, 2) }}</td>
            <td>{{ ucfirst(str_replace('_', ' ', $order->status)) }}</td>
            <td>@if($order->invoice){{ $order->invoice->invoice_number }}<br><span class="tiny">{{ ucfirst(str_replace('_', ' ', $order->invoice->status)) }}</span>@else Not invoiced @endif</td>
        </tr>@empty<tr><td class="p-5 text-center text-muted" colspan="7">No orders placed by this customer yet.</td></tr>@endforelse</tbody>
    </table></div>
    <div class="p-3 border-top">{{ $orders->links() }}</div>
</div>
@endsection
