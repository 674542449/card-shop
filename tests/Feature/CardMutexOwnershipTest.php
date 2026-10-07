<?php

namespace Tests\Feature;

use App\Models\{Card, Category, Product};
use App\Services\CardService;
use Illuminate\Support\Facades\{DB, Redis};
use Tests\TestCase;

class CardMutexOwnershipTest extends TestCase
{
    private function product(): Product
    {
        $category = Category::create(['name' => 'Fixture', 'slug' => uniqid('mutex-category-')]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Fixture', 'slug' => uniqid('mutex-product-'), 'price' => '10.00']);
        Card::create(['product_id' => $product->id, 'content' => 'DUMMY-MUTEX', 'status' => 'unsold']);
        return $product;
    }

    public function test_expired_owner_cannot_delete_a_replacement_inventory_mutex(): void
    {
        $product = $this->product();
        $key = 'card_lock:product:'.$product->id;
        $manager = Redis::getFacadeRoot();
        $connection = Redis::connection();
        // Model an expiry/new owner immediately after a non-atomic ownership
        // read. Direct connection calls keep the final observation independent.
        Redis::swap(new class($manager, $key) implements \Illuminate\Contracts\Redis\Factory {
            public function __construct(private object $manager, private string $key) {}
            public function connection($name = null) { return $this->manager->connection($name); }
            public function __call($method, $arguments) {
                $connection = $this->connection();
                if ($method === 'get' && $arguments[0] === $this->key) {
                    $previous = $connection->get($this->key);
                    $connection->set($this->key, 'DUMMY-NEXT-OWNER', 'EX', 30);
                    return $previous;
                }
                if ($method === 'eval') $connection->set($this->key, 'DUMMY-NEXT-OWNER', 'EX', 30);
                return $this->manager->$method(...$arguments);
            }
        });
        try {
            $this->assertCount(1, DB::transaction(fn () => app(CardService::class)->lockCards($product->id, 1)));
            $this->assertSame('DUMMY-NEXT-OWNER', $connection->get($key));
        } finally { Redis::swap($manager); $connection->del($key); }
    }

    public function test_successful_reservation_releases_its_own_mutex(): void
    {
        $product = $this->product();
        $key = 'card_lock:product:'.$product->id;
        $this->assertCount(1, DB::transaction(fn () => app(CardService::class)->lockCards($product->id, 1)));
        $this->assertSame(0, (int) Redis::exists($key));
        $this->assertSame('locked', $product->cards()->first()->status);
    }
}
