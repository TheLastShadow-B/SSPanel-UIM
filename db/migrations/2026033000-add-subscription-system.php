<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $pdo = DB::getPdo();

        // Create subscription table
        $pdo->exec("
            CREATE TABLE subscription (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id         INT UNSIGNED NOT NULL,
                product_id      INT UNSIGNED NOT NULL,
                product_content JSON NOT NULL,
                billing_cycle   ENUM('month','quarter','year') NOT NULL,
                renewal_price   DECIMAL(12,2) NOT NULL,
                start_date      DATE NOT NULL,
                end_date        DATE NOT NULL,
                reset_day       TINYINT UNSIGNED NOT NULL,
                last_reset_date DATE NOT NULL,
                status          ENUM('active','pending_renewal','expired','cancelled') NOT NULL DEFAULT 'active',
                created_at      DATETIME NOT NULL,
                updated_at      DATETIME NOT NULL,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_end_date (end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add subscription_id to order table
        $pdo->exec("ALTER TABLE `order` ADD COLUMN `subscription_id` INT UNSIGNED NULL AFTER `product_content`");

        // Add subscription_renewal_days config
        $pdo->exec("
            INSERT INTO config (item, value, class, is_public, type, `default`, mark)
            VALUES ('subscription_renewal_days', '7', 'cron', 0, 'int', '7', '订阅到期前X天生成续费账单')
        ");

        return 2026033000;
    }

    public function down(): int
    {
        $pdo = DB::getPdo();

        $pdo->exec("DROP TABLE IF EXISTS subscription");
        $pdo->exec("ALTER TABLE `order` DROP COLUMN `subscription_id`");
        $pdo->exec("DELETE FROM config WHERE item = 'subscription_renewal_days'");

        return 2025111300;
    }
};
