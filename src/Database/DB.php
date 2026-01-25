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
     * 获取 PDO 实例（自动检测连接状态）
     */
    public static function connection(): PDO
    {
        if (!self::$pdo) {
            throw new \Exception('Database not initialized. Call DB::init($pdo) first.');
        }
        
        // 检测连接是否存活
        if (!self::ping()) {
            self::reconnect();
        }
        
        return self::$pdo;
    }
    
    /**
     * 检测连接是否存活
     */
    public static function ping(): bool
    {
        if (!self::$pdo) {
            return false;
        }
        
        try {
            // 尝试执行一个简单查询
            self::$pdo->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            // 连接已断开
            // 常见错误码：
            // 2006 - MySQL server has gone away
            // 2013 - Lost connection to MySQL server
            return false;
        }
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
     * 执行原始 SQL
     */
    public static function query(string $sql, array $bindings = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }
    
    /**
     * 执行插入/更新/删除
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
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
