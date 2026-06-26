<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Product;
use App\Services\Cache;
use App\Services\Stripe\PriceResolver;
use App\Services\Stripe\StripeService;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * toMinorUnits — PURE, no DB / no Stripe. Exercised with many currency cases.
 * ---------------------------------------------------------------------------
 */

it('multiplies by 100 for normal currencies', function () {
    expect(PriceResolver::toMinorUnits(12.34, 'USD'))->toBe(1234);
    expect(PriceResolver::toMinorUnits(10.0, 'EUR'))->toBe(1000);
    expect(PriceResolver::toMinorUnits(0.99, 'GBP'))->toBe(99);
});

it('does not multiply for zero-decimal currencies', function () {
    expect(PriceResolver::toMinorUnits(1500.0, 'JPY'))->toBe(1500);
    expect(PriceResolver::toMinorUnits(2000.0, 'VND'))->toBe(2000);
    expect(PriceResolver::toMinorUnits(3000.0, 'KRW'))->toBe(3000);
    expect(PriceResolver::toMinorUnits(500.0, 'XOF'))->toBe(500);
    expect(PriceResolver::toMinorUnits(42.0, 'CLP'))->toBe(42);
});

it('is case-insensitive for currency and rounds', function () {
    expect(PriceResolver::toMinorUnits(9.999, 'usd'))->toBe(1000);
    expect(PriceResolver::toMinorUnits(1499.6, 'jpy'))->toBe(1500);
    expect(PriceResolver::toMinorUnits(1500.4, 'Jpy'))->toBe(1500);
    expect(PriceResolver::toMinorUnits(12.345, 'uSd'))->toBe(1235);
    expect(PriceResolver::toMinorUnits(12.344, 'USD'))->toBe(1234);
});

it('handles zero amount for both currency families', function () {
    expect(PriceResolver::toMinorUnits(0.0, 'USD'))->toBe(0);
    expect(PriceResolver::toMinorUnits(0.0, 'JPY'))->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * resolve — touches Config (DB), Exchange (Redis-cached rate) and Stripe.
 * Stripe is stubbed via a fake StripeService so we never hit live Stripe.
 * The FX rate is pre-seeded into Redis so Exchange is deterministic + offline.
 * These run on the live infra (MariaDB + Redis); they skip where ext-redis
 * is unavailable. The pure toMinorUnits tests above always run.
 * ---------------------------------------------------------------------------
 */

/**
 * Fake Stripe price service: records create() / all() calls and can pretend a
 * price already exists (reuse path) or not (create path).
 */
function fakePriceClient(?object $existing): StripeClient
{
    return new class ($existing) extends StripeClient {
        public array $createCalls = [];

        public array $allCalls = [];

        public function __construct(private ?object $existing)
        {
            parent::__construct(['api_key' => 'sk_test_resolve']);
        }

        public function __get($name)
        {
            if ($name === 'prices') {
                return new class ($this) {
                    public function __construct(private $owner) {}

                    public function all($params = null, $opts = null)
                    {
                        $this->owner->allCalls[] = $params;
                        $data = $this->owner->existingPrice() !== null
                            ? [$this->owner->existingPrice()]
                            : [];

                        return (object) ['data' => $data];
                    }

                    public function create($params = null, $opts = null)
                    {
                        $this->owner->createCalls[] = $params;

                        return (object) ['id' => 'price_created_123'];
                    }
                };
            }

            return parent::__get($name);
        }

        public function existingPrice(): ?object
        {
            return $this->existing;
        }
    };
}

function fakeStripeService(StripeClient $client): StripeService
{
    return new class ($client) extends StripeService {
        public function __construct(private StripeClient $fakeClient)
        {
            parent::__construct($fakeClient);
        }

        public function client(): StripeClient
        {
            return $this->fakeClient;
        }
    };
}

function makeProduct(float $price, object $content): Product
{
    $product = new Product();
    $product->id = 42;
    $product->name = 'Test Plan';
    $product->price = $price;
    $product->content = json_encode($content);

    return $product;
}

describe('resolve', function () {
    beforeEach(function () {
        // resolve() goes through Exchange, which requires the redis extension.
        // Skip (not fail) where phpredis is unavailable; runs on the live infra.
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not available; resolve() needs Exchange (Redis)');
        }

        TestDatabase::init();

        Config::query()->updateOrInsert(
            ['item' => 'stripe_currency'],
            ['value' => 'USD', 'type' => 'string']
        );

        // Pre-seed FX so Exchange::exchange is offline + deterministic: 1 CNY = 0.10 USD.
        $redis = (new Cache())->initRedis();
        $redis->setex('exchange_rate:CNY_USD', 3600, 0.10);
    });

    afterEach(function () {
        if (! extension_loaded('redis')) {
            return;
        }

        $redis = (new Cache())->initRedis();
        $redis->del('exchange_rate:CNY_USD');
        TestDatabase::dropTables();
        // restore singleton so later tests build a fresh real instance
        StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
    });

    it('resolves a monthly recurring price (CNY -> stripe_currency, *100) and creates it', function () {
        $client = fakePriceClient(null);
        StripeService::setInstance(fakeStripeService($client));

        // 50 CNY/month, no discount. month = price * 1 = 50 CNY -> 5.00 USD -> 500 minor.
        $product = makeProduct(50.0, (object) ['discount' => (object) ['quarter' => 0.9, 'year' => 0.8]]);

        $result = PriceResolver::resolve($product, 'month');

        expect($result)->toMatchArray([
            'price_id' => 'price_created_123',
            'amount' => 500,
            'currency' => 'USD',
        ]);

        // created exactly once, with a monthly recurring interval and no payment_method_types
        expect($client->createCalls)->toHaveCount(1);
        $params = $client->createCalls[0];
        expect($params['currency'])->toBe('USD')
            ->and($params['unit_amount'])->toBe(500)
            ->and($params['recurring'])->toBe(['interval' => 'month', 'interval_count' => 1])
            ->and($params)->not->toHaveKey('payment_method_types');
    });

    it('applies quarter discount and uses a 3-month recurring interval', function () {
        $client = fakePriceClient(null);
        StripeService::setInstance(fakeStripeService($client));

        // 50 CNY/month * 3 * 0.9 = 135 CNY -> 13.50 USD -> 1350 minor.
        $product = makeProduct(50.0, (object) ['discount' => (object) ['quarter' => 0.9, 'year' => 0.8]]);

        $result = PriceResolver::resolve($product, 'quarter');

        expect($result['amount'])->toBe(1350);
        expect($client->createCalls[0]['recurring'])->toBe(['interval' => 'month', 'interval_count' => 3]);
    });

    it('applies year discount and uses a yearly recurring interval', function () {
        $client = fakePriceClient(null);
        StripeService::setInstance(fakeStripeService($client));

        // 50 CNY/month * 12 * 0.8 = 480 CNY -> 48.00 USD -> 4800 minor.
        $product = makeProduct(50.0, (object) ['discount' => (object) ['quarter' => 0.9, 'year' => 0.8]]);

        $result = PriceResolver::resolve($product, 'year');

        expect($result['amount'])->toBe(4800);
        expect($client->createCalls[0]['recurring'])->toBe(['interval' => 'year', 'interval_count' => 1]);
    });

    it('reuses an existing price by lookup key instead of creating a new one', function () {
        $existing = (object) ['id' => 'price_existing_999'];
        $client = fakePriceClient($existing);
        StripeService::setInstance(fakeStripeService($client));

        $product = makeProduct(50.0, (object) ['discount' => (object) ['quarter' => 0.9, 'year' => 0.8]]);

        $result = PriceResolver::resolve($product, 'month');

        expect($result['price_id'])->toBe('price_existing_999')
            ->and($result['amount'])->toBe(500);
        expect($client->createCalls)->toHaveCount(0);
        expect($client->allCalls)->toHaveCount(1);
    });

    it('falls back to monthly with no discount when content has no discount object', function () {
        $client = fakePriceClient(null);
        StripeService::setInstance(fakeStripeService($client));

        // No discount -> quarter = 50 * 3 * 1.0 = 150 CNY -> 15.00 USD -> 1500 minor.
        $product = makeProduct(50.0, (object) []);

        $result = PriceResolver::resolve($product, 'quarter');

        expect($result['amount'])->toBe(1500);
    });
});
