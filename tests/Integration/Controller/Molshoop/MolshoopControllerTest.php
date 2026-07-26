<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Molshoop;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Molshoop\MolshoopController;
use Tvdt\DataFixtures\TestFixtures;
use Tvdt\Entity\Season;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(MolshoopController::class)]
final class MolshoopControllerTest extends AbstractControllerWebTestCase
{
    /** An admin owns no seasons in the fixtures, so the homepage must show only their own (none) —
     * never every season in the system, which is now exclusively the Admin > Seasons tab's job. */
    public function testHomepageShowsOnlyTheAdminsOwnSeasonsNotEverySeason(): void
    {
        $admin = $this->getUserByEmail(TestFixtures::ADMIN_EMAIL);
        // Molshoop pages resolve locale from the user's profile, not Accept-Language (see LocaleListener).
        $admin->locale = 'en';

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/molshoop/');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('You have no seasons yet.', $crawler->filter('body')->text());
        $this->assertStringNotContainsString('Doomed Season', (string) $this->client->getResponse()->getContent());
    }

    public function testAddSeasonCopiesOwnersLocaleIntoSettings(): void
    {
        $user = $this->getUserByEmail('user2@example.org');
        $user->locale = 'en';

        $this->entityManager->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/molshoop/season/add');
        $form = $crawler->filter('form')->form([
            'create_season_form[name]' => 'A New Season',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $season = $this->entityManager->getRepository(Season::class)->findOneBy(['name' => 'A New Season']);
        $this->assertInstanceOf(Season::class, $season);
        $this->assertSame('en', $season->settings?->locale);
    }

    public function testExportQuizFilenameIsSanitized(): void
    {
        $user = $this->getUserByEmail('user2@example.org');
        $user->isVerified = true;

        $this->entityManager->flush();
        $this->client->loginUser($user);

        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/molshoop/quiz/%s/export', $quiz->id));

        self::assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('filename=Quiz-1.xlsx', $disposition);
        $this->assertStringNotContainsString('Quiz 1.xlsx', $disposition);
    }

    public function testExportQuizRequiresVerifiedEmail(): void
    {
        $user = $this->getUserByEmail('user2@example.org');
        $this->assertFalse($user->isVerified);
        $this->client->loginUser($user);

        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/molshoop/quiz/%s/export', $quiz->id));

        self::assertResponseRedirects(\sprintf('/molshoop/season/%s', $quiz->season->seasonCode));
    }

    public function testExportQuizIsDeniedForNonOwner(): void
    {
        $this->loginAs('test@example.org');

        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/molshoop/quiz/%s/export', $quiz->id));

        self::assertResponseStatusCodeSame(403);
    }
}
