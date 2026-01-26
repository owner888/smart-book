<?php
/**
 * MCP 服务器管理处理器
 */

namespace SmartBook\Http\Handlers;

use Workerman\Protocols\Http\Request;

class MCPHandler
{
    /**
     * 获取 MCP 服务器列表
     */
    public static function getServers(): array
    {
        $configPath = dirname(__DIR__, 3) . '/config/mcp.json';
        
        if (!file_exists($configPath)) {
            return ['servers' => []];
        }
        
        $config = json_decode(file_get_contents($configPath), true) ?? [];
        $servers = [];
        
        foreach ($config['mcpServers'] ?? [] as $name => $serverConfig) {
            $servers[] = [
                'name' => $name,
                'description' => $serverConfig['description'] ?? '',
                'type' => 'stdio',
                'command' => $serverConfig['command'] ?? '',
                'args' => $serverConfig['args'] ?? [],
                'env' => $serverConfig['env'] ?? [],
                'enabled' => !($serverConfig['disabled'] ?? false),
                'tools' => array_keys($serverConfig['tools'] ?? []),
            ];
        }
        
        return ['servers' => $servers];
    }
    
    /**
     * 保存 MCP 服务器配置
     */
    public static function saveServers(Request $request): array
    {
        $body = json_decode($request->rawBody(), true) ?? [];
        $servers = $body['servers'] ?? [];
        
        $configPath = dirname(__DIR__, 3) . '/config/mcp.json';
        
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true) ?? [];
        }
        
        $mcpServers = [];
        foreach ($servers as $server) {
            $name = $server['name'] ?? 'unnamed';
            $mcpServers[$name] = [
                'command' => $server['command'] ?? '',
                'args' => $server['args'] ?? [],
                'disabled' => !($server['enabled'] ?? true),
            ];
            
            if (!empty($server['description'])) {
                $mcpServers[$name]['description'] = $server['description'];
            }
            if (!empty($server['env'])) {
                $mcpServers[$name]['env'] = $server['env'];
            }
        }
        
        $config['mcpServers'] = $mcpServers;
        
        $result = file_put_contents(
            $configPath, 
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        
        if ($result === false) {
            return ['success' => false, 'error' => 'Failed to save config'];
        }
        
        return ['success' => true, 'message' => 'MCP servers saved'];
    }
}
