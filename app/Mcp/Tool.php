<?php

namespace App\Mcp;

use Closure;

/**
 * One MCP tool: its public description (what an AI client sees) plus the handler.
 * `scope` is the weakest API-token scope allowed to call it ("read" or "admin").
 */
final class Tool
{
    /**
     * @param  array<string, array<string, mixed>>  $properties  JSON-schema properties of the arguments
     * @param  list<string>  $required
     * @param  Closure(array, McpContext): array  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $required,
        public readonly string $scope,
        public readonly bool $readOnly,
        public readonly bool $destructive,
        public readonly Closure $handler,
    ) {}

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $this->properties === [] ? new \stdClass : $this->properties,
                'required' => $this->required,
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => $this->title,
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->readOnly,
                'openWorldHint' => false,
            ],
        ];
    }

    // ----- small schema helpers used by the tool definitions

    public static function str(string $description, ?array $enum = null): array
    {
        return ['type' => 'string', 'description' => $description] + ($enum ? ['enum' => $enum] : []);
    }

    public static function int(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    public static function num(string $description): array
    {
        return ['type' => 'number', 'description' => $description];
    }

    public static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    public static function strs(string $description): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $description];
    }

    /** The further addresses of a server or service. */
    public static function alternates(string $what): array
    {
        return ['type' => 'array', 'description' => "Further addresses of this {$what} (for example a Wi-Fi address next to the LAN one), each with an optional domain. Replaces the whole list; an empty list clears it. Shown on the dashboard with their own status dot; they never turn the status yellow when they are down.", 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string', 'description' => 'Short name, e.g. Wi-Fi.'], 'address' => ['type' => 'string', 'description' => 'IP address or host name.'], 'domain' => ['type' => 'string', 'description' => 'Optional domain for this address.']], 'required' => ['address']]];
    }

    public static function ints(string $description): array
    {
        return ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => $description];
    }
}
