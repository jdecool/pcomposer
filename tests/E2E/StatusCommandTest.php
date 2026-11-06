<?php

declare(strict_types=1);

namespace JDecool\Composer\Plugin\PComposer\Tests\E2E;

final class StatusCommandTest extends E2ETestCase
{
    public function testCommandsAreListedByComposer(): void
    {
        $project = $this->createProject();
        [$exitCode, $output] = $this->composer($project, ['install']);
        self::assertSame(0, $exitCode, $output);

        [$exitCode, $output] = $this->composer($project, ['list']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('pcomposer:status', $output);
        self::assertStringContainsString('pcomposer:unlink', $output);
    }
}
