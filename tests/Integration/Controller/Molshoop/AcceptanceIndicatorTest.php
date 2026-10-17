<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Molshoop;

use Symfony\Component\HttpFoundation\Request;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

use function Safe\putenv;

final class AcceptanceIndicatorTest extends AbstractControllerWebTestCase
{
    public function testUntaggedBuildIsMarkedAsAcceptanceInTitleAndNavbar(): void
    {
        $this->loginAs('user2@example.org');

        $this->client->request(Request::METHOD_GET, '/molshoop/');

        self::assertPageTitleContains('Acceptatie');
        self::assertSelectorExists('nav #buildEnvironmentBadge');
    }

    public function testTaggedBuildIsNotMarkedAsAcceptance(): void
    {
        $originalTag = getenv('GIT_TAG');
        try {
            putenv('GIT_TAG=v1.0.2');
            $this->loginAs('user2@example.org');
            $this->client->request(Request::METHOD_GET, '/molshoop/');
        } finally {
            putenv(false === $originalTag ? 'GIT_TAG' : 'GIT_TAG='.$originalTag);
        }

        self::assertPageTitleContains('Tijd voor de test');
        $this->assertStringNotContainsString('Acceptatie', $this->client->getCrawler()->filter('title')->text());
        self::assertSelectorNotExists('#buildEnvironmentBadge');
    }
}
