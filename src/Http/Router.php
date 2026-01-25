<?php
/**
 * 简洁的路由系统
 * 
 * 使用示例：
 * Router::get('/api/health', fn() => ['status' => 'ok']);
 * Router::post('/api/books', [BookController::class, 'create']);
 */

namespace SmartBook\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

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
        self::$routes[] = [
            'method' => $method,
            'path' => $fullPath,
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
            if (($route['method'] === $method || $route['method'] === '*') && $route['path'] === $path) {
                $handler = $route['handler'];
                
                // 支持数组格式 [Class::class, 'method']
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    if (is_string($class)) {
                        $class = new $class();
                    }
                    return $class->$method($connection, $request);
                }
                
                // 调用闭包或函数
                return $handler($connection, $request);
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
}
