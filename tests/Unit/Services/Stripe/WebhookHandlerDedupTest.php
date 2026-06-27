<?php

declare(strict_types=1);

use App\Models\StripeEvent;
use App\Services\Stripe\WebhookHandler;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * WebhookHandler dispatch framework: idempotency (stripe_event dedup) + routing
 * of the live setup_intent.succeeded handler. The native-subscription event
 * routing (checkout.session.*, invoice.*, customer.subscription.*) was removed
 * with its handlers, so those types are now safe default no-ops.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('records the event id once and dedups a replay', function () {
    $event = \Stripe\Event::constructFrom([
        'id' => 'evt_dedup_1',
        'type' => 'some.dedup.event',
        'data' => ['object' => ['id' => 'obj_x']],
    ]);

    $handler = new WebhookHandler();
    $handler->handle($event);
    $handler->handle($event); // replay

    expect((new StripeEvent())->where('event_id', 'evt_dedup_1')->count())->toBe(1);
});

it('records the event type for an unknown event without throwing', function () {
    $event = \Stripe\Event::constructFrom([
        'id' => 'evt_unknown_1',
        'type' => 'some.unhandled.event',
        'data' => ['object' => []],
    ]);

    (new WebhookHandler())->handle($event);

    expect((new StripeEvent())->where('event_id', 'evt_unknown_1')->first()->type)
        ->toBe('some.unhandled.event');
});

it('routes the live setup_intent.succeeded type without throwing and records it once', function () {
    $event = \Stripe\Event::constructFrom([
        'id' => 'evt_route_setup_intent',
        'type' => 'setup_intent.succeeded',
        'data' => ['object' => ['id' => 'seti_route']],
    ]);

    (new WebhookHandler())->handle($event);

    expect((new StripeEvent())->where('event_id', 'evt_route_setup_intent')->count())->toBe(1);
});
