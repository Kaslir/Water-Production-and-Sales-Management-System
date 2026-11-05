<?php

namespace App\Http\Controllers;

use App\Models\DeliveryTrip;
use App\Models\SalesOrder;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeliveryTripController extends Controller
{
    public function create(): View
    {
        return view('packaged.trip-create', [
            'orders' => SalesOrder::query()->with(['customer', 'deliveryArea'])->where('status', 'confirmed')->whereNotNull('delivery_area_id')->whereDoesntHave('deliveryTrips')->orderBy('requested_delivery_on')->get(),
        ]);
    }

    public function store(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'truck_identifier' => ['required', 'string', 'max:80'],
            'driver_name' => ['required', 'string', 'max:120'],
            'delivery_on' => ['required', 'date', 'after_or_equal:today'],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'exists:sales_orders,id'],
        ]);
        $trip = DB::transaction(function () use ($data, $request, $auditTrail): DeliveryTrip {
            $orders = SalesOrder::query()->whereKey($data['order_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== count($data['order_ids'])) {
                throw ValidationException::withMessages(['order_ids' => 'Choose valid customer orders.']);
            }
            foreach ($orders as $order) {
                if ($order->status !== 'confirmed' || $order->delivery_area_id === null || $order->deliveryTrips()->exists()) {
                    throw ValidationException::withMessages(['order_ids' => 'Each order must be confirmed, have a Nairobi delivery area, and be unassigned.']);
                }
            }
            $trip = DeliveryTrip::create([
                'truck_identifier' => $data['truck_identifier'],
                'driver_name' => $data['driver_name'],
                'delivery_on' => $data['delivery_on'],
                'created_by' => $request->user()->user_id,
            ]);
            $trip->orders()->attach($orders->modelKeys());
            $auditTrail->record('delivery_trip.created', $trip, $request->user()->user_id, [], ['order_ids' => $orders->modelKeys(), 'truck' => $trip->truck_identifier, 'delivery_on' => $trip->delivery_on->toDateString()]);

            return $trip;
        });

        return redirect()->route('packaged.trips.show', $trip)->with('status', 'Shared truck trip created. Creating a trip does not deduct stock.');
    }

    public function show(DeliveryTrip $trip): View
    {
        $trip->load(['orders.customer', 'orders.deliveryArea', 'orders.dispatch', 'orders.invoice.deliveryFeeCredit']);
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
