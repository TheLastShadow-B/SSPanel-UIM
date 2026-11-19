<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Node;
use App\Services\DB;
use App\Services\Subscribe;
use App\Services\Subscribe\Clash;
use App\Services\Subscribe\Hako;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestDatabase;

/*
 * Every subscription format lists a node with custom_config.entries once per
 * entry, each line with that entry's server and port, while the node page and
 * the stored node still show one node. These tests run the real generators
 * against the test database; NodeEntriesTest.php covers the expansion itself.
 */

beforeEach(function () {
    $this->env = $_ENV;
    TestDatabase::init();

    $schema = DB::getCapsule()->schema();

    if (! $schema->hasTable('link')) {
        $schema->create('link', function (Blueprint $table) {
            $table->increments('id');
            $table->string('token');
            $table->integer('userid');
        });
    }

    foreach (['enable_ss_sub', 'enable_v2_sub', 'enable_trojan_sub'] as $item) {
        (new Config())->insert(['item' => $item, 'value' => '1', 'type' => 'bool']);
    }

    $group = ['proxy-groups' => [['name' => 'Proxy', 'type' => 'select', 'proxies' => []]]];
    $_ENV['Clash_Config'] = ['mixed-port' => 7890];
    $_ENV['Clash_Group_Indexes'] = [0];
    $_ENV['Clash_Group_Config'] = $group;
    $_ENV['Stash_Config'] = ['mixed-port' => 7890];
    $_ENV['Stash_Group_Indexes'] = [0];
    $_ENV['Stash_Group_Config'] = $group;
});

afterEach(function () {
    DB::getCapsule()->schema()->dropIfExists('link');
    TestDatabase::dropTables();
    $_ENV = $this->env;
});

if (! function_exists('entriesSubUser')) {
    function entriesSubUser(): stdClass
    {
        return (object) [
            'id' => 1,
            'class' => 0,
            'is_admin' => 0,
            'node_group' => 0,
            'passwd' => 'user-passwd',
            'method' => 'aes-128-gcm',
            'port' => 12345,
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000000',
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1024,
        ];
    }
}

if (! function_exists('entriesSubNode')) {
    /**
     * A relay whose two entries listen on 20001 and 20002. Its own server and
     * port are those of entry A, as on a node that had one entry before.
     */
    function entriesSubNode(string $name, int $sort, array $custom = [], bool $entries = true): Node
    {
        $custom['offset_port_user'] = 20001;

        if ($entries) {
            $custom['entries'] = [
                ['label' => 'A', 'server' => 'entry-a.example.com', 'port' => 20001],
                ['label' => 'B', 'server' => 'entry-b.example.com', 'port' => 20002],
            ];
        }

        $node = new Node();
        $node->name = $name;
        $node->type = 1;
        $node->server = 'entry-a.example.com';
        $node->sort = $sort;
        $node->node_class = 0;
        $node->node_group = 0;
        $node->password = 'node-password';
        $node->custom_config = json_encode($custom);
        $node->save();

        return $node;
    }
}

if (! function_exists('entriesSubSs2022')) {
    function entriesSubSs2022(string $name = 'TW Relay', bool $entries = true): Node
    {
        return entriesSubNode($name, 1, [
            'method' => '2022-blake3-aes-128-gcm',
            'server_key' => 'c2VydmVyLWtleS1mb3ItdGVzdA==',
            'uot' => true,
        ], $entries);
    }
}

if (! function_exists('entriesSubProxies')) {
    /**
     * @return array<string, string> "server:port" by proxy name, in order
     */
    function entriesSubProxies(array $config): array
    {
        $proxies = [];

        foreach ($config['proxies'] as $proxy) {
            $proxies[$proxy['name']] = $proxy['server'] . ':' . $proxy['port'];
        }

        return $proxies;
    }
}

it('lists each entry in Clash and puts both lines in the groups', function () {
    entriesSubSs2022();
    entriesSubSs2022('US Direct', false);

    $config = (new Clash())->getConfig(entriesSubUser());

    expect(entriesSubProxies($config))->toBe([
        'TW Relay · 线路A' => 'entry-a.example.com:20001',
        'TW Relay · 线路B' => 'entry-b.example.com:20002',
        'US Direct' => 'entry-a.example.com:20001',
    ])
        ->and($config['proxy-groups'][0]['proxies'])->toBe(['TW Relay · 线路A', 'TW Relay · 线路B', 'US Direct'])
        ->and($config['proxies'][1]['udp-over-tcp'])->toBeTrue()
        ->and($config['proxies'][1]['password'])->toStartWith('c2VydmVyLWtleS1mb3ItdGVzdA==:');
});

// Cmfa and Hako take their proxies from Clash::getConfig(), so they list what
// Clash lists. Hako also sorts them into region groups by name.
it('puts both lines in the region group of the profiles built on Clash', function () {
    entriesSubSs2022();

    $proxies = (new Clash())->getConfig(entriesSubUser())['proxies'];
    $groups = array_column((new Hako())->buildConfig($proxies)['proxy-groups'], 'proxies', 'name');

    expect($groups['TW'])->toBe(['TW Relay · 线路A', 'TW Relay · 线路B']);
});

it('lists each entry in Stash and puts both lines in the groups', function () {
    entriesSubSs2022();

    $config = yaml_parse(Subscribe::getContent(entriesSubUser(), 'stash'));

    expect(entriesSubProxies($config))->toBe([
        'TW Relay · 线路A' => 'entry-a.example.com:20001',
        'TW Relay · 线路B' => 'entry-b.example.com:20002',
    ])
        ->and($config['proxy-groups'][0]['proxies'])->toBe(['TW Relay · 线路A', 'TW Relay · 线路B']);
})->skip(! function_exists('yaml_emit'), 'Stash only emits YAML, which needs the yaml extension');

it('lists each entry in Surge', function () {
    entriesSubSs2022();

    $content = Subscribe::getContent(entriesSubUser(), 'surge');

    expect($content)
        ->toContain('TW Relay · 线路A = ss, entry-a.example.com, 20001, ')
        ->toContain('TW Relay · 线路B = ss, entry-b.example.com, 20002, ')
        ->not->toContain('TW Relay = ');
});

it('lists each entry in the SS format', function () {
    entriesSubSs2022();
    entriesSubNode('UK Relay', 0);

    $lines = [];

    foreach (explode(PHP_EOL, trim(Subscribe::getContent(entriesSubUser(), 'ss'))) as $link) {
        [$encoded, $name] = explode('#', $link);
        $lines[$name] = substr(base64_decode($encoded), strrpos(base64_decode($encoded), '@') + 1);
    }

    // A plain Shadowsocks node listens on the user's port whatever the entry says.
    expect($lines)->toBe([
        'TW Relay · 线路A' => 'entry-a.example.com:20001',
        'TW Relay · 线路B' => 'entry-b.example.com:20002',
        'UK Relay · 线路A' => 'entry-a.example.com:12345',
        'UK Relay · 线路B' => 'entry-b.example.com:12345',
    ]);
});

it('lists each entry in the SIP002 format', function () {
    entriesSubNode('UK Relay', 0);

    $links = explode(PHP_EOL, trim(Subscribe::getContent(entriesSubUser(), 'sip002')));

    expect($links)->toHaveCount(2)
        ->and($links[0])->toContain('@entry-a.example.com:12345/')->toEndWith('#UK Relay · 线路A')
        ->and($links[1])->toContain('@entry-b.example.com:12345/')->toEndWith('#UK Relay · 线路B');
});

it('lists each entry in the SIP008 format', function () {
    entriesSubNode('UK Relay', 0);

    $servers = json_decode(Subscribe::getContent(entriesSubUser(), 'sip008'), true)['servers'];

    expect(array_column($servers, 'server', 'remarks'))->toBe([
        'UK Relay · 线路A' => 'entry-a.example.com',
        'UK Relay · 线路B' => 'entry-b.example.com',
    ]);
});

it('lists each entry in the V2Ray format', function () {
    entriesSubNode('JP Relay', 11, ['network' => 'ws', 'security' => 'tls', 'host' => 'cdn.example.com']);

    $lines = [];

    foreach (explode(PHP_EOL, trim(Subscribe::getContent(entriesSubUser(), 'v2ray'))) as $link) {
        $vmess = json_decode(base64_decode(substr($link, strlen('vmess://'))), true);
        $lines[$vmess['ps']] = $vmess['add'] . ':' . $vmess['port'] . ' ' . $vmess['host'];
    }

    expect($lines)->toBe([
        'JP Relay · 线路A' => 'entry-a.example.com:20001 cdn.example.com',
        'JP Relay · 线路B' => 'entry-b.example.com:20002 cdn.example.com',
    ]);
});

it('lists each entry in the Trojan format', function () {
    entriesSubNode('HK Relay', 14, ['host' => 'cdn.example.com']);

    $links = explode(PHP_EOL, trim(Subscribe::getContent(entriesSubUser(), 'trojan')));

    expect($links)->toHaveCount(2)
        ->and($links[0])->toContain('@entry-a.example.com:20001?peer=cdn.example.com&')
        ->toEndWith('#HK Relay · 线路A')
        ->and($links[1])->toContain('@entry-b.example.com:20002?peer=cdn.example.com&')
        ->toEndWith('#HK Relay · 线路B');
});

it('still shows one node on the node page and stores nothing', function () {
    $node = entriesSubSs2022();
    $stored = $node->fresh()->getAttributes();
    $user = entriesSubUser();

    (new Clash())->getConfig($user);

    foreach (['surge', 'ss'] as $type) {
        Subscribe::getContent($user, $type);
    }

    expect(Subscribe::getUserNodes($user)->pluck('name')->all())->toBe(['TW Relay'])
        ->and(Subscribe::getUserSubscribeNodes($user)->pluck('name')->all())
        ->toBe(['TW Relay · 线路A', 'TW Relay · 线路B'])
        ->and((new Node())->count())->toBe(1)
        ->and($node->fresh()->getAttributes())->toBe($stored);
});
