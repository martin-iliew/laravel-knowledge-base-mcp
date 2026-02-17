<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateKnowledgeEntryTool;
use App\Mcp\Tools\SearchKnowledgeBaseTool;
use Laravel\Mcp\Server;

/**
 * MCP server entrypoint for the knowledge base.
 * Registers tools used by clients (search + create).
 */
class KnowledgeBaseServer extends Server
{
    /**
     * Tools exposed by this MCP server.
     *
     * @var list<class-string>
     */
    protected array $tools = [
        SearchKnowledgeBaseTool::class,
        CreateKnowledgeEntryTool::class,
    ];
}
