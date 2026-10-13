<?php

declare(strict_types=1);

use App\Controllers\Admin\TcpProbeController;
use App\Models\Node;
use App\Services\DB;
use App\Services\TcpProbe;
use App\Services\TcpProbeGroup;
use App\Utils\TcpProbeStatus;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;

beforeEach(function () {
    $this->resolver = Node::getConnectionResolver();
    $this->database = new DB();
    $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->database->setAsGlobal();
    $this->database->bootEloquent();
    foreach (['2026091701-add_node_tcp_probe', '2026091702-dynamic_tcp_probe_targets', '2026091703-add_taier_probe_sync', '2026091704-add_taier_sync_status'] as $name) {
        (require BASE_PATH . '/db/migrations/' . $name . '.php')->up();
    }
    DB::table('tcp_probe')->insert(['node_id' => 1, 'enabled' => true, 'threshold_ms' => 250]);
    DB::table('tcp_probe_target')->insert([
        ['id' => 1, 'carrier' => 'telecom', 'label' => '广东电信', 'ip' => '1.1.1.1', 'port' => 443],
        ['id' => 2, 'carrier' => 'telecom', 'label' => '上海电信', 'ip' => '8.8.8.8', 'port' => 443],
    ]);
    $this->migration = require BASE_PATH . '/db/migrations/2026092201-add_tcp_probe_groups.php';
    $this->migration->up();
    $this->now = 1800000000;
});

afterEach(function () {
    $this->database->getConnection()->disconnect();
    if ($this->resolver) { Node::setConnectionResolver($this->resolver); } else { Node::unsetConnectionResolver(); }
});

if (! function_exists('probeGroupReport')) {
    /** A complete report for whatever the node's group currently contains: a number is a latency, a string an error. */
    function probeGroupReport(int $node, int $time, mixed $sample = 120): array
    {
        $config = TcpProbe::config($node);
        return ['config_hash' => $config['config_hash'], 'measured_at' => $time, 'results' => array_map(
            static fn ($target) => ['target_id' => $target['id'], 'samples' => array_fill(0, $config['attempts'],
                is_numeric($sample) ? ['latency_ms' => $sample, 'error' => null] : ['latency_ms' => null, 'error' => $sample])],
            $config['targets'])];
    }
}

if (! function_exists('probeGroupNodeTable')) {
    function probeGroupNodeTable(): void
    {
        test()->database->schema()->create('node', function (Blueprint $table) {
            $table->increments('id'); $table->string('name')->default('');
        });
    }
}

if (! function_exists('probeGroupController')) {
    /** @return array{TcpProbeController, DecoratedServerRequestFactory, DecoratedResponseFactory} */
    function probeGroupController(): array
    {
        $factory = new HttpFactory();
        return [
            (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor(),
            new DecoratedServerRequestFactory($factory), new DecoratedResponseFactory($factory, $factory),
        ];
    }
}

if (! function_exists('probeGroup')) {
    function probeGroup(string $name = '中转', string $url = '', int $minutes = 60): int
    {
        return TcpProbeGroup::save(null, ['name' => $name, 'url' => $url, 'interval_minutes' => $minutes]);
    }
}

it('seeds the default group and files existing targets and nodes into it', function () {
    expect(TcpProbeGroup::installed())->toBeTrue()
        ->and(array_map(static fn ($group) => [$group['id'], $group['name']], TcpProbeGroup::all()))->toBe([[1, '默认']])
        ->and(array_column(TcpProbe::targets(), 'group_id'))->toBe([1, 1])
        ->and((int) DB::table('tcp_probe')->where('node_id', 1)->value('group_id'))->toBe(1);
    expect($this->migration->up())->toBe(2026092201)->and(TcpProbeGroup::all())->toHaveCount(1);
    expect($this->migration->down())->toBe(2026091704)->and(TcpProbeGroup::installed())->toBeFalse()
        ->and(TcpProbe::targets())->toHaveCount(2)
        ->and(array_keys(TcpProbe::targets()[0]))->not->toContain('group_id');
    // Without groups the single-list rule is back: one endpoint, one target.
    expect(fn () => DB::table('tcp_probe_target')->insert(['carrier' => 'telecom', 'label' => '重复', 'ip' => '1.1.1.1', 'port' => 443]))
        ->toThrow(QueryException::class);
});

it('hands each node only the targets of its own group and changes the hash when the node moves', function () {
    $id = probeGroup();
    DB::table('tcp_probe_target')->insert(['carrier' => 'mobile', 'label' => '广州移动入口', 'ip' => '9.9.9.9', 'port' => 443, 'group_id' => $id]);
    $before = TcpProbe::config(1);
    expect(array_column($before['targets'], 'id'))->toBe([1, 2])
        ->and(TcpProbe::targets())->toHaveCount(3)->and(TcpProbe::targets($id))->toHaveCount(1)
        ->and(TcpProbe::carriers())->toBe(['telecom' => '电信', 'mobile' => '移动']);
    DB::table('tcp_probe')->where('node_id', 1)->update(['group_id' => $id]);
    $after = TcpProbe::config(1);
    expect(array_column($after['targets'], 'id'))->toBe([3])
        ->and($after['config_hash'])->not->toBe($before['config_hash'])
        ->and(array_keys($after['targets'][0]))->toBe(['id', 'carrier', 'label', 'ip', 'port']);
    // A node that was never configured probes the default group.
    expect(array_column(TcpProbe::config(2)['targets'], 'id'))->toBe([1, 2]);
});

it('grades staleness and the period by the size of the node own group', function () {
    $id = probeGroup();
    for ($i = 1; $i <= 13; $i++) {
        DB::table('tcp_probe_target')->insert(['carrier' => 'mobile', 'label' => '入口 ' . $i, 'ip' => '8.8.4.' . $i, 'port' => 443, 'group_id' => $id]);
    }
    DB::table('tcp_probe')->insert(['node_id' => 2, 'enabled' => true, 'threshold_ms' => 250, 'group_id' => $id]);
    TcpProbe::report(1, probeGroupReport(1, $this->now), $this->now);
    TcpProbe::report(2, probeGroupReport(2, $this->now), $this->now);
    // Thirteen targets take two batches: this group runs two-minute rounds and stays fresh for six minutes.
    expect(TcpProbe::detail(2, $this->now)['interval_seconds'])->toBe(120)
        ->and(TcpProbe::detail(1, $this->now)['interval_seconds'])->toBe(60);
    $rows = TcpProbe::current([1, 2], $this->now + 181);
    expect($rows[1]['telecom']['status'])->toBe('red')->and($rows[2]['mobile']['status'])->toBe('green')
        ->and(TcpProbe::current([2], $this->now + 361)[2]['mobile']['status'])->toBe('red');
});

it('validates each node against its own group in the overview and counts every group', function () {
    probeGroupNodeTable();
    Node::create(['id' => 1]); Node::create(['id' => 2]);
    $id = probeGroup();
    DB::table('tcp_probe_target')->insert(['carrier' => 'mobile', 'label' => '广州移动入口', 'ip' => '9.9.9.9', 'port' => 443, 'group_id' => $id]);
    DB::table('tcp_probe')->insert(['node_id' => 2, 'enabled' => true, 'threshold_ms' => 250, 'group_id' => $id]);
    foreach ([$this->now - 60, $this->now] as $at) {
        TcpProbe::report(1, probeGroupReport(1, $at), $at);
        TcpProbe::report(2, probeGroupReport(2, $at), $at);
    }
    $overview = TcpProbe::overview($this->now);
    expect($overview['reporting'])->toBe(2)
        ->and($overview['nodes'][1]['states']['telecom'])->toBe('green')
        ->and($overview['nodes'][2]['states'])->toBe(['telecom' => 'gray', 'unicom' => 'gray', 'mobile' => 'green'])
        ->and($overview['carriers']['mobile']['targets'])->toBe(1)
        ->and($overview['carriers']['mobile']['counts']['green'])->toBe(1)
        ->and([$overview['targets'][3]['ok'], $overview['targets'][3]['total']])->toBe([1, 1])
        ->and([$overview['targets'][1]['ok'], $overview['targets'][1]['total']])->toBe([1, 1]);
});

it('saves the node group with the node table and refuses unknown groups', function () {
    probeGroupNodeTable();
    Node::create(['id' => 1]); Node::create(['id' => 2]);
    $id = probeGroup();
    [$controller, $requests, $responses] = probeGroupController();
    $request = $requests->createServerRequest('POST', '/admin/node/probe/nodes');
    $post = fn (array $body) => $controller->saveNodes($request->withParsedBody($body), $responses->createResponse(), []);
    $full = ['threshold_ms' => [1 => '250', 2 => '250'], 'enabled' => [1 => '1', 2 => '1'], 'group_id' => [1 => '1', 2 => (string) $id], 'node_count' => '2'];
    expect($post($full)->getStatusCode())->toBe(200)
        ->and((int) DB::table('tcp_probe')->where('node_id', 2)->value('group_id'))->toBe($id)
        ->and(TcpProbe::config(2)['targets'])->toBe([]);
    foreach ([['group_id' => [1 => '1', 2 => '99']], ['group_id' => [1 => '1']], ['group_id' => ['01' => '1', 2 => '1']], ['group_id' => null]] as $bad) {
        expect($post(array_replace($full, $bad))->getStatusCode())->toBe(422)
            ->and((int) DB::table('tcp_probe')->where('node_id', 2)->value('group_id'))->toBe($id);
    }
});

it('files a target under its group and allows the same endpoint in different groups', function () {
    $id = probeGroup();
    [$controller, $requests, $responses] = probeGroupController();
    $request = $requests->createServerRequest('POST', '/admin/node/probe/targets');
    $save = fn (array $body, array $args = []) => $controller->saveTarget($request->withParsedBody($body), $responses->createResponse(), $args);
    $body = ['carrier' => 'mobile', 'label' => '广州移动入口', 'ip' => '1.1.1.1', 'port' => '443', 'group_id' => (string) $id];
    // 1.1.1.1:443 already sits in the default group; another group may probe it as well, but only once.
    expect($save($body)->getStatusCode())->toBe(200)
        ->and($save($body)->getStatusCode())->toBe(422)
        ->and($save(['group_id' => '99'] + $body)->getStatusCode())->toBe(422)
        ->and($save(['group_id' => 'x'] + $body)->getStatusCode())->toBe(422)
        ->and(TcpProbe::targets($id))->toHaveCount(1)->and(TcpProbe::targets(1))->toHaveCount(2);
    $noGroup = $body; unset($noGroup['group_id']); $noGroup['ip'] = '2.2.2.2';
    expect($save($noGroup)->getStatusCode())->toBe(200)->and(TcpProbe::targets(1))->toHaveCount(3);
    // An edit can move a target into another group, unless that group already probes the endpoint.
    $move = ['carrier' => 'telecom', 'label' => '上海电信', 'port' => '443', 'group_id' => (string) $id];
    expect($save($move + ['ip' => '8.8.8.8'], ['id' => 2])->getStatusCode())->toBe(200)
        ->and($save($move + ['ip' => '1.1.1.1'], ['id' => 1])->getStatusCode())->toBe(422)
        ->and(array_column(TcpProbe::targets($id), 'ip'))->toBe(['8.8.8.8', '1.1.1.1'])
        ->and(array_column(TcpProbe::targets(1), 'ip'))->toBe(['1.1.1.1', '2.2.2.2']);
});

it('creates renames and deletes groups but never the default one or a group still in use', function () {
    foreach ([
        ['', '', 60], [str_repeat('长', 41), '', 60], [[], '', 60], ['A', 'ftp://example.test/x', 60], ['A', 'http://localhost/x', 60],
        ['A', 'http://127.0.0.1/x', 60], ['A', 'http://10.0.0.1/x', 60], ['A', 'http://192.168.1.1/x', 60], ['A', 'http://169.254.169.254/x', 60],
        ['A', 'http://[::1]/x', 60], ['A', 'https://', 60], ['A', 'https://example.test/' . str_repeat('a', 240), 60],
        ['A', '', 4], ['A', '', 1441], ['A', '', 'abc'],
    ] as [$name, $url, $minutes]) {
        expect(fn () => TcpProbeGroup::validate($name, $url, $minutes))->toThrow(InvalidArgumentException::class);
    }
    expect(TcpProbeGroup::validate(' 中转 A ', ' https://example.test/relay.csv ', '30'))
        ->toBe(['name' => '中转 A', 'url' => 'https://example.test/relay.csv', 'interval_minutes' => 30]);
    $id = probeGroup('中转 A', 'https://example.test/relay.csv', 30);
    expect($id)->toBe(2)->and(TcpProbeGroup::find($id))->toMatchArray([
        'name' => '中转 A', 'url' => 'https://example.test/relay.csv', 'interval_minutes' => 30, 'next_sync_at' => 0, 'last_status' => 'none',
    ]);
    // Renaming keeps the schedule; a new URL asks the cron to fetch right away and drops any lease held for the old one.
    DB::table('tcp_probe_group')->where('id', $id)->update(['next_sync_at' => 999, 'lock_until' => 999, 'lock_token' => 'x']);
    TcpProbeGroup::save($id, ['name' => '中转 B', 'url' => 'https://example.test/relay.csv', 'interval_minutes' => 30]);
    expect(TcpProbeGroup::find($id))->toMatchArray(['name' => '中转 B', 'next_sync_at' => 999, 'lock_until' => 999]);
    TcpProbeGroup::save($id, ['name' => '中转 B', 'url' => 'https://example.test/other.csv', 'interval_minutes' => 30]);
    expect(TcpProbeGroup::find($id))->toMatchArray(['next_sync_at' => 0, 'lock_until' => 0, 'lock_token' => null]);
    expect(fn () => TcpProbeGroup::save(99, ['name' => 'X', 'url' => '', 'interval_minutes' => 60]))->toThrow(InvalidArgumentException::class);
    expect(fn () => TcpProbeGroup::delete(1))->toThrow(InvalidArgumentException::class);
    DB::table('tcp_probe_target')->insert(['carrier' => 'mobile', 'label' => '入口', 'ip' => '9.9.9.9', 'port' => 443, 'group_id' => $id]);
    expect(fn () => TcpProbeGroup::delete($id))->toThrow(InvalidArgumentException::class);
    DB::table('tcp_probe_target')->where('group_id', $id)->delete();
    DB::table('tcp_probe')->where('node_id', 1)->update(['group_id' => $id]);
    expect(fn () => TcpProbeGroup::delete($id))->toThrow(InvalidArgumentException::class);
    DB::table('tcp_probe')->where('node_id', 1)->update(['group_id' => 1]);
    TcpProbeGroup::delete($id);
    expect(TcpProbeGroup::all())->toHaveCount(1)->and(TcpProbeGroup::find($id))->toBeNull();
    expect(fn () => TcpProbeGroup::delete(99))->toThrow(InvalidArgumentException::class);
});

it('manages groups through the admin endpoints with the same refusals', function () {
    [$controller, $requests, $responses] = probeGroupController();
    $request = $requests->createServerRequest('POST', '/admin/node/probe/groups');
    $save = fn (array $body, array $args = []) => $controller->saveGroup($request->withParsedBody($body), $responses->createResponse(), $args);
    $response = $save(['name' => '中转', 'url' => 'https://example.test/relay.csv', 'interval_minutes' => '30']);
    expect($response->getStatusCode())->toBe(200)->and($response->getHeaderLine('HX-Refresh'))->toBe('true');
    $id = json_decode((string) $response->getBody(), true)['id'];
    expect($save(['name' => '', 'url' => '', 'interval_minutes' => '30'])->getStatusCode())->toBe(422)
        ->and($save(['name' => '改名', 'url' => '', 'interval_minutes' => '60'], ['id' => 99])->getStatusCode())->toBe(404)
        ->and($save(['name' => '改名', 'url' => '', 'interval_minutes' => '60'], ['id' => $id])->getStatusCode())->toBe(200)
        ->and(TcpProbeGroup::find($id)['name'])->toBe('改名');
    $delete = fn (int $id) => $controller->deleteGroup($request, $responses->createResponse(), ['id' => $id]);
    DB::table('tcp_probe')->where('node_id', 1)->update(['group_id' => $id]);
    expect($delete(1)->getStatusCode())->toBe(409)->and($delete($id)->getStatusCode())->toBe(409);
    DB::table('tcp_probe')->where('node_id', 1)->update(['group_id' => 1]);
    expect($delete($id)->getStatusCode())->toBe(200)->and($delete($id)->getStatusCode())->toBe(404)
        ->and(TcpProbeGroup::all())->toHaveCount(1);
});

it('renders the group panel, a group filter and a group picker per node', function () {
    probeGroupNodeTable();
    $id = probeGroup('中转', 'https://example.test/relay.csv', 30);
    DB::table('tcp_probe_target')->insert(['carrier' => 'mobile', 'label' => '<b>入口</b>', 'ip' => '9.9.9.9', 'port' => 443, 'group_id' => $id, 'source' => 'url']);
    DB::table('tcp_probe_group')->where('id', $id)->update(['last_message' => '抓取成功：1 个目标', 'last_status' => 'ok']);
    $shell = sys_get_temp_dir() . '/sspanel-probe-shell';
    if (! is_dir($shell . '/shell')) {
        mkdir($shell . '/shell', 0o777, true);
    }
    file_put_contents($shell . '/shell/admin_header.tpl', '<body data-nav="{$nav|default:\'\'}">');
    file_put_contents($shell . '/shell/admin_footer.tpl', '</body>');
    $smarty = new \Smarty\Smarty();
    $smarty->setTemplateDir([$shell, BASE_PATH . '/resources/views/cafe']);
    $smarty->setCompileDir($shell . '/compile');
    $smarty->setForceCompile(true);
    $overview = TcpProbe::overview($this->now);
    $blank = ['status' => 'gray', 'status_label' => '暂无有效数据', 'latency_ms' => null, 'reach' => '0 / 0'];
    $targets = array_map(static fn ($target) => $target + $blank + [
        'managed' => false, 'locked' => $target['group_id'] === $id, 'origin' => $target['group_id'] === $id ? 'URL 抓取' : '手动添加',
    ], TcpProbe::targets());
    $groups = [
        TcpProbeGroup::find(1) + ['targets' => 2, 'nodes' => 1, 'status' => 'gray', 'interval_seconds' => 60],
        TcpProbeGroup::find($id) + ['targets' => 1, 'nodes' => 0, 'status' => 'green', 'interval_seconds' => 60],
    ];
    $nodes = [['id' => 1, 'name' => '香港 01', 'enabled' => true, 'threshold_ms' => 250, 'group_id' => 1, 'states' => [], 'state_labels' => [], 'reported' => '—']];
    $html = $smarty->assign('installed', true)->assign('overview', $overview)->assign('updated', null)
        ->assign('targets', $targets)->assign('managed', 0)->assign('nodes', $nodes)->assign('node_enabled', 1)
        ->assign('groups', $groups)->assign('groups_installed', true)->assign('group_names', [1 => '默认', $id => '中转'])
        ->assign('carriers', TcpProbeStatus::CARRIERS)->assign('carrier_codes', TcpProbeStatus::DISPLAY_NAMES)
        ->assign('interval_seconds', 60)->assign('probe_attempts', TcpProbe::ATTEMPTS)->assign('probe_timeout_ms', TcpProbe::TIMEOUT_MS)
        ->assign('taier_installed', false)->assign('taier', \App\Services\TaierProbeSource::settings())
        ->assign('taier_status', 'gray')->assign('taier_cities', [])->assign('csrf_token', 'token')
        ->fetch('admin/node/probe.tpl');
    expect($html)->toContain('name="group_id[1]"')->toContain('data-group="' . $id . '"')
        ->toContain('hx-post="/admin/node/probe/groups/' . $id . '/sync"')
        ->toContain('hx-delete="/admin/node/probe/groups/' . $id . '"')
        ->not->toContain('hx-delete="/admin/node/probe/groups/1"')
        ->toContain('IP,端口,运营商,名字')->toContain('抓取成功：1 个目标')
        ->not->toContain('<b>入口</b>')->toContain('&lt;b&gt;入口&lt;/b&gt;');
});
