<?php

namespace App\Http\Controllers;

use App\Models\DeliveryZone;
use App\Models\DeliveryZoneFeeChange;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeliveryZoneController extends Controller
{
    public function index(): View
    {
        return view('packaged.delivery-zones', [
            'zones' => DeliveryZone::query()->with('areas')->where('county', 'Nairobi')->orderBy('code')->get(),
            'changes' => DeliveryZoneFeeChange::query()->with(['zone', 'changer'])->latest('id')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, DeliveryZone $zone, AuditTrail $auditTrail): RedirectResponse
    {
        abort_unless($zone->county === 'Nairobi', 404);
        $data = $request->validate([
            'fixed_fee' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($zone, $data, $request, $auditTrail): void {
            $zone = DeliveryZone::query()->lockForUpdate()->findOrFail($zone->id);
            $oldFee = (float) $zone->fixed_fee;
            $newFee = (float) $data['fixed_fee'];
            $wasActive = $zone->is_active;
            if (round($oldFee, 2) !== round($newFee, 2)) {
                DeliveryZoneFeeChange::create([
                    'delivery_zone_id' => $zone->id,
                    'previous_fee' => $oldFee,
                    'new_fee' => $newFee,
                    'reason' => $data['reason'],
                    'changed_by' => $request->user()->user_id,
                    'created_at' => now(),
                ]);
            }
            $zone->update(['fixed_fee' => $newFee, 'is_active' => (bool) $data['is_active']]);
            $auditTrail->record('delivery_zone.updated', $zone, $request->user()->user_id, ['fixed_fee' => $oldFee, 'is_active' => $wasActive], ['fixed_fee' => $newFee, 'is_active' => $zone->is_active], $data['reason']);
        });

        return back()->with('status', 'Delivery zone updated. Existing orders keep their original delivery fee.');
    }
}
