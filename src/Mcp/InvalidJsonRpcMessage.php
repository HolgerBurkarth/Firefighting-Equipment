<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp;

/**
 * Keine gültige JSON-RPC-Nachricht (Fehler -32600, ohne id).
 */
final class InvalidJsonRpcMessage extends \RuntimeException
{
}
