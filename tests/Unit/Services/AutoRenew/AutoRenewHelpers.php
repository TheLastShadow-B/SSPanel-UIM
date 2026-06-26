<?php

declare(strict_types=1);

/**
 * Shared DB-backed fixtures for the self-managed auto-renew suite
 * (tests/Unit/Services/AutoRenew/*Test.php).
 *
 * This file is NOT auto-collected by PHPUnit (it does not end in `Test.php`),
 * so each test file pulls it in with `require_once`. require_once dedupes by
 * realpath, and every function is additionally `function_exists`-guarded, so a
 * full-suite run never hits a "Cannot redeclare" fatal.
 *
 * Modelled on makeSubBuyer/makeSubProduct in
 * tests/Unit/Services/Stripe/SubscriptionCheckoutModeTest.php: each helper sets
 * only the columns present in the Tests\TestDatabase schema, then save()s.
 */

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DB;
use App\Services\Stripe\StripeService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

if (! function_exists('ensureUserMoneyLogTable')) {
    /**
     * Tests\TestDatabase does not ship the `user_money_log` table; the balance
     * settlement path writes to it. Create it on demand, mirroring the
     * production migration db/migrations/2023031700-add_user_money_log.php.
     */
    function ensureUserMoneyLogTable(): void
    {
        $schema = DB::getCapsule()->schema();

        if (! $schema->hasTable('user_money_log')) {
            $schema->create('user_money_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('user_id')->default(0);
                $table->decimal('before', 12, 2)->default(0);
                $table->decimal('after', 12, 2)->default(0);
                $table->decimal('amount', 12, 2)->default(0);
                $table->text('remark');
                $table->integer('create_time')->default(0);
            });
        }
    }
}

if (! function_exists('dropUserMoneyLogTable')) {
    function dropUserMoneyLogTable(): void
    {
        DB::getCapsule()->schema()->dropIfExists('user_money_log');
    }
}

if (! function_exists('makeUserWithMoney')) {
    /**
     * A self-managed subscriber with a known balance and an active membership
     * (class > 0) so "not downgraded" assertions are meaningful.
     */
    function makeUserWithMoney(float $money, int $class = 2, ?string $classExpire = null): User
    {
        $user = new User();
        $user->email = 'autorenew_' . bin2hex(random_bytes(6)) . '@example.com';
        $user->user_name = 'autorenew_test';
        $user->passwd = bin2hex(random_bytes(8));
        $user->money = $money;
        $user->class = $class;
        $user->transfer_enable = 1099511627776;
        $user->node_group = 1;
        $user->node_speedlimit = 0;
        $user->node_iplimit = 0;
        $user->class_expire = $classExpire ?? (Carbon::today()->format('Y-m-d') . ' 23:59:59');
        $user->reg_date = date('Y-m-d H:i:s');
        $user->im_type = 0;          // email branch -> avoids IM::send TypeError
        $user->contact_method = 1;
        $user->stripe_customer_id = 'cus_autorenew_' . bin2hex(random_bytes(4));
        $user->save();

        return $user;
    }
}

if (! function_exists('makeSub')) {
    /**
     * A monthly self-managed subscription. Defaults: due today, pending_renewal,
     * auto_renew=1 (the auto-renew waterfall's target population).
     *
     * @param array<string,mixed>|null $content product_content override
     */
    function makeSub(
        User $user,
        float $renewalPrice = 30.0,
        string $endDate = 'today',
        string $status = 'pending_renewal',
        int $autoRenew = 1,
        string $billingProvider = 'manual',
        ?array $content = null,
        ?string $graceUntil = null
    ): Subscription {
        $end = $endDate === 'today' ? Carbon::today()->format('Y-m-d') : $endDate;
        $content ??= [
            'name' => 'Pro',
            'bandwidth' => 100,
            'class' => 2,
            'node_group' => 1,
            'speed_limit' => 0,
            'ip_limit' => 0,
        ];

        $sub = new Subscription();
        $sub->user_id = $user->id;
        $sub->product_id = 1;
        $sub->product_content = json_encode($content);
        $sub->billing_cycle = 'month';
        $sub->renewal_price = $renewalPrice;
        $sub->start_date = Carbon::parse($end)->subMonthNoOverflow()->addDay()->format('Y-m-d');
        $sub->end_date = $end;
        $sub->reset_day = (int) Carbon::parse($end)->format('d');
        $sub->last_reset_date = $end;
        $sub->status = $status;
        $sub->billing_provider = $billingProvider;
        $sub->auto_renew = $autoRenew;
        $sub->grace_until = $graceUntil;
        $sub->created_at = '2026-01-01 00:00:00';
        $sub->updated_at = '2026-01-01 00:00:00';
        $sub->save();

        return $sub;
    }
}

if (! function_exists('makeUnpaidRenewalInvoice')) {
    /**
     * The renewal order + its unpaid invoice, exactly as
     * SubscriptionService::generateRenewalOrder would leave them. Returns the
     * Invoice; the Order is reachable via subscription_id / order_id.
     */
    function makeUnpaidRenewalInvoice(User $user, Subscription $sub, float $price): Invoice
    {
        $content = json_decode($sub->product_content);

        $order = new Order();
        $order->user_id = $user->id;
        $order->product_id = $sub->product_id;
        $order->product_type = 'subscription';
        $order->product_name = $content->name ?? 'Plan';
        $order->product_content = $sub->product_content;
        $order->subscription_id = $sub->id;
        $order->coupon = '';
        $order->price = $price;
        $order->status = 'pending_payment';
        $order->billing_provider = $sub->billing_provider;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $user->id;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([
            ['content_id' => 0, 'name' => $content->name ?? 'Plan', 'price' => $price],
        ]);
        $invoice->price = $price;
        $invoice->status = 'unpaid';
        $invoice->billing_provider = $sub->billing_provider;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return $invoice;
    }
}

if (! function_exists('fakeCardStripe')) {
    /**
     * Stubbed StripeService for the off-session card path. `$pm` is the stored
     * default payment method id (or null for "no card"); `$charge` is either a
     * PaymentIntent status string ('succeeded'/'requires_action'/...) or a
     * \Throwable to raise from chargeOffSession (e.g. a CardException decline).
     * ensureCustomer is overridden so it never hits the network.
     */
    function fakeCardStripe(?string $pm, mixed $charge): StripeService
    {
        $client = new StripeClient(['api_key' => 'sk_test_autorenew_card']);

        return new class ($client, $pm, $charge) extends StripeService {
            /** @var array<int,array<string,mixed>> */
            public array $chargeCalls = [];

            public function __construct(StripeClient $c, public ?string $pm, public mixed $charge)
            {
                parent::__construct($c);
            }

            public function ensureCustomer(User $user): string
            {
                return $user->stripe_customer_id ?: 'cus_test_autorenew';
            }

            public function getDefaultPaymentMethod(string $customerId): ?string
            {
                return $this->pm;
            }

            public function chargeOffSession(
                string $customerId,
                string $paymentMethodId,
                int $amountMinor,
                string $currency,
                string $idempotencyKey,
                array $metadata = []
            ): PaymentIntent {
                $this->chargeCalls[] = compact(
                    'customerId',
                    'paymentMethodId',
                    'amountMinor',
                    'currency',
                    'idempotencyKey',
                    'metadata'
                );

                if ($this->charge instanceof \Throwable) {
                    throw $this->charge;
                }

                return PaymentIntent::constructFrom(['id' => 'pi_autorenew_1', 'status' => $this->charge]);
            }
        };
    }
}
