<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Permissions;

/**
 * Wie lange Sitzungen eines Kontos gelten (Grenzen in SessionLifetime). Unter einer Stadt folgt
 * die Klasse aus den Rechten der Rolle, ohne Einstellung (Plan Phase 1, Abschnitt 5, F11): Wer
 * verwalten oder ändern darf, bekommt kurze Sitzungen (wie bisher admin), wer nur ansieht und
 * bucht, lange (wie bisher user).
 */
enum SessionClass: string
{
    case SuperAdmin = 'superadmin';
    case Short = 'short';
    case Long = 'long';

    /** Aktionen, die lange Sitzungen nicht ausschließen (ansehen und buchen). */
    private const array LONG_ACTIONS = [Action::BookMove, Action::BookStatus, Action::MailLog];

    /**
     * Kurz: Rolle Admin, `users` oder `settings` mindestens Ansehen, ein Bereich auf Ändern oder eine
     * wirksame Aktion außer Umbuchen, Status setzen und Log-Mails. Sonst lang.
     */
    public static function forPermissions(Permissions $permissions): self
    {
        if (
            $permissions->isAll()
            || $permissions->allows(Area::Users, AreaLevel::Read)
            || $permissions->allows(Area::Settings, AreaLevel::Read)
            || in_array(AreaLevel::Write, $permissions->areas(), true)
        ) {
            return self::Short;
        }
        foreach ($permissions->actions() as $action) {
            if (!in_array($action, self::LONG_ACTIONS, true)) {
                return self::Short;
            }
        }

        return self::Long;
    }
}
