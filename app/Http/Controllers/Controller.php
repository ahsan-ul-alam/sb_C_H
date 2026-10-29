<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

abstract class Controller
{
    /**
     * Run a business action; rule violations (RuntimeException) are shown as a flash error.
     */
    protected function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('status', $success);
    }
}
