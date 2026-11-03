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
    */
    'realtime_from' => env('FINANCE_REALTIME_FROM'),

];
