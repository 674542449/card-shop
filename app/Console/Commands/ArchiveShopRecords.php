<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArchiveShopRecords extends Command
{
    protected $signature = 'shop:archive-records {--days=365} {--apply : Archive then remove old completed operational records}';

    protected $description = 'Preview retention; never removes orders, payment receipts, refunds or unfinished deliveries';

    public function handle(): int
    {
        $days = max(30, (int) $this->option('days'));
        $before = now()->subDays($days);
        $queries = ['operation_logs' => DB::table('operation_logs')->where('created_at', '<', $before),
            'notification_deliveries' => DB::table('notification_deliveries')->whereIn('status', ['sent', 'skipped'])->where('created_at', '<', $before),
            'seo_deliveries' => DB::table('seo_deliveries')->where('status', 'sent')->where('created_at', '<', $before)];
        $counts = array_map(fn ($q) => (clone $q)->count(), $queries);
        $this->line(json_encode($counts));
        if (! $this->option('apply')) {
            $this->info('仅预览；订单、付款、退款记录长期保留。');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($queries) {
            foreach ($queries as $table => $query) {
                (clone $query)->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                    $file = 'record-archives/'.$table.'-'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
                    $data = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    if (! Storage::disk('local')->put($file, $data) || ! hash_equals(hash('sha256', $data), hash('sha256', Storage::disk('local')->get($file)))) {
                        throw new \RuntimeException('归档文件写入或校验失败，未清理数据库记录。');
                    }
                    DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
                });
            }
        });
        $this->info('历史操作记录已归档到私有存储。');

        return self::SUCCESS;
    }
}
