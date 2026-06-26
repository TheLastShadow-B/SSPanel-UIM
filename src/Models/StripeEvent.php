<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;

/**
 * @property int    $id
 * @property string $event_id   Stripe evt_xxx
 * @property string $type       Stripe event type
 * @property string $created_at
 *
 * @mixin Builder
 */
final class StripeEvent extends Model
{
    protected $connection = 'default';
    protected $table = 'stripe_event';
}
