<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductPricingController extends Controller
{
    public function index(): View
    {
        return view('packaged.pricing', [
            'products' => Product::query()->where('is_active', true)->orderBy('volume_ml')->get(),
        ]);
    }

    public function update(Request $request, Product $product, AuditTrail $auditTrail): RedirectResponse
    {
        abort_unless($product->is_active, 404);
        $data = $request->validate(['default_price_per_case' => ['required', 'numeric', 'gt:0', 'max:9999999999.99']]);
        $before = $product->default_price_per_case;
        $product->update($data);
        $auditTrail->record('product.price_changed', $product, $request->user()->user_id, ['price' => $before], ['price' => $product->default_price_per_case]);

        return redirect()->route('packaged.pricing.index')->with('status', 'Selling price per case updated for new orders.');
    }
}
