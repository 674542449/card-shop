<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('manual');
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('schedule_key', 20)->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('phase', 50)->default('等待备份进程');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('filename', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sync_status', 20)->default('disabled');
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('health_acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        foreach (['notification_deliveries', 'seo_deliveries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('health_acknowledged_at')->nullable();
                $table->index(['status', 'health_acknowledged_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'seo_deliveries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['status', 'health_acknowledged_at']);
                $table->dropColumn('health_acknowledged_at');
            });
        }
        Schema::dropIfExists('backup_runs');
    }
};
