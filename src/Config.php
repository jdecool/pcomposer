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

    public static function fromComposer(Composer $composer): self
    {
        $extra = $composer->getPackage()->getExtra();
        $settings = \is_array($extra['pcomposer'] ?? null) ? $extra['pcomposer'] : [];
        $strategy = Platform::getEnv('PCOMPOSER_STRATEGY') ?: ($settings['strategy'] ?? 'hardlink');

        return new self(
            !Platform::getEnv('PCOMPOSER_DISABLE') && false !== ($settings['enabled'] ?? true),
            \is_string($strategy) ? $strategy : json_encode($strategy, \JSON_THROW_ON_ERROR),
        );
    }
}
