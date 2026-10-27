<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Notices;

/**
 * Aktion auf einen Hinweis (NoticeActionHandler), alle nur für Admins.
 */
enum NoticeAction
{
    /** GET api/v1/notices/{id} */
    case Show;
    /** POST api/v1/notices/{id}/resolve {note?} */
    case Resolve;
    /** POST api/v1/notices/{id}/reopen {} */
    case Reopen;
}
