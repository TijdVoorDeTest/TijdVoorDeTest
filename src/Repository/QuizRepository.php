<?php

declare(strict_types=1);

namespace Tvdt\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Safe\DateTimeImmutable;
use Safe\Exceptions\DatetimeException;
use Symfony\Component\Uid\Uuid;
use Tvdt\Dto\Result;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Exception\ErrorClearingQuizException;

/** @extends ServiceEntityRepository<Quiz> */
class QuizRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly LoggerInterface $logger)
    {
        parent::__construct($registry, Quiz::class);
    }

    /**
     * Quizzes of the season that can still receive bank questions:
     * not finalized and not started by any candidate.
     *
     * @return list<Quiz>
     */
    public function findAssignableForSeason(Season $season): array
    {
        /* @var list<Quiz> */
        return $this->getEntityManager()->createQuery(<<<DQL
            select q from Tvdt\Entity\Quiz q
            where q.season = :season
            and q.finalizedAt is null
            and not exists (
                select 1 from Tvdt\Entity\QuizCandidate qc
                where qc.quiz = q and qc.started is not null
            )
            order by q.id asc
            DQL)
            ->setParameter('season', $season)
            ->getResult();
    }

    /** @throws ErrorClearingQuizException */
    public function clearQuiz(Quiz $quiz): void
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();
        try {
            $em->createQuery(<<<DQL
                delete from Tvdt\Entity\QuizCandidate qc
                where qc.quiz = :quiz
                DQL)
                ->setParameter('quiz', $quiz)
                ->execute();

            $em->createQuery(<<<DQL
                delete from Tvdt\Entity\GivenAnswer ga
                where ga.quiz = :quiz
                DQL)
                ->setParameter('quiz', $quiz)
                ->execute();

            $em->createQuery(<<<DQL
                delete from Tvdt\Entity\Elimination e
                where e.quiz = :quiz
                DQL)
                ->setParameter('quiz', $quiz)
                ->execute();

            $em->createQuery(<<<DQL
                delete from Tvdt\Entity\BankQuestionUsage bqu
                where bqu.quiz = :quiz
                DQL)
                ->setParameter('quiz', $quiz)
                ->execute();

            $em->createQuery(<<<DQL
                update Tvdt\Entity\Quiz q set q.finalizedAt = null
                where q = :quiz
                DQL)
                ->setParameter('quiz', $quiz)
                ->execute();
        }
        // @codeCoverageIgnoreStart
        catch (\Throwable $throwable) {
            $this->logger->error($throwable->getMessage());
            $em->rollback();
            throw new ErrorClearingQuizException(message: $throwable->getMessage(), code: $throwable->getCode(), previous: $throwable);
        }

        // @codeCoverageIgnoreEnd

        $em->commit();
    }

    public function deleteQuiz(Quiz $quiz): void
    {
        $this->getEntityManager()->remove($quiz);
        $this->getEntityManager()->flush();
    }

    /**
     * @throws DatetimeException
     *
     * @return list<Result>
     */
    public function getScores(Quiz $quiz): array
    {
        $result = $this->getEntityManager()->createQuery(<<<DQL
            select
                c.id,
                c.name,
                sum(case when a.isRightAnswer = true then 1 else 0 end) as correct,
                qd.corrections,
                qd.penaltySeconds,
                max(ga.created) as end_time,
                qd.started as start_time,
                (sum(case when a.isRightAnswer = true then 1 else 0 end) + qd.corrections) as score
            from Tvdt\Entity\Candidate c
            join c.givenAnswers ga
            join ga.answer a
            join c.quizData qd
            where qd.quiz = :quiz and ga.quiz = :quiz and qd.started is not null
            group by ga.quiz, c.id, qd.id
            order by score desc, max(ga.created) - qd.started asc
            DQL
        )->setParameter('quiz', $quiz)->getResult();

        return array_map(static function (array $row): Result {
            \assert($row['start_time'] instanceof \DateTimeImmutable);

            return new Result(
                id: $row['id'],
                name: $row['name'],
                correct: (int) $row['correct'],
                corrections: $row['corrections'],
                penaltySeconds: $row['penaltySeconds'],
                time: $row['start_time']->diff(new DateTimeImmutable($row['end_time'])),
                score: $row['score'],
            );
        }, $result);
    }

    public function fetchWithQuestions(Uuid $id): Quiz
    {
        return $this->getEntityManager()->createQuery(<<<dql
            select q, qz, a from Tvdt\Entity\Quiz q
            left join q.questions qz
            left join qz.answers a
            where q.id = :id
            dql)->setParameter('id', $id)->getSingleResult();
    }

    /**
     * Fetch quiz with all relations needed for error checking and status computation (questions,
     * answers, answer candidates, candidate data, given answers, and elimination screen views).
     * Split into three queries: joining season.candidates into the same query as the
     * questions/answers tree would cross-multiply two independent to-many collections into a
     * cartesian product, which is expensive to hydrate for a quiz with many questions and
     * candidates. Eliminations/screenViews are hydrated in their own query for the same reason
     * (independent to-many collection off Quiz).
     */
    public function fetchWithQuestionsAndCandidates(Uuid $id): Quiz
    {
        $em = $this->getEntityManager();

        $em->createQuery(<<<dql
            select q, qz, a, ac from Tvdt\Entity\Quiz q
            left join q.questions qz
            left join qz.answers a
            left join a.candidates ac
            where q.id = :id
            order by qz.ordering asc, a.ordering asc, a.id asc
            dql)->setParameter('id', $id)->getSingleResult();

        $em->createQuery(<<<dql
            select q, qc, c, ga from Tvdt\Entity\Quiz q
            left join q.candidateData qc
            left join qc.candidate c
            left join c.givenAnswers ga with ga.quiz = q
            where q.id = :id
            dql)->setParameter('id', $id)->getSingleResult();

        /* @var Quiz */
        return $em->createQuery(<<<dql
            select q, e, sv from Tvdt\Entity\Quiz q
            left join q.eliminations e
            left join e.screenViews sv
            where q.id = :id
            dql)->setParameter('id', $id)->getSingleResult();
    }

    /**
     * Eager-load, for every quiz in a season, all the data `Quiz::$status` reads: questions,
     * candidate data with each candidate's given answers, and eliminations with their screen
     * views. Three queries total regardless of how many quizzes, candidates, or eliminations
     * exist, following the same split pattern as {@see self::fetchWithQuestionsAndCandidates()}
     * (questions, candidateData, and eliminations are three independent to-many collections off
     * Quiz — combining any two into one query would cross-multiply them into a cartesian
     * product).
     */
    public function eagerLoadStatusDataForSeason(Season $season): void
    {
        $em = $this->getEntityManager();

        $em->createQuery(<<<dql
            select q, qz from Tvdt\Entity\Quiz q
            left join q.questions qz
            where q.season = :season
            dql)->setParameter('season', $season)->getResult();

        $em->createQuery(<<<dql
            select q, qc, c, ga from Tvdt\Entity\Quiz q
            left join q.candidateData qc
            left join qc.candidate c
            left join c.givenAnswers ga with ga.quiz = q
            where q.season = :season
            dql)->setParameter('season', $season)->getResult();

        $em->createQuery(<<<dql
            select q, e, sv from Tvdt\Entity\Quiz q
            left join q.eliminations e
            left join e.screenViews sv
            where q.season = :season
            dql)->setParameter('season', $season)->getResult();
    }

    /**
     * Get given answers count per candidate for a quiz.
     *
     * @return array<string, int> Array with candidate ID as key and count as value
     */
    public function getGivenAnswersCountPerCandidate(Quiz $quiz): array
    {
        $results = $this->getEntityManager()->createQuery(<<<DQL
            select c.id as candidateId, count(ga.id) as answerCount
            from Tvdt\Entity\Candidate c
            left join c.givenAnswers ga with ga.quiz = :quiz
            where c.season = :season
            group by c.id
            DQL
        )->setParameter('quiz', $quiz)
         ->setParameter('season', $quiz->season)
         ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['candidateId']->toString()] = (int) $row['answerCount'];
        }

        return $counts;
    }
}
