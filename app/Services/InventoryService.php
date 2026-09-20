<?php
namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function add(float $quantity, string $date, int $userId, string $referenceType, int $referenceId, ?string $notes = null): void
    {
        $this->move($quantity, 'IN', $date, $userId, $referenceType, $referenceId, $notes);
    }
    public function remove(float $quantity, string $date, int $userId, string $referenceType, int $referenceId, ?string $notes = null): void
    {
        $this->move($quantity, 'OUT', $date, $userId, $referenceType, $referenceId, $notes);
    }
    public function adjust(float $signedQuantity, string $date, int $userId, ?string $notes = null): void
    {
        $this->move(abs($signedQuantity), 'ADJUSTMENT', $date, $userId, 'adjustment', 0, $notes, $signedQuantity >= 0 ? 1 : -1);
    }
    private function move(float $quantity, string $type, string $date, int $userId, string $referenceType, int $referenceId, ?string $notes, ?int $direction = null): void
    {
        if ($quantity <= 0) throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        DB::transaction(function () use ($quantity, $type, $date, $userId, $referenceType, $referenceId, $notes, $direction) {
            $inventory = Inventory::query()->lockForUpdate()->firstOrCreate([], ['quantity_available' => 0, 'last_updated' => now()]);
            $factor = $direction ?? ($type === 'IN' ? 1 : -1);
            if ($factor < 0 && (float)$inventory->quantity_available < $quantity) throw ValidationException::withMessages(['quantity' => 'Insufficient available water inventory.']);
            $inventory->quantity_available = (float)$inventory->quantity_available + ($factor * $quantity);
            $inventory->last_updated = now(); $inventory->save();
            InventoryTransaction::create(['transaction_type'=>$type, 'quantity'=>$quantity, 'reference_type'=>$referenceType, 'reference_id'=>$referenceId ?: null, 'transaction_date'=>$date, 'user_id'=>$userId, 'notes'=>$notes]);
        });
    }
}
