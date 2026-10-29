# SellBees ERP — Next-Step Fix & Verification Checklist

**Status:** Follow-up to Claude Code's implementation report (30 October 2026). The report is a developer statement, **not** proof of an operating/validated ERP. Existing code and DB have not been inspected by this checklist's author.

**Source of truth:** `SellBees_3_Partner_ERP_Spec.md` (copy it to the repository root). Prior four-partner policy (`policy_old4.pdf`) is the historical baseline; a new signed three-partner policy with effective date is required for new-period live postings.

## 1. Reported completed vs. actually verified

- **Reported implemented:** 34 `finance.*` routes, Laravel migration, capital/expense ledger, board approval, funds and allocations, payouts, increment resolutions, exit preview/mark-exited flow, dashboard, audit, CSV and users/roles.
- **Reported checked:** PHP syntax, Blade compilation, route registration, standalone splitter arithmetic, Pint.
- **NOT verified:** migration, seeding, tests, DB transactions, HTTP authorization and real posting. Treat the finance feature as **not yet operationally verified**.

## 2. Immediate security blockers (P0)

1. **Public `/cc` migration route:** delete the unauthenticated route that executes `migrate --force`; database migrations must be performed through controlled CLI/deployment only.
2. **`users.role` default `super_admin`:** this would assign broad admin privilege to every existing user migrated with the default and to future created accounts unless explicitly corrected. Use a least-privilege/nullable default; carefully map known existing admins and avoid public self-assignment. Recheck any ClientHub route dependency.
3. **RBAC:** server-side role checks, partner record-level scoping, segregation of creator/approver, no self-payout/salary approval and no admin approval bypass.
4. **Signed policy status:** draft A/B/C policy is not sufficient for financial posting. Don't backdate or fake a signature/approval.

## 3. Database activation without MySQL root

- Application DB credentials in `.env` (or secret manager) may be enough to run migrations **if** that DB user has `CREATE/ALTER/INDEX` rights on its own database. MySQL root password is not a standard Laravel requirement.
- Inspect `.env`/`config/database.php` **without printing passwords**; verify driver, connection and `php artisan migrate:status` against a **safe development DB**. Back up any real data before schema changes.
- Do not run `migrate:fresh` or a broad `db:seed` on a populated database. Run only reviewed, targeted finance migrations/seeders in a safe environment; inspect table existence, indexes, foreign keys and default values.
- SQLite is optional. Missing `pdo_sqlite` is **not** a reason to use MySQL root or to skip all DB verification. If no safe DB connection or migration privilege is available, report this and do not claim integration testing passed.

## 4. Missing functional work (P1)

### A. Employee increment

As in old policy pp. 9–11 and the new MD:

```text
AnnualProfitRate% = ApprovedAvailableAnnualNetProfit / OriginalTotalInvestment * 100
If rate < 80: do not auto-calculate/approve a positive theoretical increment
If 80 <= rate <= 100: Theoretical% = 10 + ((rate - 80) / 20) * 10
If rate > 100: Theoretical% = 20 + ((rate - 100) / 100) * 5
```

Expected examples: 80→10%, 90→15%, 100→20%, 150→22.5%, 200→25%, 300→30%. Track theoretical separately from board-approved actual rate; record approved amount/effective date. Do not automatically raise salaries.

### B. Exit settlement (old policy pp. 12–14)

```text
AdjustedInvestment = RecordedInvestment
   - ApprovedUnadjustedLosses
   - ApprovedUnadjustedApplicableExpensesOrLiabilities
   - PreviousCapitalWithdrawals

ExitIncentive = SUM(last 12 months VERIFIED NEW CompanyFundProfitAllocations)
                * applicable period-specific ProfitFundRatio of exiting partner

FinalSettlement = AdjustedInvestment + ExitIncentive
                  + UnpaidApprovedEntitlements - OtherVerifiedLiabilities
```

Avoid double deduction. Show supporting records and approve settlement before posting actual payables/payouts; never mark a transfer/payment as done unless it happened. Partner B example: BDT 400,000 × 30% = BDT 120,000 incentive (only for applicable approved 3-partner periods). A share transfer requires signed supporting legal records.

### C. Closed ClientHub periods

Current report says editing ClientHub payments after close does not change frozen net profit. That is an inconsistency risk: either prevent in-place changes to recognized closed-period source payments/revenue or require authorized reversal/adjustment in an open period, with a traceable link. Review refunds/voids/advances to avoid interpreting every cash receipt as earned revenue.

### D. Minimal partners UI

Partner list/create/status/details and capital history; authorized changes only; historical Partner D records retained when they exist. No generic admin CRUD allowing signed ratio edits.

## 5. High-impact finance smoke checks

- Company net-profit leaf allocation for BDT 1,000,000: Salary 200,000; Office/Growth 50,000; Emergency 250,000; A 200,000; B 150,000; C 150,000.
- Equal loss on 100 paisa: 34/33/33 and exact total 100.
- Investment commitment A40/B30/C30 independent from paid capital; ownership equal exact thirds; profit rights separately A40/B30/C30 with signed confirmation.
- Anonymous → login required; unauthorized → 403; partner can view only own records; self-approval disallowed; admin cannot bypass posted finance approval.
- Profit preview makes no journal writes; one approved period posts exactly once; journals balance; duplicate post fails; reversal is traceable.
- Closed-period payment mutation is rejected or processed as a dated adjustment.
- Increment threshold/calculations and B exit incentive example match rules.
- The old four-partner posted ledger, if any, is unchanged.

## 6. Go-live gate

No production-ready claim until these are true:

- [ ] public `/cc` removed; unsafe `super_admin` role defaults fixed.
- [ ] new spec read from repo and implementation compared with it.
- [ ] applicable DB migration + targeted seeding performed and checked in a safe environment.
- [ ] essential finance/RBAC/period immutability smoke checks passed.
- [ ] increment and exit settlement rules implemented and reviewed.
- [ ] 3-partner signed policy + effective date + independent 40/30/30 profit-share confirmation recorded.
- [ ] opening balances, approved partner identity mapping and approver roles verified.

**Build philosophy:** reuse existing ClientHub/ERP code, implement the smallest safe fixes, avoid overengineering, and do not build a large new test framework. The minimum critical checks for financial posting and access control are necessary, not optional.
