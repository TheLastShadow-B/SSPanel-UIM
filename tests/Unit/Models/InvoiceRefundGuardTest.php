<?php

declare(strict_types=1);

use App\Models\Invoice;
use Tests\TestDatabase;

beforeEach(fn () => TestDatabase::init());
afterEach(fn () => TestDatabase::dropTables());

it('refuses to refund a stripe invoice to balance', function () {
    $inv = new Invoice();
    $inv->type = 'product';
    $inv->user_id = 1;
    $inv->order_id = '0';
    $inv->content = '[]';
    $inv->price = 10;
    $inv->status = 'paid_gateway';
    $inv->billing_provider = 'stripe';
    $inv->create_time = time();
    $inv->update_time = time();
    $inv->pay_time = time();
    $inv->save();

    expect(fn () => (new Invoice())->find($inv->id)->refundToBalance())
        ->toThrow(\RuntimeException::class);

    expect((new Invoice())->find($inv->id)->status)->toBe('paid_gateway'); // unchanged
});
