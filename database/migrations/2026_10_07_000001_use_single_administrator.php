<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retain the established administrator's credentials and audit references.
        // Never promote all former staff to unrestricted administrators.
        $primary = DB::table('admins')->where('is_active', true)->where('role', 'owner')->orderBy('id')->value('id');
        $retired = DB::table('admins');
        if ($primary !== null) { $retired->where('id', '<>', $primary); }
        $retired->update(['is_active' => false]);
        Schema::table('admins', fn (Blueprint $table) => $table->dropColumn(['role', 'permissions']));
        DB::statement('CREATE UNIQUE INDEX admins_single_active ON admins ((is_active)) WHERE is_active = TRUE');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admins_single_active');
        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 20)->default('owner');
            $table->json('permissions')->nullable();
        });
        // Rollback does not reactivate retired accounts.
    }
};
