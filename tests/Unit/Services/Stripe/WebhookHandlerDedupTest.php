<?php

declare(strict_types=1);

use App\Models\StripeEvent;
use App\Services\Stripe\WebhookHandler;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * WebhookHandler dispatch framework: idempotency (stripe_event dedup) + routing
 * of live setup_intent.succeeded and legacy native-subscription compatibility
 * handlers.
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

it('routes known event types without throwing and records each once', function () {
    $types = [
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'customer.subscription.deleted',
        'setup_intent.succeeded',
    ];

    $handler = new WebhookHandler();

    foreach ($types as $i => $type) {
        $handler->handle(\Stripe\Event::constructFrom([
            'id' => 'evt_route_' . $i,
            'type' => $type,
            'data' => ['object' => ['id' => 'obj_' . $i]],
        ]));
    }

    expect((new StripeEvent())->count())->toBe(count($types));
});
