<?php

return [

    /*
    | The ledger, period close and allocations are in this single currency (integer paisa).
    | ClientHub payments in other currencies are excluded from net profit.
    */
    'currency' => env('FINANCE_CURRENCY', 'BDT'),

    /*
    | Real-time posting cutover (YYYY-MM-DD, normalised to the first day of a month). From this month on, customer
    | payments, refunds and advance/recognition entries are journaled the moment they happen and period close no longer
    | books them again. Earlier months keep the original behaviour (booked once, at period close). Leave empty to keep
    | the original behaviour everywhere. Set it only after reviewing `php artisan finance:reconcile`.
    | Safety: once anything has been journaled in real time the cutover cannot move later, and it can never be earlier
    | than the month after the last period already booked at close.
    */
    'realtime_from' => env('FINANCE_REALTIME_FROM'),

    /*
    | Which profit the monthly close freezes and the partner allocation distributes.
    |   legacy     (default) — customer income minus verified non-asset expenses at their spending date. Other income is NOT
    |                distributed and a prepaid expense counts in full when paid. This is what every existing period used.
    |   accounting — the dashboard's accounting profit: adds other income and spreads prepaid costs over the months they serve.
    | Changing this changes distributable profit for FUTURE periods only (closed periods are frozen), so it needs an explicit
    | Board decision before it is switched.
    */
    'distributable_basis' => env('FINANCE_DISTRIBUTABLE_BASIS', 'legacy'),

    /*
    | Optional income-tax rate in basis points (2500 = 25%). Used only for the "estimated tax" memo line on the profit and loss;
    | nothing is posted from it. Actual tax paid is recorded as a "Tax" expense. Leave empty when no tax estimate is wanted.
    */
    'tax_rate_bps' => env('FINANCE_TAX_RATE_BPS'),

    /*
    | First month (YYYY-MM) from which a customer receipt that nobody has classified (service, product sourcing, other) blocks the
    | close and verification of its month. A receipt classified as product sourcing without a sourcing record blocks its month
    | regardless of this setting. The default is the month the ledger began (2026-10), so that no month can be closed on top of
    | unexplained receipts by default; classify the receipts under Finance > Financial Completeness. Set the variable to an empty
    | value to only REPORT unclassified receipts (the automated test suite does this).
    */
    'completeness_from' => env('FINANCE_COMPLETENESS_FROM', '2026-10'),

];
