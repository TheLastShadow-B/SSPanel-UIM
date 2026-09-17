<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::getCapsule()->schema();
        if (! $schema->hasTable('tcp_probe_target')) {
            $schema->create('tcp_probe_target', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('carrier', 16);
                $table->string('label', 80);
                $table->string('ip', 45);
                $table->unsignedInteger('port');
            });
        }
        if (! $schema->hasTable('tcp_probe')) {
            $schema->create('tcp_probe', function (Blueprint $table): void {
                $table->unsignedInteger('node_id')->primary();
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('threshold_ms')->default(250);
            });
        }
        if (! $schema->hasTable('tcp_probe_round')) {
            $schema->create('tcp_probe_round', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('node_id');
                $table->unsignedInteger('minute');
                $table->unsignedInteger('measured_at')->index();
                $table->string('config_hash', 64);
                $table->text('results');
                $table->text('states');
                $table->unique(['node_id', 'minute']);
            });
        }
        return 2026091701;
    }

    public function down(): int
    {
        $schema = DB::getCapsule()->schema();
        foreach (['tcp_probe_round', 'tcp_probe', 'tcp_probe_target'] as $table) {
            $schema->dropIfExists($table);
        }
        return 2026091700;
    }
};
