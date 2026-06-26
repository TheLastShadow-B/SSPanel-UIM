<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $pdo = DB::getPdo();

        // user
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `stripe_customer_id` VARCHAR(64) NULL");

        // subscription
        $pdo->exec("ALTER TABLE `subscription`
            ADD COLUMN `billing_provider`       VARCHAR(16)  NOT NULL DEFAULT 'manual',
            ADD COLUMN `auto_renew`             TINYINT(1)   NOT NULL DEFAULT 0,
            ADD COLUMN `stripe_subscription_id` VARCHAR(64)  NULL,
            ADD COLUMN `stripe_status`          VARCHAR(24)  NULL,
            ADD COLUMN `grace_until`            DATETIME     NULL,
            ADD COLUMN `hosted_invoice_url`     VARCHAR(512) NULL,
            ADD COLUMN `stripe_amount`          BIGINT       NULL,
            ADD COLUMN `stripe_currency`        VARCHAR(8)   NULL,
            ADD UNIQUE KEY `uniq_stripe_subscription_id` (`stripe_subscription_id`),
            ADD INDEX `idx_billing_provider` (`billing_provider`)");

        // order / invoice
        $pdo->exec("ALTER TABLE `order`   ADD COLUMN `billing_provider` VARCHAR(16) NOT NULL DEFAULT 'manual'");
        $pdo->exec("ALTER TABLE `invoice` ADD COLUMN `billing_provider` VARCHAR(16) NOT NULL DEFAULT 'manual'");

        // stripe_event (webhook idempotency)
        $pdo->exec("
            CREATE TABLE stripe_event (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id   VARCHAR(64) NOT NULL,
                type       VARCHAR(64) NOT NULL,
                created_at DATETIME    NOT NULL,
                UNIQUE KEY uniq_event_id (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // backfill — positive value, avoid NULL three-valued-logic trap (§3)
        $pdo->exec("UPDATE `subscription` SET `billing_provider`='manual' WHERE `billing_provider` IS NULL OR `billing_provider`=''");
        $pdo->exec("UPDATE `order`        SET `billing_provider`='manual' WHERE `billing_provider` IS NULL OR `billing_provider`=''");
        $pdo->exec("UPDATE `invoice`      SET `billing_provider`='manual' WHERE `billing_provider` IS NULL OR `billing_provider`=''");

        // seed config (class='billing'); publishable key is public (goes to frontend)
        $pdo->exec("INSERT INTO config (item, value, class, is_public, type, `default`, mark) VALUES
            ('stripe_publishable_key', '', 'billing', 1, 'string', '', 'Stripe Publishable Key (前端用)'),
            ('stripe_auto_billing_enabled', '0', 'billing', 0, 'bool', '0', 'Stripe 自动续费主开关'),
            ('balance_auto_renew_enabled', '0', 'billing', 0, 'bool', '0', '余额自动续费开关'),
            ('stripe_grace_days', '7', 'billing', 0, 'int', '7', 'Stripe 扣款失败后的宽限天数')");

        return 2026062600;
    }

    public function down(): int
    {
        $pdo = DB::getPdo();

        $pdo->exec("DROP TABLE IF EXISTS stripe_event");
        $pdo->exec("ALTER TABLE `invoice`      DROP COLUMN `billing_provider`");
        $pdo->exec("ALTER TABLE `order`        DROP COLUMN `billing_provider`");
        $pdo->exec("ALTER TABLE `subscription`
            DROP INDEX `uniq_stripe_subscription_id`,
            DROP INDEX `idx_billing_provider`,
            DROP COLUMN `billing_provider`,
            DROP COLUMN `auto_renew`,
            DROP COLUMN `stripe_subscription_id`,
            DROP COLUMN `stripe_status`,
            DROP COLUMN `grace_until`,
            DROP COLUMN `hosted_invoice_url`,
            DROP COLUMN `stripe_amount`,
            DROP COLUMN `stripe_currency`");
        $pdo->exec("ALTER TABLE `user` DROP COLUMN `stripe_customer_id`");
        $pdo->exec("DELETE FROM config WHERE item IN ('stripe_publishable_key','stripe_auto_billing_enabled','balance_auto_renew_enabled','stripe_grace_days')");

        return 2026033000;
    }
};
