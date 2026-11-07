<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Mcp;

use FirefightingEquipment\Http\EmptyResponse;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Http\Response;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Mcp\InvalidJsonRpcMessage;
use FirefightingEquipment\Mcp\McpServer;
use FirefightingEquipment\Mcp\Tool\ToolRegistry;

/**
 * POST mcp (Access::Claude; Verbindung, Konto, Zugang und Rechte hat Application bei diesem Aufruf
 * neu geprüft): eine JSON-RPC-Nachricht je Anfrage. Antwort als JSON (200), bei Benachrichtigungen
 * 202 ohne Body, bei einer ungültigen Nachricht 400 mit JSON-RPC-Fehler ohne id. Ein unbekannter
 * Header MCP-Protocol-Version → 400 (fehlt er, gilt die älteste unterstützte Version).
 */
final readonly class McpHandler implements RequestHandler
{
    /**
     * @param Router $router Routen der Stadt, auf die die Werkzeuge zugreifen (derselbe Router wie
     *                       für die App, Routes::tenant)
     */
    public function __construct(private Router $router, private ToolRegistry $tools = new ToolRegistry([]))
    {
    }

    public function handle(RequestContext $context): Response
    {
        $version = $context->request->header('MCP-Protocol-Version');
        if ($version !== null && !McpServer::supportsVersion($version)) {
            throw HttpException::invalidRequest(sprintf(
                'Nicht unterstützte MCP-Protokollversion (unterstützt: %s).',
                implode(', ', McpServer::PROTOCOL_VERSIONS),
            ));
        }

        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        try {
            $response = new McpServer($tenant, $context->claude(), $this->router, $context, $this->tools)->handle($context->body);
        } catch (InvalidJsonRpcMessage $e) {
            return new JsonResponse(McpServer::error(null, McpServer::INVALID_REQUEST, $e->getMessage()), 400);
        }

        return $response === null ? new EmptyResponse(202) : new JsonResponse($response);
    }
}
