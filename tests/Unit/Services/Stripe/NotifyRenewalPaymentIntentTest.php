<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Paylist;
use App\Models\User;
use App\Services\Gateway\Stripe;
use GuzzleHttp\Psr7\HttpFactory;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * Stripe::notify — payment_intent.succeeded routing (P2 webhook no-op).
 *
 * Off-session self-managed renewal PaymentIntents (created by
 * SubscriptionService::chargeRenewalToCard) carry ONLY metadata.invoice_id —
 * no trade_no — and are already settled inline by the auto-renew cron. The
 * notify handler used to call postPayment($object->metadata->trade_no) blindly;
 * with no trade_no that is postPayment(null) -> TypeError -> 500 -> Stripe
 * retry loop. notify must treat a missing trade_no as a handled no-op, while an
 * event WITH a trade_no still routes through postPayment as before.
 *
 * The webhook is signed locally with the configured endpoint secret (HMAC-SHA256
 * over "{timestamp}.{payload}"), so Webhook::constructEvent verifies for real
 * and nothing touches the network.
 * ---------------------------------------------------------------------------
 */

const NOTIFY_ENDPOINT_SECRET = 'whsec_test_notify_secret';

beforeEach(function () {
    TestDatabase::init();

    Config::query()->updateOrInsert(
        ['item' => 'stripe_endpoint_secret'],
        ['value' => NOTIFY_ENDPOINT_SECRET, 'class' => 'billing', 'type' => 'string']
    );
});

afterEach(function () {
    TestDatabase::dropTables();
});

/**
 * Build a Stripe webhook request whose Stripe-Signature header is valid for the
 * test endpoint secret, with $payload as the raw (already-JSON) body.
 */
function notifyRequest(string $payload)
{
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, NOTIFY_ENDPOINT_SECRET);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $guzzle = new HttpFactory();
    $stream = $guzzle->createStream($payload);
    $stream->rewind();

    $request = (new DecoratedServerRequestFactory($guzzle))
        ->createServerRequest('POST', '/payment/notify/stripe')
        ->withHeader('Stripe-Signature', $header)
        ->withBody($stream);

    $response = (new DecoratedResponseFactory($guzzle, $guzzle))->createResponse();

    return [$request, $response];
}

it('treats a renewal payment_intent.succeeded with only invoice_id (no trade_no) as a safe no-op', function () {
    // A self-managed renewal PI: metadata carries invoice_id but NO trade_no. There is no Paylist to
    // advance (the cron already settled it inline), so notify must NOT call postPayment(null). It
    // should verify the signature, recognise the missing trade_no, and acknowledge with 204.
    $payload = json_encode([
        'id' => 'evt_renew_noop_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_renew_1',
            'status' => 'succeeded',
            'metadata' => ['invoice_id' => '4242'],
        ]],
    ]);

    [$request, $response] = notifyRequest($payload);

    // Must not throw (the bug was postPayment(null) -> TypeError -> 500 retry loop).
    $result = (new Stripe())->notify($request, $response, []);

    expect($result->getStatusCode())->toBe(204);
});

it('still routes a one-time payment_intent.succeeded WITH a trade_no through postPayment', function () {
    // The classic mode:'payment' checkout PI carries metadata.trade_no. notify must still settle it:
    // postPayment looks the Paylist up by tradeno and flips its unpaid invoice to paid_gateway.
    $user = new User();
    $user->email = 'notify_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'notify_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->money = 0;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->save();

    $invoice = new Invoice();
    $invoice->type = 'product';
    $invoice->user_id = $user->id;
    $invoice->order_id = 0;
    $invoice->content = json_encode([['content_id' => 0, 'name' => 'One-off', 'price' => 30]]);
    $invoice->price = 30;
    $invoice->status = 'unpaid';
    $invoice->create_time = time();
    $invoice->update_time = time();
    $invoice->save();

    $paylist = new Paylist();
    $paylist->userid = $user->id;
    $paylist->total = 30;
    $paylist->status = 0;
    $paylist->invoice_id = $invoice->id;
    $paylist->tradeno = 'trade_notify_1';
    $paylist->gateway = 'Stripe';
    $paylist->save();

    $payload = json_encode([
        'id' => 'evt_oneoff_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_oneoff_1',
            'status' => 'succeeded',
            'metadata' => ['trade_no' => 'trade_notify_1'],
        ]],
    ]);

    [$request, $response] = notifyRequest($payload);

    $result = (new Stripe())->notify($request, $response, []);

    expect($result->getStatusCode())->toBe(204);
    // postPayment ran: the Paylist is marked paid and the invoice settled to paid_gateway.
    expect((int) (new Paylist())->find($paylist->id)->status)->toBe(1);
    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_gateway');
});
