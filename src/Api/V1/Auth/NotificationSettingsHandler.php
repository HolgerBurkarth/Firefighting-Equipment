<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * Benachrichtigungen des eigenen Kontos (Admins unter ihrer Stadt, Super-Admins unter service; die
 * Zugriffsstufe der Route schließt die Rolle user aus):
 *
 * - GET api/v1/auth/notifications → {"notifications": {"device_log": bool}}
 * - POST api/v1/auth/notifications {device_log: bool} → dieselbe Antwort mit dem Stand danach
 *   (wiederholbar). "device_log" = Mail „Log“ bei jedem Vorgang der Geräte-Historie (Admins: nur
 *   ihre Stadt, Super-Admins: alle Städte). Standard aus. Auch geschützte Konten dürfen das.
 */
final readonly class NotificationSettingsHandler implements RequestHandler
{
    public function __construct(private bool $update)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $user = $context->auth()->user;
        if ($this->update) {
            if (array_diff(array_keys($context->body), ['device_log']) !== []) {
                throw HttpException::invalidRequest('Erlaubt ist nur das Feld "device_log".');
            }
            $enabled = $context->boolField('device_log');

            $users = new UserRepository($context->db->get());
            try {
                $users->setDeviceLogNotification($user->id, $enabled);
            } catch (UserNotFoundException) {
                throw HttpException::notFound();
            } catch (UserRuleViolationException $e) {
                throw HttpException::ruleViolation($e->getMessage());
            }
            $user = $users->findById($user->id) ?? throw HttpException::notFound();
        }

        return new JsonResponse(self::json($user));
    }

    /**
     * @return array{notifications: array{device_log: bool}}
     */
    public static function json(User $user): array
    {
        return ['notifications' => ['device_log' => $user->notifyDeviceLog]];
    }
}
