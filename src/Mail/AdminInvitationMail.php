<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\User\AdminInvitation;

/**
 * Versand der Einladung als Admin (API und bin/tenant.php): Link einladung-annehmen über AppUrls,
 * Text aus MailTemplates, Fehler nur über den Notifier.
 */
final class AdminInvitationMail
{
    public const string PURPOSE = 'admin_invitation';

    /**
     * @return bool ob die Mail übergeben wurde
     */
    public static function send(Notifier $notifier, AppUrls $urls, AdminInvitation $invitation): bool
    {
        return $notifier->send(
            self::PURPOSE,
            $invitation->user->id,
            static fn (): MailMessage => MailTemplates::adminInvitation(
                $invitation->user,
                $invitation->tenant,
                $urls->acceptInvitation($invitation->tenant, $invitation->token),
            ),
        );
    }
}
