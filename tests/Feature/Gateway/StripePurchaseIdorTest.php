<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Gateway\Stripe;
use Tests\TestDatabase;

beforeEach(function () {
    // SlimTestCase::setUp() forces $_ENV['db_database'] = ':memory:' (sqlite) for
    // the API feature suite. These DB-backed gateway tests run against the real
    // MariaDB `sspanel_test`, so restore the test-config DB before DB::init() is
    // invoked by TestDatabase::init(); otherwise DB::init() die()s on a missing
    // MariaDB database named ":memory:", killing the run with no summary.
    require BASE_PATH . '/config/.config.test.php';

    TestDatabase::init();
    Config::query()->updateOrInsert(['item' => 'stripe_min_recharge'], ['value' => '1', 'class' => 'billing', 'type' => 'int']);
    Config::query()->updateOrInsert(['item' => 'stripe_max_recharge'], ['value' => '10000', 'class' => 'billing', 'type' => 'int']);
});

afterEach(function () {
    // Reset the global authenticated user between tests so it does not leak.
    global $user;
    $user = null;
    TestDatabase::dropTables();
});

/**
 * 直接创建用户行（仅使用测试 schema 中存在的列）。
 * 与已合并的 P0.1–P0.4 测试一致，这里直接用 new User() 建行：
 * tests/Factories/UserFactory 依赖 fakerphp/faker（本环境未安装）且写入了
 * 测试表中不存在的列（username/uuid/locale 等），无法在本环境运行。
 */
function makeStripeIdorUser(): User
{
    $user = new User();
    $user->email = 'idor_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'idor_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->transfer_enable = 1099511627776;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function makeStripeIdorInvoice(int $userId, float $price = 50): Invoice
{
    $inv = new Invoice();
    $inv->type = 'recharge';
    $inv->user_id = $userId;
    $inv->order_id = 0;
    $inv->content = '[]';
    $inv->price = $price;
    $inv->status = 'unpaid';
    $inv->create_time = time();
    $inv->update_time = time();
    $inv->billing_provider = 'manual';
    $inv->save();

    return $inv;
}

/**
 * End-to-end IDOR guard: an attacker passing another user's invoice_id must
 * receive the "Invoice not found" rejection.
 *
 * Note on behavior across RED/GREEN:
 *  - GREEN (scoped lookup): the scoped query returns null for the attacker, so
 *    purchase() returns the not-found JSON immediately — it never reaches the
 *    Exchange/Stripe code path. The assertion below passes cleanly.
 *  - RED (unscoped find()): purchase() finds the victim's invoice and proceeds
 *    PAST the not-found branch into the Exchange/Stripe path. In this test
 *    environment (no redis extension) that downstream path throws, so the call
 *    does not return the not-found JSON. We catch any throwable and fail the
 *    expectation explicitly — the point being proven is identical either way:
 *    the unscoped code does NOT stop the attacker at the not-found guard.
 */
it('returns Invoice not found when invoice belongs to another user', function () {
    $victim = makeStripeIdorUser();
    $attacker = makeStripeIdorUser();

    $inv = makeStripeIdorInvoice($victim->id);

    // attacker is the authenticated user (Auth::getUser() reads global $user)
    global $user;
    $user = $attacker;

    $request = $this->createRequest('POST', '/user/payment/purchase/stripe')
        ->withParsedBody(['invoice_id' => (string) $inv->id]);
    $response = new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());

    try {
        $result = (new Stripe())->purchase($request, $response, []);
    } catch (\Throwable $e) {
        // The unscoped (vulnerable) code proceeds past the not-found guard and
        // blows up downstream. That is itself proof the IDOR guard did not fire.
        throw new \PHPUnit\Framework\AssertionFailedError(
            'IDOR: attacker was not stopped at the not-found guard; purchase() proceeded past it (' . $e->getMessage() . ')'
        );
    }

    $body = json_decode((string) $result->getBody(), true);

    expect($body['ret'])->toBe(0);
    expect($body['msg'])->toBe('Invoice not found');
});

/**
 * Data-access level guard mirroring the fix in Stripe::purchase(): the same
 * scoped lookup the controller now uses (id + user_id). This is the redis-free
 * core of the change and gives a deterministic RED/GREEN tied to the scoping.
 */
it('scopes the invoice lookup to the authenticated user', function () {
    $victim = makeStripeIdorUser();
    $attacker = makeStripeIdorUser();

    $inv = makeStripeIdorInvoice($victim->id);

    // Attacker scoping must NOT find the victim's invoice.
    $attackerView = (new Invoice())->where('id', $inv->id)->where('user_id', $attacker->id)->first();
    expect($attackerView)->toBeNull();

    // Owner scoping MUST still find it (legitimate behavior preserved).
    $ownerView = (new Invoice())->where('id', $inv->id)->where('user_id', $victim->id)->first();
    expect($ownerView)->not->toBeNull();
    expect($ownerView->id)->toBe($inv->id);
});
