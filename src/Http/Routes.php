<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Api\V1\Appearance\AccentColorHandler;
use FirefightingEquipment\Api\V1\Appearance\ImageHandler;
use FirefightingEquipment\Api\V1\Appearance\ManifestHandler;
use FirefightingEquipment\Api\V1\Appearance\TenantLogoHandler;
use FirefightingEquipment\Api\V1\Auth\ClaudeConnectionsHandler;
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
use FirefightingEquipment\Api\V1\Devices\DeviceBulkAction;
use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceEventListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceExportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceHistoryExportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceHistoryListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceImportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceImportTemplateHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceStateHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceSwapHandler;
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
use FirefightingEquipment\Api\V1\Mcp\McpHandler;
use FirefightingEquipment\Api\V1\Notices\NoticeAction;
use FirefightingEquipment\Api\V1\Notices\NoticeActionHandler;
use FirefightingEquipment\Api\V1\Notices\NoticeListHandler;
use FirefightingEquipment\Api\V1\OAuth\AuthorizeHandler;
use FirefightingEquipment\Api\V1\OAuth\ClientRegistrationHandler;
use FirefightingEquipment\Api\V1\OAuth\OAuthMetadataDocument;
use FirefightingEquipment\Api\V1\OAuth\OAuthMetadataHandler;
use FirefightingEquipment\Api\V1\OAuth\TokenHandler;
use FirefightingEquipment\Api\V1\Offline\ClientConfigHandler;
use FirefightingEquipment\Api\V1\Roles\PermissionCatalogHandler;
use FirefightingEquipment\Api\V1\Roles\RoleAction;
use FirefightingEquipment\Api\V1\Loans\LoanLendHandler;
use FirefightingEquipment\Api\V1\Loans\LoanListHandler;
use FirefightingEquipment\Api\V1\Returns\PublicReturnHandler;
use FirefightingEquipment\Api\V1\Stock\StockHandler;
use FirefightingEquipment\Api\V1\Returns\ReturnPointHandler;
use FirefightingEquipment\Api\V1\Returns\ReturnProcess;
use FirefightingEquipment\Api\V1\Returns\ReturnProcessHandler;
use FirefightingEquipment\Api\V1\Locations\LocationTasksHandler;
use FirefightingEquipment\Api\V1\Maintenance\MaintenanceListHandler;
use FirefightingEquipment\Api\V1\Maintenance\MaintenanceMove;
use FirefightingEquipment\Api\V1\Maintenance\MaintenanceMoveHandler;
use FirefightingEquipment\Api\V1\Roles\RoleHandler;
use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminListHandler;
use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminLockHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Api\V1\Tenants\AdminInvitationHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantAction;
use FirefightingEquipment\Api\V1\Tenants\TenantActionHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantCreateHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantListHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantSlugAvailabilityHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantUserClaudeHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantUserListHandler;
use FirefightingEquipment\Api\V1\Users\AssignableRoleListHandler;
use FirefightingEquipment\Api\V1\Users\UserAction;
use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserBulkRoleHandler;
use FirefightingEquipment\Api\V1\Users\UserListHandler;
use FirefightingEquipment\Mcp\Tool\ToolRegistry;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Requirement;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\SystemClock;

/**
 * Alle Routen der Anwendung. Neue Endpunkte werden hier eingetragen, jeweils mit Zugriffsstufe;
 * unter einer Stadt mit den nötigen Rechten (Requirement, Plan Phase 1, Abschnitt 2; Tabelle und
 * Vergleich mit der bisherigen Stufe in TenantRoutePermissionsTest).
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
            ->add('POST', 'api/v1/tenants/{id}/users/{user}/claude-access', Access::SuperAdmin, new TenantUserClaudeHandler(endConnections: false))
            ->add('POST', 'api/v1/tenants/{id}/users/{user}/claude-connections/end', Access::SuperAdmin, new TenantUserClaudeHandler(endConnections: true))
            ->add('GET', 'api/v1/tenants/{id}/location-tasks', Access::SuperAdmin, new LocationTasksHandler(superAdmin: true))
            ->add('POST', 'api/v1/tenants/{id}/location-tasks', Access::SuperAdmin, new LocationTasksHandler(superAdmin: true))
            ->add('GET', 'api/v1/tenants/{id}/roles', Access::SuperAdmin, new RoleHandler(RoleAction::List))
            ->add('POST', 'api/v1/tenants/{id}/roles', Access::SuperAdmin, new RoleHandler(RoleAction::Create))
            ->add('POST', 'api/v1/tenants/{id}/roles/import', Access::SuperAdmin, new RoleHandler(RoleAction::Import))
            ->add('GET', 'api/v1/tenants/{id}/roles/{role}', Access::SuperAdmin, new RoleHandler(RoleAction::Show))
            ->add('POST', 'api/v1/tenants/{id}/roles/{role}', Access::SuperAdmin, new RoleHandler(RoleAction::Save))
            ->add('DELETE', 'api/v1/tenants/{id}/roles/{role}', Access::SuperAdmin, new RoleHandler(RoleAction::Delete))
            ->add('GET', 'api/v1/tenants/{id}/role-filter-options', Access::SuperAdmin, new RoleHandler(RoleAction::FilterOptions))
            ->add('GET', 'api/v1/permission-catalog', Access::SuperAdmin, new PermissionCatalogHandler())
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

        $read = static fn (Area $area): Requirement => Requirement::area($area, AreaLevel::Read);
        $write = static fn (Area $area): Requirement => Requirement::area($area, AreaLevel::Write);
        $action = Requirement::action(...);
        // Geräte lesen: Handy (Suchen) oder Konsole (Ausrüstungsliste).
        $seeDevices = Requirement::anyOf($read(Area::Search), $read(Area::Devices));
        $deviceFields = Requirement::anyOf($action(Action::DeviceEdit), $action(Action::DeviceCode))
            ->withFieldRule('scan_code', Action::DeviceCode);
        foreach (['identifier', 'device_type_id', 'serial_number', 'manufacturer', 'model', 'procured_on', 'note'] as $field) {
            $deviceFields = $deviceFields->withFieldRule($field, Action::DeviceEdit);
        }
        $reinstate = $action(Action::DeviceRetire)
            ->withFieldRule('identifier', Action::DeviceEdit)
            ->withFieldRule('device_type_id', Action::DeviceEdit)
            ->withFieldRule('scan_code', Action::DeviceCode);
        $book = Requirement::anyOf($action(Action::BookMove), $action(Action::BookStatus))
            ->withFieldRule('location_id', Action::BookMove)
            ->withFieldRule('status', Action::BookStatus);
        $bulkBook = $action(Action::DevicesBulk)
            ->withFieldRule('location_id', Action::BookMove, 'devices')
            ->withFieldRule('status', Action::BookStatus, 'devices');

        $router = self::auth(Router::forTenant(), $neutralDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler())
            ->add('GET', 'api/v1/tenant', Access::Public, new TenantHandler())
            ->add('POST', 'api/v1/tenant/appearance', $write(Area::Settings), new AccentColorHandler())
            ->add('POST', 'api/v1/tenant/logo', $write(Area::Settings), new TenantLogoHandler(delete: false), TenantLogoHandler::MAX_BODY_BYTES)
            ->add('DELETE', 'api/v1/tenant/logo', $write(Area::Settings), new TenantLogoHandler(delete: true))
            ->add('GET', 'api/v1/tenant/logo/{size}', Access::Public, new ImageHandler(defaultIcon: false))
            ->add('GET', 'api/v1/app-icon/{size}', Access::Public, new ImageHandler(defaultIcon: true))
            ->add('GET', 'manifest.webmanifest', Access::Public, new ManifestHandler())
            ->add('POST', 'api/v1/auth/register', Access::Public, self::withDuration(RegisterHandler::class, $neutralDuration))
            ->add('POST', 'api/v1/auth/email/verify', Access::Public, new EmailVerifyHandler())
            ->add('POST', 'api/v1/auth/email/resend', Access::Public, self::withDuration(EmailResendHandler::class, $neutralDuration))
            ->add('POST', 'api/v1/auth/invitation/accept', Access::Public, new InvitationAcceptHandler())
            // Rückgabe ohne Anmeldung (Phase 2, P2.6): der geheime Schlüssel des Aushangs ist der Nachweis.
            ->add('POST', 'api/v1/returns/check', Access::Public, new PublicReturnHandler(check: true))
            ->add('POST', 'api/v1/returns', Access::Public, new PublicReturnHandler(check: false))
            ->add('GET', 'api/v1/auth/notifications', $action(Action::MailLog), new NotificationSettingsHandler(update: false))
            ->add('POST', 'api/v1/auth/notifications', $action(Action::MailLog), new NotificationSettingsHandler(update: true))
            ->add('GET', 'api/v1/invitation-codes', $read(Area::Users), new InvitationCodeListHandler())
            ->add('POST', 'api/v1/invitation-codes', $write(Area::Users), new InvitationCodeCreateHandler())
            ->add('POST', 'api/v1/invitation-codes/{id}/revoke', $write(Area::Users), new InvitationCodeRevokeHandler())
            ->add('GET', 'api/v1/users', $read(Area::Users), new UserListHandler())
            ->add('POST', 'api/v1/users/bulk/role', $write(Area::Users), new UserBulkRoleHandler())
            ->add('GET', 'api/v1/roles', $read(Area::Users), new AssignableRoleListHandler())
            ->add('GET', 'api/v1/users/{id}', $read(Area::Users), new UserActionHandler(UserAction::Show))
            ->add('DELETE', 'api/v1/users/{id}', $write(Area::Users), new UserActionHandler(UserAction::Delete))
            ->add('POST', 'api/v1/users/{id}/approve', $write(Area::Users), new UserActionHandler(UserAction::Approve))
            ->add('POST', 'api/v1/users/{id}/reject', $write(Area::Users), new UserActionHandler(UserAction::Reject))
            ->add('POST', 'api/v1/users/{id}/lock', $write(Area::Users), new UserActionHandler(UserAction::Lock))
            ->add('POST', 'api/v1/users/{id}/unlock', $write(Area::Users), new UserActionHandler(UserAction::Unlock))
            ->add('POST', 'api/v1/users/{id}/role', $write(Area::Users), new UserActionHandler(UserAction::ChangeRole))
            ->add('POST', 'api/v1/users/{id}/display-name', $write(Area::Users), new UserActionHandler(UserAction::ChangeDisplayName))
            // F12: zusätzlich nur die Rolle Admin (geprüft in UserAdministration, 409).
            ->add('POST', 'api/v1/users/{id}/claude-access', $write(Area::Users), new UserActionHandler(UserAction::SetClaudeAccess))
            ->add('POST', 'api/v1/users/{id}/claude-connections/end', $write(Area::Users), new UserActionHandler(UserAction::EndClaudeConnections))
            ->add('GET', 'api/v1/device-types', Requirement::anyOf($read(Area::DeviceTypes), $seeDevices), new DeviceTypeListHandler())
            ->add('POST', 'api/v1/device-types', $write(Area::DeviceTypes), new DeviceTypeActionHandler(DeviceTypeAction::Create))
            ->add('POST', 'api/v1/device-types/{id}/rename', $write(Area::DeviceTypes), new DeviceTypeActionHandler(DeviceTypeAction::Rename))
            ->add('POST', 'api/v1/device-types/{id}/deactivate', $write(Area::DeviceTypes), new DeviceTypeActionHandler(DeviceTypeAction::Deactivate))
            ->add('POST', 'api/v1/device-types/{id}/activate', $write(Area::DeviceTypes), new DeviceTypeActionHandler(DeviceTypeAction::Activate))
            ->add('POST', 'api/v1/device-types/{id}/single-use', $write(Area::DeviceTypes), new DeviceTypeActionHandler(DeviceTypeAction::SetSingleUse))
            ->add('GET', 'api/v1/locations', Requirement::anyOf($read(Area::Locations), $seeDevices), new LocationListHandler())
            ->add('POST', 'api/v1/locations', $write(Area::Locations), new LocationActionHandler(LocationAction::Create))
            ->add('GET', 'api/v1/locations/{id}', Requirement::anyOf($read(Area::Locations), $seeDevices), new LocationActionHandler(LocationAction::Show))
            ->add('POST', 'api/v1/locations/{id}/rename', $write(Area::Locations), new LocationActionHandler(LocationAction::Rename))
            ->add('POST', 'api/v1/locations/{id}/lock', $write(Area::Locations), new LocationActionHandler(LocationAction::Lock))
            ->add('POST', 'api/v1/locations/{id}/unlock', $write(Area::Locations), new LocationActionHandler(LocationAction::Unlock))
            ->add('POST', 'api/v1/locations/{id}/task', $write(Area::Locations), new LocationActionHandler(LocationAction::SetTask))
            ->add('GET', 'api/v1/location-tasks', Requirement::anyOf($read(Area::Locations), $seeDevices), new LocationTasksHandler(superAdmin: false))
            ->add('GET', 'api/v1/devices', $seeDevices, new DeviceListHandler())
            // CSV-Export nur mit der Aktion csv.export (Plan, F2; Rolle Benutzer: 403), der Verlauf
            // zusätzlich mit dem Protokoll.
            ->add('GET', 'api/v1/devices/export', $action(Action::CsvExport), new DeviceExportHandler($clock))
            ->add('GET', 'api/v1/device-events/export', Requirement::allOf($action(Action::CsvExport), $read(Area::Log)), new DeviceHistoryExportHandler($clock))
            ->add('GET', 'api/v1/device-events', Requirement::anyOf($read(Area::Dashboard), $read(Area::Log)), new DeviceHistoryListHandler())
            ->add('POST', 'api/v1/devices', $action(Action::DeviceCreate), new DeviceActionHandler(DeviceAction::Create))
            ->add('GET', 'api/v1/devices/import/template', $action(Action::CsvImport), new DeviceImportTemplateHandler(validValues: false, clock: $clock))
            ->add('GET', 'api/v1/devices/import/values', $action(Action::CsvImport), new DeviceImportTemplateHandler(validValues: true, clock: $clock))
            ->add('POST', 'api/v1/devices/import/preview', $action(Action::CsvImport), new DeviceImportHandler(apply: false, clock: $clock), DeviceImportHandler::MAX_BODY_BYTES)
            ->add('POST', 'api/v1/devices/import', $action(Action::CsvImport), new DeviceImportHandler(apply: true, clock: $clock), DeviceImportHandler::MAX_BODY_BYTES)
            ->add('POST', 'api/v1/devices/bulk/inspection-due', Requirement::allOf($action(Action::DevicesBulk), $write(Area::Due)), new DeviceBulkHandler(DeviceBulkAction::InspectionDue))
            ->add('POST', 'api/v1/devices/bulk/state', $bulkBook, new DeviceBulkHandler(DeviceBulkAction::State))
            ->add('GET', 'api/v1/devices/{id}', $seeDevices, new DeviceActionHandler(DeviceAction::Show))
            ->add('POST', 'api/v1/devices/{id}/fields', $deviceFields, new DeviceActionHandler(DeviceAction::UpdateFields))
            ->add('POST', 'api/v1/devices/{id}/inspection-due', $write(Area::Due), new DeviceActionHandler(DeviceAction::SetInspectionDue))
            ->add('POST', 'api/v1/devices/{id}/state', $book, new DeviceStateHandler($clock, $offline))
            ->add('GET', 'api/v1/stock', $read(Area::Locations), new StockHandler(save: false))
            ->add('POST', 'api/v1/locations/{id}/stock-rules', $write(Area::Locations), new StockHandler(save: true))
            ->add('GET', 'api/v1/loans', $read(Area::Loans), new LoanListHandler($clock))
            ->add('POST', 'api/v1/loans', $write(Area::Loans), new LoanLendHandler())
            ->add('POST', 'api/v1/loans/to-collection', $write(Area::Loans), new ReturnProcessHandler(ReturnProcess::ToCollection))
            ->add('POST', 'api/v1/loans/to-store', $write(Area::Loans), new ReturnProcessHandler(ReturnProcess::ToStore))
            ->add('POST', 'api/v1/return-points/{id}/renew', $write(Area::Loans), new ReturnPointHandler(true))
            ->add('POST', 'api/v1/return-points/{id}/revoke', $write(Area::Loans), new ReturnPointHandler(false))
            ->add('GET', 'api/v1/maintenance', $read(Area::Maintenance), new MaintenanceListHandler())
            ->add('POST', 'api/v1/maintenance/to-workshop', $write(Area::Maintenance), new MaintenanceMoveHandler(MaintenanceMove::ToWorkshop))
            ->add('POST', 'api/v1/maintenance/back', $write(Area::Maintenance), new MaintenanceMoveHandler(MaintenanceMove::Back))
            ->add('POST', 'api/v1/devices/swap', $action(Action::DeviceSwap), new DeviceSwapHandler())
            ->add('POST', 'api/v1/devices/{id}/used', $action(Action::DeviceUsed), new DeviceStateHandler($clock, $offline, used: true))
            ->add('POST', 'api/v1/devices/{id}/retire', $action(Action::DeviceRetire), new DeviceActionHandler(DeviceAction::Retire))
            ->add('POST', 'api/v1/devices/{id}/reinstate', $reinstate, new DeviceActionHandler(DeviceAction::Reinstate))
            ->add('GET', 'api/v1/devices/{id}/events', $seeDevices, new DeviceEventListHandler())
            ->add('GET', 'api/v1/device-summary', Requirement::anyOf($read(Area::Dashboard), $read(Area::Due)), new DeviceSummaryHandler($clock))
            ->add('GET', 'api/v1/inspection-due', $read(Area::Due), new InspectionDueHandler($clock))
            ->add('GET', 'api/v1/client-config', Access::Authenticated, new ClientConfigHandler($clock, $offline))
            ->add('GET', 'api/v1/notices', $read(Area::Log), new NoticeListHandler())
            ->add('GET', 'api/v1/notices/{id}', $read(Area::Log), new NoticeActionHandler(NoticeAction::Show))
            ->add('POST', 'api/v1/notices/{id}/resolve', $write(Area::Log), new NoticeActionHandler(NoticeAction::Resolve))
            ->add('POST', 'api/v1/notices/{id}/reopen', $write(Area::Log), new NoticeActionHandler(NoticeAction::Reopen))
            // Eigene Claude-Verbindungen (Profil, O5).
            ->add('GET', 'api/v1/auth/claude-connections', Access::Authenticated, new ClaudeConnectionsHandler())
            ->add('DELETE', 'api/v1/auth/claude-connections/{id}', Access::Authenticated, new ClaudeConnectionsHandler(delete: true))
            // Claude-Zugang (docs/PLAN-MCP.md): nur in Städten mit Claude-Zugang, sonst 404.
            ->add('GET', '.well-known/oauth-protected-resource', Access::Public, new OAuthMetadataHandler(OAuthMetadataDocument::ProtectedResource))
            ->add('GET', '.well-known/openid-configuration', Access::Public, new OAuthMetadataHandler(OAuthMetadataDocument::AuthorizationServer))
            ->add('GET', '.well-known/oauth-authorization-server', Access::Public, new OAuthMetadataHandler(OAuthMetadataDocument::AuthorizationServer))
            ->add('GET', '.well-known/jwks.json', Access::Public, new OAuthMetadataHandler(OAuthMetadataDocument::Jwks))
            ->add('POST', 'oauth/register', Access::Public, new ClientRegistrationHandler())
            ->add('POST', 'oauth/token', Access::Public, new TokenHandler(), bodyFormat: BodyFormat::Form)
            ->add('POST', 'oauth/revoke', Access::Public, new TokenHandler(revoke: true), bodyFormat: BodyFormat::Form)
            ->add('POST', 'api/v1/oauth/authorize/check', Access::Public, new AuthorizeHandler(checkOnly: true))
            ->add('POST', 'api/v1/oauth/authorize', Access::Public, new AuthorizeHandler(checkOnly: false));

        // Die Werkzeuge rufen die Routen dieses Routers auf (gleiche Erfordernisse wie in der App).
        // Body bis 3 MiB: das Werkzeug des CSV-Imports sendet die Datei (der Vermittler lässt 1 MiB durch).
        return $router->add('POST', 'mcp', Access::Claude, new McpHandler($router, ToolRegistry::standard()), Request::MAX_UPLOAD_BYTES);
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
