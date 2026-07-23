<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\QuizController;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(QuizController::class)]
final class QuizControllerTest extends AbstractControllerWebTestCase
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

    public function testIndexRedirectsToOverview(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s', $quiz->id));

        self::assertResponseRedirects(\sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz->id));
    }

    public function testOverviewLoadsSuccessfully(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Quiz 1');
    }

    public function testResultTabLoadsSuccessfully(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/result', $quiz->id));

        self::assertResponseIsSuccessful();
    }

    public function testCandidatesTabLoadsSuccessfully(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/candidates-list', $quiz->id));

        self::assertResponseIsSuccessful();
    }

    public function testAnswerMappingRedirectsToFirstQuestion(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/answer-mapping', $quiz->id));

        self::assertResponseRedirects();
        $this->assertStringContainsString('/candidates/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCandidatesQuestionTabLoadsSuccessfully(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/candidates/%s', $quiz->id, $question->id));

        self::assertResponseIsSuccessful();
    }

    public function testCandidatesQuestionRejectsQuestionFromAnotherQuiz(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $otherQuiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['name' => 'Doomed Quiz']);
        $this->assertInstanceOf(Quiz::class, $otherQuiz);
        $otherQuestion = $otherQuiz->questions->first();
        $this->assertInstanceOf(Question::class, $otherQuestion);

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/candidates/%s', $quiz->id, $otherQuestion->id));

        self::assertResponseStatusCodeSame(400);
    }

    public function testSaveCandidateAnswersPersistsSelection(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);
        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $candidate = $this->getCandidate('Tom');

        $url = \sprintf('/backoffice/season/krtek/quiz/%s/candidates/%s', $quiz->id, $question->id);
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        $token = $this->getCsrfTokenFromCurrentPage('/candidates/');

        $this->client->request(Request::METHOD_POST, $url, [
            '_token' => $token,
            'candidate_answer' => [
                (string) $candidate->id => [(string) $answer->id],
            ],
        ]);

        $this->assertResponseRedirects($url);
        $this->entityManager->clear();

        $savedAnswer = $this->entityManager->getRepository(Answer::class)->find($answer->id);
        $this->assertInstanceOf(Answer::class, $savedAnswer);
        $this->assertTrue($savedAnswer->candidates->exists(
            static fn (int $key, Candidate $c): bool => $c->id->equals($candidate->id),
        ));
    }

    public function testToggleCandidateCreatesInactiveQuizCandidate(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/candidates-list', $quiz->id));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter(\sprintf('form[action*="/%s/toggle"] input[name="_token"]', $candidate->id))->first()->attr('value');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/candidate/%s/toggle', $quiz->id, $candidate->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $quizCandidate = $this->entityManager->getRepository(QuizCandidate::class)->findOneBy([
            'quiz' => $this->getQuizByName('Quiz 1'),
            'candidate' => $this->getCandidate('Tom'),
        ]);
        $this->assertInstanceOf(QuizCandidate::class, $quizCandidate);
        $this->assertFalse($quizCandidate->active);
    }

    public function testToggleCandidateTogglesActiveState(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');

        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $quizCandidate->active = false;

        $this->entityManager->persist($quizCandidate);
        $this->entityManager->flush();

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/candidates-list', $quiz->id));
        $token = (string) $crawler->filter(\sprintf('form[action*="/%s/toggle"] input[name="_token"]', $candidate->id))->first()->attr('value');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/candidate/%s/toggle', $quiz->id, $candidate->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $updated = $this->entityManager->getRepository(QuizCandidate::class)->findOneBy([
            'quiz' => $this->getQuizByName('Quiz 1'),
            'candidate' => $this->getCandidate('Tom'),
        ]);
        $this->assertInstanceOf(QuizCandidate::class, $updated);
        $this->assertTrue($updated->active);
    }

    public function testResetCandidateProgressDeletesGivenAnswersAndClearsStarted(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');

        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $quizCandidate->started = new DateTimeImmutable();

        $this->entityManager->persist($quizCandidate);
        $firstQuestion = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $firstQuestion);
        $answer = $firstQuestion->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $this->entityManager->persist(new GivenAnswer($candidate, $quiz, $answer));
        $this->entityManager->flush();

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/candidates-list', $quiz->id));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter(\sprintf('form[action*="/%s/reset"] input[name="_token"]', $candidate->id))->first()->attr('value');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/candidate/%s/reset', $quiz->id, $candidate->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $updated = $this->entityManager->getRepository(QuizCandidate::class)->findOneBy([
            'quiz' => $this->getQuizByName('Quiz 1'),
            'candidate' => $this->getCandidate('Tom'),
        ]);
        $this->assertInstanceOf(QuizCandidate::class, $updated);
        $this->assertNotInstanceOf(\DateTimeImmutable::class, $updated->started);

        $remainingAnswers = $this->entityManager->getRepository(GivenAnswer::class)->findBy([
            'quiz' => $quiz,
            'candidate' => $candidate,
        ]);
        $this->assertCount(0, $remainingAnswers);
    }

    public function testModifyResult(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');

        // getScores() requires started IS NOT NULL and at least one GivenAnswer
        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $quizCandidate->started = new DateTimeImmutable();

        $this->entityManager->persist($quizCandidate);
        $firstQuestion = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $firstQuestion);
        $answer = $firstQuestion->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $this->entityManager->persist(new GivenAnswer($candidate, $quiz, $answer));
        $this->entityManager->flush();

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/result', $quiz->id));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter(\sprintf('form[action*="%s/modify_result"] input[name="_token"]', $candidate->id))->first()->attr('value');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/candidate/%s/modify_result', $quiz->id, $candidate->id), [
            '_token' => $token,
            'corrections' => '1.5',
            'penalty' => '30',
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $updated = $this->entityManager->getRepository(QuizCandidate::class)->findOneBy([
            'quiz' => $this->getQuizByName('Quiz 1'),
            'candidate' => $this->getCandidate('Tom'),
        ]);
        $this->assertInstanceOf(QuizCandidate::class, $updated);
        $this->assertEqualsWithDelta(1.5, $updated->corrections, \PHP_FLOAT_EPSILON);
        $this->assertSame(30, $updated->penaltySeconds);
    }

    public function testModifyDropouts(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/season/krtek/quiz/%s/result', $quiz->id), '/modify_dropouts');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/modify_dropouts', $quiz->id), [
            '_token' => $token,
            'dropouts' => '2',
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $updated = $this->getQuizByName('Quiz 1');
        $this->assertSame(2, $updated->dropouts);
    }

    public function testModifyDropoutsClampsToOne(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/season/krtek/quiz/%s/result', $quiz->id), '/modify_dropouts');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/modify_dropouts', $quiz->id), [
            '_token' => $token,
            'dropouts' => '0',
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $updated = $this->getQuizByName('Quiz 1');
        $this->assertSame(1, $updated->dropouts);
    }

    public function testDeleteQuiz(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');
        $quizId = $quiz->id;
        $token = $this->getCsrfTokenFromOverview($quiz, '/delete');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/quiz/%s/delete', $quiz->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek');
        $this->entityManager->clear();
        $this->assertNotInstanceOf(Quiz::class, $this->entityManager->getRepository(Quiz::class)->find($quizId));
    }

    public function testNonOwnerIsDenied(): void
    {
        $this->loginAs('test@example.org');

        $quiz = $this->getQuizByName('Quiz 1');
        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz->id));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOverviewLoadsForEmptyQuiz(): void
    {
        $season = $this->getSeasonByCode('krtek');

        $emptyQuiz = new Quiz();
        $emptyQuiz->name = 'Empty Quiz';

        $season->addQuiz($emptyQuiz);
        $this->entityManager->persist($emptyQuiz);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/overview', $emptyQuiz->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Empty Quiz');
    }

    public function testAnswerMappingRedirectsWithFlashWhenNoQuestions(): void
    {
        $season = $this->getSeasonByCode('krtek');

        $emptyQuiz = new Quiz();
        $emptyQuiz->name = 'Empty Quiz';

        $season->addQuiz($emptyQuiz);
        $this->entityManager->persist($emptyQuiz);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/answer-mapping', $emptyQuiz->id));

        self::assertResponseRedirects(\sprintf('/backoffice/season/krtek/quiz/%s/overview', $emptyQuiz->id));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Deze test heeft nog geen vragen');
    }
}
