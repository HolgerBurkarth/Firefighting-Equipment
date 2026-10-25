<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;

/**
 * Felder und Fehler der Städteverwaltung: ungültige Werte → 400 mit der Meldung der Regel,
 * vergebene E-Mail-Adresse → 409 email_taken (der Super-Admin darf das wissen).
 */
final class TenantFields
{
    /**
     * @throws HttpException 400 invalid_request
     */
    public static function slug(RequestContext $context): TenantSlug
    {
        try {
            return TenantSlug::fromString($context->stringField('slug'));
        } catch (InvalidTenantValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
    }

    /**
     * @throws HttpException 400 invalid_request
     */
    public static function name(RequestContext $context): TenantName
    {
        try {
            return TenantName::fromString($context->stringField('name'));
        } catch (InvalidTenantValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
    }

    /**
     * @throws HttpException 400 invalid_request
     */
    public static function email(RequestContext $context, string $field): EmailAddress
    {
        try {
            return EmailAddress::fromString($context->stringField($field));
        } catch (InvalidUserValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
    }

    /**
     * Antwort auf DuplicateUserEmailException.
     */
    public static function emailTaken(EmailAddress $email): HttpException
    {
        return HttpException::emailTaken(sprintf(
            'In dieser Stadt gibt es bereits ein Konto mit der E-Mail-Adresse „%s“. Offene Einladungen lassen sich erneut senden.',
            $email->value,
        ));
    }
}
