<?php
/**
 * 简洁的路由系统（支持动态参数）
 * 
 * 使用示例：
 * Router::get('/api/health', fn() => ['status' => 'ok']);
 * Router::get('/api/books/{id}', fn($conn, $req, $params) => ['book_id' => $params['id']]);
 * Router::post('/api/users/{userId}/posts/{postId}', fn($conn, $req, $params) => [
 *     'userId' => $params['userId'],
 *     'postId' => $params['postId']
 * ]);
 */

namespace SmartBook\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class Router
{
    private static array $routes = [];
    private static array $groups = [];
    
    /**
     * 注册 GET 路由
     */
    public static function get(string $path, callable|array $handler): void
    {
        self::addRoute('GET', $path, $handler);
    }
    
    /**
     * 注册 POST 路由
     */
    public static function post(string $path, callable|array $handler): void
    {
        self::addRoute('POST', $path, $handler);
    }
    
    /**
     * 注册 PUT 路由
     */
    public static function put(string $path, callable|array $handler): void
    {
        self::addRoute('PUT', $path, $handler);
    }
    
    /**
     * 注册 DELETE 路由
     */
    public static function delete(string $path, callable|array $handler): void
    {
        self::addRoute('DELETE', $path, $handler);
    }
    
    /**
     * 注册 PATCH 路由
     */
    public static function patch(string $path, callable|array $handler): void
    {
        self::addRoute('PATCH', $path, $handler);
    }
    
    /**
     * 注册任意方法路由
     */
    public static function any(string $path, callable|array $handler): void
    {
        self::addRoute('*', $path, $handler);
    }
    
    /**
     * 路由组（添加前缀）
     */
    public static function group(string $prefix, callable $callback): void
    {
        self::$groups[] = $prefix;
        $callback();
        array_pop(self::$groups);
    }
    
    /**
     * 添加路由
     */
    private static function addRoute(string $method, string $path, callable|array $handler): void
    {
        $fullPath = implode('', self::$groups) . $path;
        
        // 将路径模式转换为正则表达式
        // /api/books/{id} => ^/api/books/([^/]+)$
        // /api/users/{userId}/posts/{postId} => ^/api/users/([^/]+)/posts/([^/]+)$
        $pattern = $fullPath;
        $params = [];
        
        // 提取所有参数名
        if (preg_match_all('/{([^}]+)}/', $pattern, $matches)) {
            $params = $matches[1];
        }
        
        // 转换为正则表达式
        $pattern = preg_replace('/{[^}]+}/', '([^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';
        
        self::$routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'pattern' => $pattern,
            'params' => $params,
            'handler' => $handler,
        ];
    }
    
    /**
     * 匹配并执行路由
     * 
     * @return mixed 处理结果，null 表示需要流式处理（已发送响应）
     */
    public static function dispatch(TcpConnection $connection, Request $request): mixed
    {
        $method = $request->method();
        $path = $request->path();
        
        foreach (self::$routes as $route) {
            // 检查 HTTP 方法
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }
            
            // 检查路径匹配
            if (preg_match($route['pattern'], $path, $matches)) {
                // 提取路径参数
                $params = [];
                array_shift($matches); // 移除完整匹配
                
                foreach ($route['params'] as $index => $paramName) {
                    $params[$paramName] = $matches[$index] ?? null;
                }
                
                $handler = $route['handler'];
                
                // 支持数组格式 [Class::class, 'method']
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    if (is_string($class)) {
                        $class = new $class();
                    }
                    return $class->$method($connection, $request, $params);
                }
                
                // 调用闭包或函数
                return $handler($connection, $request, $params);
            }
        }
        
        // 未找到路由
        return ['error' => 'Not Found', 'path' => $path];
    }
    
    /**
     * 清空所有路由（测试用）
     */
    public static function clear(): void
    {
        self::$routes = [];
        self::$groups = [];
    }
    
    /**
     * 获取所有已注册的路由（调试用）
     */
    public static function getRoutes(): array
    {
        return array_map(fn($route) => [
            'method' => $route['method'],
            'path' => $route['path'],
            'params' => $route['params'],
        ], self::$routes);
    }
}
