<?php

declare(strict_types=1);

use App\Services\Stripe\StripeService;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

it('creates a confirmed off-session PaymentIntent with an idempotency key', function () {
    $captured = null;
    $client = new class($captured) extends StripeClient {
        public function __construct(public &$captured)
        {
            parent::__construct(['api_key' => 'sk_test_x']);
        }

        public function __get($name)
        {
            if ($name === 'paymentIntents') {
                return new class($this->captured) {
                    public function __construct(public &$captured) {}

                    public function create($params, $opts = null)
                    {
                        $this->captured = ['params' => $params, 'opts' => $opts];

                        return PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'succeeded']);
                    }
                };
            }

            return parent::__get($name);
        }
    };
    $svc = new class($client) extends StripeService {
        public function __construct(private StripeClient $c)
        {
            parent::__construct($c);
        }

        public function client(): StripeClient
        {
            return $this->c;
        }
    };

    $pi = $svc->chargeOffSession('cus_1', 'pm_1', 1408, 'usd', 'renew_inv_42', ['invoice_id' => '42']);

    expect($pi->status)->toBe('succeeded');
    expect($captured['params']['amount'])->toBe(1408);
    expect($captured['params']['currency'])->toBe('usd');
    expect($captured['params']['customer'])->toBe('cus_1');
    expect($captured['params']['payment_method'])->toBe('pm_1');
    expect($captured['params']['off_session'])->toBeTrue();
    expect($captured['params']['confirm'])->toBeTrue();
    expect($captured['params'])->not->toHaveKey('payment_method_types');
    expect($captured['opts']['idempotency_key'])->toBe('renew_inv_42');
});
