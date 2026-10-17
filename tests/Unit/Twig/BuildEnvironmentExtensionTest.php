<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tvdt\Twig\BuildEnvironmentExtension;

#[CoversClass(BuildEnvironmentExtension::class)]
final class BuildEnvironmentExtensionTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function buildProvider(): iterable
    {
        yield 'tagged build is production' => ['abc1234', 'v1.0.2', null];
        yield 'untagged build with commit is acceptance' => ['abc1234', null, 'Acceptance'];
        yield 'empty tag counts as untagged' => ['abc1234', '', 'Acceptance'];
        yield 'no commit and no tag is development' => [null, null, 'Develop'];
        yield 'empty values count as unset' => ['', '', 'Develop'];
    }

    #[DataProvider('buildProvider')]
    public function testBuildEnvironment(?string $gitSha, ?string $gitTag, ?string $expected): void
    {
        $this->assertSame($expected, new BuildEnvironmentExtension($gitSha, $gitTag)->buildEnvironment());
    }
}
