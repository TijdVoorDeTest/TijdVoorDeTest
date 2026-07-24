<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\SeasonController;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(SeasonController::class)]
final class SeasonRenameTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('krtek-admin@example.org');
    }

    public function testRenameUpdatesSeasonName(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek', '/rename');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/rename', [
            '_token' => $token,
            'name' => 'Renamed Season',
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Renamed Season', $this->getSeasonByCode('krtek')->name);
    }

    public function testRenameRefusedWhenNameIsBlank(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek', '/rename');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/rename', [
            '_token' => $token,
            'name' => '   ',
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Krtek Weekend', $this->getSeasonByCode('krtek')->name);
    }

    public function testRenameRefusedWhenNameTooLong(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek', '/rename');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/rename', [
            '_token' => $token,
            'name' => str_repeat('a', 65),
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Krtek Weekend', $this->getSeasonByCode('krtek')->name);
    }

    public function testRenameIsDeniedForNonOwner(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek', '/rename');

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/rename', [
            '_token' => $token,
            'name' => 'Hijacked Season',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        $this->assertSame('Krtek Weekend', $this->getSeasonByCode('krtek')->name);
    }
}
