<?php

namespace App\Services;

use App\Models\BatchDisposal;
use App\Models\BatchReservation;
use App\Models\CustomerRefund;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeCredit;
use App\Models\DeliveryTrip;
use App\Models\DeliveryZone;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Models\ProductManufacturingPolicy;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PackagedWaterService
{
    public function __construct(private AuditTrail $auditTrail, private AppAlertService $alerts) {}

    public function createBatch(array $data, int $actorId): ProductionBatch
    {
        return DB::transaction(function () use ($data, $actorId): ProductionBatch {
            $policy = null;
            if (! isset($data['expires_on'])) {
                $policy = ProductManufacturingPolicy::query()
                    ->where('product_id', $data['product_id'])
                    ->whereDate('effective_from', '<=', $data['produced_on'])
                    ->orderByDesc('effective_from')->first();
                if (! $policy) {
                    throw ValidationException::withMessages(['product_id' => 'A manager must approve manufacturing costs and shelf life for this product before a batch can be recorded.']);
                }
                $data['expires_on'] = CarbonImmutable::parse($data['produced_on'])->addDays($policy->shelf_life_days)->toDateString();
                foreach (['water_treatment_cost' => 'water_treatment_per_case', 'bottle_cost' => 'bottle_per_case', 'inbound_transport_cost' => 'inbound_transport_per_case', 'cap_label_cost' => 'cap_label_per_case', 'labour_cost' => 'labour_per_case', 'electricity_cost' => 'electricity_per_case', 'other_production_cost' => 'other_production_per_case'] as $batchField => $policyField) {
                    $data[$batchField] = round((float) $policy->{$policyField} * (int) $data['cases_produced'], 2);
                }
            }
            $data['batch_code'] ??= 'B-'.CarbonImmutable::parse($data['produced_on'])->format('Ymd').'-'.$data['product_id'].'-'.Str::upper((string) Str::ulid());
            $plan = isset($data['production_forecast_decision_id'])
                ? ProductionForecastDecision::query()->with('forecast')->lockForUpdate()->findOrFail($data['production_forecast_decision_id'])
                : ProductionForecastDecision::query()->with('forecast')
                    ->where('plan_status', 'planned')
                    ->whereDate('planned_for', $data['produced_on'])
                    ->whereRaw('approved_cases - cases_completed >= ?', [(int) $data['cases_produced']])
                    ->whereHas('forecast', fn ($query) => $query->where('product_id', $data['product_id']))
                    ->orderBy('id')->lockForUpdate()->first();
            if ($plan) {
                $this->require($plan->plan_status === 'planned' && $plan->forecast->product_id === (int) $data['product_id'], 'The selected production plan is not active for this product.');
                $this->require($plan->planned_for->toDateString() === $data['produced_on'] && $plan->approved_cases - $plan->cases_completed >= (int) $data['cases_produced'], 'This batch exceeds the remaining approved plan or the plan date does not match.');
            } else {
                $actor = User::findOrFail($actorId);
                $this->require($actor->hasRole('administrator', 'manager'), 'A manager must authorise production outside an approved plan.');
                $this->require(filled($data['unplanned_reason'] ?? null), 'A manager must select Yes to approve production outside an approved plan.');
            }
            $costFields = ['water_treatment_cost', 'bottle_cost', 'inbound_transport_cost', 'cap_label_cost', 'labour_cost', 'electricity_cost', 'other_production_cost'];
            $totalCost = round(array_sum(array_map(fn (string $field): float => (float) ($data[$field] ?? 0), $costFields)), 2);
            $batch = ProductionBatch::create([
                'product_id' => $data['product_id'],
                'manufacturing_policy_id' => $policy?->id,
                'production_forecast_decision_id' => $plan?->id,
                'batch_code' => $data['batch_code'],
                'produced_on' => $data['produced_on'],
                'expires_on' => $data['expires_on'],
                'cases_produced' => $data['cases_produced'],
                'cases_on_hand' => $data['cases_produced'],
                'status' => 'quality_hold',
                'water_source_reference' => $data['water_source_reference'] ?? null,
                'water_input_litres' => $data['water_input_litres'] ?? null,
                'treatment_log_reference' => $data['treatment_log_reference'] ?? null,
                'unplanned_reason' => $plan ? null : $data['unplanned_reason'],
                'unplanned_authorized_by' => $plan ? null : $actorId,
                'water_treatment_cost' => $data['water_treatment_cost'] ?? 0,
                'bottle_cost' => $data['bottle_cost'] ?? 0,
                'inbound_transport_cost' => $data['inbound_transport_cost'] ?? 0,
                'cap_label_cost' => $data['cap_label_cost'] ?? 0,
                'labour_cost' => $data['labour_cost'] ?? 0,
                'electricity_cost' => $data['electricity_cost'] ?? 0,
                'other_production_cost' => $data['other_production_cost'] ?? 0,
                'total_cost' => $totalCost,
                'cost_per_case' => round($totalCost / $data['cases_produced'], 4),
                'produced_by' => $actorId,
            ]);
            StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => $batch->cases_produced, 'reason' => 'production', 'reference_type' => 'ProductionBatch', 'reference_id' => $batch->id, 'performed_by' => $actorId]);
            if ($plan) {
                $plan->increment('cases_completed', $batch->cases_produced);
                if ($plan->fresh()->cases_completed >= $plan->approved_cases) {
                    $plan->update(['plan_status' => 'completed']);
                }
            }
            $this->auditTrail->record('batch.created', $batch, $actorId, [], ['cases' => $batch->cases_produced, 'cost' => $totalCost]);
            if (! $plan) {
                $this->auditTrail->record('batch.unplanned_authorized', $batch, $actorId, [], ['cases' => $batch->cases_produced], $data['unplanned_reason']);
            }
            $this->alerts->publish('batch:quality:'.$batch->id, 'quality', 'warning', 'Batch needs quality review', $batch->batch_code.' is on quality hold and cannot be sold yet.', 'batch', $batch->id, ['administrator', 'manager', 'inventory_staff']);
            if ($plan && $plan->fresh()->plan_status === 'completed') {
                $this->alerts->resolve('plan:approved:'.$plan->id);
            }

            return $batch;
        });
    }

    public function releaseBatch(ProductionBatch $batch, int $actorId, string $notes, int $rejectedCases = 0, array $qualityData = []): void
    {
        DB::transaction(function () use ($batch, $actorId, $notes, $rejectedCases, $qualityData): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require($batch->status === 'quality_hold', 'Only a batch on quality hold can be released.');
            $this->require($batch->expires_on->gt(CarbonImmutable::today(config('app.business_timezone'))), 'An expired batch cannot be released.');
            $this->require($rejectedCases >= 0 && $rejectedCases < $batch->cases_on_hand, 'At least one saleable case must remain after quality rejection.');
            $saleableCases = $batch->cases_on_hand - $rejectedCases;
            $batch->update(['status' => 'released', 'quality_notes' => $notes, 'quality_rejected_cases' => $rejectedCases, 'cases_on_hand' => $saleableCases, 'cost_per_case' => round((float) $batch->total_cost / $saleableCases, 4), 'released_by' => $actorId, 'released_at' => now(), 'quality_tested_on' => $qualityData['quality_tested_on'] ?? null, 'quality_evidence_reference' => $qualityData['quality_evidence_reference'] ?? null, 'quality_ph' => $qualityData['quality_ph'] ?? null, 'quality_turbidity_ntu' => $qualityData['quality_turbidity_ntu'] ?? null, 'microbiological_result' => $qualityData['microbiological_result'] ?? null]);
            if ($rejectedCases > 0) {
                StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => -$rejectedCases, 'reason' => 'quality_rejection', 'reference_type' => 'ProductionBatch', 'reference_id' => $batch->id, 'performed_by' => $actorId, 'notes' => $notes]);
            }
            $this->auditTrail->record('batch.released', $batch, $actorId, ['status' => 'quality_hold'], ['status' => 'released', 'rejected_cases' => $rejectedCases, 'saleable_cost_per_case' => $batch->cost_per_case], $notes);
            $this->alerts->resolve('batch:quality:'.$batch->id);
        });
    }

    public function quarantineBatch(ProductionBatch $batch, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($batch, $actorId, $reason): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require(in_array($batch->status, ['quality_hold', 'released'], true), 'Only an unrecalled batch can be quarantined.');
            $this->require($batch->cases_on_hand > 0, 'A batch with no cases on hand cannot be quarantined. Recall it if customers may have received affected cases.');
            $previous = $batch->status;
            $batch->update(['status' => 'quarantined', 'quality_notes' => $reason]);
            $affectedOrders = $this->resolveReservationsAfterBatchHold($batch, $actorId, $reason, 'quarantine');
            $this->auditTrail->record('batch.quarantined', $batch, $actorId, ['status' => $previous], ['status' => 'quarantined', 'affected_open_orders' => $affectedOrders], $reason);
            $this->alerts->resolve('batch:quality:'.$batch->id);
            $this->alerts->publish('batch:quarantined:'.$batch->id, 'quality', 'high', 'Batch quarantined', $batch->batch_code.' was quarantined. Review the batch before any further action.', 'batch', $batch->id, ['administrator', 'manager', 'inventory_staff']);
        });
    }

    public function recallBatch(ProductionBatch $batch, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($batch, $actorId, $reason): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require($batch->status !== 'recalled', 'This batch has already been recalled.');
            $oldStatus = $batch->status;
            $batch->update(['status' => 'recalled', 'quality_notes' => $reason]);
            $affectedOrders = $this->resolveReservationsAfterBatchHold($batch, $actorId, $reason, 'recall');
            $this->auditTrail->record('batch.recalled', $batch, $actorId, ['status' => $oldStatus], ['status' => 'recalled', 'affected_open_orders' => $affectedOrders], $reason);
            $this->alerts->resolve('batch:quality:'.$batch->id);
            $this->alerts->publish('batch:recalled:'.$batch->id, 'quality', 'high', 'Urgent batch recall', $batch->batch_code.' was recalled. Check affected stock and customers.', 'batch', $batch->id, ['administrator', 'manager', 'inventory_staff'], ['administrator', 'manager']);
        });
    }

    private function resolveReservationsAfterBatchHold(ProductionBatch $batch, int $actorId, string $reason, string $cause): int
    {
        $orderIds = DB::table('batch_reservations')
            ->join('sales_order_items', 'batch_reservations.sales_order_item_id', '=', 'sales_order_items.id')
            ->where('batch_reservations.production_batch_id', $batch->id)
            ->pluck('sales_order_items.sales_order_id')->unique();
        foreach ($orderIds as $orderId) {
            $order = SalesOrder::query()->with('items.reservations')->lockForUpdate()->findOrFail($orderId);
            foreach ($order->items as $item) {
                foreach ($item->reservations as $reservation) {
                    ProductionBatch::query()->whereKey($reservation->production_batch_id)->decrement('cases_reserved', $reservation->cases_reserved);
                    $reservation->delete();
                }
            }
            $order->update(['status' => 'draft']);
            $this->auditTrail->record('order.reservation_released_for_'.$cause, $order, $actorId, [], ['unavailable_batch' => $batch->batch_code], $reason);
            $this->alerts->resolve('order:dispatch:'.$order->id);
            try {
                $this->confirmOrder($order, $actorId);
                $this->auditTrail->record('order.reallocated_after_'.$cause, $order, $actorId, [], ['unavailable_batch' => $batch->batch_code], $reason);
            } catch (ValidationException) {
                $this->alerts->publish('order:reallocation:'.$order->id, 'order', 'high', 'Order needs new stock', $order->order_number.' returned to draft because replacement stock is insufficient after a batch '.$cause.'.', 'order', $order->id, ['administrator', 'manager', 'sales_staff', 'distribution_staff']);
            }
        }

        return $orderIds->count();
    }

    public function disposeBatch(ProductionBatch $batch, int $cases, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($batch, $cases, $reason, $actorId): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require($cases > 0 && $cases <= $batch->cases_on_hand - $batch->cases_reserved, 'Invalid disposal quantity or reserved stock.');
            $this->require(in_array($batch->status, ['quarantined', 'recalled'], true) || $batch->expires_on->lte(CarbonImmutable::today(config('app.business_timezone'))), 'Only quarantined, recalled, or expired stock can be disposed.');
            $disposal = BatchDisposal::create(['production_batch_id' => $batch->id, 'cases' => $cases, 'reason' => $reason, 'disposed_on' => CarbonImmutable::today(config('app.business_timezone')), 'recorded_by' => $actorId]);
            $batch->decrement('cases_on_hand', $cases);
            StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => -$cases, 'reason' => 'disposal', 'reference_type' => 'BatchDisposal', 'reference_id' => $disposal->id, 'performed_by' => $actorId, 'notes' => $reason]);
            $this->auditTrail->record('batch.disposed', $batch, $actorId, [], ['cases' => $cases, 'loss' => round($cases * (float) $batch->cost_per_case, 2)], $reason);
        });
    }

    public function createOrder(array $data, int $actorId): SalesOrder
    {
        return DB::transaction(function () use ($data, $actorId): SalesOrder {
            $products = Product::query()->whereIn('id', array_column($data['items'], 'product_id'))->where('is_active', true)->get()->keyBy('id');
            $this->require($products->count() === count($data['items']), 'Each ordered product must be active and appear only once.');
            $subtotal = 0.0;
            foreach ($data['items'] as $item) {
                $subtotal += $item['cases_ordered'] * $item['price_per_case'];
            }
            $discount = (float) ($data['discount'] ?? 0);
            $this->require($discount <= $subtotal, 'Discount cannot exceed the order subtotal.');
            $area = isset($data['delivery_area_id']) ? DeliveryArea::query()->findOrFail($data['delivery_area_id']) : null;
            $zone = $area ? DeliveryZone::query()->lockForUpdate()->findOrFail($area->delivery_zone_id) : null;
            if ($area) {
                $this->require($area->county === 'Nairobi' && $zone->county === 'Nairobi' && $zone->is_active, 'Choose an active Nairobi delivery area.');
            }
            $deliveryFee = $zone ? (float) $zone->fixed_fee : 0.0;
            $order = SalesOrder::create([
                'order_number' => 'ORD-'.Str::upper(Str::random(12)),
                'customer_id' => $data['customer_id'],
                'ordered_on' => CarbonImmutable::today(config('app.business_timezone')),
                'requested_delivery_on' => $data['requested_delivery_on'] ?? CarbonImmutable::today(config('app.business_timezone')),
                'status' => 'draft',
                'delivery_address' => $data['delivery_address'],
                'delivery_county' => $area ? 'Nairobi' : null,
                'delivery_area_id' => $area?->id,
                'delivery_zone_id' => $area?->delivery_zone_id,
                'delivery_fee' => $deliveryFee,
                'discount' => $discount,
                'delivery_cost' => $area ? 0 : ($data['delivery_cost'] ?? 0),
                'total_amount' => round($subtotal - $discount + $deliveryFee, 2),
                'price_override_reason' => $data['price_override_reason'] ?? null,
                'data_origin' => 'genuine',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
            foreach ($data['items'] as $item) {
                $order->items()->create(['product_id' => $item['product_id'], 'cases_ordered' => $item['cases_ordered'], 'price_per_case' => $item['price_per_case'], 'line_total' => round($item['cases_ordered'] * $item['price_per_case'], 2)]);
            }
            $this->auditTrail->record('order.created', $order, $actorId, [], ['amount' => $order->total_amount, 'discount' => $discount, 'delivery_fee' => $deliveryFee, 'delivery_area_id' => $area?->id], $order->price_override_reason);

            return $order;
        });
    }

    public function confirmOrder(SalesOrder $order, int $actorId): void
    {
        DB::transaction(function () use ($order, $actorId): void {
            $order = SalesOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'draft', 'Only draft orders can be confirmed.');
            foreach ($order->items as $item) {
                $needed = $item->cases_ordered;
                $usableThrough = $order->requested_delivery_on && $order->requested_delivery_on->gt(CarbonImmutable::today(config('app.business_timezone'))) ? $order->requested_delivery_on : CarbonImmutable::today(config('app.business_timezone'));
                $batches = ProductionBatch::query()->where('product_id', $item->product_id)->where('status', 'released')->whereDate('expires_on', '>', $usableThrough)->where('cases_on_hand', '>', 0)->orderBy('expires_on')->orderBy('id')->lockForUpdate()->get();
                foreach ($batches as $batch) {
                    $available = $batch->cases_on_hand - $batch->cases_reserved;
                    $take = min($needed, $available);
                    if ($take <= 0) {
                        continue;
                    }
                    BatchReservation::create(['sales_order_item_id' => $item->id, 'production_batch_id' => $batch->id, 'cases_reserved' => $take]);
                    $batch->increment('cases_reserved', $take);
                    $needed -= $take;
                    if ($needed === 0) {
                        break;
                    }
                }
                $this->require($needed === 0, 'Insufficient released, unexpired stock for '.$item->product->name.'.');
            }
            $order->update(['status' => 'confirmed']);
            $this->auditTrail->record('order.confirmed', $order, $actorId, ['status' => 'draft'], ['status' => 'confirmed']);
            $this->alerts->resolve('order:reallocation:'.$order->id);
            $this->alerts->publish('order:dispatch:'.$order->id, 'order', 'warning', 'Order ready for dispatch', $order->order_number.' has reserved stock and is ready to dispatch.', 'order', $order->id, ['administrator', 'manager', 'distribution_staff']);
        });
    }

    public function cancelOrder(SalesOrder $order, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($order, $actorId, $reason): void {
            $order = SalesOrder::query()->with('items.reservations')->lockForUpdate()->findOrFail($order->id);
            $this->require(in_array($order->status, ['draft', 'confirmed'], true), 'Only an undispatched order can be cancelled.');
            foreach ($order->items as $item) {
                foreach ($item->reservations as $reservation) {
                    ProductionBatch::query()->whereKey($reservation->production_batch_id)->decrement('cases_reserved', $reservation->cases_reserved);
                    $reservation->delete();
                }
            }
            $plannedTripIds = $order->deliveryTrips()->where('delivery_trips.status', 'planned')->pluck('delivery_trips.id');
            $order->deliveryTrips()->detach();
            $previous = $order->status;
            $order->update(['status' => 'cancelled']);
            $this->auditTrail->record('order.cancelled', $order, $actorId, ['status' => $previous], ['status' => 'cancelled'], $reason);
            foreach ($plannedTripIds as $tripId) {
                $trip = DeliveryTrip::query()->lockForUpdate()->findOrFail($tripId);
                if ($trip->status === 'planned' && ! $trip->orders()->exists()) {
                    $trip->update(['status' => 'cancelled']);
                    $this->auditTrail->record('delivery_trip.cancelled', $trip, $actorId, ['status' => 'planned'], ['status' => 'cancelled'], 'Last assigned order was cancelled: '.$reason);
                }
            }
        });
    }

    public function dispatchOrder(SalesOrder $order, int $actorId, string $reference): Dispatch
    {
        return DB::transaction(function () use ($order, $actorId, $reference): Dispatch {
            $order = SalesOrder::query()->with('items.reservations')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'confirmed', 'Only confirmed orders can be dispatched.');
            $trip = $order->deliveryTrips()->first();
            $this->require($trip !== null && $trip->status === 'dispatched' && (int) $trip->active_dispatch_key === 1, 'Dispatch this order from its active delivery trip.');
            $usableThrough = collect([$order->requested_delivery_on, $trip->delivery_on, CarbonImmutable::today(config('app.business_timezone'))])->filter()->max();
            $this->reallocateIneligibleReservations($order, $usableThrough, $actorId);
            $order->load('items.reservations');
            $dispatch = Dispatch::create(['sales_order_id' => $order->id, 'dispatched_on' => CarbonImmutable::today(config('app.business_timezone')), 'status' => 'dispatched', 'delivery_reference' => $reference, 'dispatched_by' => $actorId]);
            foreach ($order->items as $item) {
                $this->require($item->reservations->sum('cases_reserved') === $item->cases_ordered, 'Order reservation is incomplete.');
                foreach ($item->reservations as $reservation) {
                    $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($reservation->production_batch_id);
                    $this->require($batch->status === 'released' && $batch->expires_on->gt($usableThrough) && $batch->cases_on_hand >= $reservation->cases_reserved, 'A reserved batch is no longer eligible for dispatch.');
                    DispatchLine::create(['dispatch_id' => $dispatch->id, 'sales_order_item_id' => $item->id, 'production_batch_id' => $batch->id, 'cases' => $reservation->cases_reserved, 'cost_per_case' => $batch->cost_per_case]);
                    $batch->decrement('cases_on_hand', $reservation->cases_reserved);
                    $batch->decrement('cases_reserved', $reservation->cases_reserved);
                    StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => -$reservation->cases_reserved, 'reason' => 'customer_dispatch', 'reference_type' => 'Dispatch', 'reference_id' => $dispatch->id, 'performed_by' => $actorId]);
                    $reservation->delete();
                }
            }
            $order->update(['status' => 'dispatched']);
            $this->auditTrail->record('order.dispatched', $order, $actorId, ['status' => 'confirmed'], ['status' => 'dispatched', 'dispatch_id' => $dispatch->id]);
            $this->alerts->resolve('order:dispatch:'.$order->id);
            $this->alerts->publish('order:delivery:'.$order->id, 'order', 'warning', 'Delivery needs confirmation', $order->order_number.' was dispatched and is awaiting delivery confirmation.', 'order', $order->id, ['administrator', 'manager', 'distribution_staff']);

            return $dispatch;
        });
    }

    private function reallocateIneligibleReservations(SalesOrder $order, $usableThrough, int $actorId): void
    {
        foreach ($order->items as $item) {
            $needed = 0;
            foreach ($item->reservations as $reservation) {
                $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($reservation->production_batch_id);
                if ($batch->status === 'released' && $batch->expires_on->gt($usableThrough) && $batch->cases_on_hand >= $reservation->cases_reserved) {
                    continue;
                }
                $needed += $reservation->cases_reserved;
                $batch->decrement('cases_reserved', $reservation->cases_reserved);
                $reservation->delete();
                $this->auditTrail->record('order.reservation_released', $order, $actorId, ['batch_id' => $batch->id, 'cases' => $reservation->cases_reserved], [], 'Reserved stock became ineligible before dispatch.');
            }
            if ($needed === 0) {
                continue;
            }
            $replacements = ProductionBatch::query()->where('product_id', $item->product_id)->where('status', 'released')
                ->whereDate('expires_on', '>', $usableThrough)->where('cases_on_hand', '>', 0)
                ->orderBy('expires_on')->orderBy('id')->lockForUpdate()->get();
            foreach ($replacements as $batch) {
                $take = min($needed, max(0, $batch->cases_on_hand - $batch->cases_reserved));
                if ($take === 0) {
                    continue;
                }
                $reservation = BatchReservation::query()->firstOrNew(['sales_order_item_id' => $item->id, 'production_batch_id' => $batch->id]);
                $reservation->cases_reserved = (int) $reservation->cases_reserved + $take;
                $reservation->save();
                $batch->increment('cases_reserved', $take);
                $needed -= $take;
                $this->auditTrail->record('order.reservation_reallocated', $order, $actorId, [], ['batch_id' => $batch->id, 'cases' => $take]);
                if ($needed === 0) {
                    break;
                }
            }
            $this->require($needed === 0, 'Reserved stock is no longer eligible, and there are not enough replacement cases. Resolve the order before dispatch.');
        }
    }

    public function deliverOrder(SalesOrder $order, int $actorId, array $receipt = []): void
    {
        DB::transaction(function () use ($order, $actorId, $receipt): void {
            $order = SalesOrder::query()->with('dispatch')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'dispatched' && $order->dispatch !== null, 'The order has not been dispatched.');
            $order->dispatch->update(['status' => 'delivered', 'delivered_on' => CarbonImmutable::today(config('app.business_timezone')), 'received_by' => $receipt['received_by'] ?? null, 'receipt_reference' => $receipt['receipt_reference'] ?? null]);
            $order->update(['status' => 'delivered']);
            $this->auditTrail->record('order.delivered', $order, $actorId, ['status' => 'dispatched'], ['status' => 'delivered', 'received_by' => $receipt['received_by'] ?? null, 'receipt_reference' => $receipt['receipt_reference'] ?? null]);
            $this->alerts->resolve('order:delivery:'.$order->id);
            $this->alerts->publish('order:invoice:'.$order->id, 'order', 'warning', 'Delivered order needs invoice', $order->order_number.' has been delivered and can now be invoiced.', 'order', $order->id, ['administrator', 'manager', 'sales_staff']);
            $this->completeTripIfResolved($order, $actorId);
        });
    }

    public function failDelivery(SalesOrder $order, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($order, $reason, $actorId): void {
            $order = SalesOrder::query()->with('dispatch.lines')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'dispatched' && $order->dispatch !== null, 'Only a dispatched order can be marked as a failed delivery.');
            foreach ($order->dispatch->lines as $line) {
                DB::table('failed_delivery_lines')->insert(['dispatch_line_id' => $line->id, 'cases' => $line->cases, 'status' => 'with_truck', 'failure_reason' => $reason, 'recorded_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $order->dispatch->update(['status' => 'delivery_failed']);
            $order->update(['status' => 'delivery_failed']);
            $this->auditTrail->record('order.delivery_failed', $order, $actorId, ['status' => 'dispatched'], ['status' => 'delivery_failed'], $reason);
            $this->alerts->resolve('order:delivery:'.$order->id);
        });
    }

    public function receiveFailedDelivery(SalesOrder $order, string $reference, int $actorId): void
    {
        DB::transaction(function () use ($order, $reference, $actorId): void {
            $order = SalesOrder::query()->with('dispatch.lines')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'delivery_failed', 'The order has no failed delivery awaiting warehouse return.');
            $ids = $order->dispatch->lines->pluck('id');
            $this->require(DB::table('failed_delivery_lines')->whereIn('dispatch_line_id', $ids)->where('status', 'with_truck')->count() === $ids->count(), 'This failed delivery has already been returned.');
            DB::table('failed_delivery_lines')->whereIn('dispatch_line_id', $ids)->update(['status' => 'quality_hold', 'warehouse_reference' => $reference, 'returned_on' => CarbonImmutable::today(config('app.business_timezone'))->toDateString(), 'updated_at' => now()]);
            $this->auditTrail->record('order.failed_delivery_returned', $order, $actorId, [], ['dispatch_line_ids' => $ids->all(), 'warehouse_reference' => $reference], 'Returned cases remain on quality hold; saleable stock is unchanged.');
            $this->completeTripIfResolved($order, $actorId);
        });
    }

    public function inspectFailedDeliveryLine(int $lineId, bool $passed, string $notes, int $actorId): void
    {
        DB::transaction(function () use ($lineId, $passed, $notes, $actorId): void {
            $line = DispatchLine::query()->lockForUpdate()->findOrFail($lineId);
            $return = DB::table('failed_delivery_lines')->where('dispatch_line_id', $lineId)->lockForUpdate()->first();
            $this->require($return && $return->status === 'quality_hold', 'Only returned cases on quality hold can be inspected.');
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($line->production_batch_id);
            $canRelease = $passed && $batch->status === 'released' && $batch->expires_on->gt(CarbonImmutable::today(config('app.business_timezone')));
            $this->require(! $passed || $canRelease, 'This batch is no longer released and unexpired; reject the returned cases for disposal.');
            if ($canRelease) {
                $batch->increment('cases_on_hand', $return->cases);
                StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => $return->cases, 'reason' => 'failed_delivery_quality_release', 'reference_type' => 'DispatchLine', 'reference_id' => $lineId, 'performed_by' => $actorId]);
            }
            DB::table('failed_delivery_lines')->where('id', $return->id)->update(['status' => $canRelease ? 'released' : 'disposed', 'quality_notes' => $notes, 'inspected_by' => $actorId, 'inspected_on' => CarbonImmutable::today(config('app.business_timezone'))->toDateString(), 'updated_at' => now()]);
            $this->auditTrail->record($canRelease ? 'order.failed_delivery_stock_released' : 'order.failed_delivery_stock_disposed', $line, $actorId, ['status' => 'quality_hold'], ['cases' => $return->cases, 'batch_id' => $batch->id], $notes);
        });
    }

    private function completeTripIfResolved(SalesOrder $order, int $actorId): void
    {
        $trip = $order->deliveryTrips()->first();
        if (! $trip || $trip->status !== 'dispatched') {
            return;
        }
        $trip = DeliveryTrip::query()->lockForUpdate()->findOrFail($trip->id);
        $unresolved = $trip->orders()->whereNotIn('sales_orders.status', ['delivered', 'invoiced', 'paid', 'delivery_failed'])->exists();
        $goodsWithTruck = DB::table('failed_delivery_lines')->join('dispatch_lines', 'failed_delivery_lines.dispatch_line_id', '=', 'dispatch_lines.id')
            ->join('dispatches', 'dispatch_lines.dispatch_id', '=', 'dispatches.id')
            ->join('delivery_trip_orders', 'dispatches.sales_order_id', '=', 'delivery_trip_orders.sales_order_id')
            ->where('delivery_trip_orders.delivery_trip_id', $trip->id)->where('failed_delivery_lines.status', 'with_truck')->exists();
        if (! $unresolved && ! $goodsWithTruck) {
            $trip->update(['status' => 'completed', 'active_dispatch_key' => null]);
            $this->auditTrail->record('delivery_trip.completed', $trip, $actorId, ['status' => 'dispatched'], ['status' => 'completed']);
        }
    }

    public function invoiceOrder(SalesOrder $order, int $actorId): Invoice
    {
        return DB::transaction(function () use ($order, $actorId): Invoice {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'delivered', 'Only delivered orders can be invoiced.');
            $invoice = Invoice::create(['sales_order_id' => $order->id, 'invoice_number' => 'INV-'.Str::upper(Str::random(12)), 'issued_on' => CarbonImmutable::today(config('app.business_timezone')), 'amount' => $order->total_amount, 'status' => 'unpaid']);
            $order->update(['status' => 'invoiced']);
            $this->auditTrail->record('invoice.issued', $invoice, $actorId, [], ['amount' => $invoice->amount]);
            $this->alerts->resolve('order:invoice:'.$order->id);

            return $invoice;
        });
    }

    public function recordPayment(Invoice $invoice, array $data, int $actorId): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $actorId): Payment {
            $invoice = Invoice::query()->with(['activePayments', 'returns', 'deliveryFeeCredit'])->lockForUpdate()->findOrFail($invoice->id);
            $remaining = round((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - (float) ($invoice->deliveryFeeCredit?->amount ?? 0) - (float) $invoice->activePayments->sum('amount'), 2);
            $this->require(preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $data['amount']) === 1, 'Payment must use at most two decimal places.');
            $amountCents = (int) round((float) $data['amount'] * 100);
            $this->require($amountCents > 0 && $amountCents <= (int) round($remaining * 100), 'Payment exceeds the outstanding invoice balance.');
            $data['amount'] = number_format($amountCents / 100, 2, '.', '');
            $payment = Payment::create(['invoice_id' => $invoice->id, 'amount' => $data['amount'], 'paid_on' => CarbonImmutable::today(config('app.business_timezone')), 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'recorded_by' => $actorId]);
            $paid = $amountCents === (int) round($remaining * 100);
            $invoice->update(['status' => $paid ? 'paid' : 'part_paid']);
            if ($paid) {
                SalesOrder::query()->whereKey($invoice->sales_order_id)->update(['status' => 'paid']);
            }
            $this->auditTrail->record('invoice.payment_recorded', $invoice, $actorId, [], ['amount' => $data['amount'], 'method' => $data['method']]);

            return $payment;
        });
    }

    public function recordReturn(Invoice $invoice, DispatchLine $line, int $cases, string $reason, int $actorId): SalesReturn
    {
        return DB::transaction(function () use ($invoice, $line, $cases, $reason, $actorId): SalesReturn {
            $invoice = Invoice::query()->with(['order.items', 'activePayments', 'returns', 'deliveryFeeCredit'])->lockForUpdate()->findOrFail($invoice->id);
            $line = DispatchLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->require(Dispatch::query()->whereKey($line->dispatch_id)->where('sales_order_id', $invoice->sales_order_id)->exists(), 'This batch dispatch does not belong to the invoice.');
            $alreadyReturned = (int) SalesReturn::query()->where('dispatch_line_id', $line->id)->sum('cases');
            $this->require($cases > 0 && $cases + $alreadyReturned <= $line->cases, 'Return quantity exceeds dispatched cases.');
            $item = SalesOrderItem::findOrFail($line->sales_order_item_id);
            $subtotal = (float) $invoice->order->items->sum('line_total');
            $discountRate = $subtotal > 0 ? (float) $invoice->order->discount / $subtotal : 0;
            $creditAmount = round($cases * (float) $item->price_per_case * (1 - $discountRate), 2);
            $this->require((float) $invoice->returns->sum('credit_amount') + $creditAmount <= (float) $invoice->amount - (float) $invoice->order->delivery_fee + 0.01, 'Product credits exceed the bottled-water amount.');
            $return = SalesReturn::create(['invoice_id' => $invoice->id, 'dispatch_line_id' => $line->id, 'cases' => $cases, 'credit_amount' => $creditAmount, 'returned_on' => CarbonImmutable::today(config('app.business_timezone')), 'disposition' => 'destroyed_on_receipt', 'reason' => $reason, 'recorded_by' => $actorId]);
            $netDue = round((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - $creditAmount - (float) ($invoice->deliveryFeeCredit?->amount ?? 0) - (float) $invoice->activePayments->sum('amount'), 2);
            $invoice->update(['status' => $netDue < 0 ? 'credit_due' : ($netDue == 0.0 ? 'settled' : ((float) $invoice->activePayments->sum('amount') > 0 ? 'part_paid' : 'credited'))]);
            $this->auditTrail->record('invoice.return_credited', $return, $actorId, [], ['cases' => $cases, 'credit_amount' => $creditAmount, 'disposition' => 'destroyed_on_receipt'], $reason);

            return $return;
        });
    }

    public function recordRefund(Invoice $invoice, array $data, int $actorId): CustomerRefund
    {
        return DB::transaction(function () use ($invoice, $data, $actorId): CustomerRefund {
            $invoice = Invoice::query()->with(['activePayments', 'returns', 'refunds', 'deliveryFeeCredit'])->lockForUpdate()->findOrFail($invoice->id);
            $creditDue = round((float) $invoice->activePayments->sum('amount') - ((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - (float) ($invoice->deliveryFeeCredit?->amount ?? 0)) - (float) $invoice->refunds->sum('amount'), 2);
            $this->require($data['amount'] > 0 && $data['amount'] <= $creditDue, 'Refund exceeds the customer credit due.');
            $refund = CustomerRefund::create($data + ['invoice_id' => $invoice->id, 'refunded_on' => CarbonImmutable::today(config('app.business_timezone')), 'recorded_by' => $actorId]);
            if (round($creditDue - $data['amount'], 2) <= 0) {
                $invoice->update(['status' => 'settled']);
            }
            $this->auditTrail->record('invoice.refund_recorded', $refund, $actorId, [], ['amount' => $refund->amount, 'reference' => $refund->reference], $refund->reason);

            return $refund;
        });
    }

    public function approveDeliveryFeeCredit(Invoice $invoice, string $reason, int $actorId): DeliveryFeeCredit
    {
        return DB::transaction(function () use ($invoice, $reason, $actorId): DeliveryFeeCredit {
            $invoice = Invoice::query()->with(['order', 'activePayments', 'returns'])->lockForUpdate()->findOrFail($invoice->id);
            $fee = (float) $invoice->order->delivery_fee;
            $this->require($fee > 0 && ! DeliveryFeeCredit::query()->where('invoice_id', $invoice->id)->exists(), 'This invoice has no refundable delivery fee or it has already been credited.');
            $credit = DeliveryFeeCredit::create(['invoice_id' => $invoice->id, 'amount' => $fee, 'reason' => $reason, 'approved_by' => $actorId]);
            $remaining = round((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - $fee - (float) $invoice->activePayments->sum('amount'), 2);
            $invoice->update(['status' => $remaining < 0 ? 'credit_due' : ($remaining == 0.0 ? 'settled' : ((float) $invoice->activePayments->sum('amount') > 0 ? 'part_paid' : 'credited'))]);
            $this->auditTrail->record('invoice.delivery_fee_credited', $credit, $actorId, [], ['amount' => $fee, 'invoice_id' => $invoice->id], $reason);

            return $credit;
        });
    }

    public function reversePayment(Payment $payment, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($payment, $reason, $actorId): void {
            $invoice = Invoice::query()->with(['activePayments', 'returns', 'refunds', 'deliveryFeeCredit'])->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->require($payment->invoice_id === $invoice->id && $payment->reversed_at === null, 'Payment has already been reversed or is no longer linked to this invoice.');
            $this->require($invoice->refunds->isEmpty(), 'A customer refund has been recorded. Reconcile that refund before reversing a payment.');
            $payment->update(['reversed_at' => now(), 'reversed_by' => $actorId, 'reversal_reason' => $reason]);
            $remaining = round((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - (float) ($invoice->deliveryFeeCredit?->amount ?? 0) - (float) $invoice->activePayments->where('id', '!=', $payment->id)->sum('amount'), 2);
            $invoice->update(['status' => $remaining < 0 ? 'credit_due' : ($remaining == 0.0 ? 'settled' : ((float) $invoice->activePayments->where('id', '!=', $payment->id)->sum('amount') > 0 ? 'part_paid' : 'unpaid'))]);
            if ($remaining > 0) {
                $invoice->order()->update(['status' => 'invoiced']);
            }
            $this->auditTrail->record('invoice.payment_reversed', $payment, $actorId, ['amount' => $payment->amount], ['reversed_at' => $payment->reversed_at], $reason);
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
