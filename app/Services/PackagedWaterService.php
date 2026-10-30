<?php

namespace App\Services;

use App\Models\BatchDisposal;
use App\Models\BatchReservation;
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
            $this->alerts->publish('batch:quality:'.$batch->id, 'quality', 'warning', 'Batch needs quality review', $batch->batch_code.' is on quality hold and cannot be sold yet.', 'batch', $batch->id, ['administrator', 'manager', 'inventory_staff']);
            if ($plan && $plan->fresh()->plan_status === 'completed') {
                $this->alerts->resolve('plan:approved:'.$plan->id);
            }

            return $batch;
        });
    }

    public function releaseBatch(ProductionBatch $batch, int $actorId, string $notes, int $rejectedCases = 0): void
    {
        DB::transaction(function () use ($batch, $actorId, $notes, $rejectedCases): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require($batch->status === 'quality_hold', 'Only a batch on quality hold can be released.');
            $this->require($batch->expires_on->gt(today()), 'An expired batch cannot be released.');
            $this->require($rejectedCases >= 0 && $rejectedCases < $batch->cases_on_hand, 'At least one saleable case must remain after quality rejection.');
            $saleableCases = $batch->cases_on_hand - $rejectedCases;
            $batch->update(['status' => 'released', 'quality_notes' => $notes, 'quality_rejected_cases' => $rejectedCases, 'cases_on_hand' => $saleableCases, 'cost_per_case' => round((float) $batch->total_cost / $saleableCases, 4), 'released_by' => $actorId, 'released_at' => now()]);
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
            $this->require($batch->cases_reserved === 0, 'Cancel orders reserving this batch before quarantining it.');
            $this->require(in_array($batch->status, ['quality_hold', 'released'], true), 'Only an unrecalled batch can be quarantined.');
            $previous = $batch->status;
            $batch->update(['status' => 'quarantined', 'quality_notes' => $reason]);
            $this->auditTrail->record('batch.quarantined', $batch, $actorId, ['status' => $previous], ['status' => 'quarantined'], $reason);
            $this->alerts->resolve('batch:quality:'.$batch->id);
            $this->alerts->publish('batch:quarantined:'.$batch->id, 'quality', 'high', 'Batch quarantined', $batch->batch_code.' was quarantined. Review the batch before any further action.', 'batch', $batch->id, ['administrator', 'manager', 'inventory_staff']);
        });
    }

    public function recallBatch(ProductionBatch $batch, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($batch, $actorId, $reason): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require($batch->status !== 'recalled', 'This batch has already been recalled.');
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
                $this->auditTrail->record('order.reservation_released_for_recall', $order, $actorId, [], ['recalled_batch' => $batch->batch_code], $reason);
                $this->alerts->resolve('order:dispatch:'.$order->id);
                $this->alerts->publish('order:reallocation:'.$order->id, 'order', 'high', 'Order needs new stock', $order->order_number.' returned to draft because a reserved batch was recalled.', 'order', $order->id, ['administrator', 'manager', 'sales_staff', 'distribution_staff']);
            }
            $oldStatus = $batch->status;
            $batch->update(['status' => 'recalled', 'quality_notes' => $reason]);
            $this->auditTrail->record('batch.recalled', $batch, $actorId, ['status' => $oldStatus], ['status' => 'recalled', 'affected_open_orders' => $orderIds->count()], $reason);
            $this->alerts->resolve('batch:quality:'.$batch->id);
            $this->alerts->publish('batch:recalled:'.$batch->id, 'quality', 'high', 'Urgent batch recall', $batch->batch_code.' was recalled. Check affected stock and customers.', 'batch', $batch->id, ['administrator', 'manager', 'inventory_staff'], ['administrator', 'manager']);
        });
    }

    public function disposeBatch(ProductionBatch $batch, int $cases, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($batch, $cases, $reason, $actorId): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->require($cases > 0 && $cases <= $batch->cases_on_hand - $batch->cases_reserved, 'Invalid disposal quantity or reserved stock.');
            $this->require(in_array($batch->status, ['quarantined', 'recalled'], true) || $batch->expires_on->lte(today()), 'Only quarantined, recalled, or expired stock can be disposed.');
            $disposal = BatchDisposal::create(['production_batch_id' => $batch->id, 'cases' => $cases, 'reason' => $reason, 'disposed_on' => today(), 'recorded_by' => $actorId]);
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
            $order = SalesOrder::create([
                'order_number' => 'ORD-'.Str::upper(Str::random(12)),
                'customer_id' => $data['customer_id'],
                'ordered_on' => today(),
                'status' => 'draft',
                'delivery_address' => $data['delivery_address'],
                'discount' => $discount,
                'delivery_cost' => $data['delivery_cost'] ?? 0,
                'total_amount' => round($subtotal - $discount, 2),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
            foreach ($data['items'] as $item) {
                $order->items()->create(['product_id' => $item['product_id'], 'cases_ordered' => $item['cases_ordered'], 'price_per_case' => $item['price_per_case'], 'line_total' => round($item['cases_ordered'] * $item['price_per_case'], 2)]);
            }
            $this->auditTrail->record('order.created', $order, $actorId, [], ['amount' => $order->total_amount]);

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
                $batches = ProductionBatch::query()->where('product_id', $item->product_id)->where('status', 'released')->whereDate('expires_on', '>', today())->where('cases_on_hand', '>', 0)->orderBy('expires_on')->orderBy('id')->lockForUpdate()->get();
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
            $previous = $order->status;
            $order->update(['status' => 'cancelled']);
            $this->auditTrail->record('order.cancelled', $order, $actorId, ['status' => $previous], ['status' => 'cancelled'], $reason);
        });
    }

    public function dispatchOrder(SalesOrder $order, int $actorId, string $reference): Dispatch
    {
        return DB::transaction(function () use ($order, $actorId, $reference): Dispatch {
            $order = SalesOrder::query()->with('items.reservations')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'confirmed', 'Only confirmed orders can be dispatched.');
            $dispatch = Dispatch::create(['sales_order_id' => $order->id, 'dispatched_on' => today(), 'status' => 'dispatched', 'delivery_reference' => $reference, 'dispatched_by' => $actorId]);
            foreach ($order->items as $item) {
                $this->require($item->reservations->sum('cases_reserved') === $item->cases_ordered, 'Order reservation is incomplete.');
                foreach ($item->reservations as $reservation) {
                    $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($reservation->production_batch_id);
                    $this->require($batch->status === 'released' && $batch->expires_on->gt(today()) && $batch->cases_on_hand >= $reservation->cases_reserved, 'A reserved batch is no longer eligible for dispatch.');
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

    public function deliverOrder(SalesOrder $order, int $actorId): void
    {
        DB::transaction(function () use ($order, $actorId): void {
            $order = SalesOrder::query()->with('dispatch')->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'dispatched' && $order->dispatch !== null, 'The order has not been dispatched.');
            $order->dispatch->update(['status' => 'delivered', 'delivered_on' => today()]);
            $order->update(['status' => 'delivered']);
            $this->auditTrail->record('order.delivered', $order, $actorId, ['status' => 'dispatched'], ['status' => 'delivered']);
            $this->alerts->resolve('order:delivery:'.$order->id);
            $this->alerts->publish('order:invoice:'.$order->id, 'order', 'warning', 'Delivered order needs invoice', $order->order_number.' has been delivered and can now be invoiced.', 'order', $order->id, ['administrator', 'manager', 'sales_staff']);
        });
    }

    public function invoiceOrder(SalesOrder $order, int $actorId): Invoice
    {
        return DB::transaction(function () use ($order, $actorId): Invoice {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->require($order->status === 'delivered', 'Only delivered orders can be invoiced.');
            $invoice = Invoice::create(['sales_order_id' => $order->id, 'invoice_number' => 'INV-'.Str::upper(Str::random(12)), 'issued_on' => today(), 'amount' => $order->total_amount, 'status' => 'unpaid']);
            $order->update(['status' => 'invoiced']);
            $this->auditTrail->record('invoice.issued', $invoice, $actorId, [], ['amount' => $invoice->amount]);
            $this->alerts->resolve('order:invoice:'.$order->id);

            return $invoice;
        });
    }

    public function recordPayment(Invoice $invoice, array $data, int $actorId): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $actorId): Payment {
            $invoice = Invoice::query()->with(['payments', 'returns'])->lockForUpdate()->findOrFail($invoice->id);
            $remaining = round((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - (float) $invoice->payments->sum('amount'), 2);
            $this->require($data['amount'] > 0 && $data['amount'] <= $remaining, 'Payment exceeds the outstanding invoice balance.');
            $payment = Payment::create(['invoice_id' => $invoice->id, 'amount' => $data['amount'], 'paid_on' => today(), 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'recorded_by' => $actorId]);
            $paid = round($remaining - $data['amount'], 2) <= 0;
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
            $invoice = Invoice::query()->with(['order.items', 'payments', 'returns'])->lockForUpdate()->findOrFail($invoice->id);
            $line = DispatchLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->require(Dispatch::query()->whereKey($line->dispatch_id)->where('sales_order_id', $invoice->sales_order_id)->exists(), 'This batch dispatch does not belong to the invoice.');
            $alreadyReturned = (int) SalesReturn::query()->where('dispatch_line_id', $line->id)->sum('cases');
            $this->require($cases > 0 && $cases + $alreadyReturned <= $line->cases, 'Return quantity exceeds dispatched cases.');
            $item = SalesOrderItem::findOrFail($line->sales_order_item_id);
            $subtotal = (float) $invoice->order->items->sum('line_total');
            $discountRate = $subtotal > 0 ? (float) $invoice->order->discount / $subtotal : 0;
            $creditAmount = round($cases * (float) $item->price_per_case * (1 - $discountRate), 2);
            $this->require((float) $invoice->returns->sum('credit_amount') + $creditAmount <= (float) $invoice->amount + 0.01, 'Credits exceed the invoice amount.');
            $return = SalesReturn::create(['invoice_id' => $invoice->id, 'dispatch_line_id' => $line->id, 'cases' => $cases, 'credit_amount' => $creditAmount, 'returned_on' => today(), 'disposition' => 'destroyed_on_receipt', 'reason' => $reason, 'recorded_by' => $actorId]);
            $netDue = round((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - $creditAmount - (float) $invoice->payments->sum('amount'), 2);
            $invoice->update(['status' => $netDue < 0 ? 'credit_due' : ($netDue == 0.0 ? 'settled' : ((float) $invoice->payments->sum('amount') > 0 ? 'part_paid' : 'credited'))]);
            $this->auditTrail->record('invoice.return_credited', $return, $actorId, [], ['cases' => $cases, 'credit_amount' => $creditAmount, 'disposition' => 'destroyed_on_receipt'], $reason);

            return $return;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
