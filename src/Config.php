<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer;

use Composer\Composer;
use Composer\Util\Platform;

/**
 * Plugin settings from the root package's `extra.pcomposer`, overridden by the environment
 * (`PCOMPOSER_DISABLE`, `PCOMPOSER_STRATEGY`).
 */
final class Config
{
    public const STRATEGIES = ['hardlink', 'reflink', 'copy'];

    /** @param list<string> $copy package names always materialised with `copy` */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $strategy = 'hardlink',
        public readonly array $copy = [],
    ) {
        if (!\in_array($strategy, self::STRATEGIES, true)) {
            throw new \UnexpectedValueException(\sprintf('pcomposer: invalid strategy "%s", expected one of %s', $strategy, implode(', ', self::STRATEGIES)));
        }
    }

    /**
     * Packages listed in `copy` and Composer plugins are always copied; the others use the configured strategy.
     * Plugins are copied because several of them rewrite their own files on every install
     * (phpstan/extension-installer, infection/extension-installer), which the read-only store refuses.
     */
    public function strategyFor(string $packageName, string $packageType = 'library'): string
    {
        return 'composer-plugin' === $packageType || \in_array($packageName, $this->copy, true) ? 'copy' : $this->strategy;
    }

    public static function fromComposer(Composer $composer): self
    {
        $extra = $composer->getPackage()->getExtra();
        $settings = \is_array($extra['pcomposer'] ?? null) ? $extra['pcomposer'] : [];
        $strategy = Platform::getEnv('PCOMPOSER_STRATEGY') ?: ($settings['strategy'] ?? 'hardlink');

        // Patched packages (cweagans/composer-patches) are copied so that patching never touches the store.
        $copy = array_merge(
            \is_array($settings['copy'] ?? null) ? array_values($settings['copy']) : [],
            \is_array($extra['patches'] ?? null) ? array_keys($extra['patches']) : [],
        );

        return new self(
            !Platform::getEnv('PCOMPOSER_DISABLE') && false !== ($settings['enabled'] ?? true),
            \is_string($strategy) ? $strategy : json_encode($strategy, \JSON_THROW_ON_ERROR),
            array_values(array_filter($copy, \is_string(...))),
        );
    }
}
