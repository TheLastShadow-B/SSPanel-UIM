<?php

declare(strict_types=1);

use App\Models\DetectBanLog;
use App\Models\DetectLog;
use App\Models\EmailQueue;
use App\Models\Node;
use App\Models\User;
use App\Services\Detect;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * Detect::ban — 审计封禁计数。
 *
 * 上游 bug:计数循环里 `$user_logs[$uid] = 0;` 在每条日志上无条件重置再 ++,
 * 导致同一批次内无论多少条违规,计数恒为 1,阈值(auto_detect_ban_number)
 * 语义完全失效。修复后同批多条日志必须正确累计。
 *
 * Detect::gfw — 外部 tcping API 不可达时(file_get_contents 失败/返回非法 JSON)
 * 必须跳过该节点,不得把节点误判为被墙、不得给管理员发告警。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    detectEnsureTables();

    $_ENV['auto_detect_ban_allow_admin'] = true;
    $_ENV['auto_detect_ban_allow_users'] = [];
    $_ENV['auto_detect_ban_number'] = 3;
    $_ENV['auto_detect_ban_time'] = 60;
    $_ENV['detect_gfw_port'] = 443;
});

afterEach(function () {
    $schema = \App\Services\DB::getCapsule()->schema();
    $schema->dropIfExists('detect_log');
    $schema->dropIfExists('detect_ban_log');
    TestDatabase::dropTables();
});

if (! function_exists('detectEnsureTables')) {
    /**
     * TestDatabase 不含审计相关表/列,按需补齐(镜像生产 init 迁移的字段)。
     */
    function detectEnsureTables(): void
    {
        $schema = \App\Services\DB::getCapsule()->schema();

        // 这两张表不在 TestDatabase::dropTables() 清单里,若不强制重建,残留行会带着
        // 已回收复用的 user_id 污染 detect_number 计算(user 表每轮重建、ID 从 1 重排)。
        $schema->dropIfExists('detect_log');
        $schema->dropIfExists('detect_ban_log');

        {
            $schema->create('detect_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->default(0);
                $table->unsignedBigInteger('list_id')->default(0);
                $table->unsignedInteger('datetime')->default(0);
                $table->unsignedBigInteger('node_id')->default(0);
                $table->unsignedTinyInteger('status')->default(0);
            });
        }

        {
            $schema->create('detect_ban_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->default(0);
                $table->integer('detect_number')->default(0);
                $table->integer('ban_time')->default(0);
                $table->integer('start_time')->default(0);
                $table->integer('end_time')->default(0);
                $table->integer('all_detect_number')->default(0);
            });
        }

        foreach ([
            'is_admin' => 'tinyInteger',
            'is_banned' => 'tinyInteger',
            'all_detect_number' => 'integer',
        ] as $col => $type) {
            if (! $schema->hasColumn('user', $col)) {
                $schema->table('user', function (Blueprint $table) use ($col, $type) {
                    $table->{$type}($col)->default(0);
                });
            }
        }

        if (! $schema->hasColumn('user', 'banned_reason')) {
            $schema->table('user', function (Blueprint $table) {
                $table->string('banned_reason')->default('');
            });
        }

        foreach (['ipv4' => 'string', 'online' => 'tinyInteger', 'gfw_block' => 'tinyInteger'] as $col => $type) {
            if (! $schema->hasColumn('node', $col)) {
                $schema->table('node', function (Blueprint $table) use ($col, $type) {
                    $type === 'string'
                        ? $table->string($col)->default('')
                        : $table->tinyInteger($col)->default(0);
                });
            }
        }
    }

    /**
     * 生产库 user.last_detect_ban_time 为 NOT NULL DEFAULT '1989-06-04 00:05:00'
     * (2023020100-init.php:332);TestDatabase 把它建成了 nullable 且工厂不赋值,
     * 会让 strtotime(null) 在测试里假崩。此处对齐生产形状。
     */
    function detectMakeAuditUser(): User
    {
        $user = makeUserWithMoney(0.0);
        $user->last_detect_ban_time = '1989-06-04 00:05:00';
        $user->save();

        return $user;
    }

    function detectMakeLogs(int $userId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            (new DetectLog())->insert([
                'user_id' => $userId,
                'list_id' => 1,
                'node_id' => 1,
                'datetime' => time(),
                'status' => 0,
            ]);
        }
    }

    function detectMakeNode(string $ipv4 = '203.0.113.10'): Node
    {
        $node = new Node();
        $node->name = 'detect-node';
        $node->server = 'node.test.local';
        $node->password = 'x';
        $node->type = 1;
        $node->ipv4 = $ipv4;
        $node->online = 1;
        $node->gfw_block = 0;
        $node->save();

        return $node;
    }
}

it('accumulates multiple logs of one user within a single batch', function () {
    $user = detectMakeAuditUser();
    detectMakeLogs((int) $user->id, 3);

    ob_start();
    Detect::ban();
    ob_get_clean();

    $fresh = (new User())->find($user->id);
    // 3 条违规一次性达到阈值 3 → 立即封禁(修复前计数恒为 1,永远达不到)
    expect((int) $fresh->all_detect_number)->toBe(3);
    expect((int) $fresh->is_banned)->toBe(1);
    expect($fresh->banned_reason)->toBe('DetectBan');
    expect((new DetectBanLog())->where('user_id', $user->id)->count())->toBe(1);
    // 日志全部标记已处理
    expect((new DetectLog())->where('status', 0)->count())->toBe(0);
});

it('keeps per-user counts isolated in the same batch', function () {
    $heavy = detectMakeAuditUser();
    $light = detectMakeAuditUser();
    detectMakeLogs((int) $heavy->id, 5);
    detectMakeLogs((int) $light->id, 1);

    ob_start();
    Detect::ban();
    ob_get_clean();

    expect((int) (new User())->find($heavy->id)->all_detect_number)->toBe(5);
    expect((int) (new User())->find($heavy->id)->is_banned)->toBe(1);
    expect((int) (new User())->find($light->id)->all_detect_number)->toBe(1);
    expect((int) (new User())->find($light->id)->is_banned)->toBe(0);
});

it('does not ban below the threshold', function () {
    $user = detectMakeAuditUser();
    detectMakeLogs((int) $user->id, 2);

    ob_start();
    Detect::ban();
    ob_get_clean();

    $fresh = (new User())->find($user->id);
    expect((int) $fresh->all_detect_number)->toBe(2);
    expect((int) $fresh->is_banned)->toBe(0);
});

it('skips the node and sends no alert when the gfw api returns garbage', function () {
    $node = detectMakeNode();
    // 现存文件但内容非法 JSON:与「不可达返回 false」走同一守卫路径,
    // 且不触发 file_get_contents 警告(Pest 的错误处理器无视 @ 抑制)。
    $dir = sys_get_temp_dir() . '/detect-gfw-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/203.0.113.10-443.json', 'not-json-at-all');
    $_ENV['detect_gfw_url'] = $dir . '/{ip}-{port}.json';

    ob_start();
    Detect::gfw();
    ob_get_clean();

    // 不误判为被墙、不给管理员发信
    expect((int) (new Node())->find($node->id)->gfw_block)->toBe(0);
    expect((new EmailQueue())->count())->toBe(0);
});

it('still detects a blocked node when the api responds', function () {
    $node = detectMakeNode();

    $dir = sys_get_temp_dir() . '/detect-gfw-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/203.0.113.10-443.json', json_encode(['status' => 'false']));
    $_ENV['detect_gfw_url'] = $dir . '/{ip}-{port}.json';

    $admin = makeUserWithMoney(0.0);
    $admin->is_admin = 1;
    $admin->contact_method = 1;
    $admin->save();

    ob_start();
    Detect::gfw();
    ob_get_clean();

    expect((int) (new Node())->find($node->id)->gfw_block)->toBe(1);
    expect((new EmailQueue())->where('to_email', $admin->email)->count())->toBe(1);
});
