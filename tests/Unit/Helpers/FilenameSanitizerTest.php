<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tvdt\Helpers\FilenameSanitizer;

#[CoversClass(FilenameSanitizer::class)]
final class FilenameSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function sanitizeProvider(): iterable
    {
        yield 'replaces spaces with dashes' => ['Krtek Weekend', 'Krtek-Weekend'];
        yield 'strips path traversal' => ['../../etc/passwd', 'etc-passwd'];
        yield 'strips forward slash' => ['a/b', 'a-b'];
        yield 'strips backslash' => ['a\\b', 'a-b'];
        yield 'strips control characters and special symbols' => ["Quiz #1 <script>\0", 'Quiz-1-script'];
        yield 'transliterates unicode to ascii' => ['Wéird Ñame', 'Weird-Name'];
        yield 'transliterates at sign in email' => ['test@example.org', 'test-example-org'];
        yield 'returns unnamed for empty input' => ['', 'unnamed'];
        yield 'returns unnamed for fully stripped input' => ['///', 'unnamed'];
    }

    #[DataProvider('sanitizeProvider')]
    public function testSanitize(string $input, string $expected): void
    {
        $this->assertSame($expected, FilenameSanitizer::sanitize($input));
    }
}
