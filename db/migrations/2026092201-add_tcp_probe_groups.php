<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::getCapsule()->schema();
        if (! $schema->hasTable('tcp_probe_group')) {
            $schema->create('tcp_probe_group', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 40);
                // Empty: the group's targets are maintained by hand (and, in the default group, by Taier).
                $table->string('url', 255)->default('');
                $table->unsignedInteger('interval_minutes')->default(60);
                $table->unsignedInteger('last_attempt')->default(0);
                $table->unsignedInteger('last_success')->default(0);
                $table->unsignedInteger('next_sync_at')->default(0);
                $table->string('last_message', 255)->default('尚未抓取');
                $table->string('last_status', 8)->default('none');
                $table->string('lock_token', 64)->nullable();
                $table->unsignedInteger('lock_until')->default(0);
            });
        }
        DB::table('tcp_probe_group')->insertOrIgnore(['id' => 1, 'name' => '默认']);
        if (! $schema->hasColumn('tcp_probe_target', 'group_id')) {
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->unsignedInteger('group_id')->default(1);
            });
        }
        // An endpoint is unique within a group; two groups may well probe the same relay entry.
        if ($schema->hasIndex('tcp_probe_target', 'tcp_probe_target_ip_port_unique')) {
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->dropUnique('tcp_probe_target_ip_port_unique');
            });
        }
        if (! $schema->hasIndex('tcp_probe_target', 'tcp_probe_target_group_id_ip_port_unique')) {
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->unique(['group_id', 'ip', 'port']);
            });
        }
        if (! $schema->hasColumn('tcp_probe', 'group_id')) {
            $schema->table('tcp_probe', function (Blueprint $table): void {
                $table->unsignedInteger('group_id')->default(1);
            });
        }
        return 2026092201;
    }

    public function down(): int
    {
        $schema = DB::getCapsule()->schema();
        if ($schema->hasColumn('tcp_probe_target', 'group_id')) {
            // Only the default group's list survives: the single-list rule allows one row per endpoint.
            DB::table('tcp_probe_target')->where('group_id', '!=', 1)->delete();
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->dropUnique(['group_id', 'ip', 'port']);
            });
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->dropColumn('group_id');
            });
            $schema->table('tcp_probe_target', function (Blueprint $table): void {
                $table->unique(['ip', 'port']);
            });
        }
        if ($schema->hasColumn('tcp_probe', 'group_id')) {
            $schema->table('tcp_probe', function (Blueprint $table): void {
                $table->dropColumn('group_id');
            });
        }
        $schema->dropIfExists('tcp_probe_group');
        return 2026091704;
    }
};
