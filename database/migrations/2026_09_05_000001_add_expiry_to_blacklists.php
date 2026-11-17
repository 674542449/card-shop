<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给黑名单加「有效期」和「来源」。
 *
 * 之前黑名单只有永久封禁一种（管理员手动加）。蜜罐要自动封扫描器的 IP，而自动封
 * 必须能过期——否则一次误判就是永久拉黑一个真实用户（比如攻击者用 <img src> 骗受害
 * 者的浏览器去请求 /.env，被封的是受害者）。expires_at 到期即失效，把误判的代价从
 * 「永久」降到「几天」。source 区分手动封（永久）和蜜罐自动封，管理员一眼能分清，
 * 也让蜜罐刷新过期时间时不会误伤手动封的永久记录。
 *
 * 两列都可空、向后兼容：现有的手动封记录 expires_at 为 null = 永久，行为不变。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('reason');
            $table->string('source', 20)->nullable()->after('expires_at');
            // isBlocked / CheckBlacklist 查询会带 expires_at 过滤，给它一个索引。
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'source']);
        });
    }
};
