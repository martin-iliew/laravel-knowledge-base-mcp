<?php

use App\Mcp\Servers\KnowledgeBaseServer;
use Laravel\Mcp\Facades\Mcp;

$route = Mcp::web('/mcp/knowledge', KnowledgeBaseServer::class);

if ((bool) config('knowledge.mcp.require_auth')) {
    $route->middleware('auth:sanctum');
}
