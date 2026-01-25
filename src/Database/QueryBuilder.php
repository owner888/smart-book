<?php
/**
 * 查询构建器
 * 
 * 使用示例：
 * DB::table('users')
 *   ->select('id', 'name', 'email')
 *   ->where('age', '>', 18)
 *   ->where('status', 'active')
 *   ->orderBy('created_at', 'desc')
 *   ->limit(10)
 *   ->get();
 */

namespace SmartBook\Database;

use PDO;

class QueryBuilder
{
    private PDO $pdo;
    private string $table;
    private array $select = ['*'];
    private array $where = [];
    private array $bindings = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    
    public function __construct(PDO $pdo, string $table)
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }
    
    /**
     * 选择字段
     */
    public function select(string ...$columns): self
    {
        $this->select = $columns;
        return $this;
    }
    
    /**
     * WHERE 条件
     */
    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        // 如果只有两个参数，默认操作符为 =
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }
    
    /**
     * OR WHERE 条件
     */
    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }
    
    /**
     * WHERE IN
     */
    public function whereIn(string $column, array $values): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IN',
            'value' => $values
        ];
        
        return $this;
    }
    
    /**
     * WHERE NOT IN
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'NOT IN',
            'value' => $values
        ];
        
        return $this;
    }
    
    /**
     * WHERE NULL
     */
    public function whereNull(string $column): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null
        ];
        
        return $this;
    }
    
    /**
     * WHERE NOT NULL
     */
    public function whereNotNull(string $column): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        
        return $this;
    }
    
    /**
     * 排序
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [$column, strtoupper($direction)];
        return $this;
    }
    
    /**
     * 限制数量
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * 偏移量
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * 执行查询（自动处理连接丢失）
     */
    private function executeQuery(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\PDOException $e) {
            // 检查是否是连接丢失错误
            if (DB::isConnectionError($e)) {
                // 重连
                DB::reconnect();
                // 更新 PDO 实例
                $this->pdo = DB::connection();
                // 重试查询
                return $callback();
            }
            // 其他错误直接抛出
            throw $e;
        }
    }
    
    /**
     * 获取所有结果
     */
    public function get(): array
    {
        return $this->executeQuery(function() {
            $sql = $this->buildSelectSql();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            return $stmt->fetchAll();
        });
    }
    
    /**
     * 获取第一条结果
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    /**
     * 根据 ID 查找
     */
    public function find(int|string $id): ?array
    {
        return $this->where('id', $id)->first();
    }
    
    /**
     * 获取单个值
     */
    public function value(string $column): mixed
    {
        $result = $this->select($column)->first();
        return $result[$column] ?? null;
    }
    
    /**
     * 计数
     */
    public function count(): int
    {
        return $this->executeQuery(function() {
            $sql = "SELECT COUNT(*) as count FROM {$this->table}";
            $sql .= $this->buildWhereSql();
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            $result = $stmt->fetch();
            
            return (int) $result['count'];
        });
    }
    
    /**
     * 是否存在
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }
    
    /**
     * 插入数据
     */
    public function insert(array $data): bool
    {
        return $this->executeQuery(function() use ($data) {
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            
            $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(array_values($data));
        });
    }
    
    /**
     * 插入并返回 ID
     */
    public function insertGetId(array $data): string
    {
        $this->insert($data);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * 更新数据
     */
    public function update(array $data): int
    {
        return $this->executeQuery(function() use ($data) {
            $sets = [];
            $bindings = [];
            
            foreach ($data as $column => $value) {
                $sets[] = "{$column} = ?";
                $bindings[] = $value;
            }
            
            $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
            $sql .= $this->buildWhereSql();
            
            $bindings = array_merge($bindings, $this->bindings);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            
            return $stmt->rowCount();
        });
    }
    
    /**
     * 删除数据
     */
    public function delete(): int
    {
        return $this->executeQuery(function() {
            $sql = "DELETE FROM {$this->table}";
            $sql .= $this->buildWhereSql();
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            
            return $stmt->rowCount();
        });
    }
    
    /**
     * 自增
     */
    public function increment(string $column, int $amount = 1): int
    {
        return $this->executeQuery(function() use ($column, $amount) {
            $sql = "UPDATE {$this->table} SET {$column} = {$column} + ?";
            $sql .= $this->buildWhereSql();
            
            $bindings = array_merge([$amount], $this->bindings);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            
            return $stmt->rowCount();
        });
    }
    
    /**
     * 自减
     */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->increment($column, -$amount);
    }
    
    /**
     * 构建 SELECT SQL
     */
    private function buildSelectSql(): string
    {
        $sql = "SELECT " . implode(', ', $this->select) . " FROM {$this->table}";
        $sql .= $this->buildWhereSql();
        $sql .= $this->buildOrderBySql();
        $sql .= $this->buildLimitSql();
        
        return $sql;
    }
    
    /**
     * 构建 WHERE SQL
     */
    private function buildWhereSql(): string
    {
        if (empty($this->where)) {
            return '';
        }
        
        $conditions = [];
        
        foreach ($this->where as $i => $condition) {
            $type = $i === 0 ? 'WHERE' : $condition['type'];
            
            if ($condition['operator'] === 'IN' || $condition['operator'] === 'NOT IN') {
                $placeholders = implode(', ', array_fill(0, count($condition['value']), '?'));
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']} ({$placeholders})";
                $this->bindings = array_merge($this->bindings, $condition['value']);
            } elseif ($condition['operator'] === 'IS NULL' || $condition['operator'] === 'IS NOT NULL') {
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']}";
            } else {
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']} ?";
                $this->bindings[] = $condition['value'];
            }
        }
        
        return ' ' . implode(' ', $conditions);
    }
    
    /**
     * 构建 ORDER BY SQL
     */
    private function buildOrderBySql(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }
        
        $orders = array_map(fn($order) => "{$order[0]} {$order[1]}", $this->orderBy);
        return ' ORDER BY ' . implode(', ', $orders);
    }
    
    /**
     * 构建 LIMIT SQL
     */
    private function buildLimitSql(): string
    {
        $sql = '';
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
    
    /**
     * 获取完整的 SQL（用于调试）
     */
    public function toSql(): string
    {
        return $this->buildSelectSql();
    }
    
    /**
     * 获取绑定参数（用于调试）
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
