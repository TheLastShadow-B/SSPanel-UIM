<?php

declare(strict_types=1);

use App\Controllers\Admin\TcpProbeController;
use App\Models\Node;
use App\Services\DB;
use App\Services\TaierProbeSource;
use App\Services\TcpProbe;
use App\Utils\TcpProbeStatus;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;

beforeEach(function () {
    $this->resolver = Node::getConnectionResolver();
    $this->database = new DB();
    $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->database->setAsGlobal();
    $this->database->bootEloquent();
    foreach (['2026091701-add_node_tcp_probe', '2026091702-dynamic_tcp_probe_targets', '2026091703-add_taier_probe_sync'] as $name) {
        $this->migration = require BASE_PATH . '/db/migrations/' . $name . '.php';
        $this->migration->up();
    }
    $this->statusMigration = require BASE_PATH . '/db/migrations/2026091704-add_taier_sync_status.php';
    $this->statusMigration->up();
    DB::table('tcp_probe')->insert(['node_id' => 1, 'enabled' => true, 'threshold_ms' => 250]);
    DB::table('tcp_probe_target')->insert(['carrier' => 'telecom', 'label' => '手动', 'ip' => '1.1.1.1', 'port' => 443]);
    TaierProbeSource::saveSettings(true, ['北京', '上海', '广州'], 6);
    $this->now = 1800000000;
});

afterEach(function () {
    $this->database->getConnection()->disconnect();
    if ($this->resolver) { Node::setConnectionResolver($this->resolver); } else { Node::unsetConnectionResolver(); }
});

function taierRows(): array
{
    $rows = [];
    foreach (TaierProbeSource::CITIES as $city => $province) {
        foreach (TcpProbeStatus::DISPLAY_NAMES as $carrier => $name) {
            $id = count($rows) + 1;
            $rows[] = [[
                'hostid' => (string) $id, 'hostname' => $city . TcpProbeStatus::CARRIERS[$carrier],
                'hostip' => '8.8.4.' . $id, 'port' => '65499', 'pname' => $province, 'city' => $city,
            ]];
        }
    }
    return $rows;
}

function taierResponses(?array $rows = null): array
{
    return [new Response(200, [], '1.1.1.1|[]'), ...array_map(
        fn ($rows) => new Response(200, [], json_encode($rows)), $rows ?? taierRows()
    )];
}

function taierSource(array $responses): TaierProbeSource
{
    return new TaierProbeSource(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
}

it('imports nine strict targets and preserves ids hashes and manual targets on repeated sync', function () {
    $manual = TcpProbe::targets()[0];
    $rows = taierRows();
    $source = taierSource([...taierResponses(), ...taierResponses(), ...taierResponses($rows)]);
    expect($source->sync(true, $this->now)['ret'])->toBe(1)->and(TcpProbe::targets())->toHaveCount(10);
    $targets = TcpProbe::targets(); $config = TcpProbe::config(1);
    expect($targets[0])->toBe($manual)
        ->and(array_keys($config['targets'][1]))->toBe(['id', 'carrier', 'label', 'ip', 'port']);
    expect($source->sync(true, $this->now + 60)['ret'])->toBe(1)
        ->and(TcpProbe::targets())->toBe($targets)
        ->and(TcpProbe::config(1)['config_hash'])->toBe($config['config_hash']);
    $rows[0][0]['hostip'] = '8.8.8.9';
    expect(taierSource(taierResponses($rows))->sync(true, $this->now + 120)['ret'])->toBe(1)
        ->and(TcpProbe::targets()[1]['id'])->toBe($targets[1]['id'])
        ->and(TcpProbe::targets()[1]['ip'])->toBe('8.8.8.9')
        ->and(TcpProbe::config(1)['config_hash'])->not->toBe($config['config_hash']);
});

it('requests only the fixed HTTPS service with the expected selection fields', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler(taierResponses()));
    $stack->push(Middleware::history($history));
    $source = new TaierProbeSource(new Client(['handler' => $stack]));
    expect($source->sync(true, $this->now)['ret'])->toBe(1)->and($history)->toHaveCount(10);
    foreach ($history as $entry) {
        expect($entry['request']->getUri()->getHost())->toBe('dlcv2.cnspeedtest.cn')
            ->and($entry['request']->getUri()->getScheme())->toBe('https')
            ->and($entry['options']['allow_redirects'])->toBeFalse()
            ->and($entry['options']['timeout'])->toBe(6);
    }
    parse_str($history[1]['request']->getUri()->getQuery(), $query);
    expect($query)->toMatchArray(['ip' => '1.1.1.1', 'city' => '北京', 'wifioper' => '电信', 'ipv6' => '0']);
});

it('keeps the full previous snapshot if one carrier fails and schedules retry', function (string $failure) {
    taierSource(taierResponses())->sync(true, $this->now);
    $old = TcpProbe::targets(); $hash = TcpProbe::config(1)['config_hash'];
    $responses = taierResponses();
    $responses[4] = match ($failure) {
        'empty' => new Response(200, [], '[]'),
        'html' => new Response(200, [], '<html>Unavailable</html>'),
        'status' => new Response(503),
        'redirect' => new Response(302, ['Location' => 'http://127.0.0.1/']),
        'timeout' => new \GuzzleHttp\Exception\ConnectException('secret-egress-IP', new \GuzzleHttp\Psr7\Request('GET', 'https://example.test/?ip=secret-egress-IP')),
    };
    $result = taierSource($responses)->sync(true, $this->now + 60);
    expect($result['ret'])->toBe(0)->and($result['msg'])->not->toContain('secret-egress-IP')
        ->and(TcpProbe::targets())->toBe($old)->and(TcpProbe::config(1)['config_hash'])->toBe($hash)
        ->and(TaierProbeSource::settings()['last_success'])->toBe($this->now)
        ->and(TaierProbeSource::settings()['next_sync_at'])->toBe($this->now + 960);
})->with(['empty', 'html', 'status', 'redirect', 'timeout']);

it('does not query when disabled not due or another worker owns the lease', function () {
    $source = taierSource([]);
    DB::table('tcp_probe_source')->update(['enabled' => false]);
    expect($source->sync(true, $this->now)['ret'])->toBe(0);
    DB::table('tcp_probe_source')->update(['enabled' => true, 'next_sync_at' => $this->now + 1]);
    expect($source->sync(false, $this->now)['ret'])->toBe(0);
    DB::table('tcp_probe_source')->update(['next_sync_at' => 0, 'lock_until' => $this->now + 1]);
    expect($source->sync(true, $this->now)['ret'])->toBe(0);
    expect(taierSource(taierResponses())->sync(false, $this->now + 2)['ret'])->toBe(1)
        ->and(TaierProbeSource::settings()['next_sync_at'])->toBe($this->now + 2 + 21600);
});

it('rejects neighbour cities wrong carriers private IPs and malformed ports', function (array $changes) {
    $row = array_replace(taierRows()[0][0], $changes);
    expect(fn () => TaierProbeSource::select([$row], '北京', 'telecom'))->toThrow(RuntimeException::class);
})->with([
    [['city' => '天津']], [['hostname' => '北京移动']], [['pname' => '广东']],
    [['hostip' => '127.0.0.1']], [['hostip' => '169.254.169.254']], [['port' => '0']], [['hostid' => []]],
]);

it('prefers the previously selected remote host despite changing API order', function () {
    $a = taierRows()[0][0]; $b = array_replace($a, ['hostid' => '20', 'hostip' => '8.8.8.8']);
    expect(TaierProbeSource::select([$b, $a], '北京', 'telecom')['source_host_id'])->toBe('1')
        ->and(TaierProbeSource::select([$a, $b], '北京', 'telecom', '20')['source_host_id'])->toBe('20');
});

it('skips same-carrier manual duplicates and atomically rejects cross-carrier duplicates', function () {
    DB::table('tcp_probe_target')->update(['ip' => '8.8.4.1', 'port' => 65499]);
    $result = taierSource(taierResponses())->sync(true, $this->now);
    expect($result['ret'])->toBe(1)->and($result['msg'])->toContain('跳过 1 个')->and(TcpProbe::targets())->toHaveCount(9)
        ->and(TcpProbe::targets()[0]['source'])->toBe('manual');
    DB::table('tcp_probe_target')->where('source', 'manual')->update(['carrier' => 'mobile']);
    $old = TcpProbe::targets();
    expect(taierSource(taierResponses())->sync(true, $this->now + 60)['ret'])->toBe(0)->and(TcpProbe::targets())->toBe($old);
});

it('removes unselected automatic cities only on success and preserves manual entries', function () {
    taierSource(taierResponses())->sync(true, $this->now);
    $old = TcpProbe::targets();
    TaierProbeSource::saveSettings(true, ['北京'], 12);
    expect(TcpProbe::targets())->toBe($old);
    expect(taierSource(taierResponses(array_slice(taierRows(), 0, 3)))->sync(true, $this->now + 60)['ret'])->toBe(1)
        ->and(TcpProbe::targets())->toHaveCount(4)
        ->and(TcpProbe::targets()[0]['source'])->toBe('manual');
    TaierProbeSource::saveSettings(false, ['北京'], 12);
    expect(TcpProbe::targets())->toHaveCount(4);
});

it('discards results after settings are changed during an in-flight sync', function () {
    $old = TcpProbe::targets(); $responses = taierResponses();
    $responses[0] = function () {
        TaierProbeSource::saveSettings(false, ['北京'], 6);
        return new Response(200, [], '1.1.1.1|[]');
    };
    expect(taierSource($responses)->sync(true, $this->now)['ret'])->toBe(0)->and(TcpProbe::targets())->toBe($old);
});

it('guards managed targets while enabled and detaches edits after disabling', function () {
    taierSource(taierResponses())->sync(true, $this->now);
    $id = TcpProbe::targets()[1]['id'];
    $controller = (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor();
    $factory = new HttpFactory();
    $responses = new DecoratedResponseFactory($factory, $factory);
    $request = (new DecoratedServerRequestFactory($factory))->createServerRequest('POST', '/admin/node/probe/targets/' . $id)
        ->withParsedBody(['carrier' => 'telecom', 'label' => 'Custom', 'ip' => '8.8.8.8', 'port' => 443]);
    expect($controller->saveTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(409)
        ->and($controller->deleteTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(409);
    TaierProbeSource::saveSettings(false, ['北京'], 6);
    expect($controller->saveTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(200)
        ->and(DB::table('tcp_probe_target')->where('id', $id)->first()->source)->toBe('manual');
});

it('validates settings and safely migrates existing targets', function () {
    $old = TcpProbe::targets();
    expect($this->migration->up())->toBe(2026091703)->and(TcpProbe::targets())->toBe($old);
    foreach ([[[], 6], [['未知'], 6], [[[]], 6], [['北京'], 1]] as [$cities, $hours]) {
        expect(fn () => TaierProbeSource::saveSettings(true, $cities, $hours))->toThrow(InvalidArgumentException::class);
    }
    expect($this->migration->down())->toBe(2026091702)->and(TaierProbeSource::installed())->toBeFalse()
        ->and(TcpProbe::targets()[0]['ip'])->toBe('1.1.1.1');
});

it('records the outcome of each completed attempt so the dot always matches the message', function () {
    $settings = fn () => TaierProbeSource::settings();
    expect($settings()['last_status'])->toBe('none')->and(TcpProbeController::taierStatus($settings()))->toBe('gray');
    expect(taierSource(taierResponses())->sync(true, $this->now)['ret'])->toBe(1)
        ->and($settings()['last_status'])->toBe('ok')->and(TcpProbeController::taierStatus($settings()))->toBe('green');
    // Settings saved while a sync is in flight: the result is discarded, and the verdict beside "同步成功" must survive.
    $responses = taierResponses();
    $responses[0] = function () {
        TaierProbeSource::saveSettings(false, ['北京'], 6);
        return new Response(200, [], '1.1.1.1|[]');
    };
    expect(taierSource($responses)->sync(true, $this->now + 60)['ret'])->toBe(0)
        ->and($settings()['last_attempt'])->toBe($this->now + 60)
        ->and($settings()['last_message'])->toContain('同步成功')
        ->and($settings()['last_status'])->toBe('ok')->and(TcpProbeController::taierStatus($settings()))->toBe('green');
    // A refused claim writes nothing; a completed failure writes its message and verdict together.
    expect(taierSource([])->sync(true, $this->now + 120)['ret'])->toBe(0)->and($settings()['last_status'])->toBe('ok');
    TaierProbeSource::saveSettings(true, ['北京'], 6);
    $responses = taierResponses(array_slice(taierRows(), 0, 3));
    $responses[2] = new Response(503);
    expect(taierSource($responses)->sync(true, $this->now + 180)['ret'])->toBe(0)
        ->and($settings()['last_message'])->toContain('已保留原配置')
        ->and($settings()['last_status'])->toBe('failed')->and(TcpProbeController::taierStatus($settings()))->toBe('red');
});

it('adds the status column once and derives it from the timestamps of existing rows', function () {
    foreach ([[900, 900, 'ok'], [950, 900, 'failed'], [900, 0, 'failed'], [0, 0, 'none']] as [$attempt, $success, $status]) {
        expect($this->statusMigration->down())->toBe(2026091703)->and(TaierProbeSource::installed())->toBeFalse();
        DB::table('tcp_probe_source')->update(['last_attempt' => $attempt, 'last_success' => $success]);
        expect($this->statusMigration->up())->toBe(2026091704)->and(TaierProbeSource::settings()['last_status'])->toBe($status);
    }
    // Re-running the migration must not overwrite a verdict written by sync().
    DB::table('tcp_probe_source')->update(['last_status' => 'ok']);
    $this->statusMigration->up();
    expect(TaierProbeSource::settings()['last_status'])->toBe('ok');
});
