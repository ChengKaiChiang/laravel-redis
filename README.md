# Helper for Laravel Redis

![tests](https://github.com/104lab/laravel-redis/workflows/tests/badge.svg)

## Requirement

* PHP 8.1 ~ 8.4
* Laravel 10 ~ 12
* ext-redis 5.3 ~ 6.2 (Test covered)
* Redis 6 ~ 7 (Test covered)
* Predis ^2.0.3

## Installation

Use Composer for install.

```
composer require 104lab/laravel-redis
```

## Usage

Redis [`KEYS`](https://redis.io/docs/latest/commands/keys/) method is like full-table scan, so maybe use [`SCAN`](https://redis.io/docs/latest/commands/scan/) is good idea.

```php
$connection = Redis::connection();

# Before
$keys = $connection->keys('foo:*');

# After
$keys = (new KeysByScan($connection))('foo:*');

# Use chunk limit
$keys = (new KeysByScan($connection))('foo:*', 100);

# Use usleep, default is 10
$keys = (new KeysByScan($connection))('foo:*', 100, 10);
```

## License

The MIT License (MIT). Please see [License](LICENSE) File for more information.
