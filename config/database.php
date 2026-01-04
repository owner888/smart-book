<?php
/**
 * 数据库配置
 */

return [
    // Redis 配置
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => (int)(getenv('REDIS_DB') ?: 0),
        'prefix' => 'smartbook:',
        'timeout' => 5,
    ],
    
    // MySQL 配置（预留）
    'mysql' => [
        'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MYSQL_PORT') ?: 3306),
        'database' => getenv('MYSQL_DATABASE') ?: 'smartbook',
        'username' => getenv('MYSQL_USERNAME') ?: 'root',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    
    // 缓存配置
    'cache' => [
        'driver' => 'redis',  // redis, file, memory
        'ttl' => 3600,        // 默认缓存时间（秒）
        'semantic_ttl' => 7200, // 语义缓存时间
        'max_items' => 100,   // 语义索引最大条目数
    ],
];
