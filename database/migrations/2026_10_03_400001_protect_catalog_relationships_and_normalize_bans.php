<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products' => ['category_id', 'categories'], 'articles' => ['article_category_id', 'article_categories']] as $table => [$column, $parent]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            });
        }
        // Preserve every rule and expiry; only normalize equivalent textual IPs.
        DB::table('blacklists')->where('type', 'ip')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $packed = @inet_pton($row->value);
                if ($packed === false) { continue; }
                if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") { $packed = substr($packed, 12); }
                $value = inet_ntop($packed);
                if ($value === $row->value) { continue; }
                DB::table('blacklists')->where('id', $row->id)->update(['value' => $value]);
                try {
                    foreach ([$row->value, $value] as $key) Cache::store('redis')->forget('blacklist:ip:'.md5($key));
                } catch (\Throwable) { /* Caches expire independently when Redis is unavailable during deployment. */ }
            }
        });
    }

    public function down(): void
    {
        foreach (['products' => ['category_id', 'categories'], 'articles' => ['article_category_id', 'article_categories']] as $table => [$column, $parent]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
            });
        }
    }
};
