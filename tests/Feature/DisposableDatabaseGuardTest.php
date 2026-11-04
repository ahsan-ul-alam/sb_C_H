<?php

require_once __DIR__.'/../Support/helpers.php';

/*
| The guard that stands between the concurrency tests (which run migrate:fresh and truncate every table) and any database that is not a
| disposable test database. It needs no database itself: it only reads the configuration.
*/

function guardConfig(string $default, string $host, string $port, string $database): void
{
    config(['database.default' => $default, 'database.connections.mysql.host' => $host, 'database.connections.mysql.port' => $port, 'database.connections.mysql.database' => $database]);
}

it('refuses every database that is not positively a disposable local test database', function (string $default, string $host, string $port, string $database, string $reason) {
    guardConfig($default, $host, $port, $database);

    expect(disposableDatabaseProblem())->toContain($reason)
        ->and(fn () => assertDisposableDatabase())->toThrow(RuntimeException::class, 'Refusing to run destructive test commands');
})->with([
    'sqlite' => ['sqlite', '127.0.0.1', '33077', 'sellbees_p1_test', 'not a local MySQL'],
    'the application database on the application server' => ['mysql', '127.0.0.1', '3306', 'deletesb', 'port 3306'],
    'a test-named database on the application server' => ['mysql', '127.0.0.1', '3306', 'sellbees_p1_test', 'port 3306'],
    'the application database name on another port' => ['mysql', '127.0.0.1', '33077', 'deletesb', 'does not end in "_test"'],
    'a remote host' => ['mysql', '10.0.0.5', '33077', 'sellbees_p1_test', 'not the loopback interface'],
    'a production-looking name' => ['mysql', 'localhost', '33077', 'sellbees_production', 'does not end in "_test"'],
]);

it('accepts the approved disposable database only', function () {
    guardConfig('mysql', '127.0.0.1', '33077', 'sellbees_p1_test');

    expect(disposableDatabaseProblem())->toBeNull();
    assertDisposableDatabase();
});

it('refuses outside the testing environment even for a test-named database', function () {
    guardConfig('mysql', '127.0.0.1', '33077', 'sellbees_p1_test');
    app()['env'] = 'local';

    expect(disposableDatabaseProblem())->toContain('APP_ENV is not "testing"');
});
