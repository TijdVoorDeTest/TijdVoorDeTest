<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;

abstract class AbstractControllerWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function getUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    protected function loginAs(string $email): void
    {
        $this->client->loginUser($this->getUserByEmail($email));
    }

    /** Quiz names are only unique per season (see Quiz's UniqueConstraint), so this is scoped by season code. */
    protected function getQuizByName(string $name, string $seasonCode = 'krtek'): Quiz
    {
        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['name' => $name, 'season' => $this->getSeasonByCode($seasonCode)]);
        $this->assertInstanceOf(Quiz::class, $quiz);

        return $quiz;
    }

    /** Candidate names are only unique per season (see Candidate's UniqueConstraint), so this is scoped by season code. */
    protected function getCandidate(string $name, string $seasonCode = 'krtek'): Candidate
    {
        $candidate = $this->entityManager->getRepository(Candidate::class)->findOneBy(['name' => $name, 'season' => $this->getSeasonByCode($seasonCode)]);
        $this->assertInstanceOf(Candidate::class, $candidate);

        return $candidate;
    }

    protected function getSeasonByCode(string $seasonCode): Season
    {
        $season = $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => $seasonCode]);
        $this->assertInstanceOf(Season::class, $season);

        return $season;
    }

    /** GETs $url and extracts the CSRF token from a form whose action contains $formActionContains. */
    protected function getCsrfTokenFromPage(string $url, string $formActionContains, string $tokenFieldName = '_token'): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        return $this->getCsrfTokenFromCrawler($crawler, $formActionContains, $tokenFieldName);
    }

    /** Extracts the CSRF token from a form on the page already loaded in the client. */
    protected function getCsrfTokenFromCurrentPage(string $formActionContains, string $tokenFieldName = '_token'): string
    {
        return $this->getCsrfTokenFromCrawler($this->client->getCrawler(), $formActionContains, $tokenFieldName);
    }

    /** GETs $url and extracts the CSRF token input, regardless of which form it belongs to. */
    protected function getTokenFromPage(string $url, string $tokenFieldName = '_token'): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $input = $crawler->filter(\sprintf('input[name="%s"]', $tokenFieldName));
        $this->assertGreaterThan(0, $input->count(), \sprintf('No input named "%s" found on the page', $tokenFieldName));

        return (string) $input->first()->attr('value');
    }

    private function getCsrfTokenFromCrawler(Crawler $crawler, string $formActionContains, string $tokenFieldName): string
    {
        $input = $crawler->filter(\sprintf('form[action*="%s"] input[name="%s"]', $formActionContains, $tokenFieldName));
        $this->assertGreaterThan(0, $input->count(), \sprintf('No form found with action containing "%s"', $formActionContains));

        return (string) $input->first()->attr('value');
    }
}
