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
        self::assertSelectorExists('nav #acceptanceBadge');
    }

    public function testTaggedBuildIsNotMarkedAsAcceptance(): void
    {
        putenv('GIT_TAG=v1.0.2');
        $this->loginAs('user2@example.org');

        try {
            $this->client->request(Request::METHOD_GET, '/molshoop/');
        } finally {
            putenv('GIT_TAG');
        }

        self::assertPageTitleContains('Tijd voor de test');
        $this->assertStringNotContainsString('Acceptatie', $this->client->getCrawler()->filter('title')->text());
        self::assertSelectorNotExists('#acceptanceBadge');
    }
}
