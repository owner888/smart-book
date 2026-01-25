<?php
/**
 * 数据库连接管理器
 * 
 * 使用示例：
 * DB::init($pdo);
 * $users = DB::table('users')->where('age', '>', 18)->get();
 */

namespace SmartBook\Database;

use PDO;

class DB
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    
    /**
     * 初始化数据库连接
     */
    public static function init(PDO $pdo, array $config = []): void
    {
        self::$pdo = $pdo;
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // 保存配置用于重连
        self::$config = $config;
    }
    
    /**
     * 获取 PDO 实例
     */
    public static function connection(): PDO
    {
        if (!self::$pdo) {
            throw new \Exception('Database not initialized. Call DB::init($pdo) first.');
        }
        
        return self::$pdo;
    }
    
    /**
     * 检查是否是连接丢失错误
     */
    public static function isConnectionError(\PDOException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $errorCode = $errorInfo[1] ?? 0;
        
        // MySQL 连接丢失的错误码
        // 2006 - MySQL server has gone away
        // 2013 - Lost connection to MySQL server during query
        // 2055 - Lost connection to MySQL server at 'reading initial communication packet'
        return in_array($errorCode, [2006, 2013, 2055]);
    }
    
    /**
     * 重新连接数据库
     */
    public static function reconnect(): void
    {
        if (empty(self::$config)) {
            throw new \Exception('Cannot reconnect: No database config provided to init()');
        }
        
        try {
            $config = self::$config;
            
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );
            
            self::$pdo = new PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // 禁用持久连接（在 Workerman 中不需要）
                    PDO::ATTR_PERSISTENT => false,
                ]
            );
            
            error_log('[DB] MySQL reconnected successfully');
        } catch (\PDOException $e) {
            error_log('[DB] MySQL reconnect failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 创建查询构建器
     */
    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder(self::connection(), $table);
    }
    
    /**
     * 执行原始 SQL（带自动重连）
     */
    public static function query(string $sql, array $bindings = []): array
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            if (self::isConnectionError($e)) {
                self::reconnect();
                $stmt = self::connection()->prepare($sql);
                $stmt->execute($bindings);
                return $stmt->fetchAll();
            }
            throw $e;
        }
    }
    
    /**
     * 执行插入/更新/删除（带自动重连）
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            if (self::isConnectionError($e)) {
                self::reconnect();
                $stmt = self::connection()->prepare($sql);
                $stmt->execute($bindings);
                return $stmt->rowCount();
            }
            throw $e;
        }
    }
    
    /**
     * 开始事务
     */
    public static function beginTransaction(): bool
    {
        return self::connection()->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public static function commit(): bool
    {
        return self::connection()->commit();
    }
    
    /**
     * 回滚事务
     */
    public static function rollBack(): bool
    {
        return self::connection()->rollBack();
    }
    
    /**
     * 获取最后插入的 ID
     */
    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }
}
