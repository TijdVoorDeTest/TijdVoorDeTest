<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\UrlException;
use Tvdt\Helpers\Base64;

#[CoversClass(Base64::class)]
final class Base64Test extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pairProvider(): iterable
    {
        yield 'Marijn' => ['Marijn', 'TWFyaWpu'];
        yield 'Philine' => ['Philine', 'UGhpbGluZQ'];
        yield 'byte 254' => [\chr(254), '_g'];
        yield 'byte 250' => [\chr(250), '-g'];
    }

    #[DataProvider('pairProvider')]
    public function testBase64UrlEncode(string $decoded, string $encoded): void
    {
        $this->assertSame($encoded, Base64::base64UrlEncode($decoded));
    }

    #[DataProvider('pairProvider')]
    public function testBase64UrlDecode(string $decoded, string $encoded): void
    {
        $this->assertSame($decoded, Base64::base64UrlDecode($encoded));
    }

    public function testBase64UrlDecodeCanHandlePadding(): void
    {
        $this->assertSame('Philine', Base64::base64UrlDecode('UGhpbGluZQ=='));
    }

    public function testBase64UrlDecodeThrowsExceptionOnInvalidInput(): void
    {
        $this->expectException(UrlException::class);
        Base64::base64UrlDecode('Philine==');
    }
}
