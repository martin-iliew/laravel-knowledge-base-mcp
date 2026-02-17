<?php

use App\Mcp\Servers\KnowledgeBaseServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/knowledge', KnowledgeBaseServer::class)
    ->middleware('auth:sanctum');
