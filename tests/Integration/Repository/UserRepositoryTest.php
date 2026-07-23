<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tvdt\DataFixtures\TestFixtures;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Enum\ScreenColour;
use Tvdt\Repository\UserRepository;

use function PHPUnit\Framework\assertEmpty;

#[CoversClass(UserRepository::class)]
final class UserRepositoryTest extends DatabaseTestCase
{
    public function testUpgradePassword(): void
    {
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->getUserByEmail('user1@example.org');

        $newHash = $passwordHasher->hashPassword($user, TestFixtures::PASSWORD);

        $this->assertNotSame($newHash, $user->password);
        $this->userRepository->upgradePassword($user, $newHash);

        $this->entityManager->refresh($user);
        $this->assertSame($newHash, $user->password);
    }

    public function testMakeAdmin(): void
    {
        $user = $this->getUserByEmail('test@example.org');
        assertEmpty($user->roles);
        $this->userRepository->makeAdmin('test@example.org');
        $this->entityManager->refresh($user);
        $this->assertSame(['ROLE_ADMIN'], $user->roles);
    }

    public function testMakeAdminInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->userRepository->makeAdmin('invalid@example.org');
    }

    /**
     * GDPR right-to-erasure: deleting the sole owner of a season must physically remove every
     * row tied to it, not merely soft-delete it. QuizCandidate, GivenAnswer, and Elimination are
     * all Gedmo\SoftDeleteable, so a naive $em->remove($season) cascade leaves them (or the
     * transaction itself) behind. Assertions bypass the softdeleteable filter and read raw SQL,
     * since a soft-deleted row would otherwise still be invisible to a filtered ORM query.
     */
    public function testDeleteUserHardDeletesQuizCandidateGivenAnswerAndElimination(): void
    {
        $user = $this->getUserByEmail('sole-owner@example.org');
        $season = $this->getSeasonByCode('doomd');

        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['name' => 'Doomed Quiz', 'season' => $season]);
        $this->assertInstanceOf(Quiz::class, $quiz);
        $candidate = $this->getCandidateBySeasonAndName($season, 'Vera');

        /** @var Question $question */
        $question = $quiz->questions->first();
        $rightAnswer = $question->answers->first();
        $this->assertNotFalse($rightAnswer);

        $this->quizCandidateRepository->createIfNotExist($quiz, $candidate);
        $quizCandidate = $this->quizCandidateRepository->findOneBy(['quiz' => $quiz, 'candidate' => $candidate]);
        $this->assertInstanceOf(QuizCandidate::class, $quizCandidate);

        $givenAnswer = new GivenAnswer($candidate, $quiz, $rightAnswer);
        $this->entityManager->persist($givenAnswer);

        $elimination = new Elimination($quiz);
        $elimination->data = ['Vera' => ScreenColour::Green->value];

        $this->entityManager->persist($elimination);

        $this->entityManager->flush();

        $quizCandidateId = $quizCandidate->id->toString();
        $givenAnswerId = $givenAnswer->id->toString();
        $eliminationId = $elimination->id->toString();

        $this->userRepository->deleteUser($user);
        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from quiz_candidate where id = ?', [$quizCandidateId]));
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from given_answer where id = ?', [$givenAnswerId]));
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from elimination where id = ?', [$eliminationId]));
    }

    /**
     * Gedmo\Loggable writes an audit row (including the editor's username/email) to
     * ext_log_entries for every change to a Versioned field. Those rows aren't linked via a
     * foreign key (object_id is a plain string), so deleting the season/BankQuestion never
     * cleans them up on its own — the deleted account's email would otherwise live on forever.
     */
    public function testDeleteUserPurgesBankQuestionAuditLogEntries(): void
    {
        $user = $this->getUserByEmail('sole-owner@example.org');
        $season = $this->getSeasonByCode('doomd');

        $bankQuestion = new BankQuestion();
        $bankQuestion->question = 'Wie is de Krtek eigenlijk?';
        $bankQuestion->season = $season;

        $this->entityManager->persist($bankQuestion);
        $this->entityManager->flush();

        $bankQuestionId = $bankQuestion->id->toString();
        $connection = $this->entityManager->getConnection();

        $logCountBefore = (int) $connection->fetchOne(
            'select count(*) from ext_log_entries where object_class = ? and object_id = ?',
            [BankQuestion::class, $bankQuestionId],
        );
        $this->assertGreaterThan(0, $logCountBefore);

        $this->userRepository->deleteUser($user);
        $this->entityManager->clear();

        $logCountAfter = (int) $connection->fetchOne(
            'select count(*) from ext_log_entries where object_class = ? and object_id = ?',
            [BankQuestion::class, $bankQuestionId],
        );
        $this->assertSame(0, $logCountAfter);
    }
}
