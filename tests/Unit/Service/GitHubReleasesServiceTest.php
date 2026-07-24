<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Safe\DateTimeImmutable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tvdt\Service\GitHubReleasesService;

#[CoversClass(GitHubReleasesService::class)]
final class GitHubReleasesServiceTest extends TestCase
{
    public function testGetReleasesMapsGitHubResponse(): void
    {
        $body = json_encode([
            [
                'tag_name' => 'v0.8.0',
                'name' => 'v0.8.0',
                'published_at' => '2026-07-12T10:00:00Z',
                'body' => "## Added\n- Something new",
                'html_url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.8.0',
            ],
        ], \JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient([new MockResponse((string) $body, ['response_headers' => ['content-type' => 'application/json']])]);
        $subject = new GitHubReleasesService($httpClient, new ArrayAdapter());

        $releases = $subject->getReleases();

        $this->assertEquals([
            'tagName' => 'v0.8.0',
            'name' => 'v0.8.0',
            'publishedAt' => new DateTimeImmutable('2026-07-12T10:00:00Z'),
            'body' => "## Added\n- Something new",
            'url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.8.0',
        ], $releases[0]);
    }

    public function testGetReleasesReturnsEmptyArrayOnHttpFailure(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));
        $subject = new GitHubReleasesService($httpClient, new ArrayAdapter());

        $this->assertSame([], $subject->getReleases());
    }

    public function testHttpFailureIsNotCached(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 500]);
        });
        $subject = new GitHubReleasesService($httpClient, new ArrayAdapter());

        $subject->getReleases();
        $subject->getReleases();

        $this->assertSame(2, $requestCount);
    }

    public function testGetReleasesReturnsEmptyArrayOnUnparseablePublishedAt(): void
    {
        $body = json_encode([
            [
                'tag_name' => 'v0.8.0',
                'name' => 'v0.8.0',
                'published_at' => 'not-a-valid-date',
                'body' => 'Some release notes',
                'html_url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.8.0',
            ],
        ], \JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient([new MockResponse((string) $body, ['response_headers' => ['content-type' => 'application/json']])]);
        $subject = new GitHubReleasesService($httpClient, new ArrayAdapter());

        $this->assertSame([], $subject->getReleases());
    }

    public function testGetReleasesKeepsALiteralZeroReleaseName(): void
    {
        $body = json_encode([
            [
                'tag_name' => 'v0.9.0',
                'name' => '0',
                'published_at' => '2026-07-12T10:00:00Z',
                'body' => 'Some release notes',
                'html_url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.9.0',
            ],
        ], \JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient([new MockResponse((string) $body, ['response_headers' => ['content-type' => 'application/json']])]);
        $subject = new GitHubReleasesService($httpClient, new ArrayAdapter());

        $releases = $subject->getReleases();

        $this->assertSame('0', $releases[0]['name']);
    }

    public function testGetReleasesSortsNewestFirst(): void
    {
        $body = json_encode([
            [
                'tag_name' => 'v0.7.0',
                'name' => 'v0.7.0',
                'published_at' => '2026-07-10T10:00:00Z',
                'body' => 'Older release',
                'html_url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.7.0',
            ],
            [
                'tag_name' => 'v0.8.0',
                'name' => 'v0.8.0',
                'published_at' => '2026-07-12T10:00:00Z',
                'body' => 'Newer release',
                'html_url' => 'https://github.com/MarijnDoeve/TijdVoorDeTest/releases/tag/v0.8.0',
            ],
        ], \JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient([new MockResponse((string) $body, ['response_headers' => ['content-type' => 'application/json']])]);
        $subject = new GitHubReleasesService($httpClient, new ArrayAdapter());

        $releases = $subject->getReleases();

        $this->assertSame('v0.8.0', $releases[0]['tagName']);
        $this->assertSame('v0.7.0', $releases[1]['tagName']);
    }
}
