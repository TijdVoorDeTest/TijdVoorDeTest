<?php

declare(strict_types=1);

namespace Tvdt\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Safe\DateTimeImmutable;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;

final class TestFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public const string PASSWORD = 'test1234';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public static function getGroups(): array
    {
        return ['test'];
    }

    public function getDependencies(): array
    {
        return [KrtekFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->email = 'test@example.org';
        $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);

        $manager->persist($user);

        $user = new User();
        $user->email = 'krtek-admin@example.org';
        $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);

        $manager->persist($user);

        $krtek = $this->getReference(KrtekFixtures::KRTEK_SEASON, Season::class);
        $krtek->addOwner($user);

        // Gives Quiz 4 a real score so it can be carried into an Elimination (which only ever
        // seeds candidates that QuizRepository::getScores() returns) — needed for
        // tests/E2E/EliminationScreenE2ETest.php's real "prepare elimination" browser flow.
        // Deliberately not Quiz 3: a started QuizCandidate makes hasStartedCandidates/isLocked
        // true, which would break tests/E2E/QuestionEditModalE2ETest.php's assumption that
        // Quiz 3's questions are still editable. Deliberately not "Tom": that candidate is
        // relied on by several other tests (e.g. deletion tests) that don't expect a
        // GivenAnswer to already exist for them.
        $quiz4 = $krtek->quizzes->filter(static fn (Quiz $quiz): bool => 'Quiz 4' === $quiz->name)->first();
        \assert($quiz4 instanceof Quiz);
        $elise = $krtek->candidates->filter(static fn (Candidate $candidate): bool => 'Elise' === $candidate->name)->first();
        \assert($elise instanceof Candidate);
        $firstQuestion = $quiz4->questions->first();
        \assert($firstQuestion instanceof Question);
        $firstAnswer = $firstQuestion->answers->first();
        \assert($firstAnswer instanceof Answer);

        $quizCandidate = new QuizCandidate($quiz4, $elise);
        $quizCandidate->started = new DateTimeImmutable();

        $manager->persist($quizCandidate);
        $manager->persist(new GivenAnswer($elise, $quiz4, $firstAnswer));

        $anotherSeason = new Season();
        $anotherSeason->name = 'Another Season';
        $anotherSeason->seasonCode = 'bbbbb';

        $manager->persist($anotherSeason);
        $this->addReference('another-season', $anotherSeason);

        $user = new User();
        $user->email = 'user1@example.org';
        $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);

        $manager->persist($user);
        $user->addSeason($anotherSeason);

        $user = new User();
        $user->email = 'user2@example.org';
        $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);

        $manager->persist($user);

        $krtek->addOwner($user);
        $anotherSeason->addOwner($user);

        $soleOwner = new User();
        $soleOwner->email = 'sole-owner@example.org';
        $soleOwner->password = $this->passwordHasher->hashPassword($soleOwner, self::PASSWORD);

        $manager->persist($soleOwner);

        $doomedSeason = new Season();
        $doomedSeason->name = 'Doomed Season';
        $doomedSeason->seasonCode = 'doomd';
        $doomedSeason->addCandidate(new Candidate('Vera'));

        $quiz = new Quiz();
        $quiz->name = 'Doomed Quiz';

        $question = new Question();
        $question->question = 'Wie is de Krtek?';
        $question->ordering = 1;
        $question->addAnswer(new Answer('Vera', true));

        $quiz->addQuestion($question);
        $doomedSeason->addQuiz($quiz);

        $manager->persist($doomedSeason);

        $doomedSeason->addOwner($soleOwner);
        $anotherSeason->addOwner($soleOwner);

        $manager->flush();
    }
}
