<?php

declare(strict_types=1);

use App\Controllers\Admin\TcpProbeController;
use App\Services\DB;
use App\Services\TcpProbe;
use App\Utils\TcpProbeStatus;
use App\Models\Node;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Schema\Blueprint;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;

beforeEach(function () {
    $this->resolver = Node::getConnectionResolver();
    $this->database = new DB();
    $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->database->setAsGlobal();
    $this->database->bootEloquent();
    $this->migration = require BASE_PATH . '/db/migrations/2026091701-add_node_tcp_probe.php';
    $this->migration->up();
    DB::table('tcp_probe')->insert(['node_id' => 1, 'enabled' => true, 'threshold_ms' => 250]);
    DB::table('tcp_probe_target')->insert([
        ['id' => 1, 'carrier' => 'telecom', 'label' => '广东电信', 'ip' => '1.1.1.1', 'port' => 443],
        ['id' => 2, 'carrier' => 'telecom', 'label' => '上海电信', 'ip' => '8.8.8.8', 'port' => 443],
    ]);
    $this->dynamicMigration = require BASE_PATH . '/db/migrations/2026091702-dynamic_tcp_probe_targets.php';
    $this->dynamicMigration->up();
    $this->now = 1800000000;
});

afterEach(function () {
    $this->database->getConnection()->disconnect();
    if ($this->resolver) { Node::setConnectionResolver($this->resolver); } else { Node::unsetConnectionResolver(); }
});

/** A scalar repeats for all three samples; an array lists the three samples explicitly. */
function tcpReport(int $time, mixed $first = 120, mixed $second = 150, int $node = 1): array
{
    $results = [];
    foreach ([1 => $first, 2 => $second] as $id => $value) {
        $results[] = ['target_id' => $id, 'samples' => array_map(
            static fn ($s) => ['latency_ms' => is_numeric($s) ? $s : null, 'error' => is_numeric($s) ? null : $s],
            is_array($value) ? $value : array_fill(0, 3, $value))];
    }
    return ['config_hash' => TcpProbe::config($node)['config_hash'], 'measured_at' => $time, 'results' => $results];
}

function tcpNodeTable(): void
{
    test()->database->schema()->create('node', function (Blueprint $table) {
        $table->increments('id'); $table->string('name')->default('');
    });
}

it('migrates repeatedly and reverses without touching node schema', function () {
    expect($this->migration->up())->toBe(2026091701)
        ->and(DB::table('tcp_probe')->count())->toBe(1);
    expect($this->migration->down())->toBe(2026091700)->and(TcpProbe::installed())->toBeFalse();
    // Nothing installed: no carrier columns and every node is simply unmonitored.
    expect(TcpProbe::carriers())->toBe([])
        ->and(TcpProbe::current([1], $this->now)[1])->toBeNull();
});

it('confirms green, yellow, red and recovery without flapping', function () {
    $now = $this->now;
    $send = function ($first, $second) use (&$now) {
        TcpProbe::report(1, tcpReport($now, $first, $second), $now);
        $status = TcpProbe::current([1], $now)[1]['telecom']['status'];
        $now += 60;
        return $status;
    };
    // The first, still unconfirmed round shows its raw reading instead of a gray placeholder.
    expect($send(120, 150))->toBe('green')
        ->and($send(120, 150))->toBe('green')
        ->and($send(120, 350))->toBe('green')
        ->and($send(120, 350))->toBe('yellow')
        ->and($send('timeout', 'refused'))->toBe('yellow')
        ->and($send('timeout', 'refused'))->toBe('yellow')
        ->and($send('timeout', 'refused'))->toBe('red')
        ->and($send(120, 150))->toBe('red')
        ->and($send(120, 150))->toBe('green');
});

it('treats a partial target outage as yellow and no success as null latency', function () {
    $report = tcpReport($this->now, 'refused', 100);
    $results = TcpProbe::validateReport($report, TcpProbe::config(1), $this->now);
    $state = TcpProbeStatus::summarize($results, 250);
    expect($state['telecom']['raw'])->toBe('yellow')->and($state['telecom']['success'])->toBe(3)
        ->and($state['unicom']['raw'])->toBe('gray');
    $results = TcpProbe::validateReport(tcpReport($this->now, 'timeout', 'timeout'), TcpProbe::config(1), $this->now);
    expect(TcpProbeStatus::summarize($results, 250)['telecom']['latency_ms'])->toBeNull();
});

it('ignores retry and out of order reports and resets streaks after missing rounds', function () {
    TcpProbe::report(1, tcpReport($this->now), $this->now);
    TcpProbe::report(1, tcpReport($this->now, 'timeout', 'timeout'), $this->now);
    TcpProbe::report(1, tcpReport($this->now - 60), $this->now);
    expect(DB::table('tcp_probe_round')->count())->toBe(1);
    TcpProbe::report(1, tcpReport($this->now + 120), $this->now + 120);
    expect(TcpProbe::current([1], $this->now + 120)[1]['telecom']['status'])->toBe('green');
    // The streak really did reset: one bad round after the gap is shown raw, not held at the old confirmed state.
    TcpProbe::report(1, tcpReport($this->now + 180, 'timeout', 'timeout'), $this->now + 180);
    expect(TcpProbe::current([1], $this->now + 180)[1]['telecom']['status'])->toBe('red');
});

it('expires status by time but keeps a fresh round across target edits', function () {
    TcpProbe::report(1, tcpReport($this->now - 60), $this->now - 60);
    TcpProbe::report(1, tcpReport($this->now), $this->now);
    expect(TcpProbe::current([1], $this->now + 181)[1]['telecom']['status'])->toBe('red')
        ->and(TcpProbe::detail(1, $this->now + 181)['carriers']['telecom']['targets'][0]['status'])->toBe('red')
        ->and(TcpProbe::detail(1, $this->now + 181)['updated_at'])->toBe(date('m-d H:i:s', $this->now));
    // A target edit changes the config hash; the last round is still a real measurement while it is fresh.
    DB::table('tcp_probe_target')->where('id', 1)->update(['port' => 80]);
    DB::table('tcp_probe_target')->insert(['id' => 3, 'carrier' => 'telecom', 'label' => '北京电信', 'ip' => '9.9.9.9', 'port' => 443]);
    $detail = TcpProbe::detail(1, $this->now);
    expect(TcpProbe::current([1], $this->now)[1]['telecom']['status'])->toBe('green')
        ->and($detail['carriers']['telecom']['status'])->toBe('green')
        ->and(array_column($detail['carriers']['telecom']['targets'], 'label'))->toBe(['广东电信', '上海电信'])
        ->and($detail['carriers']['telecom']['targets'][0]['status'])->toBe('green');
    DB::table('tcp_probe')->update(['enabled' => false]);
    expect(fn () => TcpProbe::report(1, tcpReport($this->now + 60), $this->now + 60))->toThrow(InvalidArgumentException::class);
});

it('lists only carriers that have at least one target, in carrier order', function () {
    expect(TcpProbe::carriers())->toBe(['telecom' => '电信']);
    DB::table('tcp_probe_target')->insert(['id' => 3, 'carrier' => 'mobile', 'label' => '广东移动', 'ip' => '9.9.9.9', 'port' => 443]);
    expect(TcpProbe::carriers())->toBe(['telecom' => '电信', 'mobile' => '移动']);
});

it('marks nodes without an enabled probe as unmonitored instead of broken', function () {
    TcpProbe::report(1, tcpReport($this->now), $this->now);
    $rows = TcpProbe::current([1, 2], $this->now);
    expect($rows[1]['telecom']['status'])->toBe('green')
        ->and($rows[2])->toBeNull();
    DB::table('tcp_probe')->where('node_id', 1)->update(['enabled' => false]);
    expect(TcpProbe::current([1], $this->now)[1])->toBeNull()
        ->and(TcpProbe::detail(1, $this->now)['enabled'])->toBeFalse();
});

it('rejects malformed, incomplete, old and duplicate target reports', function (string $case) {
    $body = tcpReport($this->now);
    match ($case) {
        'missing' => array_pop($body['results']),
        'duplicate' => $body['results'][1]['target_id'] = 1,
        'unknown' => $body['results'][1]['target_id'] = 99,
        'old' => $body['measured_at'] -= 121,
        'future' => $body['measured_at'] += 31,
        'hash' => $body['config_hash'] = 'stale',
        'negative' => $body['results'][0]['samples'][0]['latency_ms'] = -1,
        'failure_latency' => $body['results'][0]['samples'][0]['error'] = 'timeout',
        'sample_count' => array_pop($body['results'][0]['samples']),
    };
    expect(fn () => TcpProbe::report(1, $body, $this->now))->toThrow(InvalidArgumentException::class);
    expect(DB::table('tcp_probe_round')->count())->toBe(0);
})->with(['missing', 'duplicate', 'unknown', 'old', 'future', 'hash', 'negative', 'failure_latency', 'sample_count']);

it('preserves outages within history buckets and does not count missing data as uptime', function () {
    $now = $this->now + 180;
    $states = ['telecom' => ['status' => 'red', 'attempts' => 6, 'success' => 0]];
    $rounds = [['measured_at' => $now - 120, 'states' => $states]];
    $states['telecom'] = ['status' => 'green', 'attempts' => 6, 'success' => 6];
    $rounds[] = ['measured_at' => $now - 60, 'states' => $states];
    $history = TcpProbeStatus::history($rounds, 'telecom', $now);
    expect($history['buckets'])->toHaveCount(96)
        ->and($history['buckets'][0]['status'])->toBe('gray')
        ->and($history['buckets'][95]['status'])->toBe('red')
        ->and($history['uptime'])->toBe('50%');
    $empty = TcpProbeStatus::history([], 'unicom', $now);
    expect($empty['uptime'])->toBe('—')->and($empty['coverage'])->toEqual(0);
});

it('validates public IPv4 configuration and rejects repeated endpoints', function () {
    $target = ['carrier' => 'telecom', 'label' => '广东电信', 'ip' => '1.1.1.1', 'port' => '443'];
    expect(TcpProbeController::validateTarget($target)['carrier'])->toBe('telecom');
    foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '224.0.0.1', '::1', 'example.com'] as $ip) {
        $target['ip'] = $ip;
        expect(fn () => TcpProbeController::validateTarget($target))->toThrow(InvalidArgumentException::class);
    }
});

it('serves config and accepts JSON through the actual WebAPI handlers', function () {
    $this->database->schema()->create('node', function (Blueprint $table) {
        $table->increments('id'); $table->integer('type')->default(1);
    });
    Node::create(['id' => 1]);
    $controller = (new ReflectionClass(\App\Controllers\WebAPI\TcpProbeController::class))->newInstanceWithoutConstructor();
    $factory = new HttpFactory();
    $requests = new DecoratedServerRequestFactory($factory);
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = $requests->createServerRequest('GET', '/mod_mu/nodes/1/tcp-probe');
    $response = $controller->config($request, $responses->createResponse(), ['id' => 1]);
    $data = json_decode((string) $response->getBody(), true);
    expect($data['data']['enabled'])->toBeTrue()->and($data['data']['targets'])->toHaveCount(2);
    $request = $requests->createServerRequest('POST', '/mod_mu/nodes/1/tcp-probe')
        ->withHeader('Content-Type', 'application/json')->withBody($factory->createStream(json_encode(tcpReport(time()))));
    $response = $controller->report($request, $responses->createResponse(), ['id' => 1]);
    expect($response->getStatusCode())->toBe(200)->and(DB::table('tcp_probe_round')->count())->toBe(1);
    $request = $request->withBody($factory->createStream('null'));
    expect($controller->report($request, $responses->createResponse(), ['id' => 1])->getStatusCode())->toBe(422);
    $request = $request->withBody($factory->createStream(str_repeat('a', 32769)));
    expect($controller->report($request, $responses->createResponse(), ['id' => 1])->getStatusCode())->toBe(413);
    expect($controller->report($request, $responses->createResponse(), ['id' => 2])->getStatusCode())->toBe(404);
});

it('accepts complete large target reports through the WebAPI', function () {
    $this->database->schema()->create('node', function (Blueprint $table) {
        $table->increments('id'); $table->integer('type')->default(1);
    });
    Node::create(['id' => 1]);
    DB::table('tcp_probe_target')->delete();
    for ($i = 1; $i <= 250; $i++) {
        DB::table('tcp_probe_target')->insert(['id' => $i, 'carrier' => 'telecom', 'label' => 'CT ' . $i, 'ip' => '8.8.4.' . $i, 'port' => 443]);
    }
    $config = TcpProbe::config(1);
    $body = json_encode(['config_hash' => $config['config_hash'], 'measured_at' => time(), 'results' => array_map(
        fn ($target) => ['target_id' => $target['id'], 'samples' => array_fill(0, 3, ['latency_ms' => 123.456, 'error' => null])], $config['targets'])]);
    expect(strlen($body))->toBeGreaterThan(32768);
    $factory = new HttpFactory();
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = (new DecoratedServerRequestFactory($factory))->createServerRequest('POST', '/mod_mu/nodes/1/tcp-probe')
        ->withHeader('Content-Type', 'application/json')->withBody($factory->createStream($body));
    $controller = (new ReflectionClass(\App\Controllers\WebAPI\TcpProbeController::class))->newInstanceWithoutConstructor();
    expect($controller->report($request, $responses->createResponse(), ['id' => 1])->getStatusCode())->toBe(200)
        ->and(TcpProbe::decode(DB::table('tcp_probe_round')->first())['results'])->toHaveCount(250);
});

it('rejects detail requests for nodes outside the users visible group', function () {
    $this->database->schema()->create('node', function (Blueprint $table) {
        $table->increments('id'); $table->string('name')->default('');
        foreach (['type', 'node_group', 'node_class', 'node_bandwidth', 'node_bandwidth_limit'] as $field) {
            $table->integer($field)->default(0);
        }
    });
    Node::create(['id' => 1, 'type' => 1, 'node_group' => 2]);
    $controller = (new ReflectionClass(\App\Controllers\User\ServerController::class))->newInstanceWithoutConstructor();
    $user = new \App\Models\User(); $user->node_group = 1; $user->is_admin = false;
    (new ReflectionProperty($controller, 'user'))->setValue($controller, $user);
    $factory = new HttpFactory();
    $request = (new DecoratedServerRequestFactory($factory))->createServerRequest('GET', '/user/server/1');
    $responses = new DecoratedResponseFactory($factory, $factory);
    foreach (['detail', 'status'] as $action) {
        expect($controller->$action($request, $responses->createResponse(), ['id' => 1])->getStatusCode())->toBe(404);
    }
});

it('renders 96 history bars per carrier with escaped target labels', function () {
    DB::table('tcp_probe_target')->where('id', 1)->update(['label' => '<script>alert(1)</script>']);
    $smarty = new \Smarty\Smarty();
    $smarty->setTemplateDir(BASE_PATH . '/resources/views/cafe');
    $smarty->setCompileDir(BASE_PATH . '/storage/framework/smarty/compile');
    $smarty->assign('probe', TcpProbe::detail(1, $this->now));
    $html = $smarty->fetch('user/server_probe.tpl');
    expect(substr_count($html, 'class="probe-bar"'))->toBe(288)
        ->and($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('rolls every enabled node up into the admin overview and renders every row', function () {
    $this->database->schema()->create('node', function (Blueprint $table) {
        $table->increments('id'); $table->string('name')->default('');
    });
    Node::create(['id' => 1, 'name' => '香港 01']);
    Node::create(['id' => 2, 'name' => '<script>alert(1)</script>']);
    DB::table('tcp_probe')->insert(['node_id' => 2, 'enabled' => true, 'threshold_ms' => 250]);
    // Two consecutive rounds, so both nodes' states leave the unconfirmed gray.
    foreach ([$this->now - 60, $this->now] as $at) {
        TcpProbe::report(1, tcpReport($at), $at);
        TcpProbe::report(2, tcpReport($at, 'timeout', 150), $at);
    }

    $overview = TcpProbe::overview($this->now);
    expect($overview['reporting'])->toBe(2)
        ->and($overview['carriers']['telecom']['counts'])->toBe(['green' => 1, 'yellow' => 1, 'red' => 0, 'gray' => 0])
        ->and($overview['carriers']['telecom']['status'])->toBe('yellow')
        ->and($overview['carriers']['telecom']['targets'])->toBe(2)
        ->and($overview['carriers']['mobile']['counts']['gray'])->toBe(2)
        ->and($overview['nodes'][2]['states']['telecom'])->toBe('yellow')
        // One node reaches target 1, both reach target 2.
        ->and([$overview['targets'][1]['ok'], $overview['targets'][1]['total'], $overview['targets'][1]['status']])->toBe([1, 2, 'yellow'])
        ->and([$overview['targets'][2]['ok'], $overview['targets'][2]['total'], $overview['targets'][2]['status']])->toBe([2, 2, 'green']);

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
    $targets = array_map(static fn ($target) => $target + [
        'status' => $overview['targets'][$target['id']]['status'], 'status_label' => $overview['targets'][$target['id']]['label'],
        'latency_ms' => $overview['targets'][$target['id']]['latency_ms'],
        'reach' => $overview['targets'][$target['id']]['ok'] . ' / ' . $overview['targets'][$target['id']]['total'], 'managed' => false,
    ], TcpProbe::targets());
    // Medians carry two decimals; the console must round them, not truncate (250.5 is over a 250 threshold).
    $overview['carriers']['telecom']['latency_ms'] = 250.5;
    $nodes = [
        ['id' => 1, 'name' => '香港 01', 'enabled' => true, 'threshold_ms' => 250,
            'states' => $overview['nodes'][1]['states'], 'state_labels' => ['telecom' => '正常'], 'reported' => '0 秒前'],
        ['id' => 2, 'name' => '<script>alert(1)</script>', 'enabled' => true, 'threshold_ms' => 250,
            'states' => $overview['nodes'][2]['states'], 'state_labels' => ['telecom' => '波动 / 延迟偏高'], 'reported' => '0 秒前'],
    ];
    $html = $smarty->assign('installed', true)->assign('overview', $overview)->assign('updated', '0 秒前')
        ->assign('targets', $targets)->assign('managed', 0)->assign('nodes', $nodes)->assign('node_enabled', 2)
        ->assign('carriers', TcpProbeStatus::CARRIERS)->assign('carrier_codes', TcpProbeStatus::DISPLAY_NAMES)
        ->assign('interval_seconds', TcpProbe::interval(count($targets)))
        ->assign('taier_installed', false)->assign('taier', \App\Services\TaierProbeSource::settings())
        ->assign('taier_status', 'gray')->assign('taier_cities', [])->assign('csrf_token', 'token')
        ->fetch('admin/node/probe.tpl');

    expect($html)->toContain('data-nav="node-probe"')->toContain('TaierSpeedtest')
        ->toContain('name="threshold_ms[2]"')->toContain('name="enabled[1]"')
        ->toContain('>251</span>')->toContain('2 / 2 个节点在上报')
        // The node form must reach the server even when a search-hidden row is invalid, must not submit on Enter
        // in its search box, and must carry a trailing sentinel so a request truncated by max_input_vars is detectable.
        ->toContain('hx-post="/admin/node/probe/nodes" hx-swap="none" novalidate')
        ->toContain('placeholder="搜索节点名称或 ID" x-model="q" @keydown.enter.prevent')
        ->toContain('<input type="hidden" name="node_count" value="2">')
        ->toContain('hx-delete="/admin/node/probe/targets/1" hx-swap="none"')
        // Every row renders; the list scrolls instead of truncating.
        ->and(substr_count($html, 'data-probe-target data-carrier'))->toBe(2)
        ->and(substr_count($html, 'data-probe-node data-search'))->toBe(2)
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('creates edits and deletes independent targets without replacing existing configuration', function () {
    $controller = (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor();
    $factory = new HttpFactory();
    $requests = new DecoratedServerRequestFactory($factory);
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = $requests->createServerRequest('POST', '/admin/node/probe/targets');
    $original = TcpProbe::targets();
    // More than three targets for one carrier, and more than nine overall.
    for ($i = 1; $i <= 30; $i++) {
        $response = $controller->saveTarget($request->withParsedBody([
            'carrier' => 'mobile', 'label' => '移动 ' . $i, 'ip' => '8.8.4.' . $i, 'port' => '443',
        ]), $responses->createResponse(), []);
        expect($response->getStatusCode())->toBe(200);
        $id = json_decode((string) $response->getBody(), true)['id'];
    }
    expect(TcpProbe::targets())->toHaveCount(32)
        ->and(array_slice(TcpProbe::targets(), 0, 2))->toBe($original)
        ->and($id)->toBeGreaterThan(9);
    $body = ['carrier' => 'unicom', 'label' => '联通', 'ip' => '8.8.4.30', 'port' => '80'];
    expect($controller->saveTarget($request->withParsedBody($body), $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(200);
    expect(DB::table('tcp_probe_target')->where('id', $id)->first()->carrier)->toBe('unicom');
    $hash = TcpProbe::config(1)['config_hash'];
    foreach ([['ip' => '127.0.0.1'], ['carrier' => 'other'], ['carrier' => []], ['ip' => ''], ['label' => ''], ['port' => 0], ['ip' => '1.1.1.1', 'port' => '443']] as $invalid) {
        expect($controller->saveTarget($request->withParsedBody(array_replace($body, $invalid)), $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(422)
            ->and(TcpProbe::config(1)['config_hash'])->toBe($hash);
    }
    expect($controller->saveTarget($request->withParsedBody($body), $responses->createResponse(), [])->getStatusCode())->toBe(422);
    expect($controller->deleteTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(200)
        ->and(TcpProbe::targets())->toHaveCount(31)
        ->and($controller->deleteTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(404)
        ->and($controller->saveTarget($request->withParsedBody($body), $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(404);
    foreach (TcpProbe::targets() as $target) {
        $controller->deleteTarget($request, $responses->createResponse(), ['id' => $target['id']]);
    }
    expect(TcpProbe::targets())->toBe([]);
    $controller->saveTarget($request->withParsedBody($body), $responses->createResponse(), []);
    expect(TcpProbe::targets()[0]['id'])->toBeGreaterThan($id);
});

it('preserves target data and ids during repeatable dynamic migration', function () {
    $targets = TcpProbe::targets();
    expect($this->dynamicMigration->up())->toBe(2026091702)
        ->and(TcpProbe::targets())->toBe($targets);
    expect($this->dynamicMigration->down())->toBe(2026091701)
        ->and(TcpProbe::targets())->toBe($targets);
    $this->dynamicMigration->up();
    expect(DB::table('tcp_probe_target')->insertGetId(['carrier' => 'mobile', 'label' => 'CM', 'ip' => '8.8.4.4', 'port' => 443]))->toBe(3);
});

it('uses an adaptive interval for complete reports and status freshness', function () {
    for ($i = 3; $i <= 32; $i++) {
        DB::table('tcp_probe_target')->insert(['id' => $i, 'carrier' => 'telecom', 'label' => 'CT ' . $i, 'ip' => '8.8.4.' . $i, 'port' => 443]);
    }
    $config = TcpProbe::config(1);
    expect($config['interval_seconds'])->toBe(120);
    $results = array_map(fn ($target) => ['target_id' => $target['id'], 'samples' => array_fill(0, 3, ['latency_ms' => 100, 'error' => null])], $config['targets']);
    foreach ([$this->now, $this->now + 120] as $time) {
        TcpProbe::report(1, ['config_hash' => $config['config_hash'], 'measured_at' => $time, 'results' => $results], $time + 90);
    }
    expect(TcpProbe::current([1], $this->now + 360)[1]['telecom']['status'])->toBe('green')
        ->and(TcpProbe::detail(1, $this->now + 360)['carriers']['telecom']['status'])->toBe('green')
        ->and(TcpProbe::current([1], $this->now + 481)[1]['telecom']['status'])->toBe('red');
});

it('counts scheduled slow rounds as full history coverage', function () {
    $now = intdiv($this->now, 900) * 900 + 899;
    $rounds = [];
    for ($time = $now - 899; $time < $now; $time += 120) {
        $rounds[] = ['measured_at' => $time, 'states' => ['telecom' => ['status' => 'green', 'attempts' => 96, 'success' => 96, 'interval_seconds' => 120]]];
    }
    expect(TcpProbeStatus::history($rounds, 'telecom', $now)['buckets'][95]['status'])->toBe('green');
});

it('spreads long detection periods across history buckets without double counting', function () {
    $now = intdiv($this->now, 900) * 900 + 899;
    $state = ['status' => 'green', 'attempts' => 3000, 'success' => 3000, 'interval_seconds' => 1800];
    $rounds = [['measured_at' => $now - 1799, 'states' => ['telecom' => $state]]];
    $history = TcpProbeStatus::history($rounds, 'telecom', $now);
    expect($history['buckets'][94]['status'])->toBe('green')->and($history['buckets'][95]['status'])->toBe('green');
});

it('saves the whole node table only when every node arrived intact', function () {
    tcpNodeTable();
    Node::create(['id' => 1]); Node::create(['id' => 2]);
    $controller = (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor();
    $factory = new HttpFactory();
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = (new DecoratedServerRequestFactory($factory))->createServerRequest('POST', '/admin/node/probe/nodes');
    $post = fn (array $body) => $controller->saveNodes($request->withParsedBody($body), $responses->createResponse(), []);
    $full = ['threshold_ms' => [1 => '300', 2 => '250'], 'enabled' => [1 => '0', 2 => '1'], 'node_count' => '2'];
    expect($post($full)->getStatusCode())->toBe(200)
        ->and(TcpProbe::config(1)['enabled'])->toBeFalse()->and(TcpProbe::config(1)['threshold_ms'])->toBe(300)
        ->and(TcpProbe::config(2)['enabled'])->toBeTrue();
    // PHP drops fields past max_input_vars from the tail, so a truncated request loses the sentinel first;
    // a page rendered before a node was added or removed carries the wrong count.
    foreach ([['node_count' => null], ['node_count' => '1'], ['threshold_ms' => [1 => '200']]] as $partial) {
        expect($post(array_replace($full, $partial))->getStatusCode())->toBe(409);
    }
    // Non-canonical keys such as enabled[01] must be refused rather than mapped onto another node.
    foreach ([['threshold_ms' => ['01' => '200', 2 => '250']], ['enabled' => ['01' => '1', 2 => '1']]] as $crafted) {
        expect($post(array_replace($full, $crafted))->getStatusCode())->toBe(422);
    }
    $response = $post(array_replace($full, ['threshold_ms' => [1 => '300', 2 => '0']]));
    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode((string) $response->getBody(), true)['msg'])->toContain('#2')
        ->and(TcpProbe::config(1)['threshold_ms'])->toBe(300)->and(TcpProbe::config(2)['enabled'])->toBeTrue();
    expect($post(['threshold_ms' => [1 => '300', 2 => '250'], 'node_count' => '2'])->getStatusCode())->toBe(200)
        ->and(TcpProbe::config(2)['enabled'])->toBeFalse();
});

it('takes the target id from the route or the form body only', function () {
    $controller = (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor();
    $factory = new HttpFactory();
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = (new DecoratedServerRequestFactory($factory))->createServerRequest('POST', '/admin/node/probe/targets');
    $body = ['carrier' => 'mobile', 'label' => '注入', 'ip' => '9.9.9.9', 'port' => '443'];
    $save = fn (\Psr\Http\Message\ServerRequestInterface $request) => $controller->saveTarget($request, $responses->createResponse(), []);
    // An array id must not cast to 1, and a query-string id must not select a row either.
    expect($save($request->withParsedBody($body + ['id' => ['']]))->getStatusCode())->toBe(404)
        ->and($save($request->withQueryParams(['id' => '1'])->withParsedBody($body))->getStatusCode())->toBe(200)
        ->and(DB::table('tcp_probe_target')->where('id', 1)->first()->label)->toBe('广东电信')
        ->and(TcpProbe::targets())->toHaveCount(3);
    foreach (['0', '-1', 'abc'] as $bad) {
        expect($save($request->withParsedBody($body + ['id' => $bad]))->getStatusCode())->toBe(404);
    }
    expect($save($request->withParsedBody(array_replace($body, ['id' => '2', 'ip' => '9.9.9.10'])))->getStatusCode())->toBe(200)
        ->and(DB::table('tcp_probe_target')->where('id', 2)->first()->ip)->toBe('9.9.9.10')
        ->and(TcpProbe::targets())->toHaveCount(3);
});

it('counts a node still on an outdated config as alive but without valid data', function () {
    tcpNodeTable();
    Node::create(['id' => 1]); Node::create(['id' => 2]);
    DB::table('tcp_probe')->insert(['node_id' => 2, 'enabled' => true, 'threshold_ms' => 250]);
    foreach ([$this->now - 60, $this->now] as $at) {
        TcpProbe::report(1, tcpReport($at), $at);
        TcpProbe::report(2, tcpReport($at), $at);
    }
    // Node 2's config hash changes; it keeps reporting on the old one until it fetches the new config.
    DB::table('tcp_probe')->where('node_id', 2)->update(['threshold_ms' => 300]);
    $overview = TcpProbe::overview($this->now);
    expect($overview['reporting'])->toBe(2)
        ->and($overview['measured_at'])->toBe($this->now)
        ->and($overview['nodes'][2]['measured_at'])->toBe($this->now)
        ->and($overview['nodes'][2]['states'])->toBe(['telecom' => 'gray', 'unicom' => 'gray', 'mobile' => 'gray'])
        ->and($overview['carriers']['telecom']['counts'])->toBe(['green' => 1, 'yellow' => 0, 'red' => 0, 'gray' => 1])
        ->and($overview['carriers']['telecom']['latency_ms'])->toBe(135.0)
        ->and([$overview['targets'][1]['ok'], $overview['targets'][1]['total']])->toBe([1, 1]);
});

it('keeps unconfirmed latency and deleted nodes out of the overview and grades the header', function () {
    tcpNodeTable();
    Node::create(['id' => 1]); Node::create(['id' => 2]);
    DB::table('tcp_probe')->insert([
        ['node_id' => 2, 'enabled' => true, 'threshold_ms' => 250],
        ['node_id' => 3, 'enabled' => true, 'threshold_ms' => 250],
    ]);
    foreach ([$this->now - 60, $this->now] as $at) {
        TcpProbe::report(1, tcpReport($at), $at);
        TcpProbe::report(3, tcpReport($at), $at);
    }
    // Node 2's first round is still unconfirmed gray; node 3 no longer exists in the node table.
    TcpProbe::report(2, tcpReport($this->now, 1000, 1000), $this->now);
    $overview = TcpProbe::overview($this->now);
    expect($overview['reporting'])->toBe(2)->and($overview['enabled'])->toBe(2)
        ->and($overview['nodes'])->not->toHaveKey(3)
        ->and($overview['carriers']['telecom']['counts'])->toBe(['green' => 1, 'yellow' => 0, 'red' => 0, 'gray' => 1])
        ->and($overview['carriers']['telecom']['latency_ms'])->toBe(135.0)
        ->and($overview['targets'][1]['total'])->toBe(2)
        ->and($overview['status'])->toBe('green');
    // An enabled node that has gone silent degrades the header even while every carrier is fine.
    Node::create(['id' => 4]);
    DB::table('tcp_probe')->insert(['node_id' => 4, 'enabled' => true, 'threshold_ms' => 250]);
    expect(TcpProbe::overview($this->now)['status'])->toBe('yellow');
    foreach ([60, 120, 180] as $offset) {
        TcpProbe::report(1, tcpReport($this->now + $offset, 'timeout', 'timeout'), $this->now + $offset);
    }
    expect(TcpProbe::overview($this->now + 180)['status'])->toBe('red')
        ->and(TcpProbe::overview($this->now + 180)['reporting'])->toBe(2);
});

it('rates each target per node by the rule the carrier status uses', function () {
    tcpNodeTable();
    Node::create(['id' => 1]); Node::create(['id' => 2]);
    DB::table('tcp_probe')->insert(['node_id' => 2, 'enabled' => true, 'threshold_ms' => 250]);
    foreach ([$this->now - 60, $this->now] as $at) {
        TcpProbe::report(1, tcpReport($at, 'timeout', 400), $at);
        TcpProbe::report(2, tcpReport($at, [200, 'timeout', 'timeout'], 400, 2), $at);
    }
    $targets = TcpProbe::overview($this->now)['targets'];
    // One success out of three is degraded, not healthy; slow but reachable is degraded, never broken.
    expect([$targets[1]['ok'], $targets[1]['total'], $targets[1]['status'], $targets[1]['latency_ms']])->toBe([0, 2, 'yellow', 200.0])
        ->and([$targets[2]['ok'], $targets[2]['total'], $targets[2]['status'], $targets[2]['latency_ms']])->toBe([0, 2, 'yellow', 400.0]);
    foreach ([60, 120] as $offset) {
        TcpProbe::report(1, tcpReport($this->now + $offset, 'timeout', 100), $this->now + $offset);
        TcpProbe::report(2, tcpReport($this->now + $offset, 'timeout', 100, 2), $this->now + $offset);
    }
    $targets = TcpProbe::overview($this->now + 120)['targets'];
    expect([$targets[1]['ok'], $targets[1]['total'], $targets[1]['status']])->toBe([0, 2, 'red'])
        ->and([$targets[2]['ok'], $targets[2]['total'], $targets[2]['status']])->toBe([2, 2, 'green']);
});

it('maps the persisted Taier verdict onto the status dot', function () {
    expect(TcpProbeController::taierStatus(['last_status' => 'none']))->toBe('gray')
        ->and(TcpProbeController::taierStatus(['last_status' => 'ok']))->toBe('green')
        ->and(TcpProbeController::taierStatus(['last_status' => 'failed']))->toBe('red')
        ->and(TcpProbeController::taierStatus([]))->toBe('gray');
});

it('reports a refused manual sync instead of reloading the page', function () {
    (require BASE_PATH . '/db/migrations/2026091703-add_taier_probe_sync.php')->up();
    (require BASE_PATH . '/db/migrations/2026091704-add_taier_sync_status.php')->up();
    $controller = (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor();
    $factory = new HttpFactory();
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = (new DecoratedServerRequestFactory($factory))->createServerRequest('POST', '/admin/node/probe/source/sync');
    $response = $controller->syncSource($request, $responses->createResponse(), []);
    expect($response->getStatusCode())->toBe(409)
        ->and($response->hasHeader('HX-Refresh'))->toBeFalse()
        ->and(json_decode((string) $response->getBody(), true)['ret'])->toBe(0);
});
