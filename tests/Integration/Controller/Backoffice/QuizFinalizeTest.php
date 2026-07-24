<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\QuizController;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(QuizController::class)]
final class QuizFinalizeTest extends AbstractControllerWebTestCase
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

    public function testFinalizeSetsFinalizedAt(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');
        $this->assertFalse($quiz->isFinalized);

        $token = $this->getCsrfTokenFromOverview($quiz, '/finalize');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/finalize', $quiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertTrue($this->getQuizByName('Quiz 2')->isFinalized);
    }

    public function testFinalizeRefusedWhenQuizHasErrors(): void
    {
        $season = $this->getSeasonByCode('krtek');

        $invalidQuiz = new Quiz();
        $invalidQuiz->name = 'Invalid Quiz';

        $question = new Question();
        $question->question = 'Vraag zonder goed antwoord';
        $question->ordering = 1;
        $question->addAnswer(new Answer('Fout'));
        $question->addAnswer(new Answer('Ook fout'));

        $invalidQuiz->addQuestion($question);
        $season->addQuiz($invalidQuiz);
        $this->entityManager->persist($invalidQuiz);
        $this->entityManager->flush();

        // Token intention is shared, so any quiz overview provides it
        $token = $this->getCsrfTokenFromOverview($invalidQuiz, '/finalize');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/finalize', $invalidQuiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertFalse($this->getQuizByName('Invalid Quiz')->isFinalized);
    }

    public function testEnableRefusedWhenNotFinalized(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');
        $this->assertFalse($quiz->isFinalized);

        $token = $this->getCsrfTokenFromOverview($quiz, '/enable');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/quiz/%s/enable', $quiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $season = $this->getSeasonByCode('krtek');
        $this->assertInstanceOf(Quiz::class, $season->activeQuiz);
        $this->assertSame('Quiz 1', $season->activeQuiz->name);
    }

    public function testEnableAllowedWhenFinalized(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');
        $quiz->finalizedAt = new DateTimeImmutable();

        $this->entityManager->flush();

        $token = $this->getCsrfTokenFromOverview($quiz, '/enable');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/quiz/%s/enable', $quiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $season = $this->getSeasonByCode('krtek');
        $this->assertInstanceOf(Quiz::class, $season->activeQuiz);
        $this->assertSame('Quiz 2', $season->activeQuiz->name);
    }

    public function testUnfinalize(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');
        $quiz->finalizedAt = new DateTimeImmutable();

        $this->entityManager->flush();

        $token = $this->getCsrfTokenFromOverview($quiz, '/unfinalize');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/unfinalize', $quiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertFalse($this->getQuizByName('Quiz 2')->isFinalized);
    }

    public function testUnfinalizeRefusedWhenQuizIsActive(): void
    {
        // Quiz 1 is finalized and active in the fixtures; scrape a token from Quiz 2 (same intention)
        $quiz2 = $this->getQuizByName('Quiz 2');
        $quiz2->finalizedAt = new DateTimeImmutable();

        $this->entityManager->flush();
        $token = $this->getCsrfTokenFromOverview($quiz2, '/unfinalize');

        $quiz1 = $this->getQuizByName('Quiz 1');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/unfinalize', $quiz1->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertTrue($this->getQuizByName('Quiz 1')->isFinalized);
    }

    public function testUnfinalizeRefusedWhenCandidatesStarted(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');
        $quiz->finalizedAt = new DateTimeImmutable();

        $this->entityManager->flush();

        // Scrape the token before a candidate starts, since the button disappears afterwards
        $token = $this->getCsrfTokenFromOverview($quiz, '/unfinalize');

        $candidate = $this->getCandidate('Tom');
        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $quizCandidate->started = new DateTimeImmutable();

        $this->entityManager->persist($quizCandidate);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/unfinalize', $quiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertTrue($this->getQuizByName('Quiz 2')->isFinalized);
    }

    public function testClearQuizResetsFinalization(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $this->assertTrue($quiz->isFinalized);

        $token = $this->getCsrfTokenFromOverview($quiz, '/clear');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/clear', $quiz->id), ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertFalse($this->getQuizByName('Quiz 1')->isFinalized);
    }

    public function testDeactivateWithRedirectQuizStaysOnQuizOverview(): void
    {
        // Quiz 1 is active in fixtures; deactivate while viewing Quiz 2 → should redirect to Quiz 2 overview
        $this->getQuizByName('Quiz 1');
        $quiz2 = $this->getQuizByName('Quiz 2');

        $token = $this->getCsrfTokenFromOverview($quiz2, '/enable');
        $this->client->request(
            Request::METHOD_POST,
            '/backoffice/season/krtek/quiz/null/enable',
            ['_token' => $token, 'redirect_quiz' => (string) $quiz2->id],
        );

        self::assertResponseRedirects(\sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz2->id));

        $this->entityManager->clear();
        $this->assertNotInstanceOf(Quiz::class, $this->getSeasonByCode('krtek')->activeQuiz);
    }
}
