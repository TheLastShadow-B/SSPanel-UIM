<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::getCapsule()->schema();
        if (! $schema->hasColumn('tcp_probe_source', 'last_status')) {
            $schema->table('tcp_probe_source', function (Blueprint $table): void {
                // none | ok | failed: written by sync() in the same update as last_message,
                // so the status dot can never disagree with the text beside it.
                $table->string('last_status', 8)->default('none');
            });
            // Existing rows: derive the verdict once from the timestamps the dot used to be computed from.
            DB::table('tcp_probe_source')->update(['last_status' => DB::raw(
                "CASE WHEN last_success > 0 AND last_success >= last_attempt THEN 'ok' WHEN last_attempt > 0 THEN 'failed' ELSE 'none' END"
            )]);
        }
        return 2026091704;
    }

    public function down(): int
    {
        $schema = DB::getCapsule()->schema();
        if ($schema->hasColumn('tcp_probe_source', 'last_status')) {
            $schema->table('tcp_probe_source', function (Blueprint $table): void {
                $table->dropColumn('last_status');
            });
        }
        return 2026091703;
    }
};
