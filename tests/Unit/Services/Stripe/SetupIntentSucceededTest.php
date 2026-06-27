<?php

declare(strict_types=1);

use App\Models\StripeEvent;
use App\Models\User;
use App\Services\Stripe\StripeService;
use App\Services\Stripe\WebhookHandler;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * B3 — setup_intent.succeeded: bind the saved card as the customer DEFAULT
 * payment method so the renewal engine's card fallback
 * (SubscriptionService::chargeRenewalToCard -> getDefaultPaymentMethod) can
 * charge it off-session later.
 *
 * DB-backed against the real MariaDB sspanel_test (same harness as the sibling
 * webhook tests). StripeService is stubbed via setInstance() — the handler must
 * NEVER touch live Stripe. S5: the local user is resolved ONLY from the
 * server-stored stripe_customer_id on the event, never a client-supplied id.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
    // Hand later tests a clean offline singleton.
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

/**
 * Fake StripeService that records setCustomerDefaultPaymentMethod() calls so a
 * test can assert the webhook bound the saved card as the customer default —
 * without ever reaching the network.
 */
function fakeDefaultPmStripe(): StripeService
{
    return new class (new StripeClient(['api_key' => 'sk_test_si'])) extends StripeService {
        /** @var array<int,array{customerId:string,pmId:string}> */
        public array $defaultCalls = [];

        public function setCustomerDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
        {
            $this->defaultCalls[] = ['customerId' => $customerId, 'pmId' => $paymentMethodId];
        }
    };
}

function makeSiUser(string $customerId): User
{
    $user = new User();
    $user->email = 'si_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'si_user';
    $user->passwd = bin2hex(random_bytes(8));
    $user->stripe_customer_id = $customerId;
    $user->class = 0;
    $user->transfer_enable = 0;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('-1 day'));
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function makeSetupIntentEvent(string $evtId, ?string $customer, ?string $pm): \Stripe\Event
{
    return \Stripe\Event::constructFrom([
        'id' => $evtId,
        'type' => 'setup_intent.succeeded',
        'data' => ['object' => [
            'id' => 'seti_1',
            'object' => 'setup_intent',
            'customer' => $customer,
            'payment_method' => $pm,
            'status' => 'succeeded',
        ]],
    ]);
}

it('sets the saved card as the customer default for a known customer', function () {
    makeSiUser('cus_si_known');
    $fake = fakeDefaultPmStripe();
    StripeService::setInstance($fake);

    (new WebhookHandler())->handle(makeSetupIntentEvent('evt_si_1', 'cus_si_known', 'pm_si_1'));

    expect($fake->defaultCalls)->toHaveCount(1);
    expect($fake->defaultCalls[0]['customerId'])->toBe('cus_si_known');
    expect($fake->defaultCalls[0]['pmId'])->toBe('pm_si_1');
    // recorded for dedup
    expect((new StripeEvent())->where('event_id', 'evt_si_1')->exists())->toBeTrue();
});

it('is a no-op when the customer maps to no local user', function () {
    $fake = fakeDefaultPmStripe();
    StripeService::setInstance($fake);

    (new WebhookHandler())->handle(makeSetupIntentEvent('evt_si_2', 'cus_unknown', 'pm_si_2'));

    expect($fake->defaultCalls)->toHaveCount(0);
    expect((new StripeEvent())->where('event_id', 'evt_si_2')->exists())->toBeTrue();
});

it('is a no-op when the payment_method is missing', function () {
    makeSiUser('cus_si_nopm');
    $fake = fakeDefaultPmStripe();
    StripeService::setInstance($fake);

    (new WebhookHandler())->handle(makeSetupIntentEvent('evt_si_3', 'cus_si_nopm', null));

    expect($fake->defaultCalls)->toHaveCount(0);
    expect((new StripeEvent())->where('event_id', 'evt_si_3')->exists())->toBeTrue();
});

it('is a no-op when the customer is missing', function () {
    $fake = fakeDefaultPmStripe();
    StripeService::setInstance($fake);

    (new WebhookHandler())->handle(makeSetupIntentEvent('evt_si_4', null, 'pm_si_4'));

    expect($fake->defaultCalls)->toHaveCount(0);
});

it('dedups a replay: the default PM is bound only once', function () {
    makeSiUser('cus_si_dup');
    $fake = fakeDefaultPmStripe();
    StripeService::setInstance($fake);

    $handler = new WebhookHandler();
    $handler->handle(makeSetupIntentEvent('evt_si_dup', 'cus_si_dup', 'pm_si_dup'));
    $handler->handle(makeSetupIntentEvent('evt_si_dup', 'cus_si_dup', 'pm_si_dup')); // replay

    expect($fake->defaultCalls)->toHaveCount(1);
    expect((new StripeEvent())->where('event_id', 'evt_si_dup')->count())->toBe(1);
});
