<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\ReleasesController;
use Tvdt\Service\GitHubReleasesService;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(ReleasesController::class)]
final class ReleasesControllerTest extends AbstractControllerWebTestCase
{
    public function testReleasesFrameRendersReleaseNotes(): void
    {
        $this->loginAs('user2@example.org');
        $this->mockReleasesService();

        $this->client->request(Request::METHOD_GET, '/backoffice/releases');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'v0.8.0');
        self::assertSelectorTextContains('body', 'Some release notes');
        self::assertSelectorTextContains('#releasesModalLabel', 'Huidige versie: v0.8.0');
    }

    public function testReleasesFrameIsAccessibleWithoutAuthentication(): void
    {
        $this->mockReleasesService();

        $this->client->request(Request::METHOD_GET, '/backoffice/releases');

        self::assertResponseIsSuccessful();
    }

    private function mockReleasesService(): void
    {
        $body = json_encode([
            [
                'tag_name' => 'v0.8.0',
                'name' => 'v0.8.0',
                'published_at' => '2026-07-12T10:00:00Z',
                'body' => 'Some release notes',
                'html_url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.8.0',
            ],
        ], \JSON_THROW_ON_ERROR);
        $httpClient = new MockHttpClient([new MockResponse((string) $body, ['response_headers' => ['content-type' => 'application/json']])]);
        self::getContainer()->set(GitHubReleasesService::class, new GitHubReleasesService($httpClient, new ArrayAdapter()));
    }
}
