<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\AccountUnavailableException;
use FirefightingEquipment\Auth\Authenticator;
use FirefightingEquipment\Auth\DeviceLabel;
use FirefightingEquipment\Auth\InvalidCredentialsException;
use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Auth\SessionRepository;
use FirefightingEquipment\Auth\TooManyAttemptsException;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * POST api/v1/auth/login {email, password}: legt eine Sitzung an und setzt das Cookie.
 * Unter einer Stadt nur Konten dieser Stadt, unter service nur Super-Admins.
 * Räumt dabei abgelaufene Sitzungen und alte Versuche auf (Ersatz für einen Cronjob).
 */
final readonly class LoginHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $email = $context->stringField('email');
        $password = $context->stringField('password');

        $pdo = $context->db->get();
        new LoginThrottle($pdo)->deleteOld();
        new SessionRepository($pdo)->deleteExpired();

        try {
            $user = new Authenticator($pdo, $context->hasher)->login(
                $context->tenant,
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

        $sessions = new SessionManager($pdo);
        // Eine ältere Sitzung dieses Browsers wird ersetzt, nicht liegen gelassen.
        $previous = SessionCookie::tokenFrom($context->request);
        if ($previous !== null) {
            $sessions->end($previous);
        }
        $started = $sessions->start($user, DeviceLabel::fromUserAgent($context->request->header('User-Agent')));

        return new JsonResponse(
            AuthJson::me($started->auth, $context->tenant),
            200,
            ['Set-Cookie' => SessionCookie::issue($context->tenant, $started->token, SessionLifetime::maxSeconds($user->role))],
        );
    }
}
