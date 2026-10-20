<?php

declare(strict_types=1);

use App\Controllers\WebAPI\UserController;
use App\Models\Node;
use App\Models\OnlineLog;
use App\Models\User;
use App\Services\DB;
use GuzzleHttp\Psr7\HttpFactory;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * UserController::index() leaves a user out of every node's list once they have
 * more than node_iplimit online IPs, so a miscount kicks them off all nodes at
 * once. 2026-10-20: users with one phone were kicked every few minutes because
 * mobile CGNAT gave each flow its own address in a /24, node 34's reverse proxy
 * reported 127.0.0.1 for everyone, and relayed UDP / Hysteria 2 reported the
 * relay's exit IP. These tests pin what is and is not counted.
 *
 * Same DB-backed pattern and schema patch as UserKeysUnsetTest.php.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();
    userIpLimitPatchSchema();
});

afterEach(function () {
    unset($_ENV['iplimit_ignore_ips']);
    TestDatabase::dropTables();
});

function userIpLimitPatchSchema(): void
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

    $conn->statement('ALTER TABLE node MODIFY node_heartbeat INT UNSIGNED NOT NULL DEFAULT 0');
}

function userIpLimitMakeNode(): Node
{
    $node = new Node();
    $node->name = 'iplimit-node-' . bin2hex(random_bytes(4));
    $node->type = 1;
    $node->server = 'node.example.com';
    $node->sort = 14;
    $node->status = 1;
    $node->node_class = 0;
    $node->node_group = 0;
    $node->password = bin2hex(random_bytes(8));
    $node->save();

    return $node;
}

function userIpLimitMakeUser(int $iplimit): User
{
    $user = new User();
    $user->email = 'iplimit_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'iplimit';
    $user->passwd = 'passwd';
    $user->uuid = 'a1b2c3d4-0000-4000-8000-' . bin2hex(random_bytes(6));
    $user->method = 'aes-256-gcm';
    $user->port = 12345;
    $user->transfer_enable = 1_000_000_000;
    $user->u = 0;
    $user->d = 0;
    $user->node_iplimit = $iplimit;
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
 * Stores IPs the way addAliveIp() does: IPv4 as an IPv4-mapped IPv6 address.
 */
function userIpLimitSeen(User $user, array $ips, int $ago = 0, int $node_id = 1): void
{
    foreach ($ips as $ip) {
        (new OnlineLog())->insert([
            'user_id' => $user->id,
            'ip' => str_contains($ip, ':') ? $ip : '::ffff:' . $ip,
            'node_id' => $node_id,
            'first_time' => time() - $ago,
            'last_time' => time() - $ago,
        ]);
    }
}

/**
 * @return array<int> ids of the users index() serves to the node
 */
function userIpLimitServedIds(Node $node): array
{
    $controller = (new ReflectionClass(UserController::class))->newInstanceWithoutConstructor();

    $guzzle = new HttpFactory();
    $request = (new DecoratedServerRequestFactory($guzzle))
        ->createServerRequest('GET', '/mod_mu/users?node_id=' . $node->id);
    $response = (new DecoratedResponseFactory($guzzle, $guzzle))->createResponse();

    $body = json_decode((string) $controller->index($request, $response, [])->getBody(), true);

    expect($body['ret'])->toBe(1);

    return array_column($body['data'], 'id');
}

describe('UserController::index() device limit', function () {
    it('counts one phone on mobile CGNAT plus a home line as two, ignoring the proxy and relay IPs', function () {
        $_ENV['iplimit_ignore_ips'] = ['103.169.217.55'];
        $node = userIpLimitMakeNode();
        $user = userIpLimitMakeUser(2);

        // Seven raw addresses, two real lines: the old COUNT(DISTINCT ip) kicked this user.
        userIpLimitSeen($user, ['223.104.79.66', '223.104.79.70', '223.104.79.75'], node_id: 41);
        userIpLimitSeen($user, ['240e:3a8:4caf:6de0::1', '240e:3a8:4caf:6de0::2'], node_id: 25);
        userIpLimitSeen($user, ['127.0.0.1'], node_id: 34);
        userIpLimitSeen($user, ['103.169.217.55'], node_id: 43);

        expect(userIpLimitServedIds($node))->toBe([$user->id]);
    });

    it('still leaves out a user with more distinct lines than the limit', function () {
        $node = userIpLimitMakeNode();
        $user = userIpLimitMakeUser(2);
        $other = userIpLimitMakeUser(2);

        userIpLimitSeen($user, ['58.209.187.141', '114.86.35.35', '36.6.33.251']);

        expect(userIpLimitServedIds($node))->toBe([$other->id]);
    });

    it('only counts addresses seen in the last 90 seconds', function () {
        $node = userIpLimitMakeNode();
        $user = userIpLimitMakeUser(1);

        userIpLimitSeen($user, ['58.209.187.141']);
        userIpLimitSeen($user, ['114.86.35.35'], ago: 120);

        expect(userIpLimitServedIds($node))->toBe([$user->id]);
    });

    it('never limits a user whose node_iplimit is 0', function () {
        $node = userIpLimitMakeNode();
        $user = userIpLimitMakeUser(0);

        userIpLimitSeen($user, ['58.209.187.141', '114.86.35.35', '36.6.33.251']);

        expect(userIpLimitServedIds($node))->toBe([$user->id]);
    });
});
