<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Rector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use Tvdt\Rector\NoSafeNamespaceTypeHintRector;

final class NoSafeNamespaceTypeHintRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testRule(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        yield from self::yieldFilesFromDirectory(__DIR__.'/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/no_safe_namespace_type_hint.php';
    }

    public function testRuleDefinition(): void
    {
        $ruleDefinition = $this->make(NoSafeNamespaceTypeHintRector::class)->getRuleDefinition();

        $this->assertSame(
            'Replace Safe\\ namespace type hints with their parent class to avoid rejecting compatible non-Safe objects',
            $ruleDefinition->getDescription(),
        );
        $this->assertCount(1, $ruleDefinition->getCodeSamples());
    }
}
