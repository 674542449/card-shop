<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Category, Product};
use App\Services\CardService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Hash, Http, Storage};
use Tests\TestCase;

class InputTextQualityTest extends TestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $admin = Admin::create(['username' => 'text-quality-owner', 'password' => Hash::make('dummy-owner-password'), 'role' => 'owner', 'permissions' => [], 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        $category = Category::create(['name' => 'Text quality', 'slug' => 'text-quality', 'is_active' => true]);
        $this->product = Product::create(['name' => '原始商品', 'slug' => 'text-quality-product', 'category_id' => $category->id, 'price' => '10.00', 'is_active' => true]);
    }

    public function test_invalid_text_values_keys_and_raw_json_leave_product_unchanged(): void
    {
        $path = '/api/admin/products/'.$this->product->id;
        $base = ['name' => '原始商品', 'category_id' => $this->product->category_id, 'price' => '10.00'];
        foreach ([['name' => "bad\0name"], ['description' => "bad\xFFtext"], ["bad\xFFkey" => 'normal']] as $changes) {
            $this->put($path, array_replace($base, $changes), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('input');
        }
        $this->call('PUT', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], "{\"name\":\"bad\xFF\"}")
            ->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->assertSame('原始商品', $this->product->fresh()->name);
        $this->assertSame('10.00', $this->product->fresh()->price);
        $this->putJson($path, array_replace($base, ['name' => '正常中文名称', 'description' => '<p><strong>正常富文本</strong></p>']))
            ->assertOk()->assertJsonPath('name', '正常中文名称');
    }

    public function test_invalid_card_file_is_rejected_and_bom_does_not_become_part_of_the_secret(): void
    {
        $path = '/api/admin/products/'.$this->product->id.'/cards/import';
        $bad = UploadedFile::fake()->createWithContent('dummy.txt', "DUMMY-CARD-\xFF-END\n");
        $this->post($path, ['file' => $bad], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, $this->product->cards()->count());
        $good = UploadedFile::fake()->createWithContent('dummy.txt', "\xEF\xBB\xBF虚构卡密-A\r\n虚构卡密-B\n");
        $this->post($path, ['file' => $good], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(['虚构卡密-A', '虚构卡密-B'], $this->product->cards()->orderBy('id')->pluck('content')->all());
    }

    public function test_direct_card_service_does_not_normalize_invalid_input_into_a_valid_secret(): void
    {
        foreach (["DUMMY\0CARD", "DUMMY\xFFCARD"] as $text) {
            try {
                app(CardService::class)->importCardsWithResult($this->product->id, $text);
                $this->fail('Invalid input was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('卡密内容必须是有效 UTF-8 文本，且不能包含空字符。', $exception->getMessage());
            }
        }
        $this->assertSame(0, $this->product->cards()->count());
    }

    public function test_binary_image_upload_is_not_treated_as_invalid_text(): void
    {
        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jT1kAAAAASUVORK5CYII=');
        $file = UploadedFile::fake()->createWithContent('dummy.png', $png);
        $response = $this->post('/api/admin/upload', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertExists($response->json('path'));
    }

    public function test_html_validation_redirect_has_a_session_available(): void
    {
        $this->from('/order/query')->post('/order/quote', ['product_id' => $this->product->id, 'quantity' => 1, 'comment' => "bad\xFF"], ['Accept' => 'text/html'])
            ->assertRedirect('/order/query')->assertSessionHasErrors('input');
        $this->assertSame(0, $this->product->orders()->count());
    }
}
