<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use function in_array;
use function round;
use function strtoupper;

/**
 * Stripe currency helpers.
 *
 * toMinorUnits converts a major-unit amount into Stripe's smallest currency
 * unit, honouring the zero-decimal currency list. It is used by the self-managed
 * renewal engine (SubscriptionService::chargeRenewalToCard) for the off-session
 * card charge.
 *
 * NOTE: the recurring-Price resolve() helper (and its
 * payment_method_types-free Price creation) belonged to the removed native
 * Stripe-subscription flow and has been deleted.
 */
final class PriceResolver
{
    /**
     * Currencies Stripe treats as zero-decimal (amount is already in the
     * smallest unit, so it must NOT be multiplied by 100).
     *
     * https://docs.stripe.com/currencies#zero-decimal
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
        'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Pure: convert a major-unit amount into Stripe minor units.
     *
     * Zero-decimal currencies (JPY/VND/KRW/...) are NOT multiplied by 100.
     * Currency matching is case-insensitive.
     */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }
}
