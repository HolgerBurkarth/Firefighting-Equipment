<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Config\ConfigLoader;

/**
 * Origins, von denen ändernde Anfragen kommen dürfen. https://www.prodad.de ist immer erlaubt;
 * weitere (z. B. für lokal) stehen in der Konfiguration und werden erst geladen, wenn eine Anfrage
 * einen anderen Origin trägt.
 */
final class AllowedOrigins
{
    public const string DEFAULT = 'https://www.prodad.de';

    /** @var list<string>|null */
    private ?array $extra = null;

    /**
     * @param \Closure(): list<string> $loadExtra
     */
    public function __construct(private readonly \Closure $loadExtra)
    {
    }

    public static function onlyDefault(): self
    {
        return new self(static fn (): array => []);
    }

    public static function fromConfigFile(string $configPath): self
    {
        return new self(static fn (): array => new ConfigLoader()->loadAllowedOrigins($configPath));
    }

    public function allows(string $origin): bool
    {
        if ($origin === self::DEFAULT) {
            return true;
        }

        return in_array($origin, $this->extra ??= ($this->loadExtra)(), true);
    }
}
