<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\AppAlertService;
use App\Services\AuditTrail;
use App\Services\PackagedWaterService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesOrderController extends Controller
{
    public function index(): View
    {
        return view('packaged.orders', ['orders' => SalesOrder::query()->with('customer')->orderByDesc('id')->paginate(20)]);
    }

    public function create(Request $request): View
    {
        return view('packaged.order-create', [
            'customers' => Customer::query()->orderBy('name')->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('volume_ml')->get(),
            'areas' => DeliveryArea::query()->with('zone')->where('county', 'Nairobi')->whereHas('zone', fn ($query) => $query->where('county', 'Nairobi')->where('is_active', true))->orderBy('name')->get(),
            'selectedCustomerId' => $request->query('customer_id'),
        ]);
    }

    public function store(Request $request, PackagedWaterService $service): RedirectResponse
    {
        $request->merge(['items' => array_values(array_filter($request->input('items', []), fn ($item) => (int) ($item['cases_ordered'] ?? 0) > 0))]);
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,customer_id'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'requested_delivery_on' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()],
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'delivery_area_id' => ['required', 'integer', 'exists:delivery_areas,id'],
            'price_override_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'items.*.cases_ordered' => ['required', 'integer', 'min:1'],
            'items.*.price_per_case' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);
        $products = Product::query()->whereIn('id', collect($data['items'])->pluck('product_id'))->where('is_active', true)->get()->keyBy('id');
        $hasPriceOverride = collect($data['items'])->contains(function (array $item) use ($products): bool {
            $product = $products->get($item['product_id']);

            return ! $product || (int) round((float) $item['price_per_case'] * 100) !== (int) round((float) $product->default_price_per_case * 100);
        });
        if (($hasPriceOverride || (float) ($data['discount'] ?? 0) > 0) && ! $request->user()->hasRole('administrator', 'manager')) {
            throw ValidationException::withMessages(['price_override_reason' => 'Only a manager can approve a changed selling price or discount.']);
        }
        if (($hasPriceOverride || (float) ($data['discount'] ?? 0) > 0) && blank($data['price_override_reason'] ?? null)) {
            throw ValidationException::withMessages(['price_override_reason' => 'Give a reason for the changed price or discount.']);
        }
        $area = DeliveryArea::query()->with('zone')->findOrFail($data['delivery_area_id']);
        if ($area->county !== 'Nairobi' || $area->zone->county !== 'Nairobi' || ! $area->zone->is_active) {
            throw ValidationException::withMessages(['delivery_area_id' => 'Choose an active Nairobi delivery area.']);
        }
        $order = $service->createOrder($data, $request->user()->user_id);

        return redirect()->route('packaged.orders.show', $order)->with('status', 'Order saved as draft.');
    }

    public function show(SalesOrder $order): View
    {
        $order->load(['customer', 'deliveryArea', 'deliveryZone', 'deliveryTrips', 'items.product', 'items.reservations.batch', 'dispatch.lines.batch', 'dispatch.lines.failedDeliveryLine', 'invoice.payments', 'invoice.activePayments', 'invoice.refunds', 'invoice.returns', 'invoice.deliveryFeeCredit']);

        return view('packaged.order-show', compact('order'));
    }

    public function confirm(Request $request, SalesOrder $order, PackagedWaterService $service, AppAlertService $alerts): RedirectResponse
    {
        try {
            $service->confirmOrder($order, $request->user()->user_id);
        } catch (ValidationException $exception) {
            $errors = implode(' ', array_merge([], ...array_values($exception->errors())));
            if (str_contains($errors, 'Insufficient released, unexpired stock')) {
                $alerts->publish('order:stock:'.$order->id, 'order_shortage', 'high', 'Order cannot be reserved', $order->order_number.' needs more released, unexpired stock before confirmation.', 'order', $order->id, ['administrator', 'manager', 'sales_staff']);
            }
            throw $exception;
        }
        $alerts->resolve('order:stock:'.$order->id);

        return back()->with('status', 'Eligible batches reserved in expiry order.');
    }

    public function cancel(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->cancelOrder($order, $request->user()->user_id, $data['reason']);

        return back()->with('status', 'Order cancelled and reservations released.');
    }

    public function dispatch(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['delivery_reference' => ['required', 'string', 'max:120'], 'cases' => ['prohibited']]);
        throw ValidationException::withMessages(['workflow' => 'Dispatch complete customer orders from their planned delivery trip.']);
    }

    public function deliver(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['received_by' => ['nullable', 'string', 'max:255'], 'receipt_reference' => ['nullable', 'string', 'max:255']]);
        $service->deliverOrder($order, $request->user()->user_id, $data);

        return back()->with('status', 'Delivery confirmed.');
    }

    public function deliveryFailed(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->failDelivery($order, $data['reason'], $request->user()->user_id);

        return back()->with('status', 'Delivery failure recorded. The goods remain with the truck until warehouse return is confirmed.');
    }

    public function warehouseReturn(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['warehouse_reference' => ['required', 'string', 'max:120']]);
        $service->receiveFailedDelivery($order, $data['warehouse_reference'], $request->user()->user_id);

        return back()->with('status', 'Returned cases are on quality hold. The truck is available once all trip stops are resolved.');
    }

    public function inspectFailedDelivery(Request $request, DispatchLine $line, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['result' => ['required', 'in:pass,fail'], 'notes' => ['required', 'string', 'max:2000']]);
        $service->inspectFailedDeliveryLine($line->id, $data['result'] === 'pass', $data['notes'], $request->user()->user_id);

        return back()->with('status', 'Returned cases inspected and their disposition recorded.');
    }

    public function invoice(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $service->invoiceOrder($order, $request->user()->user_id);

        return back()->with('status', 'Invoice issued.');
    }

    public function payment(Request $request, Invoice $invoice, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'method' => ['required', 'string', 'max:80'], 'reference' => ['nullable', 'string', 'max:120']]);
        $service->recordPayment($invoice, $data, $request->user()->user_id);

        return back()->with('status', 'Payment recorded.');
    }

    public function recordReturn(Request $request, Invoice $invoice, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['dispatch_line_id' => ['required', 'exists:dispatch_lines,id'], 'cases' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $service->recordReturn($invoice, DispatchLine::findOrFail($data['dispatch_line_id']), $data['cases'], $data['reason'], $request->user()->user_id);

        return back()->with('status', 'Customer return credited and marked destroyed on receipt; no saleable stock was restored.');
    }

    public function refund(Request $request, Invoice $invoice, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'method' => ['required', 'string', 'max:80'], 'reference' => ['required', 'string', 'max:120'], 'reason' => ['required', 'string', 'max:500']]);
        $service->recordRefund($invoice, $data, $request->user()->user_id);

        return back()->with('status', 'Customer refund recorded and linked to this invoice.');
    }

    public function creditDeliveryFee(Request $request, Invoice $invoice, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $service->approveDeliveryFeeCredit($invoice, $data['reason'], $request->user()->user_id);

        return back()->with('status', 'Delivery fee credited once. Any overpayment is shown for refund.');
    }

    public function reversePayment(Request $request, Payment $payment, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $service->reversePayment($payment, $data['reason'], $request->user()->user_id);

        return back()->with('status', 'Payment reversed. The original record remains in the audit trail.');
    }

    public function closeDay(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['order_day' => ['required', 'date', 'before_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()]]);
        $hasOrders = SalesOrder::query()->whereIn('status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])->whereDate('ordered_on', $data['order_day'])->exists();
        if ($hasOrders) {
            throw ValidationException::withMessages(['order_day' => 'Accepted orders are already recorded for this date.']);
        }
        DB::table('daily_order_closures')->updateOrInsert(['order_day' => $data['order_day']], ['recorded_by' => $request->user()->user_id, 'created_at' => now(), 'updated_at' => now()]);
        $auditTrail->record('sales.zero_order_day_confirmed', null, $request->user()->user_id, [], ['order_day' => $data['order_day']]);

        return back()->with('status', 'Zero-order day recorded for forecasting.');
    }
}
