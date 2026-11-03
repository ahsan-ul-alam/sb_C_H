<?php

use App\Models\Expense;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\CostRecognitionService;
use App\Services\ExpenseService;
use App\Services\LoanReceivableService;
use App\Services\PaymentRecorder;
use App\Services\SourcingPayments;
use Illuminate\Contracts\Console\Kernel;

/*
 | Test-only worker for ConcurrencyMysqlTest: performs ONE financial operation in its own PHP process so that several run at
 | the same moment against the same MySQL test database. Usage: php concurrency_worker.php <operation> '<json args>'
 | It refuses to run against anything that is not a loopback database whose name ends in _test.
 | (Imports stay at the top: the code formatter rewrites class names to imports, which must exist before they are used.)
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = config('database.connections.'.config('database.default'));
if (config('database.default') !== 'mysql' || ! in_array($db['host'], ['127.0.0.1', 'localhost'], true) || ! str_ends_with((string) $db['database'], '_test')) {
    echo json_encode(['ok' => false, 'error' => 'refusing to run outside a local *_test MySQL database']);
    exit(1);
}

$args = json_decode($argv[2] ?? '{}', true);
auth()->login(User::findOrFail($args['user']));
// Line the processes up so they hit the database together rather than one after another.
while (microtime(true) < ($args['start'] ?? 0)) {
    usleep(1000);
}

try {
    $result = match ($argv[1]) {
        'transfer' => AccountingService::transfer(['from' => $args['from'], 'to' => $args['to'], 'amount' => $args['amount'], 'date' => $args['date'], 'idempotency_key' => $args['key']], auth()->user()) !== null,
        'pay_expense' => ExpenseService::pay(Expense::findOrFail($args['expense']), ['amount' => $args['amount'], 'paid_on' => $args['date'], 'idempotency_key' => $args['key']], auth()->user()) !== null,
        'loan_repay' => LoanReceivableService::recordRepayment(LoanReceivable::findOrFail($args['loan']), ['amount' => $args['amount'], 'repaid_on' => $args['date'], 'idempotency_key' => $args['key']], auth()->user()) !== null,
        'adv_pay' => SourcingPayments::recordSupplierPayment(SourcingLot::findOrFail($args['lot']), ['supplier_id' => $args['supplier'], 'amount' => $args['amount'], 'paid_on' => $args['date'], 'purchase_order' => 'PO-1',
            'reference' => 'PAY-1', 'evidence_reference' => 'INV-1', 'idempotency_key' => $args['key']], auth()->user()) !== null,
        'adv_propose' => AdvanceSettlementService::propose(SourcingLot::findOrFail($args['lot']), $args['supplier'], auth()->user())->isNotEmpty(),
        'cost_propose' => CostRecognitionService::propose(SourcingLot::findOrFail($args['lot']), $args['date'], auth()->user()) !== null,
        'loan_repay_verify' => (function () use ($args) {
            LoanReceivableService::decideRepayment(LoanReceivableRepayment::findOrFail($args['repayment']), true, auth()->user());

            return true;
        })(),
        'payment' => (function () use ($args) {
            $project = Project::findOrFail($args['project']);

            return app(PaymentRecorder::class)->save(new Payment, $project, null, ['amount' => $args['amount'], 'paid_on' => $args['date'], 'method' => 'cash', 'idempotency_key' => $args['key']], null, $project->segments()->first()) !== null;
        })(),
    };
    echo json_encode(['ok' => true, 'recorded' => $result]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()]);
}
