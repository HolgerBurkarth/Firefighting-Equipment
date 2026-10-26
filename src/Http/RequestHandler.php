<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Bearbeitet eine Route. Darf HttpException werfen; alle anderen Fehler werden zu 500.
 */
interface RequestHandler
{
    /**
     * @return Response in der Regel JsonResponse; CsvResponse nur für Downloads
     *
     * @throws HttpException
     */
    public function handle(RequestContext $context): Response;
}
