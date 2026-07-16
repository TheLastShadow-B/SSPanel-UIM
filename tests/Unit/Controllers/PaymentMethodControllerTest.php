<?php

declare(strict_types=1);

use App\Controllers\User\PaymentMethodController;
use App\Models\Config;
use App\Models\User;
use App\Services\Stripe\StripeService;
use App\Services\View;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * B3+B4 — self-service "支付方式 / Payment method" page.
 *
 * DB-backed against the real MariaDB sspanel_test. StripeService is stubbed via
 * setInstance() so nothing touches the network. SECURITY (S5): every endpoint
 * acts ONLY on $this->user, whose Stripe customer id is server-derived through
 * ensureCustomer()/stripe_customer_id — a customer / PM / SetupIntent id is
 * NEVER read from the request. The fake records the calls so the tests prove it.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    // BaseController::view() reads View::$beginTime (set by Boot in prod); the
    // render test needs it. View::$connection is set by DB::init() above.
    View::$beginTime = microtime(true);
});

afterEach(function () {
    global $user;
    $user = null;

    TestDatabase::dropTables();
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

/**
 * Fake StripeService for the payment-method page. ensureCustomer mirrors the
 * real "use the stored id" behaviour (server-derived). getDefaultPaymentMethod
 * is keyed by customer id so cross-customer isolation is observable. Records
 * createSetupIntent + detach calls.
 *
 * @param array<string,string> $defaults customerId => current default PM id
 */
function fakePmStripe(array $defaults = [], string $clientSecret = 'seti_secret_test'): StripeService
{
    return new class (new StripeClient(['api_key' => 'sk_test_pm']), $defaults, $clientSecret) extends StripeService {
        /** @var array<int,array{customerId:string,metadata:array}> */
        public array $setupIntentCalls = [];
        /** @var array<int,string> */
        public array $detachCalls = [];
        /** @var array<string,string> customerId => latest attached (non-default) PM id */
        public array $attached = [];
        /** @var array<int,array{0:string,1:string}> recorded setDefault calls */
        public array $setDefaultCalls = [];
        /** 'card' | 'link' — shape returned by retrievePaymentMethod */
        public string $pmType = 'card';

        public function __construct(StripeClient $c, public array $defaults, public string $clientSecret)
        {
            parent::__construct($c);
        }

        public function ensureCustomer(User $user): string
        {
            // Server-derived: the stored id, else a deterministic synthetic id.
            return $user->stripe_customer_id ?: ('cus_fake_' . $user->id);
        }

        public function createSetupIntent(string $customerId, array $metadata = []): SetupIntent
        {
            $this->setupIntentCalls[] = ['customerId' => $customerId, 'metadata' => $metadata];

            return SetupIntent::constructFrom([
                'id' => 'seti_test',
                'object' => 'setup_intent',
                'client_secret' => $this->clientSecret,
                'customer' => $customerId,
            ]);
        }

        public function getDefaultPaymentMethod(string $customerId): ?string
        {
            return $this->defaults[$customerId] ?? null;
        }

        public function getLatestAttachedPaymentMethod(string $customerId): ?string
        {
            return $this->attached[$customerId] ?? null;
        }

        public function setCustomerDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
        {
            $this->setDefaultCalls[] = [$customerId, $paymentMethodId];
        }

        public function retrievePaymentMethod(string $pmId): ?PaymentMethod
        {
            if ($this->pmType === 'link') {
                return PaymentMethod::constructFrom([
                    'id' => $pmId,
                    'object' => 'payment_method',
                    'type' => 'link',
                    'link' => ['email' => 'linkuser@example.com'],
                ]);
            }

            return PaymentMethod::constructFrom([
                'id' => $pmId,
                'object' => 'payment_method',
                'type' => 'card',
                'card' => ['brand' => 'visa', 'last4' => '4242'],
            ]);
        }

        public function detachPaymentMethod(string $pmId): void
        {
            $this->detachCalls[] = $pmId;
        }
    };
}

function makePmUser(?string $customerId): User
{
    $user = new User();
    $user->email = 'pm_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'pm_user';
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

function pmRequest(array $params): \Slim\Http\ServerRequest
{
    $decorated = (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
        ->createServerRequest('POST', '/user/payment-method');

    return $decorated->withParsedBody($params);
}

function pmResponse(): \Slim\Http\Response
{
    return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
}

function seedPublishableKey(string $value): void
{
    Config::query()->updateOrInsert(
        ['item' => 'stripe_publishable_key'],
        ['value' => $value, 'class' => 'billing', 'type' => 'string', 'is_public' => 1]
    );
}

/**
 * header.tpl / footer.tpl read a handful of public_setting keys; seed them so
 * the full-page render is deterministic (no undefined-key noise).
 */
function seedRenderPublicSettings(): void
{
    foreach (['display_docs', 'display_docs_only_for_paid_user', 'display_detect_log', 'enable_ticket'] as $item) {
        Config::query()->updateOrInsert(
            ['item' => $item],
            ['value' => '0', 'class' => 'frontend', 'type' => 'bool', 'is_public' => 1]
        );
    }
    // docs_url (header) + live_chat (footer include). 'off' keeps live_chat.tpl's
    // crisp/livechat branches dark so their crisp_id/livechat_license are never read.
    foreach (['docs_url' => '', 'live_chat' => 'off'] as $item => $value) {
        Config::query()->updateOrInsert(
            ['item' => $item],
            ['value' => $value, 'class' => 'frontend', 'type' => 'string', 'is_public' => 1]
        );
    }
}

it('createSetupIntent returns a client_secret + publishable key for the caller\'s own server-derived customer', function () {
    seedPublishableKey('pk_test_abc');
    $user = makePmUser('cus_own');
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe();
    StripeService::setInstance($fake);

    $response = (new PaymentMethodController())->createSetupIntent(pmRequest([]), pmResponse(), []);

    $json = json_decode((string) $response->getBody(), true);
    expect($json['client_secret'])->toBe('seti_secret_test');
    expect($json['publishable_key'])->toBe('pk_test_abc');

    // Server-derived customer + the SetupIntent carries this user's id metadata.
    expect($fake->setupIntentCalls)->toHaveCount(1);
    expect($fake->setupIntentCalls[0]['customerId'])->toBe('cus_own');
    expect($fake->setupIntentCalls[0]['metadata']['sspanel_user_id'])->toBe((string) $user->id);
});

it('createSetupIntent ignores any client-supplied customer/SetupIntent id (S5)', function () {
    seedPublishableKey('pk_test_abc');
    $user = makePmUser('cus_real');
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe();
    StripeService::setInstance($fake);

    // Forged body fields must be ignored; only the server-derived customer is used.
    (new PaymentMethodController())->createSetupIntent(pmRequest([
        'customer' => 'cus_victim',
        'customer_id' => 'cus_victim',
        'setup_intent' => 'seti_victim',
    ]), pmResponse(), []);

    expect($fake->setupIntentCalls[0]['customerId'])->toBe('cus_real');
});

it('createSetupIntent returns handled ret:0 JSON when ensureCustomer fails (no uncaught 500)', function () {
    seedPublishableKey('pk_test_fail');
    // No stored customer id: ensureCustomer would hit the Stripe API to CREATE a
    // customer — the failure mode P2 hardens against.
    $user = makePmUser(null);
    $GLOBALS['user'] = $user;

    // Stub whose ensureCustomer raises a Stripe ApiErrorException (customer
    // creation failure). The controller must catch it and degrade to handled
    // JSON, never let it bubble into an uncaught 500.
    StripeService::setInstance(new class (new StripeClient(['api_key' => 'sk_test_pm'])) extends StripeService {
        public function ensureCustomer(User $user): string
        {
            throw new \Stripe\Exception\ApiConnectionException('Stripe unreachable');
        }
    });

    $response = (new PaymentMethodController())->createSetupIntent(pmRequest([]), pmResponse(), []);

    $json = json_decode((string) $response->getBody(), true);
    expect($json['ret'])->toBe(0);
});

it('detach clears only the caller\'s current default PM (server-derived, never client-supplied)', function () {
    $user = makePmUser('cus_detach');
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe(['cus_detach' => 'pm_caller_default']);
    StripeService::setInstance($fake);

    // Attacker passes a foreign PM in the body; it MUST be ignored.
    $response = (new PaymentMethodController())->detach(pmRequest([
        'payment_method' => 'pm_victim',
        'pm_id' => 'pm_victim',
        'customer' => 'cus_victim',
    ]), pmResponse(), []);

    expect($fake->detachCalls)->toBe(['pm_caller_default']);

    $json = json_decode((string) $response->getBody(), true);
    expect($json['ret'])->toBe(1);
});

it('detach is a clean no-op when the caller has no stored card', function () {
    $user = makePmUser('cus_nodefault');
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe([]); // no default for this customer
    StripeService::setInstance($fake);

    $response = (new PaymentMethodController())->detach(pmRequest([]), pmResponse(), []);

    expect($fake->detachCalls)->toBe([]);
    $json = json_decode((string) $response->getBody(), true);
    expect($json['ret'])->toBe(1);
});

it('detach acts on each caller\'s own customer only — user B cannot touch user A\'s card', function () {
    makePmUser('cus_A');
    $userB = makePmUser('cus_B');

    $fake = fakePmStripe(['cus_A' => 'pm_A_default', 'cus_B' => 'pm_B_default']);
    StripeService::setInstance($fake);

    // User B is the authenticated caller and tries to target A's PM via the body.
    $GLOBALS['user'] = $userB;
    (new PaymentMethodController())->detach(pmRequest(['pm_id' => 'pm_A_default']), pmResponse(), []);

    // Only B's own default PM was detached; A's was never touched.
    expect($fake->detachCalls)->toBe(['pm_B_default']);
});

it('index returns 200 and renders the publishable key + saved card summary', function () {
    seedPublishableKey('pk_test_index');
    seedRenderPublicSettings();
    $user = makePmUser('cus_index');
    $user->isLogin = false; // View::getTheme -> $_ENV['theme'] (cafe), avoids a numeric theme dir
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe(['cus_index' => 'pm_index_default']);
    StripeService::setInstance($fake);

    $response = (new PaymentMethodController())->index(pmRequest([]), pmResponse(), []);

    expect($response->getStatusCode())->toBe(200);
    $body = (string) $response->getBody();
    expect($body)->toContain('pk_test_index');   // publishable key reached the page
    expect($body)->toContain('4242');            // saved card last4 summary
    expect($body)->toContain('js.stripe.com');   // Stripe.js is loaded
});

it('index renders without a saved card when the customer has none', function () {
    seedPublishableKey('pk_test_empty');
    seedRenderPublicSettings();
    $user = makePmUser('cus_empty');
    $user->isLogin = false;
    $GLOBALS['user'] = $user;

    StripeService::setInstance(fakePmStripe([])); // no default PM

    $response = (new PaymentMethodController())->index(pmRequest([]), pmResponse(), []);

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toContain('pk_test_empty');
});

it('index adopts the latest attached PM as default when the webhook has not set one', function () {
    seedPublishableKey('pk_test_fallback');
    seedRenderPublicSettings();
    $user = makePmUser('cus_fallback');
    $user->isLogin = false;
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe([]); // no default PM…
    $fake->attached = ['cus_fallback' => 'pm_attached_late']; // …but one is attached
    StripeService::setInstance($fake);

    $response = (new PaymentMethodController())->index(pmRequest([]), pmResponse(), []);

    expect($response->getStatusCode())->toBe(200)
        ->and($fake->setDefaultCalls)->toBe([['cus_fallback', 'pm_attached_late']])
        ->and((string) $response->getBody())->toContain('4242');
});

it('index renders a Link payment method with its account email', function () {
    seedPublishableKey('pk_test_link');
    seedRenderPublicSettings();
    $user = makePmUser('cus_link');
    $user->isLogin = false;
    $GLOBALS['user'] = $user;

    $fake = fakePmStripe(['cus_link' => 'pm_link_default']);
    $fake->pmType = 'link';
    StripeService::setInstance($fake);

    $response = (new PaymentMethodController())->index(pmRequest([]), pmResponse(), []);
    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('Link')
        ->and($body)->toContain('linkuser@example.com');
});
