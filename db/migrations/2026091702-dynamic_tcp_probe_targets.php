<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::getCapsule()->schema();
        $schema->table('tcp_probe_target', function (Blueprint $table): void {
            $table->increments('id')->change();
        });
        if (! $schema->hasIndex('tcp_probe_target', 'tcp_probe_target_ip_port_unique')) {
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->unique(['ip', 'port']);
            });
        }
        $schema->table('tcp_probe_round', function (Blueprint $table): void {
            $table->longText('results')->change();
        });
        return 2026091702;
    }

    public function down(): int
    {
        $schema = DB::getCapsule()->schema();
        $schema->table('tcp_probe_target', function (Blueprint $table): void {
            $table->unsignedInteger('id')->change();
            $table->dropUnique(['ip', 'port']);
        });
        // Keep the larger report column to avoid truncating recorded results.
        return 2026091701;
    }
};
