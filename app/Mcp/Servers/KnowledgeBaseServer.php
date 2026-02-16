<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateKnowledgeEntryTool;
use App\Mcp\Tools\SearchKnowledgeBaseTool;
use Laravel\Mcp\Server;

class KnowledgeBaseServer extends Server
{
    protected array $tools = [
        SearchKnowledgeBaseTool::class,
        CreateKnowledgeEntryTool::class,
    ];
}
