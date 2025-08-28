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
