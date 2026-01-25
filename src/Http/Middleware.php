<?php
/**
 * 中间件接口
 * 
 * 中间件可以在请求到达路由处理器之前或之后执行
 */

namespace SmartBook\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

interface Middleware
{
    /**
     * 处理请求
     * 
     * @param TcpConnection $connection
     * @param Request $request
     * @param callable $next 调用下一个中间件或路由处理器
     * @return mixed 返回响应数据，或 null 表示已发送响应
     */
    public function handle(TcpConnection $connection, Request $request, callable $next): mixed;
}
