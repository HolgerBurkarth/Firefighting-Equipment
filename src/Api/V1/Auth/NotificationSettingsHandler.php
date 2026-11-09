<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\MailPreferenceRepository;
use FirefightingEquipment\Notification\MailTopic;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * Benachrichtigungen des eigenen Kontos (Admins unter ihrer Stadt, Super-Admins unter service; die
 * Zugriffsstufe der Route schließt die Rolle user aus):
 *
 * - GET api/v1/auth/notifications → {"notifications": {"device_log": bool}, "topics": {"selected": null|[…],
 *   "available": [{id, label, hint}]}}
 * - POST api/v1/auth/notifications {device_log: bool, topics?: null|[…]} → dieselbe Antwort mit dem Stand danach
 *   (wiederholbar). "topics" (Mail-Auswahl, Phase 2, P2.10): fehlt = unverändert, null = alle Themen, eine nicht leere
 *   Liste bekannter Themen (MailTopic) = nur diese; "keine" heißt device_log false. "device_log" = Mail „Log“ bei jedem Vorgang der Geräte-Historie (Admins: nur
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
        $preferences = new MailPreferenceRepository($context->db->get());
        if ($this->update) {
            if (array_diff(array_keys($context->body), ['device_log', 'topics']) !== []) {
                throw HttpException::invalidRequest('Erlaubt sind nur die Felder "device_log" und "topics".');
            }
            $enabled = $context->boolField('device_log');
            $topics = $context->hasField('topics') ? self::topics($context->body['topics']) : false;

            $users = new UserRepository($context->db->get());
            try {
                $users->setDeviceLogNotification($user->id, $enabled);
                if ($topics !== false) {
                    $preferences->save($user->id, $topics);
                }
            } catch (UserNotFoundException) {
                throw HttpException::notFound();
            } catch (UserRuleViolationException $e) {
                throw HttpException::ruleViolation($e->getMessage());
            }
            $user = $users->findById($user->id) ?? throw HttpException::notFound();
        }

        return new JsonResponse([...self::json($user), 'topics' => [
            'selected' => array_map(static fn (MailTopic $t): string => $t->value, $preferences->topicsFor($user->id) ?? []) ?: null,
            'available' => MailTopic::catalog(),
        ]]);
    }

    /**
     * @return list<MailTopic>|null null = alle Themen
     *
     * @throws HttpException 400 invalid_request
     */
    private static function topics(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw HttpException::invalidRequest('Das Feld "topics" muss null oder eine nicht leere Liste von Themen sein.');
        }
        $topics = [];
        foreach ($value as $entry) {
            $topic = is_string($entry) ? MailTopic::tryFrom($entry) : null;
            if ($topic === null) {
                throw HttpException::invalidRequest('Das Feld "topics" enthält ein unbekanntes Thema.');
            }
            $topics[] = $topic;
        }

        return array_values(array_unique($topics, SORT_REGULAR));
    }

    /**
     * @return array{notifications: array{device_log: bool}}
     */
    public static function json(User $user): array
    {
        return ['notifications' => ['device_log' => $user->notifyDeviceLog]];
    }
}
