<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductManufacturingPolicy;
use App\Services\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductPricingController extends Controller
{
    public function index(): View
    {
        $products = Product::query()->where('is_active', true)->orderBy('volume_ml')->get();
        $productIds = $products->pluck('id');
        $approvedCosts = ProductManufacturingPolicy::query()
            ->whereIn('product_id', $productIds)
            ->whereDate('effective_from', '<=', CarbonImmutable::today(config('app.business_timezone')))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('product_id')
            ->keyBy('product_id');

        return view('packaged.pricing', [
            'products' => $products,
            'approvedCosts' => $approvedCosts,
            'priceHistory' => AuditLog::query()->with('actor')
                ->where('event', 'product.price_changed')
                ->where('subject_type', 'Product')
                ->whereIn('subject_id', $productIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function update(Request $request, Product $product, AuditTrail $auditTrail): RedirectResponse
    {
        abort_unless($product->is_active, 404);
        $data = $request->validate([
            'default_price_per_case' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $changed = DB::transaction(function () use ($product, $data, $request, $auditTrail): bool {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = $lockedProduct->default_price_per_case;
            $newPrice = number_format((float) $data['default_price_per_case'], 2, '.', '');
            if ($before === $newPrice) {
                return false;
            }

            $lockedProduct->update(['default_price_per_case' => $newPrice]);
            $auditTrail->record('product.price_changed', $lockedProduct, $request->user()->user_id,
                ['price' => $before], ['price' => $newPrice], $data['reason'] ?? null);

            return true;
        });

        return redirect()->route('packaged.pricing.index')->with('status', $changed
            ? 'Selling price per case updated for new orders. Existing orders keep their recorded prices.'
            : 'The selling price is unchanged.');
    }
}
