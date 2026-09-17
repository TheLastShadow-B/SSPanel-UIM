<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::getCapsule()->schema();
        if (! $schema->hasColumn('tcp_probe_target', 'source')) {
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->string('source', 16)->default('manual');
                $table->string('source_key', 80)->nullable()->unique();
                $table->string('source_host_id', 64)->nullable();
            });
        }
        if (! $schema->hasTable('tcp_probe_source')) {
            $schema->create('tcp_probe_source', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->boolean('enabled')->default(false);
                $table->text('cities');
                $table->unsignedInteger('interval_hours')->default(6);
                $table->unsignedInteger('last_attempt')->default(0);
                $table->unsignedInteger('last_success')->default(0);
                $table->unsignedInteger('next_sync_at')->default(0);
                $table->string('last_message', 255)->default('尚未同步');
                $table->string('lock_token', 64)->nullable();
                $table->unsignedInteger('lock_until')->default(0);
            });
        }
        DB::table('tcp_probe_source')->insertOrIgnore([
            'id' => 1, 'cities' => json_encode(['北京', '上海', '广州'], JSON_UNESCAPED_UNICODE),
        ]);
        return 2026091703;
    }

    public function down(): int
    {
        $schema = DB::getCapsule()->schema();
        $schema->dropIfExists('tcp_probe_source');
        $schema->table('tcp_probe_target', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source', 'source_key', 'source_host_id']);
        });
        return 2026091702;
    }
};
