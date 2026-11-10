@extends('layouts.app')
@section('page-title', 'Manufacturing settings')
@section('content')
@php
    $costFields = [
        'water_treatment_per_case' => ['Treatment', 'Water treatment cost for one case.'],
        'bottle_per_case' => ['Bottles', 'Empty bottles used in one case.'],
        'inbound_transport_per_case' => ['Inbound packaging transport', 'Cost of bringing packaging materials to production.'],
        'cap_label_per_case' => ['Caps and labels', 'Caps and labels used in one case.'],
        'labour_per_case' => ['Labour', 'Production labour allocated to one case.'],
        'electricity_per_case' => ['Electricity', 'Production electricity allocated to one case.'],
        'other_production_per_case' => ['Other production', 'Other direct production costs for one case. Enter zero if none.'],
    ];
    $todayDate = today()->toDateString();
    $selectedHistory = $selectedProduct ? ($policies->get($selectedProduct->id) ?? collect()) : collect();
    $currentPolicy = $selectedHistory->first(fn ($policy) => $policy->effective_from->toDateString() <= $todayDate);
    $nextPolicy = $selectedHistory->filter(fn ($policy) => $policy->effective_from->toDateString() > $todayDate)->sortBy('effective_from')->first();
    $defaultEffectiveDate = $selectedHistory->contains(fn ($policy) => $policy->effective_from->toDateString() === $todayDate)
        ? today()->addDay()->toDateString() : $todayDate;
@endphp
<style>
    .manufacturing-settings .section-title{font-size:1rem;font-weight:700;color:#173a57}
    .manufacturing-settings .settings-label{font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6f7f8e}
    .manufacturing-settings .settings-value{font-weight:600;color:#173a57}
    .manufacturing-settings .cost-preview{background:#f4f7fa;border:1px solid #d9e2e9;border-radius:.5rem}
    .manufacturing-settings .form-text{font-size:.76rem}
    .manufacturing-settings .table td,.manufacturing-settings .table th{vertical-align:middle}
</style>

<div class="manufacturing-settings">
    <div class="panel mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h2 class="section-title mb-1">Product manufacturing policies</h2>
                <p class="text-secondary small mb-0">Review the approved shelf life and estimated production cost for each bottled-water product.</p>
            </div>
            <span class="badge text-bg-light border">{{ $products->count() }} products</span>
        </div>
        <p class="small text-secondary mt-3 mb-0">New settings take effect on the date you choose. Batches already recorded keep their original expiry dates and production costs.</p>
    </div>

    <div class="panel mb-3 p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Manufacturing settings overview for active products</caption>
                <thead class="table-light"><tr>
                    <th scope="col" class="ps-3">Product</th>
                    <th scope="col">Approval status</th>
                    <th scope="col">Approved shelf life</th>
                    <th scope="col">Estimated cost / case</th>
                    <th scope="col">Effective date</th>
                    <th scope="col" class="text-end pe-3">Action</th>
                </tr></thead>
                <tbody>
                    @forelse ($products as $product)
                        @php
                            $productHistory = $policies->get($product->id) ?? collect();
                            $active = $productHistory->first(fn ($policy) => $policy->effective_from->toDateString() <= $todayDate);
                            $scheduled = $productHistory->filter(fn ($policy) => $policy->effective_from->toDateString() > $todayDate)->sortBy('effective_from')->first();
                            $display = $active ?? $scheduled;
                            $displayCost = $display ? collect(array_keys($costFields))->sum(fn ($field) => (float) $display->{$field}) : null;
                        @endphp
                        <tr @class(['table-active' => $selectedProduct?->id === $product->id])>
                            <th scope="row" class="ps-3 fw-semibold">{{ $product->name }}</th>
                            <td>
                                @if ($active)
                                    <span class="badge text-bg-success">Active</span>
                                    @if ($scheduled)<div class="small text-secondary mt-1">New version scheduled {{ $scheduled->effective_from->format('d M Y') }}</div>@endif
                                @elseif ($scheduled)
                                    <span class="badge text-bg-info">Approved, scheduled</span>
                                @else
                                    <span class="badge text-bg-secondary">Not configured</span>
                                @endif
                            </td>
                            <td>{{ $display ? number_format($display->shelf_life_days).' days' : '—' }}</td>
                            <td>{{ $display ? 'KSh '.number_format($displayCost, 2) : '—' }}</td>
                            <td>{{ $display?->effective_from?->format('d M Y') ?? '—' }}</td>
                            <td class="text-end pe-3"><a class="btn btn-sm {{ $selectedProduct?->id === $product->id ? 'btn-brand' : 'btn-outline-secondary' }}" href="{{ route('packaged.manufacturing-settings.index', ['product' => $product->id]) }}#product-settings" aria-label="{{ $display ? 'Edit' : 'Configure' }} settings for {{ $product->name }}">{{ $display ? 'Edit' : 'Configure' }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">No active products are available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($selectedProduct)
        <div id="product-settings" class="panel mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <div class="settings-label mb-1">Selected product</div>
                    <h2 class="section-title mb-1">{{ $selectedProduct->name }}</h2>
                    <p class="small text-secondary mb-0">Review the current policy, then approve a new version if changes are needed.</p>
                </div>
                @if ($selectedHistory->isNotEmpty())
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#settingsHistory" aria-expanded="false" aria-controls="settingsHistory">View Settings History ({{ $selectedHistory->count() }})</button>
                @endif
            </div>

            <section aria-labelledby="current-settings-heading" class="border rounded p-3 mb-4 bg-light">
                <h3 id="current-settings-heading" class="section-title mb-3">Currently approved settings</h3>
                @if ($currentPolicy)
                    @php($currentTotal = collect(array_keys($costFields))->sum(fn ($field) => (float) $currentPolicy->{$field}))
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4"><div class="settings-label">Effective from</div><div class="settings-value">{{ $currentPolicy->effective_from->format('d M Y') }}</div></div>
                        <div class="col-sm-4"><div class="settings-label">Shelf life</div><div class="settings-value">{{ number_format($currentPolicy->shelf_life_days) }} days</div></div>
                        <div class="col-sm-4"><div class="settings-label">Estimated cost / case</div><div class="settings-value">KSh {{ number_format($currentTotal, 2) }}</div></div>
                    </div>
                    <details><summary class="small fw-semibold">View current cost components</summary>
                        <div class="row g-2 mt-1">
                            @foreach ($costFields as $field => [$label, $description])
                                <div class="col-sm-6 col-lg-4 small"><span class="text-secondary">{{ $label }}:</span> KSh {{ number_format((float) $currentPolicy->{$field}, 2) }}</div>
                            @endforeach
                        </div>
                    </details>
                @else
                    <p class="small text-secondary mb-0">No settings are active today. Production staff cannot record a batch of this product until approved settings reach their effective date.</p>
                @endif
                @if ($nextPolicy)
                    <div class="alert alert-info py-2 small mt-3 mb-0" role="status">A newer approved version takes effect on {{ $nextPolicy->effective_from->format('d M Y') }}. Its values are available in Settings History.</div>
                @endif
            </section>

            @if ($selectedHistory->isNotEmpty())
                <section id="settingsHistory" class="collapse mb-4" aria-label="Settings history for {{ $selectedProduct->name }}">
                    <h3 class="section-title mb-3">Settings history</h3>
                    <div class="d-grid gap-3">
                        @foreach ($selectedHistory as $policy)
                            @php($policyTotal = collect(array_keys($costFields))->sum(fn ($field) => (float) $policy->{$field}))
                            <div class="border rounded p-3">
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div><strong>Effective {{ $policy->effective_from->format('d M Y') }}</strong>
                                        <span class="badge {{ $policy->effective_from->toDateString() > $todayDate ? 'text-bg-info' : 'text-bg-success' }} ms-2">{{ $policy->effective_from->toDateString() > $todayDate ? 'Scheduled' : 'Approved' }}</span>
                                    </div>
                                </div>
                                <div class="row g-2 small mb-2">
                                    <div class="col-sm-4"><span class="text-secondary">Shelf life:</span> {{ number_format($policy->shelf_life_days) }} days</div>
                                    <div class="col-sm-4"><span class="text-secondary">Total cost / case:</span> KSh {{ number_format($policyTotal, 2) }}</div>
                                </div>
                                <div class="row g-2 border-top pt-2">
                                    @foreach ($costFields as $field => [$label, $description])
                                        <div class="col-sm-6 col-lg-4 small"><span class="text-secondary">{{ $label }}:</span> KSh {{ number_format((float) $policy->{$field}, 2) }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <form id="manufacturingPolicyForm" method="post" action="{{ route('packaged.manufacturing-settings.store', $selectedProduct) }}">
                @csrf
                <h3 class="section-title mb-1">Approve new settings</h3>
                <p class="small text-secondary mb-3">Values are pre-filled from the current policy when available. Check every field before approval; this creates a new historical version.</p>

                <fieldset class="border rounded p-3 mb-3">
                    <legend class="float-none w-auto px-2 fs-6 fw-semibold mb-0">Shelf-Life Settings</legend>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="effective_from">Effective from</label>
                            <input class="form-control @error('effective_from') is-invalid @enderror" id="effective_from" type="date" name="effective_from" min="{{ $todayDate }}" value="{{ old('effective_from', $defaultEffectiveDate) }}" required aria-describedby="effectiveHelp">
                            <div id="effectiveHelp" class="form-text">Today or a future date. Each product can have only one policy per effective date.</div>
                            @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="shelf_life_days">Shelf life (days)</label>
                            <input class="form-control @error('shelf_life_days') is-invalid @enderror" id="shelf_life_days" type="number" min="1" max="3650" step="1" name="shelf_life_days" value="{{ old('shelf_life_days', $currentPolicy?->shelf_life_days ?? $nextPolicy?->shelf_life_days) }}" required aria-describedby="shelfLifeHelp">
                            <div id="shelfLifeHelp" class="form-text">Used to calculate each new batch's expiry from its production date.</div>
                            @error('shelf_life_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="border rounded p-3 mb-3">
                    <legend class="float-none w-auto px-2 fs-6 fw-semibold mb-0">Production Costs</legend>
                    <p class="small text-secondary mb-3">Enter an estimated amount in Kenyan shillings for one complete case. Use 0.00 only when that component has no cost.</p>
                    <div class="row g-3">
                        @foreach ($costFields as $field => [$label, $description])
                            <div class="col-md-6 col-xl-4">
                                <label class="form-label" for="{{ $field }}">{{ $label }} per case (KSh)</label>
                                <input class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" type="number" min="0" max="9999999.99" step="0.01" inputmode="decimal" name="{{ $field }}" value="{{ old($field, $currentPolicy?->{$field} ?? $nextPolicy?->{$field} ?? 0) }}" data-cost-component required aria-describedby="{{ $field }}_help">
                                <div id="{{ $field }}_help" class="form-text">{{ $description }}</div>
                                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                <div class="cost-preview d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 mb-3" role="status" aria-live="polite">
                    <div><div class="settings-label">Estimated production cost</div><div class="small text-secondary">Sum of the seven entered cost components for one case.</div></div>
                    <strong id="totalCostPerCase" class="fs-5">KSh 0.00 per case</strong>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <button class="btn btn-brand" type="submit">Approve new settings</button>
                    <span class="small text-secondary">Approval saves a new version for future batches; existing batches are unchanged.</span>
                </div>
            </form>
        </div>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('manufacturingPolicyForm');
    if (!form) return;
    const fields = [...form.querySelectorAll('[data-cost-component]')];
    const output = document.getElementById('totalCostPerCase');
    const formatter = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const updateTotal = () => {
        const total = fields.reduce((sum, field) => sum + (Number.parseFloat(field.value) || 0), 0);
        output.textContent = `KSh ${formatter.format(total)} per case`;
    };
    fields.forEach(field => field.addEventListener('input', updateTotal));
    updateTotal();
});
</script>
@endsection
