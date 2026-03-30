<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;

/**
 * @property int    $id
 * @property int    $user_id
 * @property int    $product_id
 * @property string $product_content
 * @property string $billing_cycle
 * @property float  $renewal_price
 * @property string $start_date
 * @property string $end_date
 * @property int    $reset_day
 * @property string $last_reset_date
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin Builder
 */
final class Subscription extends Model
{
    protected $connection = 'default';
    protected $table = 'subscription';

    /**
     * 订阅状态
     */
    public function status(): string
    {
        return match ($this->status) {
            'active' => '活跃',
            'pending_renewal' => '待续费',
            'expired' => '已过期',
            'cancelled' => '已取消',
            default => '未知',
        };
    }

    /**
     * 账单周期
     */
    public function billingCycle(): string
    {
        return match ($this->billing_cycle) {
            'month' => '月付',
            'quarter' => '季付',
            'year' => '年付',
            default => '未知',
        };
    }
}
