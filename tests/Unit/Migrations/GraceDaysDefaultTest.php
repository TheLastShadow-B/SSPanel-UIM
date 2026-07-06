<?php

declare(strict_types=1);

use App\Services\DB;
use Tests\TestDatabase;

uses()->group('migration');

beforeEach(function () {
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('flips the still-default stripe_grace_days from 7 to 3', function () {
    $pdo = DB::getPdo();
    $pdo->exec("INSERT INTO config (item, value, `default`) VALUES ('stripe_grace_days', '7', '7')");

    $migration = require BASE_PATH . '/db/migrations/2026062701-set-grace-days-default.php';
    expect($migration->up())->toBe(2026062701);

    $row = $pdo->query("SELECT value, `default` FROM config WHERE item = 'stripe_grace_days'")->fetch();
    expect($row['value'])->toBe('3');
    expect($row['default'])->toBe('3');
});

it('leaves an admin-customised stripe_grace_days untouched', function () {
    $pdo = DB::getPdo();
    $pdo->exec("INSERT INTO config (item, value, `default`) VALUES ('stripe_grace_days', '14', '7')");

    (require BASE_PATH . '/db/migrations/2026062701-set-grace-days-default.php')->up();

    $row = $pdo->query("SELECT value FROM config WHERE item = 'stripe_grace_days'")->fetch();
    expect($row['value'])->toBe('14');
});

it('reports the previous schema version on rollback', function () {
    $migration = require BASE_PATH . '/db/migrations/2026062701-set-grace-days-default.php';
    expect($migration->down())->toBe(2026062600);
});
