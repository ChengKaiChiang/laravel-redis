# ext-redis 6.2.0 SCAN 方法問題研究報告

## 概述

本文件詳細記錄了 ext-redis 6.2.0 版本中一個重大相容性問題的調查與解決過程。在該版本中，`scan()` 方法持續回傳 `false`，導致 PHP 應用程式中的正常 SCAN 功能失效。

## 問題描述

### 問題陳述

使用 ext-redis 6.2.0 時，所有 `scan()` 方法的變體都會回傳 `false`，而非預期包含游標與結果的陣列：

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->set('test:1', '1');
$redis->set('test:2', '2');

$cursor = 0;
$result = $redis->scan($cursor, 'test:*'); // 回傳 false 而非預期的 [cursor, keys]
```

### 受影響的方法

- `Redis::scan($cursor)`
- `Redis::scan($cursor, $pattern)`  
- `Redis::scan($cursor, $pattern, $count)`

### 可用的替代方案

`rawCommand('SCAN', ...)` 方法仍能正常運作：

```php
$result = $redis->rawCommand('SCAN', '0', 'MATCH', 'test:*');
// 回傳: ['0', ['test:1', 'test:2']]
```

## 調查結果

### 環境詳細資訊

- **PHP 版本**: 8.4.11
- **ext-redis 版本**: 6.2.0  
- **Redis 伺服器版本**: 7.2.10
- **Laravel 版本**: 10-12（透過 Laravel 的 Redis 連線受到影響）

### 根本原因分析

#### 1. 伺服器層級驗證

Redis 伺服器的 SCAN 命令運作正常：
```bash
docker exec redis-container redis-cli SCAN 0 MATCH test:*
# 回傳: 1) "0" 2) 1) "test:1" 2) "test:2"
```

#### 2. 擴展層級測試

直接測試 ext-redis 發現問題僅限於 PHP 擴展的 `scan()` 方法實作：

```php
// 全部回傳 false
$redis->scan($cursor);           // false
$redis->scan($cursor, 'test:*'); // false  
$redis->scan($cursor, 'test:*', 10); // false

// 這個方法可以正常運作
$redis->rawCommand('SCAN', '0', 'MATCH', 'test:*'); // [cursor, results]
```

#### 3. Laravel 整合影響

Laravel 的 `PhpRedisConnection::scan()` 方法依賴於有問題的 ext-redis `scan()` 方法：

```php
// 來自 Illuminate\Redis\Connections\PhpRedisConnection
public function scan($cursor, $options = [])
{
    $result = $this->client->scan($cursor,
        $options['match'] ?? '*',
        $options['count'] ?? 10
    );
    // 在 ext-redis 6.2.0 中 $result 總是 false
    if ($result === false) {
        $result = [];
    }
    return $cursor === 0 && empty($result) ? false : [$cursor, $result];
}
```
