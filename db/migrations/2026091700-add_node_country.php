<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::getCapsule()->schema();
        if (! $schema->hasColumn('node', 'country')) {
            $schema->table('node', function (Blueprint $table): void {
                $table->string('country', 2)->default('')->comment('国家/地区代码，空值按名称识别');
            });
        }

        return 2026091700;
    }

    public function down(): int
    {
        $schema = DB::getCapsule()->schema();
        if ($schema->hasColumn('node', 'country')) {
            $schema->table('node', function (Blueprint $table): void {
                $table->dropColumn('country');
            });
        }

        return 2026062701;
    }
};
