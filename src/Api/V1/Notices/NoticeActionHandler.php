<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Notices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Device\Notice\NoticeChange;
use FirefightingEquipment\Device\Notice\NoticeNotFoundException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * Hinweis anzeigen, erledigen (optional mit Notiz, höchstens 500 Zeichen) oder wieder öffnen, siehe
 * NoticeAction. Antwort {"notice": …} mit Stand und Verlauf danach. Wiederholbar: Hat der Hinweis den
 * Ziel-Status schon, ändert sich nichts (auch kein Eintrag im Verlauf). Fremd oder unbekannt 404,
 * unbekannte Felder oder ungültige Notiz 400 (vor 404).
 */
final readonly class NoticeActionHandler implements RequestHandler
{
    public function __construct(private NoticeAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $id = $context->param('id');

        try {
            $note = null;
            if ($this->action === NoticeAction::Resolve) {
                DeviceFields::rejectUnknown($context, ['note']);
                $note = DeviceText::noticeNote($context->optionalStringField('note'));
            } elseif ($this->action === NoticeAction::Reopen) {
                DeviceFields::rejectUnknown($context, []);
            }

            $notices = new DeviceNoticeRepository($context->db->get());
            $notice = match ($this->action) {
                NoticeAction::Show => $notices->find($tenant, $id) ?? throw NoticeNotFoundException::forId($id),
                NoticeAction::Resolve => $notices->change($tenant, $id, $context->auth()->user->id, NoticeChange::Resolve, $note),
                NoticeAction::Reopen => $notices->change($tenant, $id, $context->auth()->user->id, NoticeChange::Reopen, null),
            };
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['notice' => NoticeJson::notice($notice)]);
    }
}
