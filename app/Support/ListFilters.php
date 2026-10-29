<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Remembers the query string of each list page so breadcrumbs and back links
 * return the admin to the same search and filters.
 */
class ListFilters
{
    public static function remember(Request $request, string $list): void
    {
        $request->session()->put("list_filters.{$list}", $request->query());
    }

    /**
     * URL of a list route with its remembered filters applied.
     */
    public static function url(string $list): string
    {
        return route("{$list}.index", session("list_filters.{$list}", []));
    }
}
