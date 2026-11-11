<?php

namespace App\Http\Controllers;

use App\Models\DeliveryTrip;
use App\Models\SalesOrder;
use App\Services\AuditTrail;
use App\Services\DeliveryTripService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeliveryTripController extends Controller
{
    public function create(Request $request): View
    {
        $filters = $request->validate(['order_id' => ['nullable', 'integer', 'exists:sales_orders,id']]);
        $orders = SalesOrder::query()->with(['customer', 'deliveryArea'])->where('status', 'confirmed')->whereNotNull('delivery_area_id')->whereDoesntHave('dispatch')->whereDoesntHave('deliveryTrips')->orderBy('requested_delivery_on')->get();

        return view('packaged.trip-create', [
            'orders' => $orders,
            'selectedOrderId' => $orders->contains('id', (int) ($filters['order_id'] ?? 0)) ? (int) $filters['order_id'] : null,
        ]);
    }

    public function store(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'delivery_on' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'exists:sales_orders,id'],
        ]);
        $trip = DB::transaction(function () use ($data, $request, $auditTrail): DeliveryTrip {
            $orders = SalesOrder::query()->whereKey($data['order_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== count($data['order_ids'])) {
                throw ValidationException::withMessages(['order_ids' => 'Choose valid customer orders.']);
            }
            foreach ($orders as $order) {
                if ($order->status !== 'confirmed' || $order->delivery_area_id === null || $order->dispatch()->exists() || $order->deliveryTrips()->exists()) {
                    throw ValidationException::withMessages(['order_ids' => 'Each order must be confirmed, have a Nairobi delivery area, and be unassigned.']);
                }
            }
            $trip = DeliveryTrip::create([
                'delivery_on' => $data['delivery_on'],
                'status' => 'planned',
                'created_by' => $request->user()->user_id,
            ]);
            $trip->update(['trip_reference' => sprintf('DT-%06d', $trip->id)]);
            $trip->orders()->attach($orders->modelKeys());
            $auditTrail->record('delivery_trip.created', $trip, $request->user()->user_id, [], ['order_ids' => $orders->modelKeys(), 'trip_reference' => $trip->trip_reference, 'delivery_on' => $trip->delivery_on->toDateString()]);

            return $trip;
        });

        return redirect()->route('packaged.trips.show', $trip)->with('status', 'Delivery trip planned. Stock remains reserved until dispatch.');
    }

    public function edit(DeliveryTrip $trip): View
    {
        abort_unless($trip->status === 'planned', 404);
        $trip->load('orders');

        return view('packaged.trip-create', [
            'trip' => $trip,
            'orders' => SalesOrder::query()->with(['customer', 'deliveryArea'])->where('status', 'confirmed')->whereNotNull('delivery_area_id')->whereDoesntHave('dispatch')
                ->where(fn ($query) => $query->whereDoesntHave('deliveryTrips')->orWhereHas('deliveryTrips', fn ($trips) => $trips->whereKey($trip->id)))
                ->orderBy('requested_delivery_on')->get(),
            'selectedOrderId' => null,
        ]);
    }

    public function update(Request $request, DeliveryTrip $trip, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'delivery_on' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'exists:sales_orders,id'],
        ]);
        DB::transaction(function () use ($trip, $data, $request, $auditTrail): void {
            $trip = DeliveryTrip::query()->lockForUpdate()->findOrFail($trip->id);
            if ($trip->status !== 'planned') {
                throw ValidationException::withMessages(['trip' => 'Only a planned trip can be edited.']);
            }
            $orders = SalesOrder::query()->whereKey($data['order_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== count($data['order_ids']) || $orders->contains(fn ($order) => $order->status !== 'confirmed' || $order->delivery_area_id === null || $order->dispatch()->exists() || $order->deliveryTrips()->where('delivery_trips.id', '<>', $trip->id)->exists())) {
                throw ValidationException::withMessages(['order_ids' => 'Choose confirmed, undispatched orders that are not assigned to another trip.']);
            }
            $before = ['delivery_on' => $trip->delivery_on->toDateString(), 'order_ids' => $trip->orders()->pluck('sales_orders.id')->all()];
            $trip->update(['delivery_on' => $data['delivery_on']]);
            $trip->orders()->sync($orders->modelKeys());
            $auditTrail->record('delivery_trip.updated', $trip, $request->user()->user_id, $before, ['delivery_on' => $trip->delivery_on->toDateString(), 'order_ids' => $orders->modelKeys()]);
        });

        return redirect()->route('packaged.trips.show', $trip)->with('status', 'Planned trip updated.');
    }

    public function cancel(Request $request, DeliveryTrip $trip, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($trip, $data, $request, $auditTrail): void {
            $trip = DeliveryTrip::query()->lockForUpdate()->findOrFail($trip->id);
            if ($trip->status !== 'planned') {
                throw ValidationException::withMessages(['trip' => 'Only a planned trip can be cancelled.']);
            }
            $orderIds = $trip->orders()->pluck('sales_orders.id')->all();
            $trip->orders()->detach();
            $trip->update(['status' => 'cancelled']);
            $auditTrail->record('delivery_trip.cancelled', $trip, $request->user()->user_id, ['status' => 'planned', 'order_ids' => $orderIds], ['status' => 'cancelled'], $data['reason']);
        });

        return redirect()->route('distribution.index', ['tab' => 'trips'])->with('status', 'Planned trip cancelled. Its orders are available for another trip.');
    }

    public function dispatch(Request $request, DeliveryTrip $trip, DeliveryTripService $service): RedirectResponse
    {
        $service->dispatch($trip, $request->user()->user_id);

        return redirect()->route('packaged.trips.show', $trip)->with('status', 'Trip dispatched.');
    }

    public function show(DeliveryTrip $trip): View
    {
        $trip->load(['orders.customer', 'orders.deliveryArea', 'orders.items', 'orders.dispatch.lines.failedDeliveryLine', 'orders.invoice.deliveryFeeCredit']);
        $expectedRevenue = (float) $trip->orders->sum('delivery_fee');
        $invoicedRevenue = (float) $trip->orders->filter(fn (SalesOrder $order) => $order->invoice !== null)->sum(fn (SalesOrder $order) => (float) $order->delivery_fee - (float) ($order->invoice->deliveryFeeCredit?->amount ?? 0));

        return view('packaged.trip-show', compact('trip', 'expectedRevenue', 'invoicedRevenue'));
    }

    public function expense(Request $request, DeliveryTrip $trip, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'fuel_expense' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'other_expense' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'expense_reference' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($trip, $data, $request, $auditTrail): void {
            $trip = DeliveryTrip::query()->lockForUpdate()->findOrFail($trip->id);
            $before = ['fuel_expense' => $trip->fuel_expense, 'other_expense' => $trip->other_expense, 'expense_reference' => $trip->expense_reference];
            $trip->update([
                'fuel_expense' => $data['fuel_expense'],
                'other_expense' => $data['other_expense'],
                'expense_reference' => $data['expense_reference'],
                'expense_recorded_by' => $request->user()->user_id,
            ]);
            $auditTrail->record('delivery_trip.expense_updated', $trip, $request->user()->user_id, $before, ['fuel_expense' => $trip->fuel_expense, 'other_expense' => $trip->other_expense, 'expense_reference' => $trip->expense_reference], $data['reason']);
        });

        return back()->with('status', 'Trip expenses saved once for the whole shared trip.');
    }
}
