<?php

declare(strict_types=1);

use App\Services\Stripe\StripeService;

afterEach(function () {
    // reset singleton so other tests build a fresh instance
    StripeService::setInstance(new StripeService(new \Stripe\StripeClient(['api_key' => 'sk_test_x'])));
});

it('returns the injected client', function () {
    $client = new \Stripe\StripeClient(['api_key' => 'sk_test_x']);
    $svc = new StripeService($client);

    expect($svc->client())->toBe($client);
});

it('setInstance/getInstance round-trips a fake', function () {
    $fake = new StripeService(new \Stripe\StripeClient(['api_key' => 'sk_test_y']));
    StripeService::setInstance($fake);

    expect(StripeService::getInstance())->toBe($fake);
});

it('ensureCustomer returns existing stripe_customer_id without calling Stripe', function () {
    $user = new \App\Models\User();
    $user->id = 7;
    $user->email = 'a@b.com';
    $user->stripe_customer_id = 'cus_existing';

    // fake that would explode if it tried to create a customer
    $fake = new class (new \Stripe\StripeClient(['api_key' => 'sk_test_z'])) extends StripeService {
        public function client(): \Stripe\StripeClient
        {
            throw new \RuntimeException('should not touch Stripe when customer exists');
        }
    };
    StripeService::setInstance($fake);

    expect(StripeService::getInstance()->ensureCustomer($user))->toBe('cus_existing');
});
