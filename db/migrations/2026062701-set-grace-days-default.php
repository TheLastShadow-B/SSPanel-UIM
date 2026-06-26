<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $pdo = DB::getPdo();

        // Spec wants a 3-day grace window; 2026062600 seeded '7'. Only touch the
        // still-default value ('' or '7') so an admin's custom setting is preserved.
        $pdo->exec("UPDATE config SET value='3', `default`='3' WHERE item='stripe_grace_days' AND value IN ('','7')");

        return 2026062701;
    }

    public function down(): int
    {
        // Intentional no-op: re-seeding '7' would clobber values that have
        // legitimately settled on '3', and the default tweak carries no schema
        // change to reverse. Just report the previous version.
        return 2026062600;
    }
};
