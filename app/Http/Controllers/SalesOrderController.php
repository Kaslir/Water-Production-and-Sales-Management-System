<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\PackagedWaterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesOrderController extends Controller
{
    public function index(): View
    {
        return view('packaged.orders', ['orders' => SalesOrder::query()->with('customer')->orderByDesc('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('packaged.order-create', [
            'customers' => Customer::query()->orderBy('name')->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('volume_ml')->get(),
        ]);
    }

    public function store(Request $request, PackagedWaterService $service): RedirectResponse
    {
        $request->merge(['items' => array_values(array_filter($request->input('items', []), fn ($item) => (int) ($item['cases_ordered'] ?? 0) > 0))]);
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,customer_id'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'items.*.cases_ordered' => ['required', 'integer', 'min:1'],
            'items.*.price_per_case' => ['required', 'numeric', 'gt:0'],
        ]);
        $order = $service->createOrder($data, $request->user()->user_id);

        return redirect()->route('packaged.orders.show', $order)->with('status', 'Order saved as draft.');
    }

    public function show(SalesOrder $order): View
    {
        $order->load(['customer', 'items.product', 'items.reservations.batch', 'dispatch.lines.batch', 'invoice.payments', 'invoice.returns']);

        return view('packaged.order-show', compact('order'));
    }

    public function confirm(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $service->confirmOrder($order, $request->user()->user_id);

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
        $data = $request->validate(['delivery_reference' => ['required', 'string', 'max:120']]);
        $service->dispatchOrder($order, $request->user()->user_id, $data['delivery_reference']);

        return back()->with('status', 'Order dispatched; stock deducted once.');
    }

    public function deliver(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $service->deliverOrder($order, $request->user()->user_id);

        return back()->with('status', 'Delivery confirmed.');
    }

    public function invoice(Request $request, SalesOrder $order, PackagedWaterService $service): RedirectResponse
    {
        $service->invoiceOrder($order, $request->user()->user_id);

        return back()->with('status', 'Invoice issued.');
    }

    public function payment(Request $request, Invoice $invoice, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:80'], 'reference' => ['nullable', 'string', 'max:120']]);
        $service->recordPayment($invoice, $data, $request->user()->user_id);

        return back()->with('status', 'Payment recorded.');
    }

    public function recordReturn(Request $request, Invoice $invoice, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['dispatch_line_id' => ['required', 'exists:dispatch_lines,id'], 'cases' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $service->recordReturn($invoice, DispatchLine::findOrFail($data['dispatch_line_id']), $data['cases'], $data['reason'], $request->user()->user_id);

        return back()->with('status', 'Customer return credited and marked destroyed on receipt; no saleable stock was restored.');
    }
}
