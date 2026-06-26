<?php

declare(strict_types=1);

namespace Tests;

use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class TestDatabase
{
    public static function init(): void
    {
        DB::init();
        self::createTables();
    }
    
    private static function createTables(): void
    {
        $capsule = DB::getCapsule();
        $schema = $capsule->schema();
        
        if (!$schema->hasTable('user')) {
            $schema->create('user', function (Blueprint $table) {
                $table->increments('id');
                $table->string('email')->unique();
                $table->string('user_name')->default('');
                $table->string('passwd');
                $table->integer('t')->default(0);
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->bigInteger('transfer_today')->default(0);
                $table->bigInteger('transfer_total')->default(0);
                $table->bigInteger('transfer_enable')->default(0);
                $table->integer('port')->default(0);
                $table->integer('switch')->default(1);
                $table->integer('enable')->default(1);
                $table->integer('type')->default(1);
                $table->dateTime('last_detect_ban_time')->nullable();
                $table->string('last_check_in_time')->nullable();
                $table->string('reg_ip')->default('');
                $table->integer('invite_num')->default(0);
                $table->decimal('money', 10, 2)->default(0);
                $table->string('ref_by_user_name')->default('');
                $table->string('method')->default('aes-256-gcm');
                $table->integer('custom_rss')->default(0);
                $table->string('protocol')->default('origin');
                $table->string('protocol_param')->default('');
                $table->string('obfs')->default('plain');
                $table->string('obfs_param')->default('');
                $table->integer('node_connector')->default(0);
                $table->tinyInteger('is_email_verify')->default(0);
                $table->dateTime('reg_date');
                $table->decimal('in_use', 10, 2)->default(0);
                $table->dateTime('current_login_time')->nullable();
                $table->string('current_login_ip')->default('');
                $table->tinyInteger('auto_reset_bandwidth')->default(0);
                $table->dateTime('auto_reset_bandwidth_date')->nullable();
                $table->integer('c_rebate')->default(10);
                $table->integer('commission')->default(0);
                $table->integer('agent_level')->default(0);
                $table->integer('class')->default(0);
                $table->dateTime('class_expire')->nullable();
                $table->tinyInteger('node_group')->default(0);
                $table->bigInteger('node_speedlimit')->default(0);
                $table->integer('node_iplimit')->default(0);
                $table->integer('theme')->default(1);
                $table->string('ga_token')->default('');
                $table->tinyInteger('ga_enable')->default(0);
                $table->string('telegram_id')->nullable();
                $table->string('discord_id')->nullable();
                $table->string('slack_id')->nullable();
                $table->integer('im_type')->default(0);
                $table->string('im_value')->default('');
                $table->integer('contact_method')->default(1);
                $table->string('stripe_customer_id', 64)->nullable();
                $table->timestamps();
            });
        }
        
        if (!$schema->hasTable('node')) {
            $schema->create('node', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->integer('type')->default(1);
                $table->string('server');
                $table->text('custom_config')->nullable();
                $table->text('info')->nullable();
                $table->text('traffic_rate')->nullable();
                $table->integer('status')->default(1);
                $table->integer('sort')->default(0);
                $table->integer('node_class')->default(0);
                $table->bigInteger('node_speedlimit')->default(0);
                $table->bigInteger('node_bandwidth')->default(0);
                $table->bigInteger('node_bandwidth_limit')->default(0);
                $table->bigInteger('bandwidth_resetday')->default(0);
                $table->tinyInteger('node_heartbeat')->default(0);
                $table->string('node_ip')->nullable();
                $table->tinyInteger('node_group')->default(0);
                $table->tinyInteger('custom_method')->default(0);
                $table->string('password');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        
        if (!$schema->hasTable('node_online_log')) {
            $schema->create('node_online_log', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('node_id');
                $table->integer('online_user');
                $table->integer('log_time');
                $table->index('node_id');
                $table->index('log_time');
            });
        }
        
        if (!$schema->hasTable('user_traffic_log')) {
            $schema->create('user_traffic_log', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->bigInteger('u');
                $table->bigInteger('d');
                $table->integer('node_id');
                $table->double('rate');
                $table->string('traffic');
                $table->integer('log_time');
                $table->index('user_id');
                $table->index('node_id');
                $table->index('log_time');
            });
        }

        if (!$schema->hasTable('config')) {
            $schema->create('config', function (Blueprint $table) {
                $table->increments('id');
                $table->string('item')->unique();
                $table->text('value')->nullable();
                $table->string('class')->default('');
                $table->tinyInteger('is_public')->default(0);
                $table->string('type')->default('string');
                $table->text('default')->nullable();
                $table->string('mark')->default('');
            });
        }

        if (!$schema->hasTable('subscription')) {
            $schema->create('subscription', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('product_id');
                $table->text('product_content');
                $table->string('billing_cycle');
                $table->decimal('renewal_price', 12, 2);
                $table->string('start_date');
                $table->string('end_date');
                $table->tinyInteger('reset_day');
                $table->string('last_reset_date');
                $table->string('status')->default('active');
                $table->string('created_at');
                $table->string('updated_at');
                $table->string('billing_provider', 16)->default('manual');
                $table->tinyInteger('auto_renew')->default(0);
                $table->string('stripe_subscription_id', 64)->nullable();
                $table->string('stripe_status', 24)->nullable();
                $table->dateTime('grace_until')->nullable();
                $table->string('hosted_invoice_url', 512)->nullable();
                $table->bigInteger('stripe_amount')->nullable();
                $table->string('stripe_currency', 8)->nullable();
            });
        }

        if (!$schema->hasTable('product')) {
            $schema->create('product', function (Blueprint $table) {
                $table->increments('id');
                $table->string('type');
                $table->string('name');
                $table->decimal('price', 12, 2)->default(0);
                $table->text('content');
                $table->text('limit');
                $table->integer('status')->default(1);
                $table->integer('create_time')->default(0);
                $table->integer('update_time')->default(0);
                $table->integer('sale_count')->default(0);
                $table->integer('stock')->default(0);
            });
        }

        if (!$schema->hasTable('order')) {
            $schema->create('order', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('product_id');
                $table->string('product_type');
                $table->string('product_name')->default('');
                $table->text('product_content');
                $table->integer('subscription_id')->nullable();
                $table->string('coupon')->default('');
                $table->decimal('price', 12, 2);
                $table->string('status');
                $table->integer('create_time');
                $table->integer('update_time');
                $table->string('billing_provider', 16)->default('manual');
            });
        }

        if (!$schema->hasTable('invoice')) {
            $schema->create('invoice', function (Blueprint $table) {
                $table->increments('id');
                $table->string('type');
                $table->integer('user_id');
                $table->integer('order_id');
                $table->text('content');
                $table->decimal('price', 12, 2);
                $table->string('status');
                $table->integer('create_time');
                $table->integer('update_time');
                $table->integer('pay_time')->nullable();
                $table->string('billing_provider', 16)->default('manual');
            });
        }

        if (!$schema->hasTable('paylist')) {
            $schema->create('paylist', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('userid');
                $table->decimal('total', 12, 2)->default(0);
                $table->tinyInteger('status')->default(0);
                $table->integer('invoice_id')->default(0);
                $table->string('tradeno')->default('');
                $table->string('gateway')->default('');
                $table->integer('datetime')->nullable();
            });
        }

        if (!$schema->hasTable('email_queue')) {
            $schema->create('email_queue', function (Blueprint $table) {
                $table->increments('id');
                $table->string('to_email');
                $table->string('subject');
                $table->string('template');
                $table->text('array');
                $table->integer('time');
            });
        }

        if (!$schema->hasTable('stripe_event')) {
            $schema->create('stripe_event', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('event_id', 64)->unique();
                $table->string('type', 64);
                $table->dateTime('created_at');
            });
        }
    }

    public static function dropTables(): void
    {
        $capsule = DB::getCapsule();
        $schema = $capsule->schema();
        
        $tables = ['stripe_event', 'paylist', 'email_queue', 'invoice', 'order', 'product', 'subscription', 'config', 'user_traffic_log', 'node_online_log', 'node', 'user'];
        
        foreach ($tables as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }
}