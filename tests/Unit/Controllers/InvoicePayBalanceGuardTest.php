<?php

declare(strict_types=1);

use App\Controllers\User\InvoiceController;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DB;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Schema\Blueprint;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Tests\TestDatabase;
use voku\helper\AntiXSS;

/*
 * ---------------------------------------------------------------------------
 * InvoiceController::payBalance — lapsed invoices must be unpayable.
 *
 * A renewal invoice that terminateLapsed() cancelled (or any non-unpaid invoice)
 * must NOT be settleable from balance: the top guard rejects it before any money
 * moves. The positive control proves the guard does not over-block a real unpaid
 * invoice.
 *
 * InvoiceController is final, so we build it without its (Auth-touching)
 * constructor and inject the protected user/antiXss via reflection.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();

    // payBalance writes UserMoneyLog on the happy path; create it on demand
    // (mirrors the production migration; Tests\TestDatabase ships no such table).
    $schema = DB::getCapsule()->schema();
    if (! $schema->hasTable('user_money_log')) {
        $schema->create('user_money_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->default(0);
            $table->decimal('before', 12, 2)->default(0);
            $table->decimal('after', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('remark');
            $table->integer('create_time')->default(0);
        });
    }
});

afterEach(function () {
    DB::getCapsule()->schema()->dropIfExists('user_money_log');
    TestDatabase::dropTables();
});

function payGuardUser(float $money): User
{
    $user = new User();
    $user->email = 'invpay_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'invpay_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->money = $money;
    $user->class = 1;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->save();

    return $user;
}

function payGuardInvoice(User $user, string $status, float $price = 30.0): Invoice
{
    $invoice = new Invoice();
    $invoice->type = 'product';
    $invoice->user_id = $user->id;
    $invoice->order_id = 0;
    $invoice->content = json_encode([['content_id' => 0, 'name' => 'Renewal', 'price' => $price]]);
    $invoice->price = $price;
    $invoice->status = $status;
    $invoice->create_time = time();
    $invoice->update_time = time();
    $invoice->save();

    return $invoice;
}

function payGuardCall(User $user, int $invoiceId)
{
    $ref = new ReflectionClass(InvoiceController::class);
    $controller = $ref->newInstanceWithoutConstructor();

    $userProp = $ref->getProperty('user');
    $userProp->setAccessible(true);
    $userProp->setValue($controller, $user);

    $xssProp = $ref->getProperty('antiXss');
    $xssProp->setAccessible(true);
    $xssProp->setValue($controller, new AntiXSS());

    $guzzle = new HttpFactory();
    $request = (new DecoratedServerRequestFactory($guzzle))
        ->createServerRequest('POST', '/user/invoice/pay')
        ->withParsedBody(['invoice_id' => (string) $invoiceId]);
    $response = (new DecoratedResponseFactory($guzzle, $guzzle))->createResponse();

    return $controller->payBalance($request, $response, []);
}

it('rejects paying a cancelled invoice and leaves the balance untouched', function () {
    $user = payGuardUser(100.0);
    $invoice = payGuardInvoice($user, 'cancelled', 30.0);

    $response = payGuardCall($user, $invoice->id);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['ret'])->toBe(0);

    // No money moved; invoice still cancelled.
    expect((float) (new User())->find($user->id)->money)->toBe(100.0);
    expect((new Invoice())->find($invoice->id)->status)->toBe('cancelled');
});

it('rejects paying a processing invoice (mid off-session charge) and leaves the balance untouched', function () {
    // 'processing' is the sentinel chargeRenewalToCard sets while it holds an off-session card
    // charge in flight. The user must NOT be able to settle it from balance in that window, or the
    // renewal is charged twice (P1-4). The guard rejects it (not in ['unpaid','partially_paid']).
    $user = payGuardUser(100.0);
    $invoice = payGuardInvoice($user, 'processing', 30.0);

    $response = payGuardCall($user, $invoice->id);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['ret'])->toBe(0);

    // No money moved; invoice still processing (left for the card path to settle or release).
    expect((float) (new User())->find($user->id)->money)->toBe(100.0);
    expect((new Invoice())->find($invoice->id)->status)->toBe('processing');
});

it('still settles a genuinely unpaid invoice from balance (guard does not over-block)', function () {
    $user = payGuardUser(100.0);
    $invoice = payGuardInvoice($user, 'unpaid', 30.0);

    $response = payGuardCall($user, $invoice->id);

    // Happy path redirects via HX-Redirect (not a JSON error).
    expect($response->getHeaderLine('HX-Redirect'))->toBe('/user/invoice');
    expect((float) (new User())->find($user->id)->money)->toBe(70.0);
    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_balance');
});

it('aborts and deducts nothing when the invoice flips to processing under the deduction lock (race seam)', function () {
    // P1 double-charge race: a request reads the invoice as 'unpaid' (passes the top guard), but a
    // concurrent off-session card charge (chargeRenewalToCard) claims it 'unpaid' -> 'processing'
    // BEFORE this request deducts. Settling from the stale in-memory read would charge the renewal
    // twice. The fix re-reads the invoice under lockForUpdate INSIDE the deduction transaction and
    // re-checks payability there; if it is no longer payable it must abort and move no money.
    //
    // We model the concurrent claim deterministically with a beforeExecuting hook that flips the row
    // to 'processing' exactly when the locked re-read (SELECT ... FOR UPDATE) is about to run — i.e.
    // strictly between the initial unlocked read and the in-lock deduction.
    $user = payGuardUser(100.0);
    $invoice = payGuardInvoice($user, 'unpaid', 30.0);

    $flipped = false;
    DB::getCapsule()->getConnection()->beforeExecuting(
        function ($query, $bindings, $connection) use (&$flipped, $invoice) {
            if (! $flipped && stripos($query, 'for update') !== false && stripos($query, 'invoice') !== false) {
                $flipped = true;
                $connection->update('update `invoice` set `status` = ? where `id` = ?', ['processing', $invoice->id]);
            }
        }
    );

    $response = payGuardCall($user, $invoice->id);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['ret'])->toBe(0);

    // Nothing deducted; the invoice is left 'processing' for the card path to settle or release.
    expect((float) (new User())->find($user->id)->money)->toBe(100.0);
    expect((new Invoice())->find($invoice->id)->status)->toBe('processing');
});

it('accepts completing a partially_paid invoice from balance (still payable, not over-blocked)', function () {
    // A partially_paid invoice is still payable: view.tpl renders an active 余额支付
    // button for it, and Gateway/Base + Cron::processPendingOrder both treat it as
    // outstanding. The guard must let the user finish paying it from balance.
    $user = payGuardUser(100.0);
    $invoice = payGuardInvoice($user, 'partially_paid', 30.0);

    $response = payGuardCall($user, $invoice->id);

    // Settled from balance: redirects (not a JSON error), balance deducted.
    expect($response->getHeaderLine('HX-Redirect'))->toBe('/user/invoice');
    expect((float) (new User())->find($user->id)->money)->toBe(70.0);
    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_balance');
});
