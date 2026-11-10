<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImportNextPackagedDataset extends Command
{
    protected $signature = 'dataset:import-next {--path= : Versioned packaged-water CSV directory} {--apply : Apply to an isolated demo database}';

    protected $description = 'Validate and import the versioned synthetic dataset into an isolated demo database';

    public function handle(): int
    {
        try {
            $directory = (string) ($this->option('path') ?: database_path('packaged-data-next'));
            $metadata = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'dataset_metadata.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($metadata['dataset_type'] ?? null) !== 'Entirely synthetic'
                || ($metadata['period_start'] ?? null) !== '2024-01-01'
                || ($metadata['period_end'] ?? null) !== '2026-11-05') {
                throw new RuntimeException('The versioned synthetic dataset metadata is incorrect.');
            }

            $validation = new Process([config('services.analytics.python'), $directory.DIRECTORY_SEPARATOR.'validate_dataset.py']);
            $validation->setTimeout(120);
            $validation->run();
            if (! $validation->isSuccessful()) {
                throw new RuntimeException('Dataset validation failed: '.trim($validation->getErrorOutput().' '.$validation->getOutput()));
            }

            $names = array_keys($metadata['record_counts']);
            $data = [];
            foreach ($names as $name) {
                $data[$name] = $this->readCsv($directory.DIRECTORY_SEPARATOR.$name.'.csv');
                if (count($data[$name]) !== $metadata['record_counts'][$name]) {
                    throw new RuntimeException("Incorrect row count in {$name}.csv.");
                }
            }
            $this->line('Validated '.count($data['sales_orders']).' orders, '.count($data['production_batches']).' batches and '.count($data['delivery_trips']).' trips.');
            if (! $this->option('apply')) {
                $this->info('Dry run complete. No database records changed.');

                return self::SUCCESS;
            }
            if (DB::getDatabaseName() !== 'water_management_demo_20261105') {
                throw new RuntimeException('This importer only writes to the isolated water_management_demo_20261105 database.');
            }
            DB::transaction(fn () => $this->replace($data), 1);
            $this->info('Imported the synthetic dataset into the isolated demo database.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readCsv(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }
        try {
            $headers = fgetcsv($stream);
            if (! is_array($headers)) {
                throw new RuntimeException("Missing headers in {$path}.");
            }
            $rows = [];
            while (($values = fgetcsv($stream)) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException("Malformed CSV row in {$path}.");
                }
                $rows[] = array_combine($headers, $values);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function syntheticActor(string $role): int
    {
        $id = DB::table('users')->where('role', $role)->where('is_active', false)
            ->where('email', 'like', 'synthetic.%@aquatrack.invalid')->value('user_id');
        if ($id === null) {
            throw new RuntimeException("Missing inactive synthetic {$role} actor.");
        }

        return (int) $id;
    }

    private function syntheticManager(): int
    {
        $email = 'synthetic.manager@aquatrack.invalid';
        $existing = DB::table('users')->where('email', $email)->value('user_id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('users')->insertGetId([
            'name' => 'Synthetic Dataset Manager', 'email' => $email, 'role' => 'manager',
            'is_active' => false, 'password' => bcrypt(Str::random(64)),
            'created_at' => now(), 'updated_at' => now(),
        ], 'user_id');
    }

    private function replace(array $data): void
    {
        if (DB::table('sales_orders')->where('data_origin', '!=', 'synthetic')->exists()
            || DB::table('customers')->whereNull('email')->orWhere('email', 'not like', '%@example.invalid')->exists()
            || DB::table('product_manufacturing_policies')->exists()) {
            throw new RuntimeException('Non-synthetic operational data or existing manufacturing policies require manual review.');
        }

        $actors = [
            'production' => $this->syntheticActor('production_staff'),
            'sales' => $this->syntheticActor('sales_staff'),
            'distribution' => $this->syntheticActor('distribution_staff'),
            'manager' => $this->syntheticManager(),
        ];
        $userCount = DB::table('users')->count();
        $stamp = fn (string $day): string => $day.' 12:00:00';
        $now = now();

        DB::table('app_alerts')->whereIn('target_type', ['stock', 'batch', 'order', 'trip', 'dispatch'])->delete();
        DB::table('audit_logs')->whereIn('subject_type', [
            'ProductionBatch', 'SalesOrder', 'Dispatch', 'DeliveryTrip', 'Invoice', 'Payment', 'Customer',
        ])->delete();
        foreach (['daily_order_closures', 'batch_actual_costs', 'stock_counts', 'customer_refunds',
            'delivery_fee_credits', 'delivery_trip_orders', 'delivery_trips',
            'sales_returns', 'payments', 'invoices', 'dispatch_lines', 'dispatches',
            'batch_reservations', 'sales_order_items', 'sales_orders', 'batch_disposals', 'stock_movements',
            'production_batches', 'production_forecast_decisions', 'product_forecasts',
            'operating_expenses', 'reports', 'forecasts', 'inventory_transactions',
            'sales', 'distributions', 'productions', 'inventories', 'customers',
            'product_manufacturing_policies'] as $table) {
            DB::table($table)->delete();
        }

        $products = DB::table('products')->pluck('sku', 'id')->all();
        foreach ($data['products'] as $product) {
            if (($products[(int) $product['product_id']] ?? null) !== $product['sku']) {
                throw new RuntimeException('Product IDs and SKUs differ from the existing master data.');
            }
        }
        $zones = DB::table('delivery_zones')->pluck('id', 'code')->all();
        $areas = DB::table('delivery_areas')->pluck('id', 'name')->all();
        foreach ($data['customers'] as $customer) {
            if (! isset($zones[$customer['delivery_zone_code']], $areas[$customer['delivery_area']])) {
                throw new RuntimeException('A synthetic customer delivery area is missing from the Nairobi zones.');
            }
        }
        $this->insert('customers', array_map(fn ($row) => [
            'customer_id' => (int) $row['customer_id'], 'name' => $row['name'],
            'phone_number' => $row['phone_number'], 'email' => $row['email'],
            'address' => $row['address'], 'contact_person' => null,
            'delivery_county' => 'Nairobi', 'delivery_area_id' => $areas[$row['delivery_area']],
            'created_at' => $stamp('2024-01-01'), 'updated_at' => $now,
        ], $data['customers']));

        $this->insert('product_manufacturing_policies', array_map(fn ($row) => [
            'id' => (int) $row['policy_id'], 'product_id' => (int) $row['product_id'],
            'effective_from' => $row['effective_from'], 'shelf_life_days' => (int) $row['shelf_life_days'],
            'shelf_life_evidence' => $row['shelf_life_evidence'],
            'water_treatment_per_case' => $row['water_treatment_per_case'],
            'bottle_per_case' => $row['bottle_per_case'],
            'inbound_transport_per_case' => $row['inbound_transport_per_case'],
            'cap_label_per_case' => $row['cap_label_per_case'],
            'labour_per_case' => $row['labour_per_case'],
            'electricity_per_case' => $row['electricity_per_case'],
            'other_production_per_case' => $row['other_production_per_case'],
            'approved_by' => $actors['manager'],
            'created_at' => $stamp($row['effective_from']), 'updated_at' => $stamp($row['effective_from']),
        ], $data['product_manufacturing_policies']));

        $policies = collect($data['product_manufacturing_policies'])->keyBy('policy_id');
        $this->insert('production_batches', array_map(function ($row) use ($actors, $policies, $stamp): array {
            $policy = $policies[$row['policy_id']];
            $count = (int) $row['cases_produced'];
            $cost = fn (string $field): string => number_format((float) $policy[$field] * $count, 2, '.', '');
            $released = in_array($row['status'], ['released', 'recalled'], true);

            return [
                'id' => (int) $row['batch_id'], 'product_id' => (int) $row['product_id'],
                'manufacturing_policy_id' => (int) $row['policy_id'], 'batch_code' => $row['batch_code'],
                'produced_on' => $row['produced_on'], 'expires_on' => $row['expires_on'],
                'cases_produced' => $count, 'cases_on_hand' => (int) $row['cases_on_hand'],
                'quality_rejected_cases' => (int) $row['quality_rejected_cases'], 'cases_reserved' => 0,
                'status' => $row['status'], 'water_source_reference' => $row['water_source_reference'],
                'water_input_litres' => $row['water_input_litres'], 'treatment_log_reference' => $row['treatment_log_reference'],
                'quality_tested_on' => $row['status'] === 'quality_hold' ? null : $row['produced_on'],
                'quality_evidence_reference' => $row['quality_evidence_reference'] ?: null,
                'microbiological_result' => $row['status'] === 'quality_hold' ? null : ($row['status'] === 'quarantined' ? 'fail' : 'pass'),
                'water_treatment_cost' => $cost('water_treatment_per_case'),
                'bottle_cost' => $cost('bottle_per_case'), 'inbound_transport_cost' => $cost('inbound_transport_per_case'),
                'cap_label_cost' => $cost('cap_label_per_case'), 'labour_cost' => $cost('labour_per_case'),
                'electricity_cost' => $cost('electricity_per_case'), 'other_production_cost' => $cost('other_production_per_case'),
                'total_cost' => $row['total_cost_ksh'], 'cost_per_case' => $row['cost_per_saleable_case_ksh'],
                'quality_notes' => $row['quality_notes'], 'produced_by' => $actors['production'],
                'released_by' => $released ? $actors['production'] : null,
                'released_at' => $released ? $stamp($row['produced_on']) : null,
                'created_at' => $stamp($row['produced_on']), 'updated_at' => $stamp($row['produced_on']),
            ];
        }, $data['production_batches']));

        $this->insert('sales_orders', array_map(fn ($row) => [
            'id' => (int) $row['order_id'], 'order_number' => $row['order_number'],
            'customer_id' => (int) $row['customer_id'], 'ordered_on' => $row['ordered_on'],
            'requested_delivery_on' => $row['requested_delivery_on'], 'status' => $row['status'],
            'delivery_address' => $row['delivery_address'], 'delivery_county' => 'Nairobi',
            'delivery_area_id' => $areas[$row['delivery_area']], 'delivery_zone_id' => $zones[$row['delivery_zone_code']],
            'delivery_fee' => $row['delivery_fee_ksh'], 'delivery_cost' => '0.00',
            'discount' => $row['discount_ksh'], 'total_amount' => $row['total_amount_ksh'],
            'notes' => 'Entirely synthetic demonstration order', 'data_origin' => 'synthetic',
            'created_by' => $actors['sales'], 'created_at' => $stamp($row['ordered_on']),
            'updated_at' => $stamp($row['ordered_on']),
        ], $data['sales_orders']));
        $this->insert('sales_order_items', array_map(fn ($row) => [
            'id' => (int) $row['order_item_id'], 'sales_order_id' => (int) $row['order_id'],
            'product_id' => (int) $row['product_id'], 'cases_ordered' => (int) $row['cases_ordered'],
            'price_per_case' => $row['price_per_case_ksh'], 'line_total' => $row['line_total_ksh'],
            'created_at' => $now, 'updated_at' => $now,
        ], $data['sales_order_items']));
        $this->insert('delivery_trips', array_map(fn ($row) => [
            'id' => (int) $row['trip_id'], 'trip_reference' => $row['trip_reference'],
            'status' => $row['status'], 'active_dispatch_key' => $row['status'] === 'dispatched' ? 1 : null,
            'truck_identifier' => 'AQUATRACK-01', 'driver_name' => 'Synthetic delivery driver',
            'delivery_on' => $row['delivery_on'], 'fuel_expense' => $row['fuel_expense_ksh'],
            'other_expense' => $row['other_expense_ksh'], 'expense_reference' => $row['expense_reference'],
            'created_by' => $actors['distribution'], 'expense_recorded_by' => $actors['distribution'],
            'created_at' => $stamp($row['delivery_on']), 'updated_at' => $stamp($row['delivery_on']),
        ], $data['delivery_trips']));
        $this->insert('delivery_trip_orders', array_map(fn ($row) => [
            'delivery_trip_id' => (int) $row['trip_id'], 'sales_order_id' => (int) $row['order_id'],
            'created_at' => $now, 'updated_at' => $now,
        ], $data['delivery_trip_orders']));
        $this->insert('dispatches', array_map(fn ($row) => [
            'id' => (int) $row['dispatch_id'], 'sales_order_id' => (int) $row['order_id'],
            'dispatched_on' => $row['dispatched_on'], 'delivered_on' => $row['delivered_on'] ?: null,
            'status' => $row['status'], 'delivery_reference' => $row['delivery_reference'],
            'received_by' => $row['received_by'] ?: null, 'receipt_reference' => $row['receipt_reference'] ?: null,
            'dispatched_by' => $actors['distribution'], 'created_at' => $stamp($row['dispatched_on']),
            'updated_at' => $stamp($row['dispatched_on']),
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
            'created_at' => $stamp($row['issued_on']), 'updated_at' => $stamp($row['issued_on']),
        ], $data['invoices']));
        $this->insert('payments', array_map(fn ($row) => [
            'id' => (int) $row['payment_id'], 'invoice_id' => (int) $row['invoice_id'],
            'amount' => $row['amount_ksh'], 'paid_on' => $row['paid_on'],
            'method' => $row['method'], 'reference' => $row['reference'],
            'recorded_by' => $actors['sales'], 'created_at' => $stamp($row['paid_on']),
            'updated_at' => $stamp($row['paid_on']),
        ], $data['payments']));
        $this->insert('batch_disposals', array_map(fn ($row) => [
            'id' => (int) $row['disposal_id'], 'production_batch_id' => (int) $row['batch_id'],
            'cases' => (int) $row['cases'], 'reason' => $row['reason'],
            'disposed_on' => $row['disposed_on'], 'recorded_by' => $actors['production'],
            'created_at' => $stamp($row['disposed_on']), 'updated_at' => $stamp($row['disposed_on']),
        ], $data['batch_disposals']));
        $this->insert('stock_movements', array_map(fn ($row) => [
            'id' => (int) $row['movement_id'], 'production_batch_id' => (int) $row['batch_id'],
            'signed_cases' => (int) $row['signed_cases'], 'reason' => $row['reason'],
            'reference_type' => $row['reference_type'], 'reference_id' => (int) $row['reference_id'],
            'performed_by' => $row['reason'] === 'customer_dispatch' ? $actors['distribution'] : $actors['production'],
            'notes' => 'Entirely synthetic dataset', 'created_at' => $stamp($row['movement_date']),
            'updated_at' => $stamp($row['movement_date']),
        ], $data['stock_movements']));
        $this->insert('daily_order_closures', array_map(fn ($row) => [
            'order_day' => $row['order_day'], 'recorded_by' => $actors['sales'],
            'created_at' => $stamp($row['order_day']), 'updated_at' => $stamp($row['order_day']),
        ], $data['daily_order_closures']));

        $this->insert('audit_logs', array_map(fn ($row) => [
            'actor_id' => $row['new_status'] === 'recalled' ? $actors['manager'] : $actors['production'],
            'event' => 'batch.'.$row['new_status'], 'subject_type' => 'ProductionBatch',
            'subject_id' => (int) $row['batch_id'],
            'before_values' => json_encode(['status' => $row['previous_status']]),
            'after_values' => json_encode(['status' => $row['new_status'], 'evidence_reference' => $row['evidence_reference']]),
            'reason' => $row['reason'], 'created_at' => $stamp($row['event_date']),
        ], $data['batch_quality_events']));
        $this->insert('audit_logs', array_map(fn ($row) => [
            'actor_id' => $actors['distribution'], 'event' => 'delivery_attempt.'.$row['attempt_status'],
            'subject_type' => 'Dispatch', 'subject_id' => (int) $row['dispatch_id'],
            'before_values' => null,
            'after_values' => json_encode(['trip_id' => (int) $row['trip_id'], 'order_id' => (int) $row['order_id'],
                'resolution' => $row['resolution']]),
            'reason' => $row['reason'], 'created_at' => $stamp($row['attempted_on']),
        ], $data['delivery_attempts']));

        foreach (['customers' => 'customers', 'product_manufacturing_policies' => 'product_manufacturing_policies',
            'production_batches' => 'production_batches', 'sales_orders' => 'sales_orders',
            'sales_order_items' => 'sales_order_items', 'delivery_trips' => 'delivery_trips',
            'delivery_trip_orders' => 'delivery_trip_orders', 'dispatches' => 'dispatches',
            'dispatch_lines' => 'dispatch_lines', 'invoices' => 'invoices', 'payments' => 'payments',
            'batch_disposals' => 'batch_disposals', 'stock_movements' => 'stock_movements',
            'daily_order_closures' => 'daily_order_closures'] as $table => $source) {
            if (DB::table($table)->count() !== count($data[$source])) {
                throw new RuntimeException("Imported {$table} count does not match source CSV.");
            }
        }
        if (DB::table('users')->count() !== $userCount) {
            throw new RuntimeException('Login user count changed during import.');
        }
    }
}
