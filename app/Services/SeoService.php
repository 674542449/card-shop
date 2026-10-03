<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeoService
{
    /**
     * Push URLs to Baidu for indexing.
     */
    public function pushToBaidu(array $urls): bool
    {
        $token = setting('baidu_push_token');
        $site = setting('site_url', config('app.url'));

        if (empty($token) || empty($site) || empty($urls)) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withBody(implode("\n", $urls), 'text/plain')
                ->post('https://data.zz.baidu.com/urls?'.http_build_query(['site' => $site, 'token' => $token]));

            if (!$response->successful() || $response->json('error') || (int) $response->json('success', 0) < count($urls)) {
                Log::warning('Baidu push failed', [
                    'status' => $response->status(),

                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Baidu push exception', ['type' => get_class($e)]);
            return false;
        }
    }

    /**
     * Push URLs to Bing via IndexNow protocol.
     */
    public function pushToIndexNow(array $urls): bool
    {
        $apiKey = setting('bing_indexnow_key');
        $host = parse_url((string) setting('site_url', config('app.url')), PHP_URL_HOST);

        if (empty($apiKey) || empty($host) || empty($urls)) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->post('https://api.indexnow.org/indexnow', [
                    'host' => $host,
                    'key' => $apiKey,
                    'keyLocation' => rtrim((string) setting('site_url', config('app.url')), '/').'/'.$apiKey.'.txt',
                    'urlList' => $urls,
                ]);

            if (!$response->successful()) {
                Log::warning('IndexNow push failed', [
                    'status' => $response->status(),

                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('IndexNow push exception', ['type' => get_class($e)]);
            return false;
        }
    }

}
