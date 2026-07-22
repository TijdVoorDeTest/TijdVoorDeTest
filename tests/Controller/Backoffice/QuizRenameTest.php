<?php

declare(strict_types=1);

namespace Tvdt\Tests\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\QuizController;
use Tvdt\Entity\Quiz;
use Tvdt\Tests\Controller\AbstractControllerWebTestCase;

#[CoversClass(QuizController::class)]
final class QuizRenameTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('krtek-admin@example.org');
    }

    private function getCsrfTokenFromOverview(Quiz $quiz, string $formActionContains): string
    {
        return $this->getCsrfTokenFromPage(\sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz->id), $formActionContains);
    }

    public function testRenameUpdatesQuizName(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');

        $token = $this->getCsrfTokenFromOverview($quiz, '/rename');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/rename', $quiz->id), [
            '_token' => $token,
            'name' => 'Renamed Quiz',
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Renamed Quiz', $this->getQuizByName('Renamed Quiz')->name);
    }

    public function testRenameRefusedWhenNameIsBlank(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');

        $token = $this->getCsrfTokenFromOverview($quiz, '/rename');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/rename', $quiz->id), [
            '_token' => $token,
            'name' => '   ',
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Quiz 2', $this->getQuizByName('Quiz 2')->name);
    }

    public function testRenameRefusedWhenNameTooLong(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');

        $token = $this->getCsrfTokenFromOverview($quiz, '/rename');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/rename', $quiz->id), [
            '_token' => $token,
            'name' => str_repeat('a', 65),
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Quiz 2', $this->getQuizByName('Quiz 2')->name);
    }

    public function testRenameRefusedWhenNameAlreadyExistsInSeason(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');

        $token = $this->getCsrfTokenFromOverview($quiz, '/rename');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/rename', $quiz->id), [
            '_token' => $token,
            'name' => 'Quiz 1',
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertSame('Quiz 2', $this->getQuizByName('Quiz 2')->name);
    }
}
