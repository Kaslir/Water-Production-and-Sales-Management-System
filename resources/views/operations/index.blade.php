@extends('layouts.app')
@section('page-title', 'Customers')
@section('content')
<div class="toolbar mb-3 justify-content-between"><span class="tiny">Company customers for packaged-water orders.</span><a class="btn btn-brand btn-sm" href="{{ route('operations.create', 'customers') }}"><i class="bi bi-plus-lg me-1"></i>Add customer</a></div>
<div class="panel p-0 overflow-hidden"><div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">Customers register</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="px-3">Customer ID</th><th>Name</th><th>Phone number</th><th>Email</th><th>Contact person</th><th>Address</th></tr></thead><tbody>@forelse($rows as $row)<tr><td class="px-3">{{ $row->customer_id }}</td><td>{{ $row->name }}</td><td>{{ $row->phone_number }}</td><td>{{ $row->email ?: '—' }}</td><td>{{ $row->contact_person ?: '—' }}</td><td>{{ $row->address ?: '—' }}</td></tr>@empty<tr><td class="p-5 text-center text-muted" colspan="6">No customers yet.</td></tr>@endforelse</tbody></table></div>
    <div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
@endsection
