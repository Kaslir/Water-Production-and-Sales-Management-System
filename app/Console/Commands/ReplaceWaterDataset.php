<?php

namespace App\Console\Commands;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ReplaceWaterDataset extends Command
{
    protected $signature = 'dataset:replace {--apply : Replace existing operational records after validation} {--path= : Directory containing the six dataset files}';

    protected $description = 'Validate and replace operational data with the synthetic CSV dataset while preserving login users';

    public function handle(): int
    {
        try {
            $directory = $this->option('path') ?: database_path('data');
            $data = $this->validateDataset($directory);
            $this->table(['Measure', 'Validated value'], [
                ['Period', $data['metadata']['period_start'].' to '.$data['metadata']['period_end']],
                ['Customers', count($data['customers'])],
                ['Production', count($data['productions'])],
                ['Sales', count($data['sales'])],
                ['Distributions', count($data['distributions'])],
                ['Closing stock (L)', number_format($data['closing'], 2)],
            ]);
            if (! $this->option('apply')) {
                $this->info('Validation passed. No database records were changed. Back up MySQL, then rerun with --apply.');
                return self::SUCCESS;
            }
            if (! $this->confirm('Replace operational records in database '.DB::getDatabaseName().'? Login users will be retained.', false)) {
                $this->warn('Cancelled. No records were changed.');
                return self::SUCCESS;
            }
            DB::transaction(fn () => $this->replace($data), 1);
            $this->info('Dataset import committed. Existing login users were preserved.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function csv(string $directory, string $name, array $required): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException("Cannot read {$path}");
        }
        try {
            $header = fgetcsv($handle);
            if (! is_array($header) || array_diff($required, $header)) {
                throw new RuntimeException("Missing required columns in {$name}");
            }
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new RuntimeException("Malformed row in {$name}");
                }
                $rows[] = array_combine($header, $values);
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function positive(string $value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
            throw new RuntimeException("Invalid {$label}");
        }
        return (float) $value;
    }

    private function date(string $value, string $start, string $end): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value || $value < $start || $value > $end) {
            throw new RuntimeException("Invalid or out-of-period date: {$value}");
        }
    }

    private function close(float $actual, float $expected, string $label): void
    {
        if (abs($actual - $expected) > 0.005) {
            throw new RuntimeException("{$label} does not reconcile: {$actual} versus {$expected}");
        }
    }

    private function validateDataset(string $directory): array
    {
        $metadata = json_decode((string) @file_get_contents($directory.DIRECTORY_SEPARATOR.'dataset_metadata.json'), true);
        if (! is_array($metadata) || ($metadata['dataset_type'] ?? '') !== 'Entirely synthetic') {
            throw new RuntimeException('Missing or invalid dataset metadata.');
        }
        $start = $metadata['period_start'] ?? '';
        $end = $metadata['period_end'] ?? '';
        $this->date($start, $start, $end);
        $this->date($end, $start, $end);
        $customers = $this->csv($directory, 'customers.csv', ['customer_id', 'customer_code', 'name', 'phone_number']);
        $productions = $this->csv($directory, 'productions.csv', ['production_id', 'production_date', 'quantity_produced', 'user_role']);
        $sales = $this->csv($directory, 'sales.csv', ['sale_id', 'sale_date', 'quantity_sold', 'unit_price', 'total_amount', 'consumes_inventory', 'customer_id', 'customer_code', 'user_role']);
        $distributions = $this->csv($directory, 'distributions.csv', ['distribution_id', 'distribution_date', 'quantity_distributed', 'destination', 'user_role']);
        $daily = $this->csv($directory, 'daily_summary.csv', ['date', 'opening_inventory', 'quantity_produced', 'quantity_sold', 'quantity_distributed', 'daily_revenue', 'closing_inventory']);
        foreach (['customers' => 'customer_records', 'productions' => 'production_records', 'sales' => 'sales_records', 'distributions' => 'distribution_records', 'daily' => 'number_of_days'] as $group => $key) {
            if (count($$group) !== (int) ($metadata[$key] ?? -1)) {
                throw new RuntimeException("{$group} row count differs from metadata.");
            }
        }
        $customerIds = $customerCodes = $phones = [];
        foreach ($customers as $row) {
            $id = (int) $row['customer_id'];
            if ($id < 1 || isset($customerIds[$id]) || $row['name'] === '' || $row['phone_number'] === '' || isset($phones[$row['phone_number']]) || isset($customerCodes[$row['customer_code']])) {
                throw new RuntimeException('Invalid or duplicate customer.');
            }
            $customerIds[$id] = $row['customer_code'];
            $customerCodes[$row['customer_code']] = true;
            $phones[$row['phone_number']] = true;
        }
        $byDay = [];
        $ids = ['production' => [], 'sale' => [], 'distribution' => []];
        foreach ($productions as $row) {
            $date = $row['production_date'];
            $this->date($date, $start, $end);
            $id = (int) $row['production_id'];
            if ($id < 1 || isset($ids['production'][$id]) || $row['user_role'] !== 'production_staff') {
                throw new RuntimeException('Invalid production ID or role.');
            }
            $ids['production'][$id] = true;
            $byDay[$date]['production'] = ($byDay[$date]['production'] ?? 0) + $this->positive($row['quantity_produced'], 'production quantity');
        }
        foreach ($sales as $row) {
            $date = $row['sale_date'];
            $this->date($date, $start, $end);
            $id = (int) $row['sale_id'];
            $customerId = (int) $row['customer_id'];
            if ($id < 1 || isset($ids['sale'][$id]) || ($customerIds[$customerId] ?? null) !== $row['customer_code'] || $row['user_role'] !== 'sales_staff' || strtolower($row['consumes_inventory']) !== 'true') {
                throw new RuntimeException('Invalid sale ID, customer, role or stock flag.');
            }
            $ids['sale'][$id] = true;
            $quantity = $this->positive($row['quantity_sold'], 'sales quantity');
            if (! is_numeric($row['unit_price']) || (float) $row['unit_price'] < 0) {
                throw new RuntimeException('Invalid sale price.');
            }
            $revenue = $quantity * (float) $row['unit_price'];
            $this->close($revenue, (float) $row['total_amount'], 'sale amount');
            $byDay[$date]['sales'] = ($byDay[$date]['sales'] ?? 0) + $quantity;
            $byDay[$date]['revenue'] = ($byDay[$date]['revenue'] ?? 0) + $revenue;
        }
        foreach ($distributions as $row) {
            $date = $row['distribution_date'];
            $this->date($date, $start, $end);
            $id = (int) $row['distribution_id'];
            if ($id < 1 || isset($ids['distribution'][$id]) || $row['user_role'] !== 'distribution_staff' || $row['destination'] === '') {
                throw new RuntimeException('Invalid distribution ID, role or destination.');
            }
            $ids['distribution'][$id] = true;
            $byDay[$date]['distribution'] = ($byDay[$date]['distribution'] ?? 0) + $this->positive($row['quantity_distributed'], 'distribution quantity');
        }
        $balance = (float) ($metadata['opening_inventory_litres'] ?? 0);
        $expectedDate = $start;
        $totals = ['production' => 0.0, 'sales' => 0.0, 'distribution' => 0.0, 'revenue' => 0.0];
        foreach ($daily as $row) {
            if ($row['date'] !== $expectedDate) {
                throw new RuntimeException("Missing, duplicate or unordered date near {$expectedDate}.");
            }
            $this->close($balance, (float) $row['opening_inventory'], 'opening inventory on '.$expectedDate);
            foreach (['production' => 'quantity_produced', 'sales' => 'quantity_sold', 'distribution' => 'quantity_distributed', 'revenue' => 'daily_revenue'] as $key => $column) {
                $quantity = $byDay[$expectedDate][$key] ?? 0;
                $this->close($quantity, (float) $row[$column], "{$key} on {$expectedDate}");
                $totals[$key] += $quantity;
            }
            $balance += ($byDay[$expectedDate]['production'] ?? 0) - ($byDay[$expectedDate]['sales'] ?? 0) - ($byDay[$expectedDate]['distribution'] ?? 0);
            if ($balance < 0) {
                throw new RuntimeException("Negative stock on {$expectedDate}.");
            }
            $this->close($balance, (float) $row['closing_inventory'], 'closing inventory on '.$expectedDate);
            $expectedDate = (new DateTimeImmutable($expectedDate))->modify('+1 day')->format('Y-m-d');
        }
        if ((new DateTimeImmutable($expectedDate))->modify('-1 day')->format('Y-m-d') !== $end) {
            throw new RuntimeException('The daily summary does not reach the requested end date.');
        }
        foreach (['production' => 'total_production_litres', 'sales' => 'total_sales_litres', 'distribution' => 'total_distribution_litres', 'revenue' => 'total_revenue_ksh'] as $key => $metadataKey) {
            $this->close($totals[$key], (float) ($metadata[$metadataKey] ?? -1), "metadata {$key}");
        }
        $this->close($balance, (float) ($metadata['closing_inventory_litres'] ?? -1), 'metadata closing inventory');
        return compact('metadata', 'customers', 'productions', 'sales', 'distributions', 'daily') + ['closing' => $balance];
    }

    private function replace(array $data): void
    {
        foreach (['reports', 'forecasts', 'inventory_transactions', 'sales', 'distributions', 'productions', 'customers', 'inventories'] as $table) {
            DB::table($table)->delete();
        }
        $actors = [];
        foreach (['production_staff' => 'Production', 'sales_staff' => 'Sales', 'distribution_staff' => 'Distribution'] as $role => $label) {
            $actor = User::firstOrCreate(
                ['email' => 'synthetic.'.str_replace('_', '.', $role).'@aquatrack.invalid'],
                ['name' => "Synthetic Dataset {$label}", 'role' => $role, 'is_active' => false, 'password' => Hash::make(Str::random(64))]
            );
            if ($actor->role !== $role || $actor->is_active) {
                throw new RuntimeException("Synthetic account configuration conflict for {$role}.");
            }
            $actors[$role] = $actor->user_id;
        }
        $now = now();
        $insert = function (string $table, array $rows): void {
            foreach (array_chunk($rows, 300) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        };
        $insert('customers', array_map(fn ($r) => ['customer_id' => (int) $r['customer_id'], 'name' => $r['name'], 'phone_number' => $r['phone_number'], 'created_at' => $now, 'updated_at' => $now], $data['customers']));
        $insert('productions', array_map(fn ($r) => ['production_id' => (int) $r['production_id'], 'production_date' => $r['production_date'], 'quantity_produced' => $r['quantity_produced'], 'notes' => $r['notes'] ?? null, 'user_id' => $actors['production_staff'], 'created_at' => $now, 'updated_at' => $now], $data['productions']));
        $insert('sales', array_map(fn ($r) => ['sale_id' => (int) $r['sale_id'], 'sale_date' => $r['sale_date'], 'quantity_sold' => $r['quantity_sold'], 'unit_price' => $r['unit_price'], 'total_amount' => $r['total_amount'], 'consumes_inventory' => true, 'customer_id' => (int) $r['customer_id'], 'user_id' => $actors['sales_staff'], 'created_at' => $now, 'updated_at' => $now], $data['sales']));
        $insert('distributions', array_map(fn ($r) => ['distribution_id' => (int) $r['distribution_id'], 'distribution_date' => $r['distribution_date'], 'quantity_distributed' => $r['quantity_distributed'], 'destination' => $r['destination'], 'notes' => $r['notes'] ?? null, 'user_id' => $actors['distribution_staff'], 'created_at' => $now, 'updated_at' => $now], $data['distributions']));
        $transactions = [];
        foreach ($data['productions'] as $r) {
            $transactions[] = ['transaction_type' => 'IN', 'quantity' => $r['quantity_produced'], 'reference_type' => 'production', 'reference_id' => (int) $r['production_id'], 'transaction_date' => $r['production_date'], 'user_id' => $actors['production_staff'], 'notes' => 'Synthetic dataset import', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($data['sales'] as $r) {
            $transactions[] = ['transaction_type' => 'OUT', 'quantity' => $r['quantity_sold'], 'reference_type' => 'sale', 'reference_id' => (int) $r['sale_id'], 'transaction_date' => $r['sale_date'], 'user_id' => $actors['sales_staff'], 'notes' => 'Synthetic dataset import', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($data['distributions'] as $r) {
            $transactions[] = ['transaction_type' => 'OUT', 'quantity' => $r['quantity_distributed'], 'reference_type' => 'distribution', 'reference_id' => (int) $r['distribution_id'], 'transaction_date' => $r['distribution_date'], 'user_id' => $actors['distribution_staff'], 'notes' => 'Synthetic dataset import', 'created_at' => $now, 'updated_at' => $now];
        }
        usort($transactions, fn ($a, $b) => [$a['transaction_date'], $a['transaction_type'] === 'IN' ? 0 : 1, $a['reference_type'], $a['reference_id']] <=> [$b['transaction_date'], $b['transaction_type'] === 'IN' ? 0 : 1, $b['reference_type'], $b['reference_id']]);
        $insert('inventory_transactions', $transactions);
        DB::table('inventories')->insert(['quantity_available' => $data['closing'], 'last_updated' => $now, 'created_at' => $now, 'updated_at' => $now]);
        foreach (['customers', 'productions', 'sales', 'distributions'] as $table) {
            if (DB::table($table)->count() !== count($data[$table])) {
                throw new RuntimeException("Database count mismatch for {$table}.");
            }
        }
        if (DB::table('inventory_transactions')->count() !== count($transactions)) {
            throw new RuntimeException('Database inventory movement count mismatch.');
        }
        $this->close((float) DB::table('inventories')->value('quantity_available'), $data['closing'], 'database closing inventory');
        $this->close((float) DB::table('sales')->sum('total_amount'), (float) $data['metadata']['total_revenue_ksh'], 'database revenue');
    }
}
