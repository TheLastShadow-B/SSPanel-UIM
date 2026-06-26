<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RedisException;
use function json_decode;
use function round;

class Exchange
{
    /**
     * Process-wide singleton so callers (e.g. SubscriptionService::chargeRenewalToCard)
     * can be exercised with an offline fake. Mirrors StripeService::getInstance/setInstance.
     */
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * @throws GuzzleException
     * @throws RedisException
     */
    public function exchange(float $amount, string $from, string $to): float
    {
        return round($amount * $this->getExchangeRate($from, $to), 2);
    }

    /**
     * @throws GuzzleException
     * @throws RedisException
     */
    public function getExchangeRate(string $from, string $to): float
    {
        $redis = (new Cache())->initRedis();
        $rate = $redis->get('exchange_rate:' . $from . '_' . $to);

        if (! $rate) {
            $client = new Client();
            $response = $client->get('https://open.er-api.com/v6/latest/USD');
            $data = json_decode($response->getBody()->getContents(), true);
            $rate = $data['rates'][$to] / $data['rates'][$from];
            $redis->setex('exchange_rate:' . $from . '_' . $to, 3600, $rate);
        }

        return (float) $rate;
    }
}
