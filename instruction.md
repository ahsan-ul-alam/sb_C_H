
eta age 4 jon chilo ekhon 3 jon hobe investment ager moto 40% 30% 30% hobe. ownership 33.33 kore . baki jinis gulo 3 joner hisabe ager hisabe hobe update kore de . and esob kaj korar jonno ekta erp hobe onek ongso already kora ache but eta kivabe ki hobe seta instruction de claude code kaj korbe . over engineering bad. hudai test er need nai. urgent kaj. RBAC thakbe. so prompt de ekta r ekta md file de jekhane jinisgulo bujhiye deya thakbe.



policy_old4.pdf
PDF

SellBees ERP-এর জন্য আগের ৪ জনের কাঠামো আপডেট করে ৩ জন পার্টনারের সম্পূর্ণ Implementation Guide এবং Claude Code Prompt তৈরি করেছি।

আগের নীতিমালার গুরুত্বপূর্ণ নিয়মগুলো রেখেছি। নতুন কাঠামো হবে:

বিষয়	Partner A	Partner B	Partner C
Investment	40%	30%	30%
Ownership	33.33%	33.33%	33.33%
Loss Sharing	33.33%	33.33%	33.33%
Profit Sharing	40%	30%	30%
একটি গুরুত্বপূর্ণ বিষয়: আগের চারজনের Profit Sharing ছিল 40:20:20:20। সেটিকে তিনজনের জন্য 40:30:30 ধরে আপডেট করেছি। বিনিয়োগ ও লাভের ভাগ আলাদা বিষয়, তাই চূড়ান্ত আর্থিক বণ্টনের আগে নতুন Profit Sharing অনুপাতটি লিখিতভাবে অনুমোদন করতে হবে।

Ownership সফটওয়্যারে প্রত্যেকের প্রকৃত অংশ 1/3 রাখা হবে। UI-তে 33.33% দেখাবে, কিন্তু মোট মালিকানা ঠিক 100% থাকবে।

তৈরি করা ফাইল
1. Complete ERP Specification (.md)


এই ফাইলে রয়েছে আপডেটেড আর্থিক নীতিমালা, Profit Calculation, Investment Ledger, Company Fund, Salary, Employee Increment, Partner Exit, RBAC, Database Changes এবং ERP Development Instructions।

2. Claude Code Prompt (.txt)


এই Prompt সরাসরি Claude Code-এ ব্যবহার করতে পারবে।

Claude Code-কে যেভাবে কাজ করতে বলবে
প্রথমে .md ফাইলটি ERP Project-এর root directory-তে রাখবে। তারপর নিচের Prompt ব্যবহার করবে।

You are Claude Code, working on an EXISTING, partially developed SellBees ERP application.

Your task is to update and complete the existing ERP according to:

SellBees_3_Partner_ERP_Spec.md

Read the complete specification before implementing anything.

PRIMARY OBJECTIVE
Convert the previous 4-founder ERP structure into a 3-founder structure.

New configuration:

Partner A: 40% Investment, 33.33% Ownership

Partner B: 30% Investment, 33.33% Ownership

Partner C: 30% Investment, 33.33% Ownership

Ownership must be exactly equal internally (1/3 each).

Internal loss sharing must also be equal.

Partner Profit Fund distribution should use 40:30:30 as specified in the MD, with separate policy confirmation required before live financial posting.

Keep investment, ownership, profit sharing, and salary calculations independent.

IMPORTANT DEVELOPMENT INSTRUCTIONS
This is an EXISTING ERP. Inspect the current repository, architecture, database, authentication, APIs, and frontend first.

Identify which modules are already implemented, partially completed, or missing.

REUSE existing working code. Do not rebuild the application.

Follow the existing technology stack and coding patterns.

Implement mandatory Role-Based Access Control (RBAC).

Authorization must work on the backend, not just through frontend menu visibility.

Implement or complete the modules described in the attached specification:

Partner Management

Investment & Capital Ledger

Income & Expense Management

Financial Dashboard

Profit Calculation & Distribution

Company Fund Management

Salary & Remuneration

Employee Increment

Partner Withdrawals and Payouts

Partner Exit Settlement

Board Approval Management

Reports and Audit History

Users, Roles and Permissions

Apply the updated 3-partner calculations everywhere, including backend, frontend, reports, seed data, and financial workflows.

Preserve existing historical transactions. Never delete legitimate Partner D history or modify previously posted financial allocations.

Use policy versions and effective dates so old transactions retain their original rules.

FINANCIAL RULES
Keep the previous company fund allocation structure:

50% of approved net profit goes to Company Fund.

50% goes to Partners' Profit Fund.

Company Fund is divided equally into Growth Fund and Emergency Reserve.

Growth Fund is divided into 80% Salary Fund and 20% Office & Business Growth Fund.

New Partner Profit Fund distribution:

Partner A: 40%

Partner B: 30%

Partner C: 30%

Apply these calculations precisely using the existing financial architecture.

Financial entries must be traceable.

Prevent duplicate profit allocation, unauthorized withdrawals, and modification of posted transactions.

Use safe monetary calculations and database transactions.

RBAC REQUIREMENTS
Use existing roles and permissions where available.

Required business roles:

Super Admin

Board Member

Finance

HR

Partner

Employee

Auditor

Each role must have appropriate access restrictions.

Partners must not access another partner's private records without permission.

Financial approval must be separate from transaction creation.

Prevent self-approval of personal payouts and remuneration.

STRICT DEVELOPMENT RULES
This project is URGENT.

DO NOT OVERENGINEER.

No unnecessary abstractions.

No unnecessary packages.

No microservices.

No complicated architectural redesign.

No unnecessary automated test suites.

No excessive documentation.

No unrelated feature development.

No speculative database restructuring.

No rebuilding completed modules.

No unnecessary UI redesign.

Focus on making the existing ERP fully functional.

Only perform essential build checks and quick manual verification of important financial calculations, RBAC, and posting safety.

IMPLEMENTATION PRIORITY
First, inspect and update the existing 3-partner configuration, database relationships, and RBAC.

Second, finish investment tracking, income/expenses, and financial ledgers.

Third, implement the updated profit distribution, Company Fund allocation, approvals, and partner payouts.

Fourth, complete employee increments, partner exit calculations, and reporting.

Finally, verify that the ERP works with the new three-partner rules.

Do not stop after producing a plan.

IMPLEMENT THE ACTUAL CODE.

At completion, provide a concise summary of completed modules, changed files, database migrations, any remaining blockers, and checks actually performed.

Prioritize usable, maintainable software over unnecessary complexity.

START IMPLEMENTATION NOW.

বিশেষভাবে যেগুলো নিশ্চিত করেছি
RBAC বাধ্যতামূলক: Backend এবং Frontend উভয় জায়গায় permission কার্যকর হবে।

Existing Code Reuse: ইতিমধ্যে করা কাজ নতুন করে বানাবে না।

No Overengineering: অপ্রয়োজনীয় architecture, package বা complexity যোগ করবে না।

Minimal Testing: অযথা test suite বানাবে না; প্রয়োজনীয় financial ও security verification করবে।

Financial Accuracy: Profit, Loss, Investment, Fund এবং Exit-এর হিসাব সঠিকভাবে সংরক্ষণ করবে।

Historical Data Safety: আগের চার-পার্টনার হিসাব থাকলে সেগুলো নষ্ট করবে না।

আমার পরামর্শ: Claude Code-এ আগে MD ফাইলটি দেবে, তারপর Prompt চালাবে। এতে Claude শুধু ৩ জনের অনুপাত পরিবর্তন করবে না, বরং বিদ্যমান ERP-এর অসম্পূর্ণ অংশগুলোও নির্ধারিত নীতিমালা অনুযায়ী সম্পন্ন করার নির্দেশ পাবে।




The finance and governance modules are written on top of your ClientHub app, but I haven't run the migration or any feature test. The WSL PHP 8.5 has no pdo_sqlite, and I don't have the MySQL root password. SellBees_3_Partner_ERP_Spec.md is still not in the repo, so every rule I used comes from your prompt text.

Checked:

Syntax passed on all new PHP files, every Blade view compiled, and the 34 finance.* routes load.
A direct script run of the splitter gave the expected results. BDT 1,000,000 splits into Salary 200,000, Office 50,000, Emergency 250,000, A 200,000, B 150,000 and C 150,000, and the pieces always sum to the total. A 100-paisa loss splits 34/33/33. 400,000 at B's 30% gives 120,000.
Pint was applied to the changed PHP files.
Not checked: I wrote tests/Feature/FinanceRulesTest.php covering those rules plus preview with no writes, one-time posting, separation of duties and 403s. It has never run. To run it: php artisan migrate && php artisan db:seed, then php artisan test --compact tests/Feature/FinanceRulesTest.php, on a machine with sqlite.

What I added (migration 2026_10_30_100000_create_finance_tables.php, no existing table touched except two new columns on users):

Access control: roles are enforced on the backend. Super Admin manages users and the ClientHub screens but has no financial approval or posting permission.
Whoever records or prepares something can never verify or approve it.
Partners see only their own records, and can't approve or pay their own payout or salary.
Existing ClientHub routes now require the clienthub permission (Super Admin or Finance). Your existing admin keeps working because the new users.role column defaults to super_admin.
Seed: partners A, B, C as active founders, plus a draft "3-Partner Policy".
Investment is A 40, B 30, C 30.
Ownership and loss are equal thirds.
The profit-fund ratio is A 40, B 30, C 30.
The draft has no approval date or signature, so live posting stays blocked until the Board signs the policy and separately confirms the profit ratio. There is no Partner D row, so no D history was touched.
Ledger: a balanced journal that can't be edited or deleted, and one posting per source record.
Capital, expenses and period close: verified capital and expenses, and period close from ClientHub payments in BDT only. Net profit is frozen when a period is closed.
Allocation: prepare, Board approval, then a single post, with a preview that never writes. Losses go through the same flow split equally.
Other workflows: payouts, partner salary from the Salary Fund, employee-increment resolutions, and exit settlement with the incentive calculation.
Screens: finance dashboard, journal with CSV export, audit log, and Users & Roles. They are in the sidebar under "Finance & Governance".
Blockers and gaps:

Missing spec rules: the employee increment formula and the full capital and receivable/liability settlement are not implemented. Increments are only recorded as Board-approved decisions with a previous and new salary. Exit settlement calculates the figures and marks the partner exited, but posts no payment.
Closed periods: ClientHub payments edited after a period closes don't change the stored net profit.
Existing risk, not changed: the existing public /cc route in routes/web.php runs migrate --force with no login. I'd put it behind auth or remove it.
UI: I didn't build a UI for creating partners, or for adding the old Partner D history from a real 4-partner period if you have one.

Claude Code-এর রিপোর্ট অনুযায়ী SellBees ERP-এর Finance & Governance অংশ অনেকটা এগিয়েছে। কিন্তু এখনো ERP-কে কার্যকর বা Production Ready বলা যাবে না, কারণ database migration এবং বাস্তব financial workflow যাচাই হয়নি।

রিপোর্ট এবং আগের নীতিমালা মিলিয়ে পরবর্তী কাজের নির্দেশনা তৈরি করেছি।

১. আগে যে সমস্যাগুলো ঠিক করতে হবে
দুটি গুরুতর Security Issue পাওয়া গেছে:

Public /cc Route: লগইন ছাড়াই database migration চালানো যায়। এটি অবিলম্বে সরাতে হবে।

Super Admin Default: users.role-এর default super_admin রাখা হয়েছে। এতে সাধারণ ব্যবহারকারীও ভুলভাবে সর্বোচ্চ প্রশাসনিক ক্ষমতা পেতে পারে।

এগুলো ঠিক না করে ERP চালু করা উচিত নয়।

অন্যান্য অসম্পূর্ণ কাজ
Module	বর্তমান অবস্থা	পরবর্তী কাজ
RBAC	Implemented বলে রিপোর্ট করেছে	বাস্তবে permissions যাচাই
Investment	Implemented	Database verification
Profit Distribution	Calculation checked	Actual posting verification
Employee Increment	Partial	পুরোনো policy-এর formula যুক্ত
Partner Exit	Partial	Full settlement calculation
Period Closing	Risk আছে	Closed transaction editing বন্ধ
Partner Management	UI নেই	Minimal management screen
Database	Migration হয়নি	Safe migration ও seeding
পুরোনো Policy-এর ৯–১৪ নম্বর পৃষ্ঠায় Employee Increment এবং Partner Exit-এর প্রয়োজনীয় হিসাব রয়েছে। সেগুলো নতুন নির্দেশনায় অন্তর্ভুক্ত করেছি। 


২. Claude Code-এর জন্য নতুন ফাইল
তিনটি ফাইল ব্যবহার করবে।

File 1 — Main ERP Specification


এটি অবশ্যই ERP Repository-এর root directory-তে রাখতে হবে। Claude জানিয়েছে, এই ফাইলটি এখনো Repository-তে নেই।

File 2 — Claude Code Continuation Prompt


এটি আগের কাজের ওপর ভিত্তি করে তৈরি। নতুন Project বা Module আবার তৈরি করতে বলবে না।

File 3 — Remaining Work & Fix Checklist


এখানে Security Fixes, Database, Financial Calculations, Partner Exit এবং Go-Live Requirements সাজানো আছে।

৩. Claude Code-কে এখন যে নির্দেশনা দেবে
প্রথমে Main Specification ফাইলটি Project-এর root-এ রাখবে। এরপর নিচের Prompt চালাবে।

Continue implementing the existing SellBees Finance & Governance ERP.

The previous implementation report confirms that finance modules were written, but migrations and integration tests have not been executed.

READ FIRST:

SellBees_3_Partner_ERP_Spec.md

SELLBEES_ERP_FOLLOWUP_CHECKLIST.md

Existing application code, migrations, routes, and authentication.

Do NOT start a new project.

Do NOT rebuild completed modules.

PRIORITY 1 — SECURITY
Remove the unauthenticated /cc migration endpoint immediately.

Fix the unsafe super_admin default role.

Preserve access for existing authorized administrators.

Verify backend RBAC and partner-specific record permissions.

Enforce separation of duties and prevent self-approval.

PRIORITY 2 — DATABASE
Resolve the migration blocker using the existing application's database configuration.

MySQL root credentials are not required if the configured application database user has sufficient privileges.

Use a safe development database.

Never reset or destroy existing ClientHub data.

Run the required migrations and targeted seeders when database access is available.

PRIORITY 3 — COMPLETE FINANCIAL MODULES
Implement missing functionality according to the specification.

Complete:

Employee theoretical increment calculations.

Board-approved actual increments.

Partner exit capital reconciliation.

Outstanding receivables and liabilities.

Exit incentive calculations.

Exit settlement approval and payment tracking.

Closed-period financial integrity.

Minimal Partner Management interface.

Preserve all implemented ledger, allocation, fund and payout functionality.

PRIORITY 4 — THREE-PARTNER POLICY
Maintain:

Investment:
A 40%, B 30%, C 30%.

Ownership:
Equal thirds.

Loss:
Equal thirds.

Partners' Profit Fund:
A 40%, B 30%, C 30%.

Company Fund:
50% of approved net profit.

Partners' Profit Fund:
50% of approved net profit.

Preserve the remaining fund distribution rules.

Do not activate financial posting under the new policy until the required signed approvals and effective date are recorded.

PRIORITY 5 — VERIFICATION
Avoid overengineering and unnecessary test development.

Use the existing test file where a safe compatible database is available.

Perform essential checks covering:

RBAC authorization

Database migration

Profit distribution

Loss allocation

Financial journal integrity

Duplicate posting prevention

Closed-period protection

Employee increment calculations

Partner exit settlement

DEVELOPMENT RULES
This work is urgent.

Keep the existing Laravel and ClientHub architecture.

Reuse existing working components.

Do not introduce unnecessary packages, abstractions, services, or complexity.

Focus on completing the application.

Do not stop after planning. Make the actual code changes.

At the end, report what was implemented, what was verified, migration status, and remaining blockers.

START WORKING ON THE EXISTING CODEBASE.

৪. Database নিয়ে গুরুত্বপূর্ণ কথা
Claude জানিয়েছে, MySQL root password নেই এবং PHP-তে SQLite driver নেই।

কিন্তু MySQL root password ছাড়াও Laravel migration চালানো সম্ভব, যদি .env-এ কনফিগার করা database user-এর প্রয়োজনীয় permissions থাকে।

তাই এখনই PHP version পরিবর্তন বা নতুন database architecture তৈরির প্রয়োজন নেই।

প্রথমে বিদ্যমান Database Connection যাচাই করতে হবে। সেটি কাজ করলে নিরাপদ development database-এ Migration চালিয়ে Finance Module যাচাই করতে হবে।

আমার পরামর্শ
এখন নতুন feature যোগ করার চেয়ে বিদ্যমান ERP-এর Security, Database এবং অসম্পূর্ণ Financial Workflow কার্যকর করা বেশি জরুরি।

বিশেষ করে Partner Exit, Employee Increment এবং Closed Period-এর হিসাব ঠিক করা প্রয়োজন।

এবারের Prompt-এ Claude-কে স্পষ্টভাবে নির্দেশ দেওয়া হয়েছে—অপ্রয়োজনীয় Test, নতুন Architecture বা Overengineering নয়; আগে বিদ্যমান ERP কার্যকর করতে হবে।





Library
/
ERP
/
SellBees_3_Partner_ERP_Spec.md



SellBees — ৩ জন পার্টনারের Governance, Finance Policy ও ERP Implementation Guide
Version: 2.0 — তিন-পার্টনার খসড়া / ERP implementation reference
Updated: 30 October 2026
Company: SellBees
Audience: Founding Partners, Finance, Board, Claude Code / Developer
Source baseline: পূর্বের চার-পার্টনার SellBees Company Governance & Financial Policy (v1.0) ও SellBees Software Build Plan।
Project mode: Existing ERP-তে যতটুকু কাজ হয়েছে সেটি রেখে দ্রুত প্রয়োজনীয় অংশ সম্পন্ন করা; no rewrite, no overengineering.

গুরুত্বপূর্ণ: এই নথি সফটওয়্যার বাস্তবায়নের জন্য আপডেটেড তিন-পার্টনার খসড়া। মূল চার-পার্টনার স্বাক্ষরিত চুক্তি/আইনি অধিকার থাকলে এটি একা সেগুলো বাতিল করবে না। প্রভাবিত পার্টনারদের লিখিত সম্মতি, কার্যকর হওয়ার তারিখ, প্রয়োজনীয় Board/আইনি অনুমোদন ও সংশোধিত দলিল সংরক্ষণ করতে হবে। নতুন নীতির কার্যকর তারিখের আগের পোস্টেড লেনদেন স্বয়ংক্রিয়ভাবে নতুন অনুপাতে বদলানো যাবে না।

1. নতুন কাঠামোর এক-পৃষ্ঠার সিদ্ধান্ত
1.1 তিনজন প্রতিষ্ঠাতা
বিষয়	Partner A	Partner B	Partner C	সর্বমোট
সম্মত বিনিয়োগের লক্ষ্য/Commitment	40%	30%	30%	100%
Ownership / মালিকানা	1/3 (UI: 33.33%)	1/3 (UI: 33.33%)	1/3 (UI: 33.33%)	100% (exact)
Internal Loss Sharing	1/3	1/3	1/3	100% (exact)
Partners' Profit Fund-এর বণ্টন	40%	30%	30%	100%
মোট নিট মুনাফার সরাসরি অংশ	20%	15%	15%	50%
Profit ratio নিয়ে বাস্তবায়ন ব্যাখ্যা: মূল চারজনের লাভের হার ছিল A:B:C:D = 40:20:20:20। তিনজন করার সময় আগের প্রধান পার্টনারের 40% অপরিবর্তিত রেখে বাকি 60% দুইজনের মধ্যে সমান ভাগ করলে নতুন A:B:C = 40:30:30 হয়। ব্যবহারকারীর নতুন 40/30/30 বিনিয়োগের নির্দেশের সঙ্গে এই হিসাব মিলে যায়, কিন্তু বিনিয়োগ ও লাভের অধিকার স্বতন্ত্র। এই ফাইলের ERP target হিসেবে লাভের অনুপাত 40:30:30 ধরা হয়েছে; চূড়ান্ত অর্থ বণ্টন চালুর আগে লিখিত নীতিতে Profit Ratio আলাদাভাবে নিশ্চিত করতে হবে। কোনো সিস্টেম যেন বিনিয়োগের হার দেখে অটোমেটিক Profit Ratio সেট না করে।

Ownership rounding: 33.33 + 33.33 + 33.33 = 99.99 শুধু দশমিকের প্রদর্শনজনিত সমস্যা। ERP-তে 1/3 করে তিনটি সমান শেয়ার/ইউনিট (যেমন ownership_units=1 প্রতিজন, total=3) রাখতে হবে; UI-তে 33.33% দেখাবে, মোট 100%। কোনো একজনকে প্রকৃতপক্ষে 33.34% মালিক করা যাবে না, যদি তিনজন সমান মালিক হওয়ার চুক্তি থাকে। হিসাবের টাকার সবচেয়ে ছোট rounding remainder নির্ধারিত deterministic rule দিয়ে সমন্বয় হবে।

1.2 Investment ≠ Ownership ≠ Profit ≠ Salary
Investment commitment 40:30:30 মোট লক্ষ্য বিনিয়োগের ভাগ বোঝায়; বাস্তবে জমা হওয়া টাকা আলাদা Investment Ledger-এ পরিমাণ, তারিখ, প্রমাণসহ রাখতে হবে। Payment না করলে অবদান হয়েছে—এমন ধরে নেওয়া যাবে না।

Ownership 1/3 করে সমান; অতিরিক্ত বিনিয়োগ করলেই স্বয়ংক্রিয়ভাবে মালিকানা বাড়ে না।

Profit Share 40:30:30 of Partners' Fund (working agreed target); মালিকানা অনুপাতের সঙ্গে গুলিয়ে ফেলা যাবে না।

Loss Sharing সমান 1/3 করে; মূল নীতির সমান-ভাগে লোকসান বহনের ধারণা বজায় থাকবে।

Salary/Remuneration দায়িত্ব ও অনুমোদনের ওপর নির্ভর করবে; মালিকানা বা লাভের ভাগ অনুযায়ী স্বয়ংক্রিয় নয়।

Partner A/B/C হলো placeholder — বিদ্যমান সিস্টেমের আসল পার্টনার আইডি/নামের সঙ্গে প্রশাসনিকভাবে ম্যাপ করতে হবে; ID hardcode করা যাবে না।

2. বিনিয়োগ, মূলধন এবং লোকসানের হিসাব
2.1 Target capital example
যদি মোট লক্ষ্য মূলধন ৳10,00,000 হয়, commitment হবে:

পার্টনার	Target %	Target Amount
A	40%	৳4,00,000
B	30%	৳3,00,000
C	30%	৳3,00,000
এই টেবিল actual paid-in balance নয়। বাস্তব জমা, পরবর্তী মূলধন যোগ, অনুমোদিত মূলধন ফেরত, সংশোধন, লোকসান আলাদাভাবে ledger entry-তে রাখতে হবে।

2.2 Investment ledger
সর্বনিম্ন ক্ষেত্র: partner_id, transaction_date, type (capital_in / capital_return / adjustment / allocated_loss), amount, currency=BDT, status, reference, attachment, created_by, approved_by, posted_at, policy_version। প্রয়োজনে বিদ্যমান স্কিমার নাম ব্যবহার করবে।

Capital transaction lifecycle: draft → submitted → approved → posted; ভুল হলে reversed/offset entry, posted amount edit/delete নয়। বিনিয়োগ থেকে কোনো ক্যাশ খরচ হলেই পার্টনারের মালিকানা কমে যায় না।

2.3 লোকসান ভাগ
Approved Internal Company Loss = L
A Loss = L / 3
B Loss = L / 3
C Loss = L / 3
উদাহরণ: L=৳2,00,000 হলে যথাক্রমে ৳66,666.67, ৳66,666.67, ৳66,666.66 (paisa rounding; তিনজনের হিসাব exact 100%)। সমান দায়ের চুক্তি থাকবে; ১ পয়সার rounding allocation ব্যক্তির ownership % বদল নয়।

যাচাইকৃত লোকসান ও Board অনুমোদন ছাড়া কারও capital balance কমানো যাবে না।

Loss allocation এবং positive profit distribution আলাদা workflow।

একই ক্ষতি দুবার সমন্বয় নয়; source record/period reference রাখতে হবে।

অভ্যন্তরীণ লোকসান বণ্টন তৃতীয় পক্ষের কাছে আইনগত দায়ের বিকল্প নয়।

3. মুনাফা নির্ধারণ এবং ৫০/৫০ বণ্টন — আগের মতোই
3.1 Distributable profit
Net Profit = Recognized Business Revenue
           - Recognized Operating Expenses
           - Recognized Salaries
           - Applicable Taxes
           +/- Approved Financial Adjustments
শুধু verified + approved + closed accounting period-এর positive net profit বণ্টনযোগ্য হবে। Net Profit <= 0 হলে positive allocation করা যাবে না। একই salary/expense P&L এবং ফান্ড ব্যয়ে দুবার বাদ যাবে না। পূর্বের ফান্ড ব্যালেন্সকে নতুন মুনাফা হিসেবে পুনরায় ভাগ করা যাবে না।

3.2 Profit waterfall
Distributable Net Profit P = 100%
├─ Company Fund = 50% of P
│  ├─ Growth Fund = 50% of Company Fund = 25% of P
│  │  ├─ Salary Fund = 80% of Growth = 20% of P
│  │  └─ Office & Business Growth Fund = 20% of Growth = 5% of P
│  └─ Emergency Reserve Fund = 50% of Company Fund = 25% of P
└─ Partners' Profit Fund = 50% of P
   ├─ Partner A = 40% of Partners' Fund = 20% of P
   ├─ Partner B = 30% of Partners' Fund = 15% of P
   └─ Partner C = 30% of Partners' Fund = 15% of P
Check only leaf buckets: Salary 20% + Office/Growth 5% + Emergency 25% + A 20% + B 15% + C 15% = 100%। Company Fund এবং Growth Fund parent grouping — child-এর সঙ্গে আবার sum করলে double count হবে।

3.3 উদাহরণ: P = ৳10,00,000
Allocation	Net Profit-এর ভাগ	Amount
Salary Fund	20%	৳2,00,000
Office & Business Growth Fund	5%	৳50,000
Emergency Reserve Fund	25%	৳2,50,000
Partner A Entitlement	20%	৳2,00,000
Partner B Entitlement	15%	৳1,50,000
Partner C Entitlement	15%	৳1,50,000
Total	100%	৳10,00,000
তাই Company Fund = ৳5,00,000 এবং Partners' Fund = ৳5,00,000। Salary Fund-এর বরাদ্দ মানেই কর্মীকে নগদ বেতন পরিশোধ নয়। Profit entitlement মানেই partner-এর ব্যাংকে টাকা পাঠানো নয়; payable এবং payout পৃথক হবে।

3.4 Profit allocation workflow
Finance period-এর income/expense ledger reconcile করবে।

Period close + approved distributable profit তৈরি হবে।

Preview-তে নতুন তিন-পার্টনার ratios, Company Fund, leaf funds ও প্রত্যেকের প্রাপ্য দেখা যাবে; preview কোনো balance বদলাবে না।

Authorized Board approver approve করবে; প্রয়োজনীয় policy_version, period_id, approval_ref ফিক্স হবে।

একটি database transaction-এ একবারই final allocation journal, fund entries ও partner payables পোস্ট হবে। Same period ও policy-তে accidental double-post blocked।

Partner payout আলাদা authorized disbursement workflow-তে হবে; তার নিজের pending payout সে নিজে approve করতে পারবে না।

ভুল posted transaction সংশোধন করতে reversal এবং approved correction; history rewrite নয়।

4. Company Fund — আগের নিয়ম অপরিবর্তিত
Growth Fund: Company Fund-এর 50%; Salary 80%, Office/Growth 20%।

Salary Fund: পার্টনার/কর্মী বেতন ও অনুমোদিত remuneration বাজেটের জন্য সংরক্ষিত।

Office & Business Growth: অফিস, প্রযুক্তি, সফটওয়্যার, যন্ত্রপাতি, কার্যক্রম সম্প্রসারণ; অনুমোদন ছাড়া ব্যক্তিগত ব্যয় নয়।

Emergency Reserve: জরুরি ব্যবসায়িক প্রয়োজনে যথাযথ অনুমোদনের ভিত্তিতে ব্যয়; partner distribution নয়।

প্রতিটি leaf fund-এ Opening, Allocation, Transfer In, Transfer Out, Disbursement, Closing দেখাবে।

Fund debit/transfer একটি approved voucher/journal থেকে হবে; manual fund.balance = ... নয়।

Real cash/bank balance, fund earmarking এবং owed partner payable আলাদা UI হিসাব।

5. Partner Salary & Employee Increment — আগের নিয়ম অপরিবর্তিত
5.1 Partner salary
প্রাথমিক পর্যায়ে প্রতিষ্ঠাতা পার্টনারদের বেতন বাধ্যতামূলক নয়। Board দায়িত্ব, কাজের চাপ, সময়, performance, cash position দেখে পৃথক remuneration approve করতে পারবে। Ownership equal হলেও salary equal হওয়া বাধ্যতামূলক নয়। নিজের salary নিজে approve নয়।

5.2 Employee increment calculation
Annual Profit Rate (%) = (Available Annual Net Profit / Original Total Investment) × 100

80% <= Rate <= 100%:
Theoretical Increment (%) = 10 + ((Rate - 80) / 20) × 10

Rate > 100%:
Theoretical Increment (%) = 20 + ((Rate - 100) / 100) × 5

Rate < 80%:
No theoretical increment defined by the formula; do NOT auto-approve an increase.
80%→10%, 90%→15%, 100%→20%, 150%→22.50%, 200%→25%, 300%→30%। Theoretical calculation guaranteed salary increment নয়। Board final budget এবং কর্মীভিত্তিক approved rate নির্ধারণ করবে। আগের উদাহরণে theoretical 20% হলেও কর্মী A=15%, B=10%, C=8%, D=0% হতে পারে — এখানে Employee A-D প্রতিষ্ঠাতা পার্টনারের সংখ্যা নয়; কর্মীর সংখ্যা তিনে নামানোর প্রয়োজন নেই।

Salary update কেবল approved effective date-এ হবে। current_salary, theoretical_rate, approved_rate, approved_by, effective_date এবং notification/letter record রাখবে।

6. Partner Exit & Incentive — তিনজনের জন্য আপডেট
6.1 Exit request + verification
কোনো পার্টনার exit চাইলে লিখিত request, capital ledger, loss adjustments, previous withdrawals, outstanding approved profit/salary entitlement, liability, এবং Company Fund-এর last 12 months approved allocation verify করতে হবে।

Adjusted Investment Balance
  = Recorded Investment
  - Unadjusted Approved Allocated Loss
  - Applicable Unadjusted Approved Expenses/Liabilities
  - Previous Capital Withdrawals

Exit Incentive
  = Verified NEW Company Fund Profit Allocations during last 12 months
    × Exiting Partner's PROFIT ratio effective for the applicable period

Final Settlement
  = Adjusted Investment Balance
  + Exit Incentive
  + Unpaid Approved Entitlements
  - Other Verified Outstanding Liabilities
1/3 Ownership দিয়ে incentive নয়; Profit Ratio A40/B30/C30 দিয়ে হিসাব হবে (signed policy ও effective period অনুযায়ী)।

Company Fund balance নয়, শুধু trailing-12-month period-এ নতুন approved allocation গণনা।

ইতিমধ্যে মূলধন থেকে deducted loss বা আগে paid entitlement দ্বিতীয়বার হিসাব নয়।

Exit incentive কোম্পানি ফান্ডে charge হবে, নির্দিষ্ট sub-fund নির্বাচন accounting/Board-এর approved mapping অনুযায়ী।

Exit approval, signed settlement, payment schedule এবং applicable share/ownership transfer legal documents বাধ্যতামূলক। ERP-তে শুধু একটি exit button দিয়ে legal transfer সেরে ফেলা যাবে না।

Effective exit ও legal transfer সম্পন্ন হলে ঐ ব্যক্তি ভবিষ্যৎ profit run-এ অন্তর্ভুক্ত হবে না; prior receivable/obligation থাকবে।

6.2 তিনজনের উদাহরণ
Partner B exit; recorded investment ৳2,50,000, approved unadjusted loss/expense ৳30,000, withdrawal ৳0 → adjusted capital ৳2,20,000। Last 12 months verified Company Fund allocation ৳4,00,000 হলে B-এর incentive 30% × 4,00,000 = ৳1,20,000। অন্য পাওনা বা দায় না থাকলে settlement = ৳3,40,000। (আগের চার-পার্টনার নথির B=20% ধরে ৳3,00,000 উদাহরণটি বর্তমান তিন-পার্টনার নীতিতে আর ব্যবহার হবে না।)

7. Board, approval, policy versioning ও audit
Board income/expense verification, profit run, fund disbursement, salary, increment, partner exit এবং extraordinary loss/capital adjustments approve করবে।

প্রতিটি approval-এ requester, approver, timestamp, status, reason/note, document reference থাকবে।

কোনো ব্যক্তি নিজের payout/অতিরিক্ত বেতন/নিজের সংশ্লিষ্ট request নিজে final approve করতে পারবে না।

Ownership, loss ratio, partner profit ratio, exit rights মৌখিক নির্দেশ/সাধারণ Settings edit দিয়ে পরিবর্তন নয়। সংশ্লিষ্ট পার্টনারের লিখিত সম্মতি, প্রয়োজনীয় আইনি অনুমোদন ও effective_from-সহ নতুন policy_version লাগবে।

Historical policy snapshot: closed/posted period নিজের সময়কার ratios ও rule version ধরে রাখবে। নতুন তিনজনের policy এলে পুরোনো চারজনের posted journals পরিবর্তন হবে না।

Business rules-এর operational settings admin panel-এ থাকতে পারে, কিন্তু fixed formula edit option থাকবে না; amendment flow ছাড়া change নয়।

নতুন policy signed & active না হলে calculation preview সম্ভব, final new-policy financial posting gated থাকবে।

8. ন্যূনতম ERP Scope (existing project reuse)
প্রধান কাজ: পুরোনো চার-পার্টনার accounting policy সংশোধন করে existing codebase-এ তিন-পার্টনার finance/governance ERP ব্যবহারযোগ্য করা। এটা পূর্ণ e-commerce, warehouse, courier, CRM বা নতুন platform rewrite project নয়।

Priority	Module	Must-have
P0	Existing repository audit	Stack/routes/models/schema/auth/current modules বোঝা, completed portions reuse
P0	Authentication + RBAC	Server/API permission enforcement + frontend navigation restrictions
P0	Organization/Policy	Active 3 partners, policy version/effective date, ratios stored separately
P0	Partner & Capital	Partner list, commitment, actual contributions, capital transactions/ledger
P0	Income / Expense	Entry, approval, attachment/reference, financial period totals
P0	Accounting/Postings	Balanced immutable posted journals (or project-equivalent ledger), reversal, totals
P0	Profit & Period Close	Net-profit approval, preview, single commit, 50/50 fund allocation, 40/30/30 entitlements
P0	Dashboard & Reports	Net profit, cash vs allocated fund, capital, partner payable, period summaries
P1	Fund Spending/Payouts	Approval, fund disbursement, partner payout, fund balances
P1	Board Approvals	Pending list, approve/reject, approval log, supporting document
P1	Salary & Increment	Board-approved salary, theoretical/actual increment + effective date
P1	Partner Exit	Exit request, settlement preview, last-12-month incentive, approval, payment tracking
P1	Exports	Existing PDF/CSV export reuse where available; not a new report builder
Explicitly out of scope for this urgent iteration: multi-company/multi-tenant engine, microservices, event bus, Kubernetes, heavy generic workflow builder, custom permissions DSL, complex tax engine, auto bank integrations, mobile apps, AI features, full e-commerce/stock/logistics module, speculative refactors। Existing working modules যেন না ভাঙে।

9. RBAC — আবশ্যিক এবং practical
বিদ্যমান auth/permission system থাকলে সেটিই reuse করবে। দরকার না হলে নতুন role package আনবে না। Backend-এ route/controller/service authorization এবং record-level scope enforce করবে; frontend-এ menu hiding শুধু user experience, security নয়।

Role	প্রয়োজনীয় ক্ষমতা	সীমাবদ্ধতা
SUPER_ADMIN	Users/roles, org setup, technical configuration, policy document upload	Approval ছাড়াই posted journal, fund, profit, own payout edit করতে পারবে না
BOARD_MEMBER	Approval queue, report, profit-run/fund/exit/remuneration approve or reject	Own request self-approve নয়; policy change signed docs ছাড়া নয়
FINANCE	Income/expense & investment entry, reconciliations, profit preview, payout request, reports	Own submission final approve নয়; posted entry overwrite নয়
HR	Employee directory, salary/increment draft, approved employee details	Finance capital/partner profit পরিবর্তন নয়
PARTNER	Own capital, ownership %, own profit entitlement/payout, own exit request, published board decisions	Other partners' private data বা approvals access role ছাড়া নয়
EMPLOYEE	Own profile, approved salary/increment information	Other employee pay, finance/Board records নয়
AUDITOR	Authorized read-only ledgers, logs, exports	Write/post/approve নয়
Minimum permission groups: users.manage, roles.manage, partners.view, partners.manage, capital.view, capital.create, capital.approve, transactions.view, transactions.create, transactions.approve, period.close, profit.preview, profit.approve, profit.post, funds.view, funds.request, funds.approve, payouts.request, payouts.approve, board.manage, salary.manage, increments.manage, increments.approve, exits.request, exits.approve, reports.view, audit.view। Existing naming/pattern follow করবে; এগুলো exact DB seed list হওয়ার বাধ্যবাধকতা নেই।

Security checks: unauthenticated→401, no permission→403; partner-scoped records query conditions enforce; approval/self-approval check; posted finance records read-only; never rely on frontend percentages submitted by client.

10. Existing ERP কীভাবে patch করতে হবে — Claude Code-এর জন্য HOW-TO
Step 1 — Understand, don't rewrite
Repo root-এ existing README, lockfiles, app routes, migration, models, services, auth/RBAC, policies, UI pages, seed data এবং current progress (git status) inspect করবে। প্রকৃত stack যা আছে সেটি reuse: Laravel/Next.js থাকলে সেটাই, অন্য stack হলে সেটাই। শুধু কোথায় 4-partner assumption আছে তা খুঁজবে।

Look for: Partner D, partner_d, four, 4 founders, 25/25/25/25, 40/20/20/20, 25%, 2500 bps, 2000 bps, old seed fixtures, hardcoded dashboard/cards, forms/report/export, exit incentive, loss allocation, employee seed vs partner seed.

Step 2 — Inventory & map old data
Existing module/page কে done, partially done, missing, broken হিসেবে ছোট টেবিলে note করো।

Existing three actual partner records চিহ্নিত করো; seeded Partner D historical data থাকলে delete নয়।

New active policy start-এর আগে চারজনের বাস্তব historical transactions থাকলে preserved historical read-only; পুরোনো period নতুন অনুপাতে re-run করবে না।

যদি data কেবল dev dummy fixture হয়, migration/seed responsibly update; development DB reset only when safe/explicit, production ledger নয়।

Step 3 — Update policy model/config, not scattered magic numbers
Single active policy version + effective date। Fields/pattern already থাকলে reuse; না থাকলে minimally add:

partners: id, name, status (ACTIVE/EXITED/INACTIVE), ownership_units (A/B/C = 1 each)
partner_capital_commitments: partner_id, target_percent (40/30/30), approved_target_amount?
partner_profit_ratios: partner_id, policy_version_id, profit_percent (40/30/30)
partner_loss_rules: group/equal-share OR explicit 1/3 per active partner under policy
policy_versions: id, name, effective_from, approval_status, approval_doc, is_active
partners বা policy_versions টেবিল বিদ্যমান থাকলে duplicate table বানাবে না। Ownership fraction-এর জন্য existing decimal/units scheme adapt করো যাতে তিনজন equal এবং sum exactly 100%। Financial historical entries must reference applicable policy_version_id/rate snapshots.

Step 4 — Database changes safely
Only necessary migrations, no destructive drop। Legacy partner_d hardcodes, 4-length arrays, foreign keys, dashboards, exports, period calculations, fixtures, API validators এবং policy snapshots patch। Existing posted journals immutable। App currently supports users/roles? reuse middleware/policies; else minimum RBAC tables/middleware/seed।

Step 5 — Financial services, sequential delivery
Implement/reuse minimum services (actual project naming as appropriate):

CapitalService: approved investment entry and capital ledger.

FinanceService: verified income/expense and period net profit.

ProfitAllocationService: preview/approve/post once using policy-effective ratios and approved period.

FundService: leaf fund balances, spend/transfer approval, no double count.

PartnerPayoutService: payable vs actual cash payout, approval/audit.

IncrementService: theoretical annual rate, Board-approved actual rate.

PartnerExitService: exit settlement + last 12-month approved allocation + ratios.

Keep code close to existing architecture. Don't invent abstract factory/repository layers when simple services already work.

Step 6 — UI only what's needed
Minimal, readable existing design: Dashboard, Partners & Investments, Income/Expense, Ledger, Profit Close/Allocation, Funds, Payout Requests, Approvals, Employees/Increment, Partner Exit, Users/Roles, Reports। Existing pages may be renamed/extended; don't rebuild layouts unnecessarily।

Useful dashboard cards: current period revenue, expense, net profit, cash balance (if ledger provides), Company/Salary/Office/Emergency allocations, partner payables, capital received, pending approvals. Always separate actual cash and allocated reserves.

Step 7 — Real permission and posting safeguards
All financial POST endpoints validate amount>0, currency BDT, period, policy effective date, authorization, approvals, no duplicate posting.

Posted journals and allocations are append-only; correction via reversal.

All journal groups balanced; changes and approval/posting run atomically.

No float for money; store integer paisa or existing reliable fixed-point decimal. Rounding residual deterministic so leaf allocations total original amount exactly.

No one approves own related payout/salary or bypasses document-backed policy activation.

Frontend-only role checks do not count as RBAC.

Step 8 — Urgent verification only; no test project
Do not spend time generating large test suites, factories, 100-page QA plans, or speculative edge-case harnesses. Only run existing quick lint/typecheck/build if feasible, and manual smoke for: login + role 403, 3 active partners, ownership display, investment commitment totals, positive profit example, loss split exact total, preview not posted, approval then single posting, repeat post blocked, B exit incentive example, legacy entries unchanged. Do not ignore financial rounding or authorization errors just to avoid testing.

Step 9 — Delivery report
Claude Code শেষে সংক্ষিপ্ত রিপোর্ট দেবে: changed file paths, migrations run/not run, implemented/left incomplete, business-rule assumptions, any approval-required blockers, actual quick verification run, and next minimum action. Future nice-to-have নয়, working ERP first।

11. Minimal workflow (user-facing)
Workflow A: investment
Finance enters contribution → attaches proof → Board/authorized approval → journal post → partner capital balance update → dashboard reflects actual paid funds। Commitment remaining = agreed target − posted actual contribution; negative if over-contributed can be shown but does not auto-update shares.

Workflow B: monthly profit
Finance records revenue/expense → closes/reconciles month → Board approves net profit → previews fund split → Board approves distribution → one posted allocation → A/B/C payable ledger → individual approved payout।

Workflow C: emergency/office/salary fund spending
Request from permitted role → reason + amount + supporting document → Board/assigned approver → sufficient earmarked fund check → approved journal/cash payment → fund register।

Workflow D: yearly increment
Finance confirms 12-month net profit and original investment → system calculates theoretical rate → HR drafts employee rates → Board approves budget/rates/date → approved employee salary changes on effective date।

Workflow E: partner exit
Partner submits written request → finance verifies capital, losses, last-12-month company allocation, pending payable → settlement preview → Board approval and signed documents → scheduled payment journal(s) → legal transfer confirmation → status EXITED।

12. Required accounting registers (৯টি আগের মতোই)
Investment Ledger — মূল ও পরবর্তী capital paid-in।

Capital Adjustment Ledger — loss, liability, capital return, correction।

Profit Distribution Ledger — period-wise approved A/B/C allocations, payouts, balances।

Company Fund Ledger — Company Fund grouped allocation, spending, balance।

Growth & Salary Fund Ledger — Growth parent, Salary এবং Office leaf allocations।

Emergency Reserve Ledger — reserve allocation/spending/closing।

Employee Increment Register — theoretical and Board-approved actual changes।

Partner Exit Register — exit, capital settlement, incentive, payment & legal docs।

Board Resolution Register — verified approvals and policy amendments।

Existing unified ledger থেকে filtered report করলে আলাদা ৯টি physical DB table বানানো বাধ্যতামূলক নয়। সঠিক রিপোর্ট ও traceability থাকলেই হবে।

13. Three-partner update checklist (search & replace-এর বাইরে)
Active founder count = 3 everywhere (company settings, cards, partner directory, summary, API validation, print/export).

A/B/C mapped to actual IDs; D historical row preserved or inactive, not in new allocations.

Target investment = 40/30/30, actual investment entries separate.

Ownership = exact 1/3 each, UI 33.33 each and total 100% exact.

Loss = 1/3 each; deterministic paisa rounding.

Partner Profit Fund split = 40/30/30 (separate signed approval of this assumption).

Net-profit split Company 50%, Partners 50% unchanged.

Company→Growth/Emergency 50/50 unchanged.

Growth→Salary/Office 80/20 unchanged.

Exit incentive uses new applicable Profit Ratio, trailing 12-month new Company allocations.

Partner salary and employee increment formula unchanged.

Employee count NOT tied to founder count (original Employee D remains valid).

RBAC enforced on server/backend and record level.

Preview has no financial side effects; approved posting cannot occur twice.

Existing posted history is never rewritten to the new ratios.

Policy effective date, written consent, supporting documents attached before live new-policy posting.

14. Quick calculation reference
Commitment (planned): A40%, B30%, C30%
Ownership (legal/equity): A1/3, B1/3, C1/3 (display 33.33% each)
Internal Loss: A1/3, B1/3, C1/3
Profit Ratio (of Partners Fund): A40%, B30%, C30% [written confirmation needed]

For approved P > 0:
Company       = 50% * P
Partners      = 50% * P
Growth        = 25% * P
Salary        = 20% * P
OfficeGrowth  =  5% * P
Emergency     = 25% * P
Partner A     = 20% * P
Partner B     = 15% * P
Partner C     = 15% * P

Leaf total = Salary + OfficeGrowth + Emergency + A + B + C = P

For approved L > 0:
Loss A + Loss B + Loss C = L, exact (equal 1/3 each before rounding)

Exit incentive = sum(verified newly allocated Company Fund in trailing 12 months)
               * applicable partner profit ratio, never ownership ratio
15. Development handoff / order of operations
First: existing code inspection + RBAC + 3-partner policy + safe migration।
Second: income/expense/capital journal + month close + exact profit waterfall + fund/partner payable।
Third: approvals/payout + Board register + reports।
Fourth: increment, remuneration, exit and final smoke।

Each completed task should produce working endpoints + working UI using existing patterns (not only design documents). Avoid speculative refactor and unnecessary packages. A blocker that can cause wrong money movement, unauthorized access, or altered historical records is high priority even under urgent delivery.

Go-live gate: signed three-partner policy, effective date, approved separate 40/30/30 Profit Fund ratio, owner/partner ID mapping, verified opening balances and authorized approver configuration. Without these, development/preview may continue but actual new-policy monetary posting must remain blocked.

