<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Returns;

/**
 * Sammelaktion „Rückgaben bearbeiten“ (ReturnProcessHandler).
 */
enum ReturnProcess
{
    /** POST api/v1/loans/to-collection {devices: [{id, version}], comment?}: benutzte bzw. defekte Ausrüstung in die passende Sammelstelle */
    case ToCollection;
    /** POST api/v1/loans/to-store {devices: [{id, version}], comment?}: ins Standard-Lager, Status einsatzbereit */
    case ToStore;
}
