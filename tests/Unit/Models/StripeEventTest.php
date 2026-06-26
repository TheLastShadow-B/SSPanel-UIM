<?php

declare(strict_types=1);

use App\Models\StripeEvent;
use Tests\TestDatabase;

beforeEach(function () {
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('persists a stripe event row', function () {
    $e = new StripeEvent();
    $e->event_id = 'evt_test_1';
    $e->type = 'invoice.paid';
    $e->created_at = '2026-06-26 00:00:00';
    $e->save();

    expect((new StripeEvent())->where('event_id', 'evt_test_1')->first())->not->toBeNull();
    expect($e->getTable())->toBe('stripe_event');
});

it('rejects a duplicate event_id', function () {
    $a = new StripeEvent();
    $a->event_id = 'evt_dup';
    $a->type = 'invoice.paid';
    $a->created_at = '2026-06-26 00:00:00';
    $a->save();

    $b = new StripeEvent();
    $b->event_id = 'evt_dup';
    $b->type = 'invoice.paid';
    $b->created_at = '2026-06-26 00:00:00';

    expect(static fn () => $b->save())->toThrow(Illuminate\Database\QueryException::class);
});
