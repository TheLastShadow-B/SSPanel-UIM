<?php

declare(strict_types=1);

use App\Controllers\Admin\NodeController;
use App\Models\Node;
use App\Services\DB;
use App\Utils\NodeRegion;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Schema\Blueprint;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;

// This suite only uses an isolated in-memory database, never configured databases.
beforeEach(function () {
    $this->previousResolver = Node::getConnectionResolver();
    $this->database = new DB();
    $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->database->setAsGlobal();
    $this->database->bootEloquent();
    $schema = $this->database->schema();
    $schema->create('node', function (Blueprint $table) {
        $table->increments('id');
        foreach (['name', 'server', 'dynamic_rate_config', 'custom_config', 'password'] as $field) {
            $table->string($field)->default('');
        }
        foreach (['node_group', 'is_dynamic_rate', 'dynamic_rate_type', 'node_speedlimit', 'type', 'sort', 'node_class', 'node_bandwidth_limit', 'bandwidthlimit_resetday', 'node_bandwidth'] as $field) {
            $table->integer($field)->default(0);
        }
        $table->float('traffic_rate')->default(1);
    });
    $schema->create('config', function (Blueprint $table) {
        $table->string('item');
        $table->string('value');
        $table->string('type');
    });
    $this->migration = require BASE_PATH . '/db/migrations/2026091700-add_node_country.php';
});

afterEach(function () {
    $this->database->getConnection()->disconnect();
    if ($this->previousResolver !== null) {
        Node::setConnectionResolver($this->previousResolver);
    } else {
        Node::unsetConnectionResolver();
    }
});

function countryNodeRequest(string $action, array $params, ?int $id = null): array
{
    $controller = (new ReflectionClass(NodeController::class))->newInstanceWithoutConstructor();
    $http = new HttpFactory();
    $request = (new DecoratedServerRequestFactory($http))->createServerRequest('POST', '/admin/node')
        ->withParsedBody($params);
    $response = (new DecoratedResponseFactory($http, $http))->createResponse();
    $result = $controller->$action($request, $response, ['id' => $id]);
    return json_decode((string) $result->getBody(), true);
}

function countryNodeFields(): array
{
    return [
        'name' => 'HK legacy name', 'server' => 'node.example.test', 'node_group' => 0,
        'node_speedlimit' => 0, 'type' => 'true', 'sort' => 12, 'node_class' => 1,
        'node_bandwidth_limit' => 100, 'bandwidthlimit_resetday' => 1,
    ];
}

it('adds the country field without changing existing nodes and can be repeated', function () {
    Node::create(['name' => 'Legacy HK']);
    expect($this->migration->up())->toBe(2026091700);
    expect(Node::first()->country)->toBe('');
    Node::first()->update(['country' => 'JP']);
    $this->migration->up();
    expect(Node::first()->country)->toBe('JP')
        ->and(Node::first()->name)->toBe('Legacy HK');
    expect($this->migration->down())->toBe(2026062701)
        ->and($this->database->schema()->hasColumn('node', 'country'))->toBeFalse();
});

it('creates and reloads every supported country through the admin save handler', function (string $country) {
    $this->migration->up();
    $result = countryNodeRequest('add', countryNodeFields() + ['country' => $country]);
    expect($result['ret'])->toBe(1);
    $node = Node::find($result['node_id']);
    expect($node->country)->toBe($country)
        ->and(NodeRegion::group([$node->toArray()])[0]['code'])->toBe($country);
})->with(['US', 'HK', 'JP', 'SG', 'TW']);

it('updates, preserves omitted values, copies, and clears the saved country', function () {
    $this->migration->up();
    $result = countryNodeRequest('add', countryNodeFields() + ['country' => 'US']);
    $id = $result['node_id'];
    expect(countryNodeRequest('update', countryNodeFields() + ['country' => 'JP'], $id)['ret'])->toBe(1)
        ->and(Node::find($id)->country)->toBe('JP');
    countryNodeRequest('update', countryNodeFields(), $id);
    expect(Node::find($id)->country)->toBe('JP');
    expect(countryNodeRequest('copy', [], $id)['ret'])->toBe(1)
        ->and(Node::orderByDesc('id')->first()->country)->toBe('JP');
    countryNodeRequest('update', countryNodeFields() + ['country' => ''], $id);
    $node = Node::find($id);
    expect($node->country)->toBe('')
        ->and(NodeRegion::group([$node->toArray()])[0]['code'])->toBe('HK');
});

it('rejects unsupported values before changing node data', function (mixed $country) {
    $this->migration->up();
    expect(countryNodeRequest('add', countryNodeFields() + ['country' => $country])['ret'])->toBe(0)
        ->and(Node::count())->toBe(0);
    $created = countryNodeRequest('add', countryNodeFields() + ['country' => 'SG']);
    expect(countryNodeRequest('update', ['name' => 'changed', 'country' => $country], $created['node_id'])['ret'])->toBe(0);
    expect(Node::first()->country)->toBe('SG')
        ->and(Node::first()->name)->toBe('HK legacy name');
})->with([['DE'], ['<script>'], [['US']]]);
