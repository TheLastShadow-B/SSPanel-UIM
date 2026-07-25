<?php

declare(strict_types=1);

use App\Controllers\WebAPI\UserController;
use App\Models\Node;
use App\Models\User;
use App\Services\DB;
use GuzzleHttp\Psr7\HttpFactory;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * UserController::index() — $keys_unset per node.sort pins which fields
 * XrayR receives per protocol. Final-review Finding 1 (2026-07-25): sort=12
 * (VLESS) had no dedicated arm and fell into `default`, which UNSETS uuid —
 * VLESS authenticates by UUID, so every VLESS connection would be silently
 * rejected while the panel looked healthy. This test exercises the real
 * `match` inside index() end-to-end so a new protocol sort landing in the
 * wrong arm cannot recur silently.
 *
 * Why DB-backed instead of a pure reflection unit test: the `match` is
 * inline in index(), which also calls Node::find(), $node->update(), and
 * User::where()->get() before the match is ever reached — there is no
 * DB-free seam onto it short of extracting it out of production code
 * (out of scope for a fix wave, and liable to be reverted the moment
 * someone "simplifies" the controller back). This mirrors the existing
 * DB-backed pattern in InvoicePayBalanceGuardTest.php / SubscriptionPurchaseTest.php.
 *
 * TestDatabase's shared user/node tables are missing a few columns index()
 * touches (user.is_banned / user.is_admin / user.uuid; node.node_heartbeat
 * is a tinyint but always receives time()). Patched locally in beforeEach;
 * TestDatabase::dropTables() in afterEach drops both tables wholesale, so
 * nothing here leaks into other test files.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();
    userKeysUnsetPatchSchema();
});

afterEach(function () {
    TestDatabase::dropTables();
});

function userKeysUnsetPatchSchema(): void
{
    $conn = DB::getCapsule()->getConnection();
    $schema = DB::getCapsule()->schema();

    if (! $schema->hasColumn('user', 'is_banned')) {
        $conn->statement('ALTER TABLE user ADD COLUMN is_banned TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    }

    if (! $schema->hasColumn('user', 'is_admin')) {
        $conn->statement('ALTER TABLE user ADD COLUMN is_admin TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    }

    if (! $schema->hasColumn('user', 'uuid')) {
        $conn->statement("ALTER TABLE user ADD COLUMN uuid CHAR(36) NOT NULL DEFAULT ''");
    }

    // Production is int(11) unsigned (db/migrations/2023061800-update_new_shop_data_type.php);
    // index() unconditionally writes time() into it. TestDatabase ships a tinyint (max 127),
    // which throws "Out of range value" under MariaDB's STRICT_TRANS_TABLES before the match
    // statement under test is ever reached.
    $conn->statement('ALTER TABLE node MODIFY node_heartbeat INT UNSIGNED NOT NULL DEFAULT 0');
}

function userKeysUnsetMakeNode(int $sort, ?string $customConfig = null): Node
{
    $node = new Node();
    $node->name = 'pin-node-' . $sort . '-' . bin2hex(random_bytes(4));
    $node->type = 1;
    $node->server = 'node.example.com';
    $node->sort = $sort;
    $node->status = 1;
    $node->node_class = 0;
    $node->node_group = 0;
    $node->password = bin2hex(random_bytes(8));
    $node->custom_config = $customConfig;
    $node->save();

    return $node;
}

function userKeysUnsetMakeUser(): User
{
    $user = new User();
    $user->email = 'keys_unset_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'keys_unset';
    $user->passwd = 'plaintext-passwd-sentinel';
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';
    $user->method = 'aes-256-gcm';
    $user->port = 12345;
    $user->transfer_enable = 1_000_000_000;
    $user->u = 0;
    $user->d = 0;
    $user->node_iplimit = 0;
    $user->node_group = 0;
    $user->class = 0;
    $user->is_admin = 0;
    $user->is_banned = 0;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('+1 year'));
    $user->reg_date = date('Y-m-d H:i:s');
    $user->save();

    return $user;
}

/**
 * Calls the real UserController::index() over HTTP-shaped Slim request/response
 * objects (no Auth-touching constructor needed: index() never reads $this->).
 *
 * @return array<string, mixed> the single served user's surviving JSON fields
 */
function userKeysUnsetCallIndex(Node $node): array
{
    $controller = (new ReflectionClass(UserController::class))->newInstanceWithoutConstructor();

    $guzzle = new HttpFactory();
    $request = (new DecoratedServerRequestFactory($guzzle))
        ->createServerRequest('GET', '/mod_mu/users?node_id=' . $node->id);
    $response = (new DecoratedResponseFactory($guzzle, $guzzle))->createResponse();

    $result = $controller->index($request, $response, []);
    $body = json_decode((string) $result->getBody(), true);

    expect($body['ret'])->toBe(1)
        ->and($body['data'])->toHaveCount(1);

    return $body['data'][0];
}

describe('UserController::index() $keys_unset per node.sort', function () {
    it('serves VLESS (sort=12) with the same uuid-bearing shape as VMess (11) and Trojan (14)', function () {
        $user = userKeysUnsetMakeUser();

        foreach ([11, 12, 14] as $sort) {
            $node = userKeysUnsetMakeNode($sort);
            $served = userKeysUnsetCallIndex($node);

            expect($served)
                ->toHaveKey('uuid')
                ->not->toHaveKey('passwd')
                ->not->toHaveKey('method')
                ->not->toHaveKey('port');
            expect($served['uuid'])->toBe($user->uuid);
        }
    });

    it('pins the exact surviving-key set for every sort value', function () {
        userKeysUnsetMakeUser();

        $expected = [
            // default arm (Shadowsocks sort=0, and any sort with no dedicated arm): uuid
            // unset, ss shape kept. This is the shape sort=12 WRONGLY fell into before the
            // fix — VLESS needs the opposite (uuid kept, ss fields unset).
            0 => ['id', 'node_speedlimit', 'method', 'port', 'passwd'],
            // Shadowsocks2022: passwd only (re-derived per-user pk), no uuid/method/port.
            1 => ['id', 'node_speedlimit', 'passwd'],
            // TUIC: authenticates with both a password and a uuid.
            2 => ['id', 'node_speedlimit', 'passwd', 'uuid'],
            // VMess: uuid-authenticated, ss fields stripped.
            11 => ['id', 'node_speedlimit', 'uuid'],
            // VLESS: must match VMess/Trojan exactly — this is Finding 1.
            12 => ['id', 'node_speedlimit', 'uuid'],
            // Trojan: uuid-authenticated (the "passwd" field carries the uuid client-side).
            14 => ['id', 'node_speedlimit', 'uuid'],
            // Hysteria 2: single-string passwd auth, no uuid/method/port.
            15 => ['id', 'node_speedlimit', 'passwd'],
        ];

        foreach ($expected as $sort => $expectedKeys) {
            $customConfig = $sort === 1 ? json_encode(['method' => '2022-blake3-aes-128-gcm']) : null;
            $node = userKeysUnsetMakeNode($sort, $customConfig);

            $served = userKeysUnsetCallIndex($node);

            $actualKeys = $served === [] ? [] : array_keys($served);
            sort($actualKeys);
            $expectedSorted = $expectedKeys;
            sort($expectedSorted);

            expect($actualKeys)->toBe($expectedSorted);
        }
    });
});
