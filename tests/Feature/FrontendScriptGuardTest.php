<?php

/*
| Regression guard for a defect the browser found: a form with a field named "method" (capital, supplier payment, customer payment) shadows form.method,
| so `form.method.toLowerCase()` threw "is not a function" and the shared AJAX handler (confirmation dialog, duplicate-click guard) was skipped.
*/
it('never reads form.method as a property in the shared submit script', function () {
    $js = file_get_contents(public_path('js/sellbees.js'));

    expect($js)->not->toMatch('/\bform\.method\b/')->and($js)->toContain("form.getAttribute('method')");
});
