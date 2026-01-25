# 数据库查询构建器使用指南

## 🎉 简介

基于 PDO 的轻量级查询构建器，提供类似 Laravel 的优雅 API。

## 🔧 初始化

### 方式 1：简单初始化（不支持自动重连）

```php
use SmartBook\Database\DB;

$pdo = new PDO(
    'mysql:host=localhost;dbname=smartbook;charset=utf8mb4',
    'username',
    'password'
);

DB::init($pdo);
```

### 方式 2：完整初始化（推荐，支持自动重连）⭐

```php
use SmartBook\Database\DB;

// 数据库配置
$config = [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'smartbook',
    'username' => 'root',
    'password' => 'password',
    'charset' => 'utf8mb4',
];

// 创建初始连接
$pdo = new PDO(
    "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password']
);

// 初始化并传入配置（用于自动重连）
DB::init($pdo, $config);
```

**为什么需要传入配置？**

在 Workerman 长连接场景下，MySQL 会在空闲 8 小时后断开连接（`wait_timeout`）。传入配置后，DB 类会：
1. 自动检测连接状态
2. 连接断开时自动重连
3. 对用户透明，无需手动处理

## 📚 基本用法

### 查询数据

```php
use SmartBook\Database\DB;

// 获取所有记录
$users = DB::table('users')->get();

// 获取第一条记录
$user = DB::table('users')->first();

// 根据 ID 查找
$user = DB::table('users')->find(1);

// 获取单个值
$email = DB::table('users')->where('id', 1)->value('email');
```

### WHERE 条件

```php
// 简单条件
$users = DB::table('users')
    ->where('age', 18)
    ->get();

// 操作符
$users = DB::table('users')
    ->where('age', '>', 18)
    ->where('status', 'active')
    ->get();

// OR 条件
$users = DB::table('users')
    ->where('age', '>', 18)
    ->orWhere('role', 'admin')
    ->get();

// WHERE IN
$users = DB::table('users')
    ->whereIn('id', [1, 2, 3])
    ->get();

// WHERE NOT IN
$users = DB::table('users')
    ->whereNotIn('status', ['banned', 'deleted'])
    ->get();

// WHERE NULL
$users = DB::table('users')
    ->whereNull('deleted_at')
    ->get();

// WHERE NOT NULL
$users = DB::table('users')
    ->whereNotNull('email_verified_at')
    ->get();
```

### 选择字段

```php
// 选择特定字段
$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->get();

// 默认选择所有字段 (*)
$users = DB::table('users')->get();
```

### 排序

```php
// 升序
$users = DB::table('users')
    ->orderBy('created_at', 'ASC')
    ->get();

// 降序
$users = DB::table('users')
    ->orderBy('created_at', 'DESC')
    ->get();

// 多列排序
$users = DB::table('users')
    ->orderBy('age', 'DESC')
    ->orderBy('name', 'ASC')
    ->get();
```

### 限制和偏移

```php
// 限制数量
$users = DB::table('users')
    ->limit(10)
    ->get();

// 分页
$users = DB::table('users')
    ->limit(10)
    ->offset(20)
    ->get();
```

### 计数和存在性

```php
// 计数
$count = DB::table('users')->count();

// 带条件的计数
$count = DB::table('users')
    ->where('status', 'active')
    ->count();

// 是否存在
$exists = DB::table('users')
    ->where('email', 'user@example.com')
    ->exists();
```

## 🔨 插入数据

```php
// 插入
DB::table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => date('Y-m-d H:i:s')
]);

// 插入并获取 ID
$userId = DB::table('users')->insertGetId([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

## ✏️ 更新数据

```php
// 更新
$affected = DB::table('users')
    ->where('id', 1)
    ->update([
        'name' => 'Jane Doe',
        'updated_at' => date('Y-m-d H:i:s')
    ]);

// 自增
DB::table('posts')
    ->where('id', 1)
    ->increment('views');

// 自增指定数量
DB::table('posts')
    ->where('id', 1)
    ->increment('views', 10);

// 自减
DB::table('users')
    ->where('id', 1)
    ->decrement('credits', 5);
```

## 🗑️ 删除数据

```php
// 删除
$affected = DB::table('users')
    ->where('status', 'inactive')
    ->delete();

// 删除单条记录
DB::table('users')
    ->where('id', 1)
    ->delete();
```

## 🎨 实战示例

### 示例 1：用户 CRUD

```php
use SmartBook\Http\Exceptions\NotFoundException;

Router::group('/api/users', function() {
    
    // 列表（分页）
    Router::get('', function($ctx) {
        $page = (int) $ctx->query('page', 1);
        $perPage = 10;
        
        $users = DB::table('users')
            ->select('id', 'name', 'email', 'created_at')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        $total = DB::table('users')->count();
        
        return $ctx->success([
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage
        ]);
    });
    
    // 详情
    Router::get('/{id:int}', function($ctx) {
        $user = DB::table('users')->find($ctx->param('id'));
        
        if (!$user) {
            throw new NotFoundException('User not found');
        }
        
        return $ctx->success(['user' => $user]);
    });
    
    // 创建
    Router::post('', function($ctx) {
        $data = $ctx->post();
        
        // 检查邮箱是否存在
        $exists = DB::table('users')
            ->where('email', $data['email'])
            ->exists();
        
        if ($exists) {
            throw new ValidationException('Email already exists');
        }
        
        $userId = DB::table('users')->insertGetId([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        $user = DB::table('users')->find($userId);
        
        return $ctx->success(['user' => $user], 'User created');
    });
    
    // 更新
    Router::put('/{id:int}', function($ctx) {
        $id = $ctx->param('id');
        
        $user = DB::table('users')->find($id);
        if (!$user) {
            throw new NotFoundException('User not found');
        }
        
        DB::table('users')
            ->where('id', $id)
            ->update([
                'name' => $ctx->post('name'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        
        $user = DB::table('users')->find($id);
        
        return $ctx->success(['user' => $user], 'User updated');
    });
    
    // 删除
    Router::delete('/{id:int}', function($ctx) {
        $id = $ctx->param('id');
        
        $user = DB::table('users')->find($id);
        if (!$user) {
            throw new NotFoundException('User not found');
        }
        
        DB::table('users')->where('id', $id)->delete();
        
        return $ctx->success([], 'User deleted');
    });
});
```

### 示例 2：文章点赞

```php
Router::post('/api/posts/{id:int}/like', function($ctx) {
    $postId = $ctx->param('id');
    
    // 检查文章是否存在
    $post = DB::table('posts')->find($postId);
    if (!$post) {
        throw new NotFoundException('Post not found');
    }
    
    // 增加点赞数
    DB::table('posts')
        ->where('id', $postId)
        ->increment('likes');
    
    // 获取更新后的数据
    $post = DB::table('posts')->find($postId);
    
    return $ctx->success(['post' => $post]);
});
```

### 示例 3：搜索功能

```php
Router::get('/api/search', function($ctx) {
    $keyword = $ctx->query('q');
    
    $results = DB::table('posts')
        ->select('id', 'title', 'content', 'created_at')
        ->where('title', 'LIKE', "%{$keyword}%")
        ->orWhere('content', 'LIKE', "%{$keyword}%")
        ->where('status', 'published')
        ->orderBy('created_at', 'DESC')
        ->limit(20)
        ->get();
    
    return $ctx->success(['results' => $results]);
});
```

## 🔄 事务

```php
use SmartBook\Database\DB;

try {
    DB::beginTransaction();
    
    // 扣除余额
    DB::table('users')
        ->where('id', 1)
        ->decrement('balance', 100);
    
    // 创建订单
    $orderId = DB::table('orders')->insertGetId([
        'user_id' => 1,
        'amount' => 100,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    DB::commit();
} catch (Exception $e) {
    DB::rollBack();
    throw $e;
}
```

## 🐛 调试

```php
// 查看生成的 SQL
$query = DB::table('users')
    ->where('age', '>', 18)
    ->orderBy('created_at', 'DESC')
    ->limit(10);

echo $query->toSql();
// SELECT * FROM users WHERE age > ? ORDER BY created_at DESC LIMIT 10

print_r($query->getBindings());
// [18]
```

## 🚀 原始 SQL

```php
// 查询
$users = DB::query('SELECT * FROM users WHERE age > ?', [18]);

// 执行（INSERT/UPDATE/DELETE）
$affected = DB::execute('UPDATE users SET status = ? WHERE age < ?', ['inactive', 18]);
```

## 🔌 连接管理（Workerman 长连接）

### MySQL 超时问题

在 Workerman 中，进程是常驻内存的，MySQL 连接会保持很长时间。但 MySQL 默认配置：
- `wait_timeout` = 28800秒（8小时）
- `interactive_timeout` = 28800秒（8小时）

超过这个时间，MySQL 会断开连接，返回错误：
- `MySQL server has gone away` (错误码 2006)
- `Lost connection to MySQL server` (错误码 2013)

### 解决方案

#### 方案 1：自动重连（推荐）⭐

使用完整初始化方式，DB 类会自动处理：

```php
DB::init($pdo, $config);  // 传入配置
```

**工作原理：**
1. 每次查询前自动 `ping` 检测连接
2. 连接断开时自动重连
3. 对业务代码透明，无需手动处理

#### 方案 2：修改 MySQL 配置

修改 MySQL 配置文件（不推荐，治标不治本）：

```ini
# /etc/mysql/my.cnf
[mysqld]
wait_timeout = 86400        # 24小时
interactive_timeout = 86400 # 24小时
```

#### 方案 3：定时 ping（备选）

如果不想使用自动重连，可以定时保持连接：

```php
use Workerman\Timer;

// 每5分钟 ping 一次
Timer::add(300, function() {
    if (DB::ping()) {
        echo "MySQL connection is alive\n";
    } else {
        echo "MySQL connection is dead, reconnecting...\n";
        DB::reconnect();
    }
});
```

### 最佳实践

1. ✅ **使用自动重连** - 传入配置到 `DB::init()`
2. ✅ **禁用持久连接** - 已在 `reconnect()` 中设置
3. ✅ **监控日志** - 重连时会记录到 error_log
4. ⚠️ **注意事务** - 连接断开时事务会回滚

## ⚡ 性能提示

1. **选择必要的字段** - 使用 `select()` 而不是 `*`
2. **添加索引** - 为常用的 WHERE 字段添加索引
3. **使用 limit** - 避免一次性获取大量数据
4. **避免 N+1 问题** - 考虑 JOIN 或批量查询
5. **连接配置优化** - 传入配置启用自动重连

## 📋 特性清单

- ✅ SELECT 查询
- ✅ WHERE 条件（=, >, <, >=, <=, LIKE）
- ✅ WHERE IN / NOT IN
- ✅ WHERE NULL / NOT NULL
- ✅ OR WHERE
- ✅ ORDER BY
- ✅ LIMIT / OFFSET
- ✅ INSERT
- ✅ UPDATE
- ✅ DELETE
- ✅ INCREMENT / DECREMENT
- ✅ COUNT / EXISTS
- ✅ 事务支持
- ✅ SQL 防注入（预处理语句）

## 🎯 总结

这个查询构建器：
- ✅ **简单** - API 清晰易懂
- ✅ **安全** - 自动防 SQL 注入
- ✅ **轻量** - 只有2个文件，无依赖
- ✅ **优雅** - 链式调用，代码简洁
- ✅ **实用** - 覆盖90%的日常需求

完美适合你的 AI 书籍助手项目！🚀
