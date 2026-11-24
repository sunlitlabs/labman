<?php

namespace App\Mcp;

use App\Mcp\Tools\CatalogTools;
use App\Models\ApiToken;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A small Model Context Protocol server (JSON-RPC 2.0 over the Streamable HTTP
 * transport, stateless, JSON responses only). Implements initialize, ping,
 * tools/list and tools/call, which is everything an MCP client needs for tools.
 */
final class McpServer
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const INSTRUCTIONS = 'LabMan is a home-lab dashboard. Use list_services/list_servers/get_status to see what exists and what is healthy. '
        .'Admin tools change the configuration; ask before deleting anything, replacing via import, changing settings that affect who can see data, or pushing to Nginx Proxy Manager. '
        .'For Nginx Proxy Manager always run the preview tool first, show the user the diff, and only apply what they approve.';

    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct()
    {
        foreach (self::registry() as $tool) {
            $this->tools[$tool->name] = $tool;
        }
    }

    /** @return list<Tool> */
    public static function registry(): array
    {
        return array_merge(
            CatalogTools::all(),
            \App\Mcp\Tools\NpmTools::all(),
            \App\Mcp\Tools\InventoryTools::all(),
            \App\Mcp\Tools\ZoneTools::all(),
            \App\Mcp\Tools\SemaphoreTools::all(),
        );
    }

    /**
     * Handle one JSON-RPC message. Returns null for notifications (no response).
     *
     * @param  mixed  $message  decoded JSON
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message, McpContext $ctx): ?array
    {
        if (! is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! isset($message['method']) || ! is_string($message['method'])) {
            return self::error($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($isNotification) {
            return null; // notifications/initialized, notifications/cancelled, ...
        }

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass,
                'tools/list' => $this->listTools($ctx),
                'tools/call' => $this->callTool($params, $ctx),
                default => throw new McpProtocolError(-32601, "Method not found: {$message['method']}"),
            };
        } catch (McpProtocolError $e) {
            return self::error($id, $e->rpcCode, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return self::error($id, -32603, 'Internal error');
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($asked, self::SUPPORTED_VERSIONS, true) ? $asked : self::SUPPORTED_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'labman', 'title' => 'LabMan', 'version' => '0.2.0'],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /** @return array{tools: list<array<string, mixed>>} */
    private function listTools(McpContext $ctx): array
    {
        return ['tools' => array_values(array_map(
            fn (Tool $t) => $t->describe(),
            array_filter($this->tools, fn (Tool $t) => $this->allowed($t, $ctx)),
        ))];
    }

    /** @return array<string, mixed> */
    private function callTool(array $params, McpContext $ctx): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? ($this->tools[$name] ?? null) : null;

        if ($tool === null) {
            throw new McpProtocolError(-32602, 'Unknown tool: '.(is_string($name) ? $name : ''));
        }

        // Read-scope tokens cannot even see admin tools; calling one is refused.
        if (! $this->allowed($tool, $ctx)) {
            return self::toolError("This API token has the 'read' scope and cannot call '{$tool->name}'. Create an admin-scope token in LabMan under Settings.");
        }

        $args = $params['arguments'] ?? [];
        if (! is_array($args) || ($args !== [] && array_is_list($args))) {
            throw new McpProtocolError(-32602, 'arguments must be an object');
        }

        $unknown = array_diff(array_keys($args), array_keys($tool->properties));
        if ($unknown) {
            return self::toolError('Unknown argument(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', array_keys($tool->properties)));
        }
        $missing = array_diff($tool->required, array_keys($args));
        if ($missing) {
            return self::toolError('Missing required argument(s): '.implode(', ', $missing));
        }

        try {
            $out = ($tool->handler)($args, $ctx);
        } catch (ValidationException $e) {
            return self::toolError("Validation failed:\n- ".implode("\n- ", $e->validator->errors()->all()));
        } catch (ToolException $e) {
            return self::toolError($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return self::toolError('The tool failed unexpectedly. Check the LabMan logs.');
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'structuredContent' => $out,
            'isError' => false,
        ];
    }

    private function allowed(Tool $tool, McpContext $ctx): bool
    {
        return $tool->scope === ApiToken::SCOPE_READ || $ctx->canWrite();
    }

    /** @return array<string, mixed> */
    private static function toolError(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
