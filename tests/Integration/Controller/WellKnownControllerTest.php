<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\WellKnownController;

#[CoversClass(WellKnownController::class)]
final class WellKnownControllerTest extends AbstractControllerWebTestCase
{
    public function testChangePasswordRedirectsToSettings(): void
    {
        $this->client->request(Request::METHOD_GET, '/.well-known/change-password');

        self::assertResponseRedirects('/backoffice/settings');
    }

    /** @throws \Exception */
    public function testSecurityTxt(): void
    {
        $this->client->request(Request::METHOD_GET, '/.well-known/security.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Contact:', $content);
        $this->assertMatchesRegularExpression('/^Expires: (.+)$/m', $content);

        \Safe\preg_match('/^Expires: (.+)$/m', $content, $matches);
        $this->assertArrayHasKey(1, $matches);
        $expires = new DateTimeImmutable($matches[1]);
        $this->assertGreaterThan(new DateTimeImmutable('now'), $expires);
    }
}
