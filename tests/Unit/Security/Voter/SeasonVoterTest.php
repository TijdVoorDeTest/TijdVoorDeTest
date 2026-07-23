<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Security\Voter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Safe\DateTimeImmutable;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Tvdt\Entity\Answer;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuestionLabel;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;
use Tvdt\Security\Voter\SeasonVoter;

#[CoversClass(SeasonVoter::class)]
final class SeasonVoterTest extends TestCase
{
    private SeasonVoter $seasonVoter;

    private TokenInterface&Stub $token;

    protected function setUp(): void
    {
        $this->seasonVoter = new SeasonVoter();
        $this->token = $this->createStub(TokenInterface::class);

        $user = $this->createStub(User::class);
        $this->token->method('getUser')->willReturn($user);
    }

    #[DataProvider('typesProvider')]
    public function testWithTypes(mixed $subject): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->seasonVoter->vote($this->token, $subject, ['SEASON_EDIT']));
    }

    public function testNotOwnerWillReturnDenied(): void
    {
        $season = self::createStub(Season::class);
        $season->method('isOwner')->willReturn(false);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->seasonVoter->vote($this->token, $season, ['SEASON_EDIT']));
    }

    public static function typesProvider(): \Generator
    {
        $season = self::createStub(Season::class);
        $season->method('isOwner')->willReturn(true);
        yield 'Season' => [$season];

        $candidate = self::createStub(Candidate::class);
        $candidate->season = $season;
        yield 'Candidate' => [$candidate];

        $quiz = self::createStub(Quiz::class);
        $quiz->season = $season;
        yield 'Quiz' => [$quiz];

        $elimination = self::createStub(Elimination::class);
        $elimination->quiz = $quiz;
        yield 'Elimination' => [$elimination];

        $question = self::createStub(Question::class);
        $question->quiz = $quiz;
        yield 'Question' => [$question];

        $answer = self::createStub(Answer::class);
        $answer->question = $question;
        yield 'Answer' => [$answer];

        $bankQuestion = self::createStub(BankQuestion::class);
        $bankQuestion->season = $season;
        yield 'BankQuestion' => [$bankQuestion];

        $questionLabel = self::createStub(QuestionLabel::class);
        $questionLabel->season = $season;
        yield 'QuestionLabel' => [$questionLabel];
    }

    public function testModifyQuizContentGrantedOnUnlockedQuiz(): void
    {
        $season = self::createStub(Season::class);
        $season->method('isOwner')->willReturn(true);

        $quiz = new Quiz();
        $quiz->season = $season;
        // finalizedAt = null, candidateData empty → isLocked = false

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->seasonVoter->vote($this->token, $quiz, [SeasonVoter::MODIFY_QUIZ_CONTENT]));
    }

    public function testModifyQuizContentDeniedOnLockedQuiz(): void
    {
        $season = self::createStub(Season::class);
        $season->method('isOwner')->willReturn(true);

        $quiz = new Quiz();
        $quiz->season = $season;
        $quiz->finalizedAt = new DateTimeImmutable(); // → isLocked = true

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->seasonVoter->vote($this->token, $quiz, [SeasonVoter::MODIFY_QUIZ_CONTENT]));
    }

    public function testModifyQuizContentDeniedOnLockedQuizForAdmin(): void
    {
        $user = new User();
        $user->roles = ['ROLE_ADMIN'];

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $quiz = new Quiz();
        $quiz->season = self::createStub(Season::class);
        $quiz->finalizedAt = new DateTimeImmutable(); // → isLocked = true

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->seasonVoter->vote($token, $quiz, [SeasonVoter::MODIFY_QUIZ_CONTENT]));
    }

    public function testModifyQuizContentDeniedOnNonQuizSubject(): void
    {
        $season = self::createStub(Season::class);
        $season->method('isOwner')->willReturn(true);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->seasonVoter->vote($this->token, $season, [SeasonVoter::MODIFY_QUIZ_CONTENT]));
    }

    public function testWrongUserTypeReturnFalse(): void
    {
        $user = self::createStub(UserInterface::class);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->seasonVoter->vote($token, new Season(), ['SEASON_EDIT']));
    }

    public function testAdminCanDoAnything(): void
    {
        $user = new User();
        $user->roles = ['ROLE_ADMIN'];

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->seasonVoter->vote($token, new Season(), ['SEASON_EDIT']));
    }

    public function testRandomClassWillAbstain(): void
    {
        $subject = new \stdClass();
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->seasonVoter->vote($this->token, $subject, ['SEASON_EDIT']));
    }

    public function testRandomSunjectWillAbstain(): void
    {
        $subject = new Season();
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->seasonVoter->vote($this->token, $subject, ['DO_NOTHING']));
    }
}
