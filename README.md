# ClientHub

Admin-only client, project, payment, and invoice management (Laravel 13, Blade, SQLite).

## Requirements

- PHP 8.3+ with `pdo_sqlite`, `mbstring`, `dom`, `gd`, `bcmath`
- Composer

You do not need Node.js. Styles are a plain stylesheet at `public/css/app.css`, so there is no build step.

## Setup

```bash
composer install
cp .env.example .env            # then set ADMIN_EMAIL and ADMIN_PASSWORD
php artisan key:generate
touch database/database.sqlite  # default DB; or set DB_* in .env for MySQL/PostgreSQL
php artisan migrate
php artisan db:seed             # creates/updates the admin user from .env
php artisan serve               # http://localhost:8000
```

Log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`. To change the password later, edit `.env` and run `php artisan db:seed` again.

On first use, open **Settings** and enter the business name, address, phone, email, logo, and default currency. These appear on invoices.

## How it fits together

- **Client → Projects → Segments.** A segment is an agreed charge, for example "Onboarding charge: BDT 10,000". Project charges are the sum of its segments.
- **Payments.** Each real transaction is stored as one row. It belongs to a project and can also belong to an invoice. It is allocated to one or more segments. Project "Received" counts each payment once. Segment "Received" is the sum of its allocations.
  - Recording a payment from a segment allocates the full amount to that segment.
  - Recording a payment on an invoice allocates automatically to the segments billed on that invoice. To split it yourself, enter amounts per segment.
- **Invoices.** Creating an invoice from a segment never adds to project charges. Status (Unpaid, Partially paid, Paid) is recalculated from saved payments every time a payment is added, edited, or removed. The stamp shows **DUE** when nothing has been paid, **PARTIALLY PAID** once any payment is recorded, and **PAID** when the balance reaches zero.
- **Mark as Paid** records one payment for exactly the remaining balance. Repeated clicks or resubmits are ignored: buttons disable on submit, and each form carries a one-time key with a unique constraint in the database.
- **Money** is stored as integer minor units (paisa or cents), so totals are exact. Currencies are never combined. The dashboard groups totals by currency.
- **Archiving.** Clients with projects, and projects with invoices or payments, cannot be deleted. Archive them instead.
- **PDF.** Use **Preview / Print** or **Download PDF** on an invoice. The PDF is rendered from the current saved data every time.

## Tests

```bash
php artisan test --compact
```

`tests/Feature/InvoiceWorkflowTest.php` covers the core workflow. It creates a client, a project, and a BDT 10,000 segment, then an invoice for that segment. It pays BDT 4,000 (PARTIALLY PAID stamp), then marks the invoice paid (PAID stamp), checks that BDT 10,000 is counted only once, and checks the PDF. It also confirms that every screen renders.
