<?php

declare(strict_types=1);

use App\Services\Stripe\StripeService;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Build a StripeService whose client() returns the given fake StripeClient,
 * so the off-session wrappers never touch live Stripe.
 */
function offSessionFakeService(StripeClient $client): StripeService
{
    return new class($client) extends StripeService {
        public function __construct(private StripeClient $c)
        {
            parent::__construct($c);
        }

        public function client(): StripeClient
        {
            return $this->c;
        }
    };
}

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

it('reads the customer default payment method, resolving string or object form', function () {
    $client = new class extends StripeClient {
        public function __construct()
        {
            parent::__construct(['api_key' => 'sk_test_x']);
        }

        public function __get($name)
        {
            if ($name === 'customers') {
                return new class {
                    public function retrieve($id, $params = null, $opts = null)
                    {
                        $shapes = [
                            'cus_string' => ['invoice_settings' => ['default_payment_method' => 'pm_string']],
                            'cus_object' => ['invoice_settings' => ['default_payment_method' => ['id' => 'pm_object']]],
                            'cus_none' => ['invoice_settings' => ['default_payment_method' => null]],
                        ];

                        return Customer::constructFrom($shapes[$id]);
                    }
                };
            }

            return parent::__get($name);
        }
    };
    $svc = offSessionFakeService($client);

    expect($svc->getDefaultPaymentMethod('cus_string'))->toBe('pm_string');
    expect($svc->getDefaultPaymentMethod('cus_object'))->toBe('pm_object');
    expect($svc->getDefaultPaymentMethod('cus_none'))->toBeNull();
});

it('attaches the payment method then sets it as the customer invoice default', function () {
    $captured = ['attach' => null, 'update' => null];
    $client = new class($captured) extends StripeClient {
        public function __construct(public &$captured)
        {
            parent::__construct(['api_key' => 'sk_test_x']);
        }

        public function __get($name)
        {
            if ($name === 'paymentMethods') {
                return new class($this->captured) {
                    public function __construct(public &$captured) {}

                    public function attach($id, $params = null, $opts = null)
                    {
                        $this->captured['attach'] = ['id' => $id, 'params' => $params];
                    }
                };
            }

            if ($name === 'customers') {
                return new class($this->captured) {
                    public function __construct(public &$captured) {}

                    public function update($id, $params = null, $opts = null)
                    {
                        $this->captured['update'] = ['id' => $id, 'params' => $params];
                    }
                };
            }

            return parent::__get($name);
        }
    };
    $svc = offSessionFakeService($client);

    $svc->setCustomerDefaultPaymentMethod('cus_1', 'pm_1');

    expect($captured['attach']['id'])->toBe('pm_1');
    expect($captured['attach']['params'])->toBe(['customer' => 'cus_1']);
    expect($captured['update']['id'])->toBe('cus_1');
    expect($captured['update']['params'])->toBe(['invoice_settings' => ['default_payment_method' => 'pm_1']]);
});
