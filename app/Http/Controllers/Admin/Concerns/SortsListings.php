<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** ?sort=<key>&dir=asc|desc on admin lists, limited to the columns each list allows; otherwise the list's own order. */
trait SortsListings
{
    /** @param  array<string, string>  $columns  sort key => database column */
    protected function sorted(Builder $query, Request $request, array $columns, callable $default): Builder
    {
        $key = (string) $request->query('sort');
        if (! array_key_exists($key, $columns)) {
            return $default($query);
        }

        return $query->reorder()->orderBy($columns[$key], $request->query('dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');
    }
}
