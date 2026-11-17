<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Consistent limits for the paginated admin tables and their API callers. */
final class AdminListQuery
{
    public static function pageSize(Request $request, int $default = 20, array $filters = []): int
    {
        $request->validate(array_merge([
            'page' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|min:1|max:200',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ], $filters));

        return (int) $request->input('per_page', $request->input('pageSize', $default));
    }
}
