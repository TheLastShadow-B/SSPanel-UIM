<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\Config;
use App\Models\Product;
use App\Services\Exchange;
use App\Services\SubscriptionService;
use function count;
use function in_array;
use function json_decode;
use function round;
use function strtoupper;

/**
 * Resolves a SSPanel product + billing cycle into a recurring Stripe Price.
 *
 * The CNY catalog price is converted to the configured stripe_currency using
 * the same Exchange service + zero-decimal logic as the one-time Stripe gateway
 * (src/Services/Gateway/Stripe.php), then a recurring Price is created or reused
 * via the injectable StripeService so it stays stubbable in tests.
 *
 * NEVER passes payment_method_types — dynamic payment methods are used.
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

    /**
     * Resolve (creating or reusing) a recurring Stripe Price for product+cycle.
     *
     * Computes the per-cycle CNY price via SubscriptionService::calculateCyclePrice,
     * converts it to stripe_currency, then maps the cycle to a Stripe recurring
     * interval (month=1mo / quarter=3mo / year=1yr). Prices are keyed by a
     * deterministic lookup_key so repeat calls reuse the same Stripe Price.
     *
     * @return array{price_id: string, amount: int, currency: string}
     */
    public static function resolve(Product $product, string $cycle): array
    {
        $currency = (string) Config::obtain('stripe_currency');
        $content = json_decode($product->content);

        // Per-cycle CNY amount via the existing self-managed billing math.
        $cnyAmount = SubscriptionService::calculateCyclePrice(
            (float) $product->price,
            $cycle,
            $content
        );

        $fxAmount = (new Exchange())->exchange($cnyAmount, 'CNY', $currency);
        $amount = self::toMinorUnits((float) $fxAmount, $currency);

        $interval = match ($cycle) {
            'month' => ['interval' => 'month', 'interval_count' => 1],
            'quarter' => ['interval' => 'month', 'interval_count' => 3],
            'year' => ['interval' => 'year', 'interval_count' => 1],
        };

        $lookupKey = "sspanel_p{$product->id}_{$cycle}_{$currency}_{$amount}";

        $client = StripeService::getInstance()->client();

        $existing = $client->prices->all([
            'lookup_keys' => [$lookupKey],
            'limit' => 1,
        ]);

        if (count($existing->data) > 0) {
            $price = $existing->data[0];
        } else {
            $price = $client->prices->create([
                'currency' => $currency,
                'unit_amount' => $amount,
                'recurring' => $interval,
                'lookup_key' => $lookupKey,
                'product_data' => ['name' => $product->name],
            ]);
        }

        return [
            'price_id' => $price->id,
            'amount' => $amount,
            'currency' => $currency,
        ];
    }
}
