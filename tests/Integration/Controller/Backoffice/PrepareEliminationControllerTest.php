<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\PrepareEliminationController;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\EliminationScreenView;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Enum\ScreenColour;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(PrepareEliminationController::class)]
final class PrepareEliminationControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('krtek-admin@example.org');
    }

    public function testIndexCreatesEliminationAndRedirectsToView(): void
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

        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/season/krtek/quiz/%s/result', $quiz->id), '/elimination/prepare');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/quiz/%s/elimination/prepare', $quiz->id), [
            '_token' => $token,
        ]);

        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('/backoffice/elimination/', (string) $response->headers->get('Location'));

        $this->entityManager->clear();
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = $this->entityManager->getRepository(Elimination::class)->findOneBy(['quiz' => $quiz]);
        $this->assertInstanceOf(Elimination::class, $elimination);
        $this->assertArrayHasKey('Tom', $elimination->data);
    }

    public function testViewEliminationPageLoads(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/elimination/%s', $elimination->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testViewEliminationPageShowsScreenViewLog(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');
        $elimination = new Elimination($quiz);
        // Elimination's current colour is red, but the logged screen view was green: the log must show the
        // historical view colour, not whatever the elimination happens to say now.
        $elimination->data = ['Tom' => ScreenColour::Red->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->persist(new EliminationScreenView($elimination, $candidate, ScreenColour::Green));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/elimination/%s', $elimination->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Tom');
        self::assertSelectorTextContains('table', 'Groen');
    }

    public function testViewEliminationPageHidesScreenViewLogWhenEmpty(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/elimination/%s', $elimination->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('table');
    }

    public function testViewEliminationShowsCandidatesOrderedByScoreWithTime(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $tom = $this->getCandidate('Tom');
        $claudia = $this->getCandidate('Claudia');
        $iris = $this->getCandidate('Iris');

        $questions = $quiz->questions;
        $firstQuestion = $questions->first();
        $secondQuestion = $questions->get(1);
        $this->assertInstanceOf(Question::class, $firstQuestion);
        $this->assertInstanceOf(Question::class, $secondQuestion);
        $firstAnswer = $firstQuestion->answers->filter(static fn (Answer $answer): bool => $answer->isRightAnswer)->first();
        $secondAnswer = $secondQuestion->answers->filter(static fn (Answer $answer): bool => $answer->isRightAnswer)->first();
        $this->assertInstanceOf(Answer::class, $firstAnswer);
        $this->assertInstanceOf(Answer::class, $secondAnswer);

        // Tom answers both questions correctly: highest score, ranks first.
        // Claudia and Iris both answer only the first question correctly (tied score), but Claudia's answer is
        // persisted a full second before Iris's: the tie must be broken by elapsed time, not insertion order.
        // A real sleep (rather than a mocked clock) is used because `given_answer.created` is TIMESTAMP(0) —
        // second precision — and the container's clock is already initialised by client/login by this point.
        $started = new DateTimeImmutable();
        $tomQuizCandidate = new QuizCandidate($quiz, $tom);
        $tomQuizCandidate->started = $started;

        $this->entityManager->persist($tomQuizCandidate);

        $claudiaQuizCandidate = new QuizCandidate($quiz, $claudia);
        $claudiaQuizCandidate->started = $started;

        $this->entityManager->persist($claudiaQuizCandidate);

        $irisQuizCandidate = new QuizCandidate($quiz, $iris);
        $irisQuizCandidate->started = $started;

        $this->entityManager->persist($irisQuizCandidate);

        $this->entityManager->persist(new GivenAnswer($tom, $quiz, $firstAnswer));
        $this->entityManager->persist(new GivenAnswer($tom, $quiz, $secondAnswer));
        $this->entityManager->persist(new GivenAnswer($claudia, $quiz, $firstAnswer));
        $this->entityManager->flush();

        sleep(1);
        $this->entityManager->persist(new GivenAnswer($iris, $quiz, $firstAnswer));

        $elimination = new Elimination($quiz);
        $elimination->data = ['Claudia' => ScreenColour::Green->value, 'Tom' => ScreenColour::Green->value, 'Iris' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/elimination/%s', $elimination->id));

        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('label')->each(static fn ($node): string => mb_trim((string) $node->text()));
        $this->assertSame(['Tom', 'Claudia', 'Iris'], $labels);
    }

    public function testViewEliminationSavesUpdatedColours(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $token = $this->getTokenFromPage(\sprintf('/backoffice/elimination/%s', $elimination->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/elimination/%s', $elimination->id), [
            '_token' => $token,
            'colour-tom' => ScreenColour::Red->value,
            'start' => '0',
        ]);

        self::assertResponseRedirects(\sprintf('/backoffice/elimination/%s', $elimination->id));
        $this->entityManager->clear();

        $updated = $this->entityManager->getRepository(Elimination::class)->find($elimination->id);
        $this->assertInstanceOf(Elimination::class, $updated);
        $this->assertSame(ScreenColour::Red->value, $updated->data['Tom']);
    }

    public function testViewEliminationWithStartRedirectsToPublicElimination(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $token = $this->getTokenFromPage(\sprintf('/backoffice/elimination/%s', $elimination->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/elimination/%s', $elimination->id), [
            '_token' => $token,
            'start' => '1',
        ]);

        self::assertResponseRedirects(\sprintf('/elimination/%s', $elimination->id));
    }

    public function testDeleteEliminationRemovesEliminationAndRedirectsToQuiz(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $screenView = new EliminationScreenView($elimination, $candidate, ScreenColour::Green);
        $this->entityManager->persist($screenView);
        $this->entityManager->flush();

        $eliminationId = $elimination->id;
        $screenViewId = $screenView->id;

        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/elimination/%s', $elimination->id), \sprintf('/backoffice/elimination/%s/delete', $elimination->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/elimination/%s/delete', $elimination->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects(\sprintf('/backoffice/season/krtek/quiz/%s', $quiz->id));
        $this->entityManager->clear();

        $this->assertNotInstanceOf(Elimination::class, $this->entityManager->getRepository(Elimination::class)->find($eliminationId));
        $this->assertNotInstanceOf(EliminationScreenView::class, $this->entityManager->getRepository(EliminationScreenView::class)->find($screenViewId));
    }

    public function testDeleteEliminationIsDeniedForNonOwner(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/elimination/%s', $elimination->id), \sprintf('/backoffice/elimination/%s/delete', $elimination->id));

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/elimination/%s/delete', $elimination->id), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexIsDeniedForNonOwner(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/season/krtek/quiz/%s/result', $quiz->id), '/elimination/prepare');

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/quiz/%s/elimination/prepare', $quiz->id), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testViewEliminationIsDeniedForNonOwner(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);
        $this->entityManager->flush();

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/elimination/%s', $elimination->id));

        self::assertResponseStatusCodeSame(403);
    }
}
