<?php

declare(strict_types=1);

use App\Controllers\Admin\TcpProbeController;
use App\Models\Node;
use App\Services\DB;
use App\Services\TcpProbe;
use App\Services\TcpProbeGroup;
use App\Services\UrlProbeSource;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;

beforeEach(function () {
    $this->resolver = Node::getConnectionResolver();
    $this->database = new DB();
    $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->database->setAsGlobal();
    $this->database->bootEloquent();
    foreach (['2026091701-add_node_tcp_probe', '2026091702-dynamic_tcp_probe_targets', '2026091703-add_taier_probe_sync', '2026091704-add_taier_sync_status', '2026092201-add_tcp_probe_groups'] as $name) {
        (require BASE_PATH . '/db/migrations/' . $name . '.php')->up();
    }
    DB::table('tcp_probe_target')->insert(['carrier' => 'telecom', 'label' => '默认组手动', 'ip' => '1.1.1.1', 'port' => 443]);
    $this->group = TcpProbeGroup::save(null, ['name' => '中转', 'url' => 'https://example.test/relay.csv', 'interval_minutes' => 30]);
    DB::table('tcp_probe')->insert(['node_id' => 1, 'enabled' => true, 'threshold_ms' => 250, 'group_id' => $this->group]);
    DB::table('tcp_probe_target')->insert(['carrier' => 'telecom', 'label' => '中转组手动', 'ip' => '5.5.5.5', 'port' => 443, 'group_id' => $this->group]);
    $this->now = 1800000000;
});

afterEach(function () {
    $this->database->getConnection()->disconnect();
    if ($this->resolver) { Node::setConnectionResolver($this->resolver); } else { Node::unsetConnectionResolver(); }
});

if (! function_exists('urlSource')) {
    function urlSource(array $responses): UrlProbeSource
    {
        return new UrlProbeSource(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    }
}

if (! function_exists('urlBody')) {
    function urlBody(): string
    {
        return "1.1.1.1,443,电信,入口A\n2.2.2.2,443,联通,入口B\n5.5.5.5,443,电信,与手动重复\n";
    }
}

if (! function_exists('urlController')) {
    /** @return array{TcpProbeController, DecoratedServerRequestFactory, DecoratedResponseFactory} */
    function urlController(): array
    {
        $factory = new HttpFactory();
        return [
            (new ReflectionClass(TcpProbeController::class))->newInstanceWithoutConstructor(),
            new DecoratedServerRequestFactory($factory), new DecoratedResponseFactory($factory, $factory),
        ];
    }
}

it('parses one target per line and skips what it cannot use', function () {
    $body = "\xEF\xBB\xBF" . implode("\r\n", [
        '# IP,端口,运营商,名字', '', '1.1.1.1,443,电信,广东电信入口', '2.2.2.2, 8443 , CU , 上海联通 ', '3.3.3.3,443,mobile',
        '4.4.4.4，443，移动，全角逗号', '1.1.1.1,443,联通,重复地址', '5.5.5.5,443,其他,未知运营商', '10.0.0.1,443,电信,内网地址',
        '6.6.6.6,70000,电信,坏端口', '7.7.7.7,443,电信,名字,带,逗号', '8.8.8.8,443,电信,' . str_repeat('长', 81), '这一行没有逗号', '9.9.9.9,443',
    ]);
    $parsed = UrlProbeSource::parse($body);
    expect(array_map(static fn ($t) => [$t['ip'], $t['port'], $t['carrier'], $t['label']], $parsed['targets']))->toBe([
        ['1.1.1.1', 443, 'telecom', '广东电信入口'], ['2.2.2.2', 8443, 'unicom', '上海联通'], ['3.3.3.3', 443, 'mobile', '3.3.3.3:443'],
        ['4.4.4.4', 443, 'mobile', '全角逗号'], ['7.7.7.7', 443, 'telecom', '名字,带,逗号'], ['8.8.8.8', 443, 'telecom', str_repeat('长', 80)],
    ])->and($parsed['skipped'])->toBe(6)->and($parsed['truncated'])->toBe(0);
});

it('keeps at most one hundred targets and reports how many it dropped', function () {
    $lines = [];
    for ($i = 1; $i <= 105; $i++) {
        $lines[] = '8.8.' . intdiv($i, 256) . '.' . ($i % 256) . ',443,电信,T' . $i;
    }
    $parsed = UrlProbeSource::parse(implode("\n", $lines));
    expect($parsed['targets'])->toHaveCount(100)->and($parsed['truncated'])->toBe(5)
        ->and($parsed['targets'][99]['label'])->toBe('T100');
});

it('fetches the group url into url targets and reconciles by endpoint on later runs', function () {
    $result = urlSource([new Response(200, [], urlBody())])->syncGroup($this->group, true, $this->now);
    expect($result['ret'])->toBe(1)->and($result['msg'])->toContain('2 个目标')->toContain('跳过 1');
    $targets = TcpProbe::targets($this->group);
    expect(array_map(static fn ($t) => [$t['ip'], $t['source'], $t['label'], $t['group_id']], $targets))->toBe([
        ['5.5.5.5', 'manual', '中转组手动', $this->group], ['1.1.1.1', 'url', '入口A', $this->group], ['2.2.2.2', 'url', '入口B', $this->group],
    ])->and(TcpProbe::targets(1))->toHaveCount(1);
    $group = TcpProbeGroup::find($this->group);
    expect($group)->toMatchArray(['last_status' => 'ok', 'last_success' => $this->now, 'last_attempt' => $this->now, 'next_sync_at' => $this->now + 1800, 'lock_until' => 0, 'lock_token' => null])
        ->and($group['last_message'])->toBe($result['msg']);
    $hash = TcpProbe::config(1)['config_hash'];
    expect(urlSource([new Response(200, [], urlBody())])->syncGroup($this->group, true, $this->now + 60)['ret'])->toBe(1)
        ->and(TcpProbe::targets($this->group))->toBe($targets)->and(TcpProbe::config(1)['config_hash'])->toBe($hash);
    // A changed list keeps the id (and so the history) of every endpoint that is still there.
    $body = "2.2.2.2,443,联通,入口B 改名\n3.3.3.3,443,移动,入口C\n";
    expect(urlSource([new Response(200, [], $body)])->syncGroup($this->group, true, $this->now + 120)['ret'])->toBe(1);
    $after = TcpProbe::targets($this->group);
    expect(array_map(static fn ($t) => [$t['id'], $t['ip'], $t['label']], $after))->toBe([
        [$targets[0]['id'], '5.5.5.5', '中转组手动'], [$targets[2]['id'], '2.2.2.2', '入口B 改名'], [$after[2]['id'], '3.3.3.3', '入口C'],
    ])->and($after[2]['id'])->toBeGreaterThan($targets[2]['id'])->and(TcpProbe::config(1)['config_hash'])->not->toBe($hash);
});

it('keeps the previous targets and schedules a retry when a fetch cannot be used', function (string $failure) {
    urlSource([new Response(200, [], urlBody())])->syncGroup($this->group, true, $this->now);
    $old = TcpProbe::targets($this->group);
    $hash = TcpProbe::config(1)['config_hash'];
    $response = match ($failure) {
        'status' => new Response(503),
        'redirect' => new Response(302, ['Location' => 'http://127.0.0.1/']),
        'empty' => new Response(200, [], ''),
        'garbage' => new Response(200, [], "hello\nworld"),
        'huge' => new Response(200, [], str_repeat("1.1.1.1,443,电信,x\n", 60000)),
        'timeout' => new ConnectException('secret-token-in-message', new Request('GET', 'https://example.test/relay.csv?secret')),
    };
    $result = urlSource([$response])->syncGroup($this->group, true, $this->now + 60);
    expect($result['ret'])->toBe(0)->and($result['msg'])->not->toContain('secret')
        ->and(TcpProbe::targets($this->group))->toBe($old)->and(TcpProbe::config(1)['config_hash'])->toBe($hash);
    expect(TcpProbeGroup::find($this->group))->toMatchArray([
        'last_status' => 'failed', 'last_success' => $this->now, 'last_attempt' => $this->now + 60,
        'next_sync_at' => $this->now + 60 + 900, 'lock_until' => 0, 'lock_token' => null, 'last_message' => $result['msg'],
    ]);
})->with(['status', 'redirect', 'empty', 'garbage', 'huge', 'timeout']);

it('runs only due groups that have a url and honours another worker lease', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], urlBody()), new Response(200, [], urlBody())]));
    $stack->push(Middleware::history($history));
    $source = new UrlProbeSource(new Client(['handler' => $stack]));
    DB::table('tcp_probe_group')->where('id', $this->group)->update(['next_sync_at' => $this->now + 1]);
    expect($source->sync(false, $this->now))->toBe([])->and($history)->toHaveCount(0);
    DB::table('tcp_probe_group')->where('id', $this->group)->update(['lock_until' => $this->now + 1]);
    expect($source->syncGroup($this->group, true, $this->now)['ret'])->toBe(0)->and($history)->toHaveCount(0);
    DB::table('tcp_probe_group')->where('id', $this->group)->update(['lock_until' => 0]);
    $results = $source->sync(false, $this->now + 1);
    expect($results)->toHaveCount(1)->and($results[0]['ret'])->toBe(1)->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://example.test/relay.csv')
        ->and($history[0]['options']['allow_redirects'])->toBeFalse()
        ->and($history[0]['options']['timeout'])->toBe(10);
    // The default group has no URL: never fetched, not even on demand; unknown groups are refused the same way.
    expect($source->syncGroup(1, true, $this->now + 2)['ret'])->toBe(0)
        ->and($source->syncGroup(99, true, $this->now + 2)['ret'])->toBe(0)
        ->and($history)->toHaveCount(1);
});

it('guards url targets while the group keeps its url and detaches edits once it is cleared', function () {
    urlSource([new Response(200, [], urlBody())])->syncGroup($this->group, true, $this->now);
    $id = TcpProbe::targets($this->group)[1]['id'];
    [$controller, $requests, $responses] = urlController();
    $request = $requests->createServerRequest('POST', '/admin/node/probe/targets/' . $id)
        ->withParsedBody(['carrier' => 'telecom', 'label' => 'Custom', 'ip' => '8.8.8.8', 'port' => 443, 'group_id' => (string) $this->group]);
    expect($controller->saveTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(409)
        ->and($controller->deleteTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(409);
    TcpProbeGroup::save($this->group, ['name' => '中转', 'url' => '', 'interval_minutes' => 30]);
    expect($controller->saveTarget($request, $responses->createResponse(), ['id' => $id])->getStatusCode())->toBe(200)
        ->and(DB::table('tcp_probe_target')->where('id', $id)->first()->source)->toBe('manual');
});

it('exposes fetch-now per group and reports refusals without reloading', function () {
    [$controller, $requests, $responses] = urlController();
    $request = $requests->createServerRequest('POST', '/admin/node/probe/groups/1/sync');
    $response = $controller->syncGroup($request, $responses->createResponse(), ['id' => 1]);
    expect($response->getStatusCode())->toBe(409)->and($response->hasHeader('HX-Refresh'))->toBeFalse()
        ->and($controller->syncGroup($request, $responses->createResponse(), ['id' => 99])->getStatusCode())->toBe(404);
});
