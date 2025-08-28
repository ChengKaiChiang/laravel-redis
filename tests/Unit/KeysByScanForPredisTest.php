<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Lab104\Laravel\Redis\KeysByScan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KeysByScanForPredisTest extends TestCase
{
    private function createRedisManager(): RedisManager
    {
        return new RedisManager(
            new Container(),
            'predis',
            [
                'options' => [
                    'cluster' => 'redis',
                ],

                'default' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'database' => 0,
                    'read_timeout' => 1,
                ],
            ],
        );
    }

    private function provisionRedisData(Connection $connection): void
    {
        $connection->flushdb();

        $connection->set('foo:1', '1');
        $connection->set('foo:2', '2');
        $connection->set('foo:3', '3');
        $connection->set('foo:4', '4');
        $connection->set('bar:5', '5');
        $connection->set('bar:6', '6');
        $connection->set('bar:foo:7', '7');
        $connection->set('bar:foo:8', '8');
    }

    private ?Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createRedisManager()->connection();

        $this->provisionRedisData($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection = null;

        parent::tearDown();
    }

    #[Test]
    public function testBasicScanCase(): void
    {
        $this->assertSame([
            'foo:1',
            'foo:2',
            'foo:3',
            'foo:4',
        ], (new KeysByScan($this->connection))('foo:*', 1));
    }

    #[Test]
    public function testBasicScanCase2(): void
    {
        $this->assertSame([
            'bar:5',
            'bar:6',
            'bar:foo:7',
            'bar:foo:8',
        ], (new KeysByScan($this->connection))('bar:*', 1));
    }

    #[Test]
    public function testBasicScanCase3(): void
    {
        $this->assertSame([
            'bar:foo:7',
            'bar:foo:8',
        ], (new KeysByScan($this->connection))('bar:foo:*', 1));
    }
}
