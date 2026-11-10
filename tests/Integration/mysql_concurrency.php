<?php

// Run only against a dedicated disposable MySQL database whose name contains "concurrency".
// Two PHP processes enter the real controller methods together to exercise InnoDB row locks.

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\ProductForecastController;
use App\Http\Controllers\UserManagementController;
use App\Models\Customer;
use App\Models\DeliveryTrip;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AppAlertService;
use App\Services\AuditTrail;
use App\Services\DeliveryTripService;
use App\Services\PackagedWaterService;
use App\Services\ProductForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (DB::getDriverName() !== 'mysql' || ! str_contains((string) DB::getDatabaseName(), 'concurrency')) {
    throw new RuntimeException('Refusing to run outside a dedicated MySQL concurrency test database.');
}

function concurrentRequest(int $actorId, array $payload): Request
{
    $actor = User::findOrFail($actorId);
    Auth::setUser($actor);
    $request = Request::create('/concurrency-test', 'POST', $payload);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $actor);

    return $request;
}

if (($argv[1] ?? null) === 'worker') {
    [$action, $actorId, $targetId, $barrier, $name] = array_slice($argv, 2);
    touch($barrier.'-'.$name.'.ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'.go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.go')) {
        throw new RuntimeException('Concurrent start barrier timed out.');
    }
    try {
        if ($action === 'approve') {
            $request = concurrentRequest((int) $actorId, [
                'approved_cases' => 0,
                'planned_for' => CarbonImmutable::today(config('app.business_timezone'))->toDateString(),
                'reason' => 'Concurrency regression test',
            ]);
            app(ProductForecastController::class)->decision($request, ProductForecast::findOrFail($targetId), app(AuditTrail::class), app(ProductForecastService::class), app(AppAlertService::class));
        } elseif ($action === 'deactivate') {
            $request = concurrentRequest((int) $actorId, []);
            app(UserManagementController::class)->destroy($request, User::findOrFail($targetId), app(AuditTrail::class));
        } else {
            app(DeliveryTripService::class)->dispatch(DeliveryTrip::findOrFail($targetId), (int) $actorId);
        }
        echo json_encode(['worker' => $name, 'result' => 'success']);
    } catch (Throwable $error) {
        echo json_encode(['worker' => $name, 'result' => 'rejected', 'type' => get_class($error), 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()]);
    }
    exit;
}

function race(string $action, array $jobs): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aquatrack-race-'.bin2hex(random_bytes(6));
    $processes = [];
    foreach ($jobs as $name => [$actorId, $targetId]) {
        $process = new Process([PHP_BINARY, __FILE__, 'worker', $action, (string) $actorId, (string) $targetId, $barrier, $name], base_path());
        $process->start();
        $processes[$name] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'-*.ready')) !== count($jobs) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (count(glob($barrier.'-*.ready')) !== count($jobs)) {
        throw new RuntimeException('Workers did not reach the concurrency barrier.');
    }
    touch($barrier.'.go');
    $results = [];
    foreach ($processes as $name => $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($name.' worker failed: '.$process->getErrorOutput());
        }
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

$suffix = bin2hex(random_bytes(4));
$first = User::create(['name' => 'Concurrent Admin A', 'email' => 'race-a-'.$suffix.'@example.test', 'password' => 'password-long-enough', 'role' => 'administrator', 'is_active' => true]);
$second = User::create(['name' => 'Concurrent Admin B', 'email' => 'race-b-'.$suffix.'@example.test', 'password' => 'password-long-enough', 'role' => 'administrator', 'is_active' => true]);
$product = Product::where('sku', 'WATER-500ML')->firstOrFail();
$forecast = ProductForecast::create(['product_id' => $product->id, 'origin_date' => CarbonImmutable::today(config('app.business_timezone'))->startOfWeek()->subDay(),
    'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 10, 'mae_cases' => 2, 'bias_cases' => 0,
    'evaluation' => ['selected' => true, 'mode' => 'current']]);

$approvals = race('approve', ['a' => [$first->user_id, $forecast->id], 'b' => [$second->user_id, $forecast->id]]);
$decisions = ProductionForecastDecision::where('product_forecast_id', $forecast->id)->count();
if (count(array_filter($approvals, fn ($r) => $r['result'] === 'success')) !== 1 || $decisions !== 1) {
    throw new RuntimeException('Concurrent approvals did not resolve to exactly one decision: '.json_encode($approvals));
}

$deactivations = race('deactivate', ['a' => [$first->user_id, $second->user_id], 'b' => [$second->user_id, $first->user_id]]);
$activeAdmins = User::where('role', 'administrator')->where('is_active', true)->count();
if (count(array_filter($deactivations, fn ($r) => $r['result'] === 'success')) !== 1 || $activeAdmins !== 1) {
    throw new RuntimeException('Concurrent deactivations did not preserve one administrator: '.json_encode($deactivations));
}

$active = User::where('role', 'administrator')->where('is_active', true)->firstOrFail();
$batch = ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'RACE-'.bin2hex(random_bytes(4)),
    'produced_on' => CarbonImmutable::today(config('app.business_timezone'))->subDay(),
    'expires_on' => CarbonImmutable::today(config('app.business_timezone'))->addDays(30),
    'cases_produced' => 10, 'cases_on_hand' => 10, 'status' => 'released', 'total_cost' => 100, 'cost_per_case' => 10, 'produced_by' => $active->user_id]);
$customer = Customer::create(['name' => 'Concurrency customer', 'phone_number' => '0712'.random_int(100000, 999999)]);
$water = app(PackagedWaterService::class);
$order = $water->createOrder(['customer_id' => $customer->customer_id, 'delivery_address' => 'Nairobi', 'items' => [[
    'product_id' => $product->id, 'cases_ordered' => 2, 'price_per_case' => 100,
]]], $active->user_id);
$water->confirmOrder($order, $active->user_id);
$trip = DeliveryTrip::create(['delivery_on' => CarbonImmutable::today(config('app.business_timezone')), 'status' => 'planned', 'created_by' => $active->user_id]);
$trip->orders()->attach($order->id);
$dispatches = race('dispatch', ['a' => [$active->user_id, $trip->id], 'b' => [$active->user_id, $trip->id]]);
$dispatchMovements = StockMovement::where('production_batch_id', $batch->id)->where('reason', 'customer_dispatch')->count();
if (count(array_filter($dispatches, fn ($r) => $r['result'] === 'success')) !== 1 || $dispatchMovements !== 1 || (int) $batch->fresh()->cases_on_hand !== 8) {
    throw new RuntimeException('Concurrent trip dispatch deducted stock more than once or failed unexpectedly: '.json_encode($dispatches));
}

echo json_encode(['database' => DB::getDatabaseName(), 'approval_results' => $approvals, 'decisions' => $decisions,
    'deactivation_results' => $deactivations, 'active_administrators' => $activeAdmins,
    'dispatch_results' => $dispatches, 'dispatch_stock_movements' => $dispatchMovements], JSON_PRETTY_PRINT).PHP_EOL;
