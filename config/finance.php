<?php

return [

    /*
    | The ledger, period close and allocations are in this single currency (integer paisa).
    | ClientHub payments in other currencies are excluded from net profit.
    */
    'currency' => env('FINANCE_CURRENCY', 'BDT'),

];
