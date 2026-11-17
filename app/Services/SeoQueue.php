<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\SeoDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeoQueue
{
    public function enqueueSite(): int
    {
        $before = SeoDelivery::count();
        $revision = 'manual:'.Str::uuid();
        $this->enqueue('/', $revision);
        Category::active()->select(['id', 'slug'])->chunkById(200, function ($items) use ($revision) {
            foreach ($items as $item) {
                $this->enqueue('/category/'.$item->slug, $revision);
            }
        });
        Product::active()->select(['products.id', 'products.slug'])->chunkById(200, function ($items) use ($revision) {
            foreach ($items as $item) {
                $this->enqueue('/product/'.$item->slug, $revision);
            }
        });
        Article::published()->select(['id', 'slug'])->chunkById(200, function ($items) use ($revision) {
            foreach ($items as $item) {
                $this->enqueue('/articles/'.$item->slug, $revision);
            }
        });

        return SeoDelivery::count() - $before;
    }

    public function enqueue(string $path, string $revision): void
    {
        $url = rtrim((string) setting('site_url', config('app.url')), '/').$path;
        foreach (['baidu' => 'baidu_push_token', 'indexnow' => 'bing_indexnow_key'] as $provider => $key) {
            if (! setting($key)) {
                continue;
            }
            SeoDelivery::firstOrCreate(['dedupe_key' => hash('sha256', $provider.$url.$revision)],
                ['provider' => $provider, 'url' => $url, 'available_at' => now()]);
        }
    }

    public function process(int $limit = 5): int
    {
        $count = 0;
        while ($count < $limit) {
            $job = DB::transaction(function () {
                SeoDelivery::where('status', 'processing')->where('reserved_at', '<', now()->subMinutes(5))->where('attempts', '>=', 5)
                    ->update(['status' => 'failed', 'lease_token' => null, 'last_error' => '发送进程中断，请人工重试。', 'health_acknowledged_at' => null]);
                $job = SeoDelivery::where('attempts', '<', 5)->where(fn ($q) => $q
                    ->where(fn ($p) => $p->where('status', 'pending')->where('available_at', '<=', now()))
                    ->orWhere(fn ($p) => $p->where('status', 'processing')->where('reserved_at', '<', now()->subMinutes(5))))
                    ->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($job) {
                    $job->update(['status' => 'processing', 'attempts' => $job->attempts + 1, 'lease_token' => (string) Str::uuid(), 'reserved_at' => now()]);
                }

                return $job;
            });
            if (! $job) {
                break;
            }
            try {
                $success = $job->provider === 'baidu' ? app(SeoService::class)->pushToBaidu([$job->url]) : app(SeoService::class)->pushToIndexNow([$job->url]);
            } catch (\Throwable) {
                $success = false;
            }
            SeoDelivery::whereKey($job->id)->where('lease_token', $job->lease_token)->update([
                'status' => $success ? 'sent' : ($job->attempts >= 5 ? 'failed' : 'pending'),
                'last_error' => $success ? null : '推送失败，请检查站点域名、密钥和搜索引擎响应。',
                'sent_at' => $success ? now() : null, 'available_at' => now()->addSeconds([60, 300, 900, 3600, 3600][$job->attempts - 1]),
                'reserved_at' => null, 'lease_token' => null,
                'health_acknowledged_at' => null,
            ]);
            $count++;
        }

        return $count;
    }
}
