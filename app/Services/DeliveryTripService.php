<?php

namespace App\Services;

use App\Models\DeliveryTrip;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryTripService
{
    public function __construct(private PackagedWaterService $water, private AuditTrail $auditTrail) {}

    public function dispatch(DeliveryTrip $trip, int $actorId): void
    {
        try {
            DB::transaction(function () use ($trip, $actorId): void {
                $trip = DeliveryTrip::query()->lockForUpdate()->findOrFail($trip->id);
                if ($trip->status !== 'planned') {
                    throw ValidationException::withMessages(['trip' => 'Only a planned delivery trip can be dispatched.']);
                }
                if (DeliveryTrip::query()->where('status', 'dispatched')->exists()
                    || DeliveryTrip::query()->whereNull('status')->whereHas('orders', fn ($query) => $query->where('status', 'dispatched'))->exists()) {
                    throw ValidationException::withMessages(['trip' => 'Complete the trip already out for delivery before dispatching another.']);
                }
                $orders = $trip->orders()->orderBy('sales_orders.id')->lockForUpdate()->get();
                if ($orders->isEmpty() || $orders->contains(fn ($order) => $order->status !== 'confirmed' || $order->dispatch()->exists())) {
                    throw ValidationException::withMessages(['trip' => 'Every order on this trip must still be confirmed and awaiting dispatch.']);
                }

                $trip->update(['status' => 'dispatched', 'active_dispatch_key' => 1]);
                foreach ($orders as $order) {
                    $this->water->dispatchOrder($order, $actorId, $trip->trip_reference.'-'.$order->id);
                }
                $this->auditTrail->record('delivery_trip.dispatched', $trip, $actorId, ['status' => 'planned'], ['status' => 'dispatched', 'order_ids' => $orders->modelKeys()]);
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'active_dispatch_key')) {
                throw ValidationException::withMessages(['trip' => 'Another delivery trip is already out. Complete it before dispatching this one.']);
            }

            throw $exception;
        }
    }
}
