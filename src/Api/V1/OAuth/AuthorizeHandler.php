<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

use FirefightingEquipment\Auth\AccountUnavailableException;
use FirefightingEquipment\Auth\Authenticator;
use FirefightingEquipment\Auth\InvalidCredentialsException;
use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\TooManyAttemptsException;
use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\OAuth\AuthorizationCodeRepository;
use FirefightingEquipment\OAuth\AuthorizationRequest;
use FirefightingEquipment\OAuth\AuthorizationRequestException;
use FirefightingEquipment\OAuth\OAuthClientRepository;
use FirefightingEquipment\OAuth\OAuthSecret;
use FirefightingEquipment\OAuth\OAuthSecretKind;
use FirefightingEquipment\OAuth\OAuthUrls;
use FirefightingEquipment\OAuth\RedirectUriRules;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\User;

/**
 * API der Seite „Claude verbinden“ (öffentlich, JSON; docs/PLAN-MCP.md, Abschnitt 9a):
 * - POST api/v1/oauth/authorize/check {params}: prüft die Parameter aus der Adresse, liefert Stadt,
 *   Anwendung, Ziel und den angefragten Zugang.
 * - POST api/v1/oauth/authorize {params, decision, email?, password?}: decision "deny" führt ohne
 *   Anmeldung mit error=access_denied zurück; "preview" prüft E-Mail und Passwort (Bremse und Meldungen
 *   wie auth/login) und zeigt den gewährten Zugang; "allow" prüft erneut und gibt den Code aus
 *   (redirect_url). Es entsteht nie eine Sitzung oder ein Cookie (O7: immer frische Anmeldung).
 * Fehler der Parameter: ohne gültigen Client oder Rücksprung 400 invalid_request ohne Rücksprung;
 * sonst 400 invalid_request mit details {redirect_url} (Fehler für Claude, state unverändert).
 */
final readonly class AuthorizeHandler implements RequestHandler
{
    private const array DECISIONS = ['preview', 'allow', 'deny'];

    public function __construct(private bool $checkOnly)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = ClaudeTenant::of($context);
        $params = $context->body['params'] ?? null;
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            throw HttpException::invalidRequest('Das Feld "params" fehlt oder ist kein Objekt.');
        }
        $decision = null;
        if (!$this->checkOnly) {
            $decision = $context->stringField('decision');
            if (!in_array($decision, self::DECISIONS, true)) {
                throw HttpException::invalidRequest('Das Feld "decision" muss "preview", "allow" oder "deny" sein.');
            }
        }

        $pdo = $context->db->get();
        $urls = new OAuthUrls($context->urls, $tenant);
        try {
            $request = AuthorizationRequest::fromParams($params, new OAuthClientRepository($pdo), $tenant->id, $urls);
        } catch (AuthorizationRequestException $e) {
            throw HttpException::invalidRequest(
                $e->getMessage(),
                $e->redirectUrl === null ? null : ['redirect_url' => $e->redirectUrl],
            );
        }

        if ($decision === null) {
            return new JsonResponse(self::summary($request, $tenant));
        }
        if ($decision === 'deny') {
            return new JsonResponse(['redirect_url' => $request->redirectWith([
                'error' => 'access_denied',
                'error_description' => 'Die Verbindung wurde abgelehnt.',
            ])]);
        }

        $user = $this->login($context, $tenant);
        $access = $request->requestedAccess->min($user->claudeAccess);
        if ($access === ClaudeAccess::Off) {
            throw HttpException::claudeAccessMissing();
        }
        if ($decision === 'preview') {
            return new JsonResponse([
                'access' => $access->value,
                'access_label' => $access->label(),
                'user' => ['display_name' => $user->label()],
            ]);
        }

        $code = OAuthSecret::generate(OAuthSecretKind::Code);
        new AuthorizationCodeRepository($pdo)->create($tenant->id, $user->id, $request, $access, $code);

        return new JsonResponse([
            'access' => $access->value,
            'access_label' => $access->label(),
            'redirect_url' => $request->redirectWith(['code' => $code->value()]),
        ]);
    }

    /**
     * Anmeldung wie auth/login (gleiche Bremse, Meldungen und Laufzeit), aber ohne Sitzung.
     *
     * @throws HttpException 400, 401, 403, 429
     */
    private function login(RequestContext $context, Tenant $tenant): User
    {
        $email = $context->stringField('email');
        $password = $context->stringField('password');
        $pdo = $context->db->get();
        new LoginThrottle($pdo)->deleteOld();

        try {
            return new Authenticator($pdo, $context->hasher)->login(
                $tenant,
                $email,
                $password,
                IpPrefix::fromAddress($context->request->remoteAddress),
            );
        } catch (TooManyAttemptsException $e) {
            throw HttpException::tooManyAttempts($e->retryAfterSeconds);
        } catch (InvalidCredentialsException) {
            throw HttpException::invalidCredentials();
        } catch (AccountUnavailableException $e) {
            throw match ($e->reason) {
                AccountUnavailableException::LOCKED => HttpException::accountLocked(),
                AccountUnavailableException::EMAIL_NOT_VERIFIED => HttpException::emailNotVerified(),
                AccountUnavailableException::PENDING_APPROVAL => HttpException::pendingApproval(),
            };
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(AuthorizationRequest $request, Tenant $tenant): array
    {
        return [
            'tenant' => ['name' => $tenant->name->value],
            'client' => ['name' => $request->client->clientName],
            'target' => RedirectUriRules::target($request->redirectUri),
            'requested_access' => $request->requestedAccess->value,
            'requested_access_label' => $request->requestedAccess->label(),
        ];
    }
}
