<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;
use Tvdt\Repository\CandidateRepository;
use Tvdt\Repository\QuestionRepository;
use Tvdt\Repository\QuizCandidateRepository;
use Tvdt\Repository\QuizRepository;
use Tvdt\Repository\SeasonRepository;
use Tvdt\Repository\UserRepository;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected private(set) EntityManagerInterface $entityManager;

    protected private(set) CandidateRepository $candidateRepository;

    protected private(set) QuestionRepository $questionRepository;

    protected private(set) QuizCandidateRepository $quizCandidateRepository;

    protected private(set) QuizRepository $quizRepository;

    protected private(set) SeasonRepository $seasonRepository;

    protected private(set) UserRepository $userRepository;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->candidateRepository = self::getContainer()->get(CandidateRepository::class);
        $this->questionRepository = self::getContainer()->get(QuestionRepository::class);
        $this->quizCandidateRepository = self::getContainer()->get(QuizCandidateRepository::class);
        $this->quizRepository = self::getContainer()->get(QuizRepository::class);
        $this->seasonRepository = self::getContainer()->get(SeasonRepository::class);
        $this->userRepository = self::getContainer()->get(UserRepository::class);
    }

    protected function getUserByEmail(string $email): User
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    protected function getSeasonByCode(string $code): Season
    {
        $season = $this->seasonRepository->findOneBySeasonCode($code);
        $this->assertInstanceOf(Season::class, $season);

        return $season;
    }

    protected function getCandidateBySeasonAndName(Season $season, string $name): Candidate
    {
        $candidate = $this->candidateRepository->findOneBy(['season' => $season, 'name' => $name]);
        $this->assertInstanceOf(Candidate::class, $candidate);

        return $candidate;
    }
}
