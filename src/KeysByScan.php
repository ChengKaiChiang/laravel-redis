<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis;

use Illuminate\Redis\Connections\Connection;
use Predis\Client as PredisClient;
use Redis as PhpRedisClient;

use function array_unique;
use function is_array;
use function sort;
use function usleep;

/**
 * KeysByScan class is used to scan Redis keys by pattern.
 * It uses the SCAN command to iterate through the keys in the Redis database.
 * The class is designed to be used with the Laravel framework and its Redis connection.
 */
class KeysByScan
{
    private const DEFAULT_CURSOR = '0';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(string $pattern, ?int $count = null, int $usleep = 10): array
    {
        $client = $this->connection->client();

        if ($client instanceof PhpRedisClient) {
            $prefix = $client->getOption(PhpRedisClient::OPT_PREFIX);
        } elseif ($client instanceof PredisClient) {
            $prefix = $client->getOptions()->prefix ?? '';
        }
        $cursor = self::DEFAULT_CURSOR;
        $keys = [];

        $options = [
            'match' => $prefix . $pattern,
        ];

        if ($count !== null) {
            $options['count'] = $count;
        }

        do {
            $scanResult = $this->performScan($cursor, $options);

            if ($scanResult === false || !is_array($scanResult)) {
                break;
            }

            [$cursor, $result] = $scanResult;

            if (!is_array($result)) {
                break;
            }

            if (empty($result)) {
                continue;
            }

            $result = array_unique($result);

            $keys = [
                ...$keys,
                ...$result,
            ];

            usleep($usleep);
        } while ((string)$cursor !== self::DEFAULT_CURSOR);

        sort($keys);

        return $keys;
    }

    private function performScan(&$cursor, array $options)
    {
        $client = $this->connection->client();

        if ($client instanceof PhpRedisClient) {
            return $this->performRawScan($cursor, $options);
        }

        $result = $this->connection->scan($cursor, $options);

        return $result;
    }

    private function performRawScan(&$cursor, array $options): array|false
    {
        $client = $this->connection->client();
        $args = [$cursor];

        if (isset($options['match'])) {
            $args[] = 'MATCH';
            $args[] = $options['match'];
        }

        if (isset($options['count'])) {
            $args[] = 'COUNT';
            $args[] = $options['count'];
        }

        $result = $client->rawCommand('SCAN', ...$args);

        if (!is_array($result) || count($result) !== 2) {
            return false;
        }

        $cursor = (string)$result[0];
        return [$cursor, $result[1]];
    }
}
