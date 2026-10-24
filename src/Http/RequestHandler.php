<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Bearbeitet eine Route. Darf HttpException werfen; alle anderen Fehler werden zu 500.
 */
interface RequestHandler
{
    /**
     * @throws HttpException
     */
    public function handle(RequestContext $context): JsonResponse;
}
