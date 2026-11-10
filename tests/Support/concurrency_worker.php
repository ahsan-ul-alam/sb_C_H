<?php

use App\Models\AdvanceSettlement;
use App\Models\CapitalEntry;
use App\Models\Client;
use App\Models\ClientApiIntegration;
use App\Models\ClientOrder;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryEngineEnrolment;
use App\Models\InventoryHandover;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnSettlement;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\ClientInformationOtp;
use App\Services\ClientPortalOtp;
use App\Services\ClientSettlementService;
use App\Services\CostRecognitionService;
use App\Services\DuplicateSubmission;
use App\Services\ExpenseService;
use App\Services\InventoryEngine;
use App\Services\InventoryHandoverService;
use App\Services\InvestmentService;
use App\Services\LoanReceivableService;
use App\Services\LoanRecoveryAllocationService;
use App\Services\OrderFulfilment;
use App\Services\PaymentRecorder;
use App\Services\PayoutService;
use App\Services\ProjectStockAllocation;
use App\Services\SourcingPayments;
use App\Services\SubmissionGuard;
use App\Services\SupplierReturnService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 | Test-only worker for ConcurrencyMysqlTest: performs ONE financial operation in its own PHP process so that several run at
 | the same moment against the same MySQL test database. Usage: php concurrency_worker.php <operation> '<json args>'
 | It refuses to run against anything that is not a loopback database whose name ends in _test.
 | (Imports stay at the top: the code formatter rewrites class names to imports, which must exist before they are used.)
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// The test process fixes its clock (Carbon::setTestNow); the workers are separate processes, so they take the same date from the environment.
if ($now = getenv('CC_TEST_NOW')) {
    Carbon::setTestNow($now);
}

require_once __DIR__.'/helpers.php';
if ($problem = disposableDatabaseProblem()) {
    echo json_encode(['ok' => false, 'error' => 'refusing to run outside a disposable test database: '.$problem]);
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
        'pay_payout' => (function () use ($args) {
            PayoutService::pay(Payout::findOrFail($args['payout']), auth()->user(), $args['account']);

            return true;
        })(),
        'capital_reverse' => (function () use ($args) {
            InvestmentService::reverse(CapitalEntry::findOrFail($args['entry']), 'race', auth()->user());

            return true;
        })(),
        // The same steps as CapitalController::decide when the Board verifies a pending capital entry.
        'capital_verify' => (function () use ($args) {
            DB::transaction(function () use ($args) {
                $capital = CapitalEntry::lockForUpdate()->findOrFail($args['entry']);
                if ($capital->status !== 'pending') {
                    throw new RuntimeException('Entry already decided.');
                }
                $capital->update(['status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now()]);
                InvestmentService::post($capital, auth()->id());
            });

            return true;
        })(),
        'enrol_propose' => (function () use ($args) {
            InventoryEngine::propose(SourcingLot::findOrFail($args['lot']), 'company', 'Board resolution 2026/11 and signed purchase order', '2026-11-01', auth()->user());

            return true;
        })(),
        'enrol_decide' => (function () use ($args) {
            InventoryEngine::decide(InventoryEngineEnrolment::findOrFail($args['enrolment']), true, auth()->user());

            return true;
        })(),
        'handover_decide' => (function () use ($args) {
            InventoryHandoverService::decide(InventoryHandover::findOrFail($args['handover']), true, auth()->user());

            return true;
        })(),
        'sret_decide' => (function () use ($args) {
            SupplierReturnService::decide(SupplierReturn::findOrFail($args['return']), true, auth()->user());

            return true;
        })(),
        'sret_settle_decide' => (function () use ($args) {
            SupplierReturnService::decideSettlement(SupplierReturnSettlement::findOrFail($args['settlement']), true, auth()->user());

            return true;
        })(),
        'adv_decide' => (function () use ($args) {
            AdvanceSettlementService::decide(AdvanceSettlement::findOrFail($args['settlement']), true, auth()->user());

            return true;
        })(),
        'adv_propose' => AdvanceSettlementService::propose(SourcingLot::findOrFail($args['lot']), $args['supplier'], auth()->user())->isNotEmpty(),
        'cost_propose' => CostRecognitionService::propose(SourcingLot::findOrFail($args['lot']), $args['date'], auth()->user()) !== null,
        'loan_repay_verify' => (function () use ($args) {
            LoanReceivableService::decideRepayment(LoanReceivableRepayment::findOrFail($args['repayment']), true, auth()->user());

            return true;
        })(),
        // The guarded capital form: the same steps as CapitalController::store once its checks pass.
        'guard_capital' => (function () use ($args) {
            try {
                SubmissionGuard::once($args['key'], 'capital.record', auth()->id(), ['partner_id' => $args['partner'], 'amount' => $args['amount']], fn () => CapitalEntry::create([
                    'partner_id' => $args['partner'], 'kind' => 'contribution', 'amount' => $args['amount'], 'paid_on' => '2026-11-09', 'created_by' => auth()->id()]));

                return true;
            } catch (DuplicateSubmission) {
                return false;
            }
        })(),
        // Confirming an order (reserves stock under the lot lock) and handing units to another project, both of which must respect each project's entitlement.
        'order_confirm' => (function () use ($args) {
            OrderFulfilment::confirm(ClientOrder::findOrFail($args['order']), auth()->user());

            return true;
        })(),
        'stock_allocate' => (function () use ($args) {
            ProjectStockAllocation::allocate(SourcingLotLine::findOrFail($args['line']), Project::findOrFail($args['project']), $args['qty'], 'race', auth()->user());

            return true;
        })(),
        // A client settlement: pays the client what is owed after fees and clears withheld money against a courier advance.
        'client_settle' => ClientSettlementService::record(Client::findOrFail($args['client']), null, $args['date'], 'race-'.$args['key'], null, $args['key'], auth()->user()) !== null,
        // An optional investor allocation of a verified loan repayment (tracking only); the repayment row lock must let exactly the free amount through.
        'loan_alloc' => LoanRecoveryAllocationService::propose(LoanReceivableRepayment::findOrFail($args['repayment']), Partner::findOrFail($args['partner']), $args['amount'], 'race', null, null, auth()->user(), $args['key']) !== null,
        'client_create' => Client::create(['name' => 'Race client '.$args['n'], 'mobile' => '017'])->sb_number,
        'otp_verify' => ClientInformationOtp::verify(ClientApiIntegration::findOrFail($args['integration']), $args['sb'], $args['request_id'], $args['code']) !== null,
        'portal_verify' => ClientPortalOtp::verify($args['nonce'], $args['code'], $args['binding']) !== null,
        'payment' => (function () use ($args) {
            $project = Project::findOrFail($args['project']);

            return app(PaymentRecorder::class)->save(new Payment, $project, null, ['amount' => $args['amount'], 'paid_on' => $args['date'], 'method' => 'cash', 'financial_account_id' => FinancialAccount::where('is_default', true)->value('id'), 'idempotency_key' => $args['key']], null, $project->segments()->first()) !== null;
        })(),
    };
    echo json_encode(['ok' => true, 'recorded' => $result]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()]);
}
