<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Api\V1\Appearance\AccentColorHandler;
use FirefightingEquipment\Api\V1\Appearance\ImageHandler;
use FirefightingEquipment\Api\V1\Appearance\ManifestHandler;
use FirefightingEquipment\Api\V1\Appearance\TenantLogoHandler;
use FirefightingEquipment\Api\V1\Auth\EmailResendHandler;
use FirefightingEquipment\Api\V1\Auth\EmailVerifyHandler;
use FirefightingEquipment\Api\V1\Auth\InvitationAcceptHandler;
use FirefightingEquipment\Api\V1\Auth\LoginHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutOthersHandler;
use FirefightingEquipment\Api\V1\Auth\MeHandler;
use FirefightingEquipment\Api\V1\Auth\NotificationSettingsHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordChangeHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordResetConfirmHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordResetRequestHandler;
use FirefightingEquipment\Api\V1\Auth\RegisterHandler;
use FirefightingEquipment\Api\V1\Auth\SessionDeleteHandler;
use FirefightingEquipment\Api\V1\Auth\SessionListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceAction;
use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceEventListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceExportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceHistoryExportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceHistoryListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceImportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceStateHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceSummaryHandler;
use FirefightingEquipment\Api\V1\DeviceTypes\DeviceTypeAction;
use FirefightingEquipment\Api\V1\DeviceTypes\DeviceTypeActionHandler;
use FirefightingEquipment\Api\V1\DeviceTypes\DeviceTypeListHandler;
use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\Inspections\InspectionDueHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeCreateHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeListHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeRevokeHandler;
use FirefightingEquipment\Api\V1\Locations\LocationAction;
use FirefightingEquipment\Api\V1\Locations\LocationActionHandler;
use FirefightingEquipment\Api\V1\Locations\LocationListHandler;
use FirefightingEquipment\Api\V1\Notices\NoticeAction;
use FirefightingEquipment\Api\V1\Notices\NoticeActionHandler;
use FirefightingEquipment\Api\V1\Notices\NoticeListHandler;
use FirefightingEquipment\Api\V1\Offline\ClientConfigHandler;
use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminListHandler;
use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminLockHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Api\V1\Tenants\AdminInvitationHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantAction;
use FirefightingEquipment\Api\V1\Tenants\TenantActionHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantCreateHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantListHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantSlugAvailabilityHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantUserListHandler;
use FirefightingEquipment\Api\V1\Users\UserAction;
use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserListHandler;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\SystemClock;

/**
 * Alle Routen der Anwendung. Neue Endpunkte werden hier eingetragen, jeweils mit Zugriffsstufe.
 */
final class Routes
{
    /**
     * Routen unter /firefighting/service/ (Super-Admin, keine Stadt).
     *
     * @param MinimumDuration|null $neutralDuration Mindestdauer der Antworten, die nicht verraten
     *                                              dürfen, ob ein Konto existiert
     *                                              (password-reset/request, register,
     *                                              email/resend; null =
     *                                              Standard der Handler, andere nur für Tests)
     */
    public static function service(?MinimumDuration $neutralDuration = null): Router
    {
        return self::auth(Router::forService(), $neutralDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler())
            ->add('GET', 'api/v1/app-icon/{size}', Access::Public, new ImageHandler(defaultIcon: true))
            ->add('GET', 'api/v1/tenants', Access::SuperAdmin, new TenantListHandler())
            ->add('POST', 'api/v1/tenants', Access::SuperAdmin, new TenantCreateHandler())
            ->add('GET', 'api/v1/tenants/slug-available', Access::SuperAdmin, new TenantSlugAvailabilityHandler())
            ->add('GET', 'api/v1/tenants/{id}', Access::SuperAdmin, new TenantActionHandler(TenantAction::Show))
            ->add('POST', 'api/v1/tenants/{id}/rename', Access::SuperAdmin, new TenantActionHandler(TenantAction::Rename))
            ->add('POST', 'api/v1/tenants/{id}/lock', Access::SuperAdmin, new TenantActionHandler(TenantAction::Lock))
            ->add('POST', 'api/v1/tenants/{id}/unlock', Access::SuperAdmin, new TenantActionHandler(TenantAction::Unlock))
            ->add('POST', 'api/v1/tenants/{id}/admins', Access::SuperAdmin, new AdminInvitationHandler(resend: false))
            ->add('GET', 'api/v1/tenants/{id}/users', Access::SuperAdmin, new TenantUserListHandler())
            ->add('POST', 'api/v1/tenants/{id}/users/{user}/resend-invitation', Access::SuperAdmin, new AdminInvitationHandler(resend: true))
            ->add('GET', 'api/v1/superadmins', Access::SuperAdmin, new SuperAdminListHandler())
            ->add('POST', 'api/v1/superadmins/{id}/lock', Access::SuperAdmin, new SuperAdminLockHandler(lock: true))
            ->add('POST', 'api/v1/superadmins/{id}/unlock', Access::SuperAdmin, new SuperAdminLockHandler(lock: false))
            ->add('GET', 'api/v1/auth/notifications', Access::SuperAdmin, new NotificationSettingsHandler(update: false))
            ->add('POST', 'api/v1/auth/notifications', Access::SuperAdmin, new NotificationSettingsHandler(update: true));
    }

    /**
     * Routen unter /firefighting/<kürzel>/ (nur aktive Städte).
     *
     * @param MinimumDuration|null $neutralDuration wie bei service()
     * @param Clock|null           $clock           Uhr für „heute“ der Prüffristen und Exporte
     *                                              (null = Systemzeit, andere nur für Tests)
     * @param OfflineSettings|null $offline         Zeitlimits der Offline-Buchungen (null =
     *                                              Standardwerte; Anwendung: aus der Konfiguration)
     */
    public static function tenant(?MinimumDuration $neutralDuration = null, ?Clock $clock = null, ?OfflineSettings $offline = null): Router
    {
        $clock ??= new SystemClock();
        $offline ??= new OfflineSettings();

        return self::auth(Router::forTenant(), $neutralDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler())
            ->add('GET', 'api/v1/tenant', Access::Public, new TenantHandler())
            ->add('POST', 'api/v1/tenant/appearance', Access::Admin, new AccentColorHandler())
            ->add('POST', 'api/v1/tenant/logo', Access::Admin, new TenantLogoHandler(delete: false), TenantLogoHandler::MAX_BODY_BYTES)
            ->add('DELETE', 'api/v1/tenant/logo', Access::Admin, new TenantLogoHandler(delete: true))
            ->add('GET', 'api/v1/tenant/logo/{size}', Access::Public, new ImageHandler(defaultIcon: false))
            ->add('GET', 'api/v1/app-icon/{size}', Access::Public, new ImageHandler(defaultIcon: true))
            ->add('GET', 'manifest.webmanifest', Access::Public, new ManifestHandler())
            ->add('POST', 'api/v1/auth/register', Access::Public, self::withDuration(RegisterHandler::class, $neutralDuration))
            ->add('POST', 'api/v1/auth/email/verify', Access::Public, new EmailVerifyHandler())
            ->add('POST', 'api/v1/auth/email/resend', Access::Public, self::withDuration(EmailResendHandler::class, $neutralDuration))
            ->add('POST', 'api/v1/auth/invitation/accept', Access::Public, new InvitationAcceptHandler())
            ->add('GET', 'api/v1/auth/notifications', Access::Admin, new NotificationSettingsHandler(update: false))
            ->add('POST', 'api/v1/auth/notifications', Access::Admin, new NotificationSettingsHandler(update: true))
            ->add('GET', 'api/v1/invitation-codes', Access::Admin, new InvitationCodeListHandler())
            ->add('POST', 'api/v1/invitation-codes', Access::Admin, new InvitationCodeCreateHandler())
            ->add('POST', 'api/v1/invitation-codes/{id}/revoke', Access::Admin, new InvitationCodeRevokeHandler())
            ->add('GET', 'api/v1/users', Access::Admin, new UserListHandler())
            ->add('GET', 'api/v1/users/{id}', Access::Admin, new UserActionHandler(UserAction::Show))
            ->add('DELETE', 'api/v1/users/{id}', Access::Admin, new UserActionHandler(UserAction::Delete))
            ->add('POST', 'api/v1/users/{id}/approve', Access::Admin, new UserActionHandler(UserAction::Approve))
            ->add('POST', 'api/v1/users/{id}/reject', Access::Admin, new UserActionHandler(UserAction::Reject))
            ->add('POST', 'api/v1/users/{id}/lock', Access::Admin, new UserActionHandler(UserAction::Lock))
            ->add('POST', 'api/v1/users/{id}/unlock', Access::Admin, new UserActionHandler(UserAction::Unlock))
            ->add('POST', 'api/v1/users/{id}/role', Access::Admin, new UserActionHandler(UserAction::ChangeRole))
            ->add('POST', 'api/v1/users/{id}/display-name', Access::Admin, new UserActionHandler(UserAction::ChangeDisplayName))
            ->add('GET', 'api/v1/device-types', Access::Authenticated, new DeviceTypeListHandler())
            ->add('POST', 'api/v1/device-types', Access::Admin, new DeviceTypeActionHandler(DeviceTypeAction::Create))
            ->add('POST', 'api/v1/device-types/{id}/rename', Access::Admin, new DeviceTypeActionHandler(DeviceTypeAction::Rename))
            ->add('POST', 'api/v1/device-types/{id}/deactivate', Access::Admin, new DeviceTypeActionHandler(DeviceTypeAction::Deactivate))
            ->add('POST', 'api/v1/device-types/{id}/activate', Access::Admin, new DeviceTypeActionHandler(DeviceTypeAction::Activate))
            ->add('GET', 'api/v1/locations', Access::Authenticated, new LocationListHandler())
            ->add('POST', 'api/v1/locations', Access::Admin, new LocationActionHandler(LocationAction::Create))
            ->add('GET', 'api/v1/locations/{id}', Access::Authenticated, new LocationActionHandler(LocationAction::Show))
            ->add('POST', 'api/v1/locations/{id}/rename', Access::Admin, new LocationActionHandler(LocationAction::Rename))
            ->add('POST', 'api/v1/locations/{id}/lock', Access::Admin, new LocationActionHandler(LocationAction::Lock))
            ->add('POST', 'api/v1/locations/{id}/unlock', Access::Admin, new LocationActionHandler(LocationAction::Unlock))
            ->add('GET', 'api/v1/devices', Access::Authenticated, new DeviceListHandler())
            ->add('GET', 'api/v1/devices/export', Access::Authenticated, new DeviceExportHandler($clock))
            ->add('GET', 'api/v1/device-events/export', Access::Authenticated, new DeviceHistoryExportHandler($clock))
            ->add('GET', 'api/v1/device-events', Access::Admin, new DeviceHistoryListHandler())
            ->add('POST', 'api/v1/devices', Access::Admin, new DeviceActionHandler(DeviceAction::Create))
            ->add('POST', 'api/v1/devices/import/preview', Access::Admin, new DeviceImportHandler(apply: false, clock: $clock), DeviceImportHandler::MAX_BODY_BYTES)
            ->add('POST', 'api/v1/devices/import', Access::Admin, new DeviceImportHandler(apply: true, clock: $clock), DeviceImportHandler::MAX_BODY_BYTES)
            ->add('GET', 'api/v1/devices/{id}', Access::Authenticated, new DeviceActionHandler(DeviceAction::Show))
            ->add('POST', 'api/v1/devices/{id}/fields', Access::Admin, new DeviceActionHandler(DeviceAction::UpdateFields))
            ->add('POST', 'api/v1/devices/{id}/inspection-due', Access::Admin, new DeviceActionHandler(DeviceAction::SetInspectionDue))
            ->add('POST', 'api/v1/devices/{id}/state', Access::Authenticated, new DeviceStateHandler($clock, $offline))
            ->add('POST', 'api/v1/devices/{id}/retire', Access::Admin, new DeviceActionHandler(DeviceAction::Retire))
            ->add('POST', 'api/v1/devices/{id}/reinstate', Access::Admin, new DeviceActionHandler(DeviceAction::Reinstate))
            ->add('GET', 'api/v1/devices/{id}/events', Access::Authenticated, new DeviceEventListHandler())
            ->add('GET', 'api/v1/device-summary', Access::Authenticated, new DeviceSummaryHandler($clock))
            ->add('GET', 'api/v1/inspection-due', Access::Authenticated, new InspectionDueHandler($clock))
            ->add('GET', 'api/v1/client-config', Access::Authenticated, new ClientConfigHandler($clock, $offline))
            ->add('GET', 'api/v1/notices', Access::Admin, new NoticeListHandler())
            ->add('GET', 'api/v1/notices/{id}', Access::Admin, new NoticeActionHandler(NoticeAction::Show))
            ->add('POST', 'api/v1/notices/{id}/resolve', Access::Admin, new NoticeActionHandler(NoticeAction::Resolve))
            ->add('POST', 'api/v1/notices/{id}/reopen', Access::Admin, new NoticeActionHandler(NoticeAction::Reopen));
    }

    /** Anmeldung, Sitzungen und Passwort; unter service und unter jeder Stadt gleich. */
    private static function auth(Router $router, ?MinimumDuration $neutralDuration): Router
    {
        $resetRequest = self::withDuration(PasswordResetRequestHandler::class, $neutralDuration);

        return $router
            ->add('POST', 'api/v1/auth/login', Access::Public, new LoginHandler())
            ->add('POST', 'api/v1/auth/logout', Access::Public, new LogoutHandler())
            ->add('GET', 'api/v1/auth/me', Access::Authenticated, new MeHandler())
            ->add('GET', 'api/v1/auth/sessions', Access::Authenticated, new SessionListHandler())
            ->add('DELETE', 'api/v1/auth/sessions/{id}', Access::Authenticated, new SessionDeleteHandler())
            ->add('POST', 'api/v1/auth/sessions/logout-others', Access::Authenticated, new LogoutOthersHandler())
            ->add('POST', 'api/v1/auth/password', Access::Authenticated, new PasswordChangeHandler())
            ->add('POST', 'api/v1/auth/password-reset/request', Access::Public, $resetRequest)
            ->add('POST', 'api/v1/auth/password-reset/confirm', Access::Public, new PasswordResetConfirmHandler());
    }

    /**
     * Handler mit Mindestdauer: ohne Angabe sein Standard, sonst die übergebene (nur Tests).
     *
     * @template T of RequestHandler
     *
     * @param class-string<T> $handler Konstruktor mit einem optionalen MinimumDuration-Parameter
     *
     * @return T
     */
    private static function withDuration(string $handler, ?MinimumDuration $duration): RequestHandler
    {
        return $duration === null ? new $handler() : new $handler($duration);
    }
}
