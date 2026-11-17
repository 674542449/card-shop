<?php

namespace Tests\Feature;

use App\Exceptions\SecretStorageException;
use App\Models\{Card, Category, Product, Setting};
use App\Security\{SecretCipher, SecretSettings};
use App\Services\{CardService, SettingService};
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

class SecretStorageTest extends TestCase
{
    private function product(): Product
    {
        $category = Category::create(['name' => 'Vault', 'slug' => 'vault', 'is_active' => true]);

        return Product::create(['category_id' => $category->id, 'name' => 'Vault', 'slug' => 'vault',
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 20, 'is_active' => true]);
    }

    public function test_model_and_bulk_cards_are_encrypted_but_authorized_model_reads_stay_compatible(): void
    {
        $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-model', 'status' => 'unsold']);
        Card::insert([['product_id' => $product->id, 'content' => '0', 'status' => 'unsold'],
            ['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-bulk', 'status' => 'sold']]);
        foreach (DB::table('cards')->get() as $row) {
            $this->assertStringStartsWith(SecretCipher::PREFIX, $row->content);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row->content_fingerprint);
        }
        $this->assertSame('PUBLIC-DUMMY-model', $card->fresh()->content);
        $this->assertEqualsCanonicalizing(['PUBLIC-DUMMY-model', '0', 'PUBLIC-DUMMY-bulk'], $product->cards()->pluck('content')->all());
        $this->assertSame(1, $product->cards()->where('content', '0')->count());
        $this->assertArrayNotHasKey('content', $card->toArray());
        $this->assertArrayNotHasKey('content_fingerprint', $card->makeVisible('content')->toArray());
        $this->assertSame('PUBLIC-DUMMY-model', $card->toArray()['content']);
        $card->update(['content' => 'PUBLIC-DUMMY-revised']);
        $this->assertSame('PUBLIC-DUMMY-revised', $card->fresh()->content);
        $this->assertSame(1, $product->cards()->where('content', 'PUBLIC-DUMMY-revised')->count());
        $product->cards()->whereKey($card->id)->update(['content' => 'PUBLIC-DUMMY-bulk-rewrite']);
        $this->assertSame('PUBLIC-DUMMY-bulk-rewrite', $card->fresh()->content);
        $this->assertStringStartsWith(SecretCipher::PREFIX, DB::table('cards')->where('id', $card->id)->value('content'));
    }

    public function test_import_deduplicates_encrypted_inventory_without_querying_plaintext_content(): void
    {
        $product = $this->product();
        $service = app(CardService::class);
        $first = $service->importCardsWithResult($product->id, "PUBLIC-DUMMY-A\n0\nPUBLIC-DUMMY-A");
        $this->assertSame(['count' => 2, 'skipped' => 1, 'total' => 3], $first);
        $product->cards()->where('content', 'PUBLIC-DUMMY-A')->update(['status' => 'sold']);
        $second = $service->importCardsWithResult($product->id, "PUBLIC-DUMMY-A\n0\nPUBLIC-DUMMY-B");
        $this->assertSame(['count' => 1, 'skipped' => 2, 'total' => 3], $second);
        $this->assertSame(3, $product->cards()->count());
    }

    public function test_sensitive_settings_database_and_all_cache_paths_contain_only_ciphertext(): void
    {
        Setting::set('site_name', 'Vault shop');
        Setting::set('epay_merchant_key', 'PUBLIC-DUMMY-payment-key', 'payment');
        Setting::set('mail_password', 'PUBLIC-DUMMY-smtp-password', 'email');
        Setting::insert(['value' => 'PUBLIC-DUMMY-push-token', 'key' => 'baidu_push_token', 'group' => 'seo']);
        foreach (SecretSettings::KEYS as $key) {
            $raw = DB::table('settings')->where('key', $key)->value('value');
            if ($raw !== null) { $this->assertStringStartsWith(SecretCipher::PREFIX, $raw); }
        }
        $this->assertSame('PUBLIC-DUMMY-payment-key', Setting::where('key', 'epay_merchant_key')->value('value'));
        $this->assertSame(['epay_merchant_key' => 'PUBLIC-DUMMY-payment-key'], Setting::where('key', 'epay_merchant_key')->pluck('value', 'key')->all());
        $this->assertSame('PUBLIC-DUMMY-payment-key', setting('epay_merchant_key'));
        $this->assertSame('PUBLIC-DUMMY-push-token', setting('baidu_push_token'));
        $this->assertSame('PUBLIC-DUMMY-smtp-password', app(SettingService::class)->get('mail_password'));
        $this->assertSame('PUBLIC-DUMMY-payment-key', app(SettingService::class)->getAll()['payment']['epay_merchant_key']);
        $map = Cache::get('settings:stored:v1');
        $groups = Cache::store('redis')->get('settings:all:stored:v1');
        $this->assertStringStartsWith(SecretCipher::PREFIX, $map['epay_merchant_key']);
        $this->assertStringStartsWith(SecretCipher::PREFIX, $groups['email']['mail_password']);
        $this->assertStringNotContainsString('PUBLIC-DUMMY-payment-key', json_encode($map));
        $this->assertStringNotContainsString('PUBLIC-DUMMY-smtp-password', json_encode($groups));
        $this->assertNull(Cache::get('setting:epay_merchant_key'));
        Setting::set('epay_merchant_key', 'PUBLIC-DUMMY-new-key', 'payment');
        $this->assertSame('PUBLIC-DUMMY-new-key', setting('epay_merchant_key'));
    }

    public function test_cipher_rejects_tampering_wrong_purpose_and_missing_external_keyring(): void
    {
        $cipher = app(SecretCipher::class);
        $sealed = $cipher->encrypt('PUBLIC-DUMMY-secret', 'card-content');
        $this->assertNotSame($sealed, $cipher->encrypt('PUBLIC-DUMMY-secret', 'card-content'));
        foreach ([$sealed, substr($sealed, 0, -5).'AAAAA'] as $candidate) {
            try {
                $cipher->decrypt($candidate, 'setting:mail_password');
                $this->fail('Wrong-purpose ciphertext must be refused.');
            } catch (SecretStorageException) { $this->addToAssertionCount(1); }
        }
        try {
            $cipher->decrypt(substr($sealed, 0, -5).'AAAAA', 'card-content');
            $this->fail('Tampered ciphertext must be refused.');
        } catch (SecretStorageException) { $this->addToAssertionCount(1); }
        config(['secrets.keyring_file' => sys_get_temp_dir().'/missing-cardshop-vault-'.bin2hex(random_bytes(6))]);
        $this->expectException(SecretStorageException::class);
        $cipher->decrypt($sealed, 'card-content');
    }

    public function test_missing_keyring_refuses_new_card_writes_without_changing_inventory(): void
    {
        $product = $this->product();
        config(['secrets.keyring_file' => sys_get_temp_dir().'/missing-cardshop-vault-'.bin2hex(random_bytes(6))]);
        try {
            Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-refused', 'status' => 'unsold']);
            $this->fail('Missing keyring must fail closed.');
        } catch (SecretStorageException) { $this->assertSame(0, $product->cards()->count()); }
    }

    public function test_legacy_conversion_is_explicit_resumable_and_preserves_business_state(): void
    {
        $product = $this->product();
        DB::table('cards')->insert(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-legacy',
            'status' => 'sold', 'sold_at' => '2026-10-01 10:00:00', 'created_at' => '2026-09-30 10:00:00']);
        DB::table('settings')->insert(['key' => 'epay_merchant_key', 'value' => 'PUBLIC-DUMMY-legacy-payment', 'group' => 'payment']);
        Cache::put('settings:map', ['epay_merchant_key' => 'PUBLIC-DUMMY-legacy-payment']);
        Cache::put('setting:epay_merchant_key', 'PUBLIC-DUMMY-legacy-payment');
        Cache::forget('settings:stored:v1');
        settings_memo(clear: true);
        $legacyMap = settings_all();
        $this->assertStringNotContainsString('PUBLIC-DUMMY-legacy-payment', json_encode($legacyMap));
        try {
            setting('epay_merchant_key');
            $this->fail('Legacy payment credentials require explicit conversion.');
        } catch (SecretStorageException) { $this->addToAssertionCount(1); }
        try {
            $product->cards()->firstOrFail()->content;
            $this->fail('Legacy cards require explicit conversion.');
        } catch (SecretStorageException) { $this->addToAssertionCount(1); }
        $this->assertSame(1, $this->artisan('secrets:encrypt'));
        $this->assertSame(0, $this->artisan('secrets:encrypt', ['--force' => true, '--batch-size' => 1]));
        $before = DB::table('cards')->first();
        $this->assertStringStartsWith(SecretCipher::PREFIX, $before->content);
        $this->assertSame('sold', $before->status);
        $this->assertSame('2026-10-01 10:00:00', $before->sold_at);
        $this->assertSame('2026-09-30 10:00:00', $before->created_at);
        $this->assertSame('PUBLIC-DUMMY-legacy', $product->cards()->firstOrFail()->content);
        $this->assertSame('PUBLIC-DUMMY-legacy-payment', setting('epay_merchant_key'));
        $this->assertNull(Cache::get('settings:map'));
        $this->assertNull(Cache::get('setting:epay_merchant_key'));
        $this->assertSame(0, $this->artisan('secrets:encrypt', ['--force' => true]));
        $this->assertSame($before->content, DB::table('cards')->value('content'));
        $this->assertSame(0, $this->artisan('secrets:status', ['--json' => true]));
    }

    public function test_key_rotation_retains_old_versions_and_deduplication_fingerprint(): void
    {
        $directory = sys_get_temp_dir().'/cardshop-rotate-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/keyring.json';
        copy(config('secrets.keyring_file'), $path);
        if (PHP_OS_FAMILY !== 'Windows') { chmod($path, 0600); }
        config(['secrets.keyring_file' => $path]);
        try {
            $product = $this->product();
            $card = Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-rotate', 'status' => 'unsold']);
            Setting::set('mail_password', 'PUBLIC-DUMMY-rotate-mail');
            $old = $card->getRawOriginal('content');
            $fingerprint = $card->getRawOriginal('content_fingerprint');
            $id = app(SecretCipher::class)->metadata()['keyring_id'];
            $this->assertSame(0, $this->artisan('secrets:rotate', ['--key-id' => 'test-v2']));
            $this->assertSame('test-v2', app(SecretCipher::class)->metadata()['active']);
            $this->assertSame($id, app(SecretCipher::class)->metadata()['keyring_id']);
            $this->assertSame('PUBLIC-DUMMY-rotate', $card->fresh()->content);
            $this->assertSame(1, $this->artisan('secrets:rotate', ['--key-id' => 'test-v2']));
            $this->assertSame(0, $this->artisan('secrets:encrypt', ['--force' => true, '--rotate' => true]));
            $this->assertSame('test-v2', app(SecretCipher::class)->version($card->fresh()->getRawOriginal('content')));
            $this->assertSame($fingerprint, $card->fresh()->getRawOriginal('content_fingerprint'));
            $this->assertSame('PUBLIC-DUMMY-rotate-mail', setting('mail_password'));
            $this->assertSame('PUBLIC-DUMMY-rotate', app(SecretCipher::class)->decrypt($old, 'card-content'));
            $this->assertSame(0, app(CardService::class)->importCardsWithResult($product->id, 'PUBLIC-DUMMY-rotate')['count']);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    public function test_keyring_initialization_refuses_application_paths_and_never_overwrites_valid_keys(): void
    {
        $this->assertSame(1, $this->artisan('secrets:init', ['--path' => base_path('.local/forbidden-keyring.json')]));
        $directory = sys_get_temp_dir().'/cardshop-init-test-'.bin2hex(random_bytes(8));
        $path = $directory.'/keyring.json';
        try {
            $this->assertSame(0, $this->artisan('secrets:init', ['--path' => $path]));
            $before = hash_file('sha256', $path);
            $this->assertSame(0, $this->artisan('secrets:init', ['--path' => $path]));
            $this->assertSame($before, hash_file('sha256', $path));
        } finally {
            if (is_file($path)) { unlink($path); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }
}
