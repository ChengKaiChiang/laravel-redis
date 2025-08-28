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

## 歷史背景

### 相關的 phpredis 問題

根據我們的研究，ext-redis 在各個版本中都有幾個與 SCAN 相關的問題：

#### Issue #2454: 游標轉換問題
- **問題**: Redis scan 游標從無符號整數轉換為有符號整數
- **影響**: 游標值 > ZEND_LONG_MAX 會導致溢位
- **解決方案**: 將大型游標存儲為字串而非整數
- **版本**: 在 6.2.0 中修復
- https://github.com/phpredis/phpredis/issues/2454

#### Laravel Framework 問題
- **#24222**: phpredis 的 HSCAN 迭代問題
- **#24257**: 前綴 Redis 連線與 scan 命令不相容
- **#32336**: *scan 方法相容性修復

### 6.2.0 版本的游標處理改進

變更日誌提及：「正確處理任意大小的 `SCAN` 游標」，相關提交為 [`2612d444`](https://github.com/phpredis/phpredis/commit/2612d444) 和 [`e52f0afa`](https://github.com/phpredis/phpredis/commit/e52f0afa)。此改進專注於：

- 當游標超過最大無符號長整數值時，將其表示為字串
- 與現有程式碼保持向後相容性
- 防止非常大的游標值產生整數溢位

## 假設：回歸錯誤

基於所有證據，最可能的原因是 **在 ext-redis 6.2.0 游標處理改進過程中引入的回歸錯誤**。

### 支持證據

1. **時間點**: 問題與 6.2.0 版本的游標處理變更同時出現
2. **影響範圍**: 僅影響 `scan()` 方法，`rawCommand()` 不受影響
3. **模式**: 與歷史上 phpredis scan 問題的模式一致
4. **行為**: 完全失效（false）而非部分故障

### 可能的技術原因

1. **參數處理錯誤**: 新的游標處理邏輯錯誤處理方法參數
2. **回傳值對應**: 內部結果處理無法正確對應到 PHP 回傳值
3. **記憶體管理**: 游標字串/整數轉換導致記憶體問題
4. **驗證邏輯**: 新的驗證程式碼錯誤拒絕有效參數