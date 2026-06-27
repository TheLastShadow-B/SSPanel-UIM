<?php

declare(strict_types=1);

use App\Services\Stripe\PriceResolver;

/*
 * ---------------------------------------------------------------------------
 * toMinorUnits — PURE, no DB / no Stripe. Exercised with many currency cases.
 *
 * The recurring-Price resolve() path was native-Stripe-subscription-only and
 * has been removed; its (Config/Exchange/Stripe-backed) cases were dropped with
 * it. toMinorUnits stays live — chargeRenewalToCard uses it for the off-session
 * card charge of the self-managed renewal engine.
 * ---------------------------------------------------------------------------
 */

it('multiplies by 100 for normal currencies', function () {
    expect(PriceResolver::toMinorUnits(12.34, 'USD'))->toBe(1234);
    expect(PriceResolver::toMinorUnits(10.0, 'EUR'))->toBe(1000);
    expect(PriceResolver::toMinorUnits(0.99, 'GBP'))->toBe(99);
});

it('does not multiply for zero-decimal currencies', function () {
    expect(PriceResolver::toMinorUnits(1500.0, 'JPY'))->toBe(1500);
    expect(PriceResolver::toMinorUnits(2000.0, 'VND'))->toBe(2000);
    expect(PriceResolver::toMinorUnits(3000.0, 'KRW'))->toBe(3000);
    expect(PriceResolver::toMinorUnits(500.0, 'XOF'))->toBe(500);
    expect(PriceResolver::toMinorUnits(42.0, 'CLP'))->toBe(42);
});

it('is case-insensitive for currency and rounds', function () {
    expect(PriceResolver::toMinorUnits(9.999, 'usd'))->toBe(1000);
    expect(PriceResolver::toMinorUnits(1499.6, 'jpy'))->toBe(1500);
    expect(PriceResolver::toMinorUnits(1500.4, 'Jpy'))->toBe(1500);
    expect(PriceResolver::toMinorUnits(12.345, 'uSd'))->toBe(1235);
    expect(PriceResolver::toMinorUnits(12.344, 'USD'))->toBe(1234);
});

it('handles zero amount for both currency families', function () {
    expect(PriceResolver::toMinorUnits(0.0, 'USD'))->toBe(0);
    expect(PriceResolver::toMinorUnits(0.0, 'JPY'))->toBe(0);
});
