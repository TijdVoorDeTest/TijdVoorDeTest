<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\QuizController;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Helpers\Base64;

#[CoversClass(QuizController::class)]
final class QuizControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Season-code guessing is rate-limited; start each test with a clean quota.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function answerQuestion(Question $question): void
    {
        $tomHash = Base64::base64UrlEncode('Tom');
        $url = \sprintf('/krtek/%s', $tomHash);

        $crawler = $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="token"]')->first()->attr('value');

        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);

        $this->client->request(Request::METHOD_POST, $url, [
            'token' => $token,
            'answer' => (string) $answer->id,
        ]);

        self::assertResponseRedirects($url);
    }

    public function testSelectSeasonPageLoads(): void
    {
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testSelectSeasonWithInvalidCodeRedirectsWithFlash(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/');
        $form = $crawler->filter('form')->form([
            'select_season[season_code]' => 'aaaaa',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Ongeldige seizoencode');
    }

    public function testSelectSeasonWithValidCodeRedirectsToEnterName(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/');
        $form = $crawler->filter('form')->form([
            'select_season[season_code]' => 'krtek',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/krtek');
    }

    public function testSelectSeasonIsThrottledAfterTooManyAttempts(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $crawler = $this->client->request(Request::METHOD_GET, '/');
            $form = $crawler->filter('form')->form([
                'select_season[season_code]' => 'aaaaa',
            ]);
            $this->client->submit($form);
            self::assertResponseRedirects('/');
        }

        $crawler = $this->client->request(Request::METHOD_GET, '/');
        $form = $crawler->filter('form')->form([
            'select_season[season_code]' => 'aaaaa',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(429);
    }

    public function testEnterNamePageLoads(): void
    {
        $this->client->request(Request::METHOD_GET, '/krtek');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEnterNameRedirectsToQuizPage(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/krtek');
        $form = $crawler->filter('form')->form([
            'enter_name[name]' => 'Tom',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/krtek/%s', Base64::base64UrlEncode('Tom')));
    }

    public function testQuizPageUnknownCandidateRedirectsWithFlash(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/krtek/%s', Base64::base64UrlEncode('Nobody')));

        self::assertResponseRedirects('/krtek');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Kandidaat niet gevonden');
    }

    public function testQuizPageWithoutActiveQuizRedirectsWithFlash(): void
    {
        $season = $this->getSeasonByCode('bbbbb');
        $season->addCandidate(new Candidate('Nienke'));

        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/bbbbb/%s', Base64::base64UrlEncode('Nienke')));

        self::assertResponseRedirects('/bbbbb');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Er is geen test actief');
    }

    public function testQuizPageRendersFirstQuestion(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/krtek/%s', Base64::base64UrlEncode('Tom')));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Is de Krtek een man of een vrouw?');
    }

    public function testQuizPageAnsweringPersistsGivenAnswerAndRedirects(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $firstQuestion = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $firstQuestion);
        $answer = $firstQuestion->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);

        $this->answerQuestion($firstQuestion);
        $this->entityManager->clear();

        $candidate = $this->getCandidate('Tom');
        $givenAnswer = $this->entityManager->getRepository(GivenAnswer::class)->findOneBy(['candidate' => $candidate]);
        $this->assertInstanceOf(GivenAnswer::class, $givenAnswer);
        $this->assertTrue($answer->id->equals($givenAnswer->answer->id));
    }

    public function testQuizPageInvalidAnswerIdShowsFlash(): void
    {
        $url = \sprintf('/krtek/%s', Base64::base64UrlEncode('Tom'));
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        $token = (string) $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request(Request::METHOD_POST, $url, [
            'token' => $token,
            'answer' => '00000000-0000-0000-0000-000000000000',
        ]);

        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Selecteer een antwoorden alsjeblieft');
    }

    public function testQuizPageOutOfOrderAnswerShowsFlash(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $secondQuestion = $quiz->questions->get(1);
        $this->assertInstanceOf(Question::class, $secondQuestion);
        $answer = $secondQuestion->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);

        $url = \sprintf('/krtek/%s', Base64::base64UrlEncode('Tom'));
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        $token = (string) $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request(Request::METHOD_POST, $url, [
            'token' => $token,
            'answer' => (string) $answer->id,
        ]);

        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Je kan deze vraag niet beantwoorden');
    }

    public function testQuizPageCompletedShowsFlashAndRedirects(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');

        foreach ($quiz->questions as $question) {
            $this->answerQuestion($question);
        }

        $this->client->request(Request::METHOD_GET, \sprintf('/krtek/%s', Base64::base64UrlEncode('Tom')));

        self::assertResponseRedirects('/krtek');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Test voltooid');
    }

    public function testQuizPageInactiveCandidateIsBlocked(): void
    {
        $quiz = $this->getQuizByName('Quiz 1');
        $candidate = $this->getCandidate('Tom');

        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $quizCandidate->active = false;

        $this->entityManager->persist($quizCandidate);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/krtek/%s', Base64::base64UrlEncode('Tom')));

        self::assertResponseRedirects('/krtek');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Je mag deze test niet beantwoorden');
    }
}
