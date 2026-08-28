<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds URL slugs that are guaranteed not to collide with an existing row.
 *
 * Every admin controller used to do `Str::slug($name) ?: Str::slug(Str::random(8))`
 * and hand the result straight to create(). Two products named "Netflix Gift" both
 * slugify to "netflix-gift", so the second save hit the unique index and surfaced to
 * the operator as a raw 500 — no message, no hint that the name was the problem.
 * Appending -2, -3, ... turns that into a save that just works.
 */
class SlugGenerator
{
    /**
     * @param  string    $source     Human-readable name/title to slugify.
     * @param  string    $table      Table holding the unique index.
     * @param  int|null  $ignoreId   Row being updated, excluded from the collision check.
     */
    public static function unique(string $source, string $table, ?int $ignoreId = null, string $column = 'slug'): string
    {
        // Chinese names slugify to an empty string — there is nothing ASCII to keep.
        // Those get a random base rather than colliding with every other CJK name.
        $base = Str::slug($source);

        if ($base === '') {
            $base = Str::lower(Str::random(8));
        }

        $base = Str::limit($base, 90, '');
        $candidate = $base;

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            if (!self::exists($table, $column, $candidate, $ignoreId)) {
                return $candidate;
            }

            $candidate = $base . '-' . $suffix;
        }

        // Pathological case only (1000 rows sharing a name). Random keeps the save
        // alive instead of throwing after the operator already filled in the form.
        return $base . '-' . Str::lower(Str::random(6));
    }

    private static function exists(string $table, string $column, string $value, ?int $ignoreId): bool
    {
        $query = DB::table($table)->where($column, $value);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
