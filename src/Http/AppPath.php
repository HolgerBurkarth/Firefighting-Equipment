<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Zerlegt eine Adresse /firefighting/<kürzel>/<rest> in Bereich (Kürzel oder "service")
 * und den Pfad relativ dazu. Ob das Kürzel zu einer Stadt gehört, prüft erst TenantResolver.
 */
final readonly class AppPath
{
    /** Einzige Stelle für das Präfix; gilt auf dem Server und lokal mit php -S. */
    public const string PREFIX = '/firefighting';

    /** Bereich Super-Admin; ist in TenantSlug::RESERVED und nie eine Stadt. */
    public const string SERVICE_SCOPE = 'service';

    /** Wie die Rewrite-Regel in htdocs/.htaccess. */
    private const string SCOPE_PATTERN = '/^[a-z0-9-]+$/D';

    private function __construct(
        /** Erstes Segment nach dem Präfix: "service" oder ein (noch ungeprüftes) Kürzel. */
        public string $scope,
        /** Pfad relativ zum Bereich ohne führenden Slash, z. B. "api/v1/health"; "" für die Startseite. */
        public string $rest,
    ) {
    }

    /**
     * @param string $requestUri z. B. $_SERVER['REQUEST_URI'] (Query-String wird ignoriert)
     *
     * @return self|null null, wenn die Adresse nicht dem Schema entspricht
     */
    public static function parse(string $requestUri): ?self
    {
        $path = substr($requestUri, 0, strcspn($requestUri, '?#'));
        if (!str_starts_with($path, self::PREFIX . '/')) {
            return null;
        }

        $relative = substr($path, strlen(self::PREFIX) + 1);
        if (str_ends_with($relative, '/')) {
            $relative = substr($relative, 0, -1);
        }

        $segments = explode('/', $relative);
        if (in_array('', $segments, true)) {
            return null;
        }

        $scope = array_shift($segments);
        if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            return null;
        }

        return new self($scope, implode('/', $segments));
    }

    public function isService(): bool
    {
        return $this->scope === self::SERVICE_SCOPE;
    }
}
