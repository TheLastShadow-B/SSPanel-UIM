<?php

declare(strict_types=1);

use App\Services\DB;
use Tests\TestDatabase;

uses()->group('migration');

beforeEach(function () {
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('exposes stripe_customer_id on user', function () {
    $schema = DB::getCapsule()->schema();
    expect($schema->hasColumn('user', 'stripe_customer_id'))->toBeTrue();
});

it('exposes billing columns on subscription', function () {
    $schema = DB::getCapsule()->schema();
    foreach ([
        'billing_provider', 'auto_renew', 'stripe_subscription_id',
        'stripe_status', 'grace_until', 'hosted_invoice_url',
        'stripe_amount', 'stripe_currency',
    ] as $col) {
        expect($schema->hasColumn('subscription', $col))->toBeTrue("missing {$col}");
    }
});

it('exposes billing_provider on order and invoice', function () {
    $schema = DB::getCapsule()->schema();
    expect($schema->hasColumn('order', 'billing_provider'))->toBeTrue();
    expect($schema->hasColumn('invoice', 'billing_provider'))->toBeTrue();
});

it('defaults subscription.billing_provider to manual on insert', function () {
    $pdo = DB::getPdo();
    $pdo->exec("INSERT INTO subscription (user_id, product_id, product_content, billing_cycle, renewal_price, start_date, end_date, reset_day, last_reset_date, status, created_at, updated_at) VALUES (1, 1, '{}', 'month', 10, '2026-01-01', '2026-01-31', 1, '2026-01-01', 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $row = $pdo->query('SELECT billing_provider FROM subscription LIMIT 1')->fetch();
    expect($row['billing_provider'])->toBe('manual');
});

it('exposes the stripe_event table with a unique event_id', function () {
    $schema = DB::getCapsule()->schema();
    expect($schema->hasTable('stripe_event'))->toBeTrue();
    expect($schema->hasColumn('stripe_event', 'event_id'))->toBeTrue();
});
