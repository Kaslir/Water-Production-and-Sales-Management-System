<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class ReplacePackagedDataset extends Command
{
    protected $signature = 'dataset:replace-packaged {--path= : Directory containing the packaged-water CSV files} {--apply : Replace existing operational data} {--force : Skip the final confirmation after validation}';

    protected $description = 'Validate and replace synthetic operational records while preserving login users';

    public function handle(): int
    {
        try {
            $directory = (string) ($this->option('path') ?: database_path('packaged-data'));
            $metadata = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'dataset_metadata.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($metadata['dataset_type'] ?? null) !== 'Entirely synthetic'
                || ($metadata['period_start'] ?? null) !== '2024-01-01'
                || ($metadata['period_end'] ?? null) !== '2026-10-26'
                || ($metadata['months'] ?? null) !== 34
                || ($metadata['days'] ?? null) !== 1030) {
                throw new RuntimeException('Dataset metadata does not match the intended period through 26 October 2026.');
            }

            $python = config('services.analytics.python');
            $validation = new Process([$python, $directory.DIRECTORY_SEPARATOR.'validate_dataset.py']);
            $validation->setTimeout(120);
            $validation->run();
            if (! $validation->isSuccessful()) {
                throw new RuntimeException('Dataset validation failed: '.$validation->getErrorOutput());
            }

            $names = ['products', 'customers', 'production_batches', 'sales_orders', 'sales_order_items', 'dispatches', 'dispatch_lines', 'invoices', 'payments', 'stock_movements', 'batch_disposals', 'daily_product_demand'];
            $data = [];
            foreach ($names as $name) {
                $data[$name] = $this->readCsv($directory.DIRECTORY_SEPARATOR.$name.'.csv');
                if (count($data[$name]) !== ($metadata['record_counts'][$name] ?? -1)) {
                    throw new RuntimeException("Unexpected row count in {$name}.csv.");
                }
            }

            $this->table(['Dataset', 'Validated records'], [
                ['Period', $metadata['period_start'].' to '.$metadata['period_end']],
                ['Customers', count($data['customers'])],
                ['Orders', count($data['sales_orders'])],
                ['Order items', count($data['sales_order_items'])],
                ['Production batches', count($data['production_batches'])],
                ['Daily product observations', count($data['daily_product_demand'])],
            ]);
            if (! $this->option('apply')) {
                $this->info('Validation passed. No database records were changed. Back up MySQL before using --apply.');

                return self::SUCCESS;
            }
            if (! $this->option('force') && ! $this->confirm('Replace operational data in '.DB::getDatabaseName().'? Login users will be preserved.', false)) {
                $this->warn('Cancelled. No records were changed.');

                return self::SUCCESS;
            }

            $userCount = DB::table('users')->count();
            DB::transaction(fn () => $this->replace($data, $userCount), 1);
            $this->info('Replacement committed. All login users were preserved.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers) || ! $headers) {
                throw new RuntimeException("Missing CSV header in {$path}.");
            }
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($headers) !== count($values)) {
                    throw new RuntimeException("Malformed CSV row in {$path}.");
                }
                $rows[] = array_combine($headers, $values);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function syntheticActor(string $role): int
    {
        $id = DB::table('users')
            ->where('role', $role)
            ->where('is_active', false)
            ->where('email', 'like', 'synthetic.%@aquatrack.invalid')
            ->value('user_id');
        if ($id === null) {
            throw new RuntimeException("An existing inactive synthetic {$role} user is required; login users will not be changed.");
        }

        return (int) $id;
    }

    private function replace(array $data, int $userCount): void
    {
        $actors = [
            'production' => $this->syntheticActor('production_staff'),
            'sales' => $this->syntheticActor('sales_staff'),
            'distribution' => $this->syntheticActor('distribution_staff'),
        ];
        foreach (['production_forecast_decisions', 'product_forecasts', 'sales_returns', 'payments', 'invoices', 'dispatch_lines', 'dispatches', 'batch_reservations', 'sales_order_items', 'sales_orders', 'batch_disposals', 'stock_movements', 'production_batches', 'operating_expenses', 'reports', 'forecasts', 'inventory_transactions', 'sales', 'distributions', 'productions', 'inventories', 'customers'] as $table) {
            DB::table($table)->delete();
        }
        $now = now();
        $stamp = fn (string $date): string => $date.' 12:00:00';

        foreach ($data['products'] as $product) {
            $updated = DB::table('products')->where('id', (int) $product['product_id'])->where('sku', $product['sku'])->update([
                'default_price_per_case' => $product['price_per_case_ksh'], 'is_active' => true, 'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('Product IDs and SKUs do not match the packaged-water schema.');
            }
        }
        $this->insert('customers', array_map(fn ($row) => [
            'customer_id' => (int) $row['customer_id'], 'name' => $row['name'], 'phone_number' => $row['phone_number'],
            'email' => $row['email'], 'address' => $row['address'], 'contact_person' => null,
            'created_at' => $now, 'updated_at' => $now,
        ], $data['customers']));

        $this->insert('production_batches', array_map(function ($row) use ($actors, $now, $stamp) {
            $total = (float) $row['total_cost_ksh'];
            $treatment = round($total * 0.08, 2);
            $bottle = round($total * 0.48, 2);
            $inbound = round($total * 0.05, 2);
            $capLabel = round($total * 0.12, 2);
            $labour = round($total * 0.14, 2);
            $electricity = round($total * 0.08, 2);

            return [
                'id' => (int) $row['batch_id'], 'product_id' => (int) $row['product_id'], 'batch_code' => $row['batch_code'],
                'produced_on' => $row['produced_on'], 'expires_on' => $row['expires_on'],
                'cases_produced' => (int) $row['cases_produced'], 'cases_on_hand' => (int) $row['cases_on_hand'],
                'quality_rejected_cases' => (int) $row['quality_rejected_cases'], 'cases_reserved' => 0,
                'status' => $row['status'], 'water_treatment_cost' => $treatment, 'bottle_cost' => $bottle,
                'inbound_transport_cost' => $inbound, 'cap_label_cost' => $capLabel, 'labour_cost' => $labour,
                'electricity_cost' => $electricity,
                'other_production_cost' => round($total - $treatment - $bottle - $inbound - $capLabel - $labour - $electricity, 2),
                'total_cost' => $row['total_cost_ksh'], 'cost_per_case' => $row['cost_per_saleable_case_ksh'],
                'quality_notes' => $row['quality_notes'], 'produced_by' => $actors['production'],
                'released_by' => $actors['production'], 'released_at' => $stamp($row['produced_on']),
                'created_at' => $stamp($row['produced_on']), 'updated_at' => $now,
            ];
        }, $data['production_batches']));

        $this->insert('sales_orders', array_map(fn ($row) => [
            'id' => (int) $row['order_id'], 'order_number' => $row['order_number'], 'customer_id' => (int) $row['customer_id'],
            'ordered_on' => $row['ordered_on'], 'status' => $row['status'], 'delivery_address' => $row['delivery_address'],
            'discount' => $row['discount_ksh'], 'delivery_cost' => $row['delivery_cost_ksh'],
            'total_amount' => $row['total_amount_ksh'], 'notes' => 'Entirely synthetic dataset',
            'created_by' => $actors['sales'], 'created_at' => $stamp($row['ordered_on']), 'updated_at' => $now,
        ], $data['sales_orders']));
        $this->insert('sales_order_items', array_map(fn ($row) => [
            'id' => (int) $row['order_item_id'], 'sales_order_id' => (int) $row['order_id'],
            'product_id' => (int) $row['product_id'], 'cases_ordered' => (int) $row['cases_ordered'],
            'price_per_case' => $row['price_per_case_ksh'], 'line_total' => $row['line_total_ksh'],
            'created_at' => $now, 'updated_at' => $now,
        ], $data['sales_order_items']));
        $this->insert('dispatches', array_map(fn ($row) => [
            'id' => (int) $row['dispatch_id'], 'sales_order_id' => (int) $row['order_id'],
            'dispatched_on' => $row['dispatched_on'], 'delivered_on' => $row['delivered_on'],
            'status' => $row['status'], 'delivery_reference' => $row['delivery_reference'],
            'dispatched_by' => $actors['distribution'], 'created_at' => $stamp($row['dispatched_on']), 'updated_at' => $now,
        ], $data['dispatches']));
        $this->insert('dispatch_lines', array_map(fn ($row) => [
            'id' => (int) $row['dispatch_line_id'], 'dispatch_id' => (int) $row['dispatch_id'],
            'sales_order_item_id' => (int) $row['order_item_id'], 'production_batch_id' => (int) $row['batch_id'],
            'cases' => (int) $row['cases'], 'cost_per_case' => $row['cost_per_case_ksh'],
            'created_at' => $now, 'updated_at' => $now,
        ], $data['dispatch_lines']));
        $this->insert('invoices', array_map(fn ($row) => [
            'id' => (int) $row['invoice_id'], 'sales_order_id' => (int) $row['order_id'],
            'invoice_number' => $row['invoice_number'], 'issued_on' => $row['issued_on'],
            'amount' => $row['amount_ksh'], 'status' => $row['status'],
            'created_at' => $stamp($row['issued_on']), 'updated_at' => $now,
        ], $data['invoices']));
        $this->insert('payments', array_map(fn ($row) => [
            'id' => (int) $row['payment_id'], 'invoice_id' => (int) $row['invoice_id'],
            'amount' => $row['amount_ksh'], 'paid_on' => $row['paid_on'], 'method' => $row['method'],
            'reference' => $row['reference'], 'recorded_by' => $actors['sales'],
            'created_at' => $stamp($row['paid_on']), 'updated_at' => $now,
        ], $data['payments']));
        $this->insert('batch_disposals', array_map(fn ($row) => [
            'id' => (int) $row['disposal_id'], 'production_batch_id' => (int) $row['batch_id'],
            'cases' => (int) $row['cases'], 'reason' => $row['reason'], 'disposed_on' => $row['disposed_on'],
            'recorded_by' => $actors['production'], 'created_at' => $stamp($row['disposed_on']), 'updated_at' => $now,
        ], $data['batch_disposals']));
        $this->insert('stock_movements', array_map(fn ($row) => [
            'id' => (int) $row['movement_id'], 'production_batch_id' => (int) $row['batch_id'],
            'signed_cases' => (int) $row['signed_cases'], 'reason' => $row['reason'],
            'reference_type' => $row['reference_type'], 'reference_id' => (int) $row['reference_id'],
            'performed_by' => $row['reason'] === 'dispatch' ? $actors['distribution'] : $actors['production'],
            'notes' => 'Entirely synthetic dataset', 'created_at' => $stamp($row['movement_date']), 'updated_at' => $now,
        ], $data['stock_movements']));

        foreach (['customers', 'production_batches', 'sales_orders', 'sales_order_items', 'dispatches', 'dispatch_lines', 'invoices', 'payments', 'batch_disposals', 'stock_movements'] as $table) {
            if (DB::table($table)->count() !== count($data[$table])) {
                throw new RuntimeException("Imported {$table} count does not match the validated dataset.");
            }
        }
        if (DB::table('users')->count() !== $userCount || DB::table('sales')->count() !== 0 || DB::table('inventory_transactions')->count() !== 0) {
            throw new RuntimeException('Post-import user preservation or legacy-data check failed.');
        }
    }
}
