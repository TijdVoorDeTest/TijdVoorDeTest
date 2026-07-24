<?php

declare(strict_types=1);

namespace Tvdt\Service;

use Psr\Cache\InvalidArgumentException;
use Safe\DateTimeImmutable;
use Safe\Exceptions\SafeExceptionInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubReleasesService
{
    private const string RELEASES_URL = 'https://api.github.com/repos/TijdVoorDeTest/TijdVoorDeTest/releases';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {}

    /**
     * @throws InvalidArgumentException
     *
     * @return list<array{tagName: string, name: string, publishedAt: ?\DateTimeImmutable, body: string, url: string}>
     */
    public function getReleases(): array
    {
        return $this->cache->get('github_releases', $this->fetchReleases(...));
    }

    /** @return list<array{tagName: string, name: string, publishedAt: ?\DateTimeImmutable, body: string, url: string}> */
    private function fetchReleases(ItemInterface $item, bool &$save): array
    {
        try {
            $response = $this->httpClient->request('GET', self::RELEASES_URL, [
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'TijdVoorDeTest',
                ],
            ]);

            /** @var list<array{tag_name: string, name: ?string, published_at: ?string, body: ?string, html_url: string}> $releases */
            $releases = $response->toArray();

            usort($releases, static fn (array $a, array $b): int => ($b['published_at'] ?? '') <=> ($a['published_at'] ?? ''));

            $result = array_map(static function (array $release): array {
                $name = $release['name'] ?? '';

                return [
                    'tagName' => $release['tag_name'],
                    'name' => '' !== $name ? $name : $release['tag_name'],
                    'publishedAt' => $release['published_at'] ? new DateTimeImmutable($release['published_at']) : null,
                    'body' => (string) $release['body'],
                    'url' => $release['html_url'],
                ];
            }, $releases);
        } catch (ExceptionInterface|SafeExceptionInterface|\DateMalformedStringException) {
            $save = false;

            return [];
        }

        $item->expiresAfter(3600);

        return $result;
    }
}
