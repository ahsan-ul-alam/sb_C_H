# Sellbees ERP: reconciliation and guarded correction (SSH runbook)

Run from the application folder over cPanel SSH. Plain Artisan only: no root, no Docker, no web route, no queue worker. Never run `migrate:fresh`, `migrate:refresh` or `db:wipe`.
Both commands exist in the code and are listed by `php artisan list sellbees`.

## Before anything: backup (mandatory)
Export the database from cPanel (phpMyAdmin or `mysqldump`), check the file size and that it ends with `-- Dump completed` or `COMMIT;`, and keep a copy of `.env` and the application folder. Do not continue without it.

## 1. `sellbees:reconcile` (read-only, safe at any time)
```
php artisan sellbees:reconcile                # concise list of exceptions and failures
php artisan sellbees:reconcile --all          # also list the checks that passed
php artisan sellbees:reconcile --json         # machine-readable
php artisan sellbees:reconcile --snapshot=history/before.json   # write a history fingerprint (under storage/app)
php artisan sellbees:reconcile --compare=history/before.json    # prove history is unchanged since the snapshot
```
Exit code: `0` clean, `2` only documented historical exceptions (needs business evidence, nothing wrong with the software), `1` an integrity failure (stop and investigate).
It reuses the existing finance and inventory checks (`finance:reconcile`, `inventory:reconcile` logic) and adds invoice, receipt, supplier, ownership, client-number and frozen-allocation checks. The `--snapshot`/`--compare` pair proves posted journals, frozen allocations, closed periods and recorded payments up to the snapshot are byte-for-byte unchanged; new business after the snapshot is allowed.

Use: `--snapshot` before a release, deploy and migrate (`php artisan migrate --force`, only with explicit authorization), then `--compare` after.

## 2. `sellbees:correct` (guarded; preview by default)
Only one correction is offered, because it is the only one that is a mechanical step after approvals already made in the application:

`receipt-classification`: applies an owner-confirmed receipt review that was already **proposed, reviewed and approved by three different people** in Finance > Historical Reconciliation. It records a classification and posts nothing to the ledger, never edits the payment, never marks an account or revenue as verified.

```
php artisan sellbees:correct kinds
php artisan sellbees:correct prepare receipt-classification --payments=9,10 --out=manifests/receipts-1.json   # read-only
php artisan sellbees:correct apply --manifest=manifests/receipts-1.json                                       # preview, changes nothing
php artisan sellbees:correct apply --manifest=manifests/receipts-1.json --apply --as=finance.user@example.com # applies
```
- `prepare` includes only approved cases and says why each other payment is excluded.
- The manifest is signed with the server's application key: it only works on the server that prepared it, and any edit makes it invalid. Prepare it on production (read-only), review the preview, then apply.
- `apply` re-checks every case on the live data (record fingerprint, approval state, closed month, no ledger posting, schema version) and refuses any change or mismatch. A second identical apply says "already applied" and does nothing.
- `--apply` needs `--as=` an existing user who may reconcile (Finance or Board).

Everything else stays in the application's own approval screens (Finance and Board people are required; an SSH operator cannot bypass them): account activation and statements, opening cash allocation, lot ownership with documents, supplier shortfall, linking a payment to an invoice, any ledger correction. Posted journals are never edited or deleted.

## What the production backup dated 2026-11-07 shows (recalculated, not assumed)
| Id | Finding | Needed to resolve |
|---|---|---|
| HX-CASH-UNASSIGNED | Ledger cash -52,169.00 with no financial account | Real Cash in Hand / bKash / Bank identities, opening dates, verified statements (screens) |
| HX-ACCOUNTS-NOT-ACTIVE | 0 financial accounts exist | Same |
| HX-RECEIPTS-* | 9 receipts, 78,400.00: none classified, none attributed to an account, none in the ledger (real-time posting is off) | Owner confirmation, review and approval per receipt; then `sellbees:correct` |
| HX-PAYMENT-UNLINKED | Payment 13 (19,200.00) beside unpaid invoice INV-2026-PR2-L2 | Proof of which invoice it pays |
| HX-VOIDED-PAYMENT-DIFF | Payment 11 (2,830.00, voided) vs payment 12 (2,650.00): 180.00 | The reason for the difference |
| HX-LOT-OWNERSHIP-SL-000002 | Lot SL-000002 has no stated owner (SL-000001 is now `company`) | Ownership evidence and approval in the lot screen |
| HX-SUPPLIER-SHORTFALL | SL-000002 us polo: ordered 22, received 15, supplier paid for 22: 2,030.00 | Supplier confirmation (shortfall workflow) |
| HX-LOT-COSTS-UNPAID | Lot cost 2 (Delivery, 500.00) has no expense record | Pay or record as payable |
| HX-ASSET-PROJECT-EXPENSE | Expense 13 "Delivery" 800.00 is linked to a client project but booked as a company asset | Decision: client cost or company asset |

No frozen profit allocation exists yet (the allocations table is empty); the one closed period (2026-09) is unchanged by the migrations.

## Failure handling
- Any `Refused:` message: read it, change nothing, fix the cause (usually a missing approval or a record that changed) and prepare a new manifest.
- Exit code `1` from `sellbees:reconcile`: stop, restore the backup if a release caused it, report the check id.
- Interrupted command: re-run it; every apply step is its own transaction and repeating it is safe.
