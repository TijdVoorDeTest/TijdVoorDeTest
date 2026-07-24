<?php

declare(strict_types=1);

namespace Tvdt\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;

/** @extends ServiceEntityRepository<Season> */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    public function findOneBySeasonCode(string $seasonCode): ?Season
    {
        return $this->getEntityManager()->createQuery(<<<DQL
            select s from Tvdt\Entity\Season s
            where s.seasonCode = :seasonCode
            DQL)
            ->setParameter('seasonCode', $seasonCode)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /** @return list<Season> Returns an array of Season objects */
    public function getSeasonsForUser(User $user): array
    {
        return $this->getEntityManager()->createQuery(<<<DQL
            select s from Tvdt\Entity\Season s where :user member of s.owners order by s.name
            DQL
        )->setParameter('user', $user)->getResult();
    }

    /** Deletes the season and every row tied to it, including data Gedmo would otherwise only soft-delete. */
    public function deleteSeason(Season $season): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $season): void {
            $bankQuestionIds = $this->scheduleForRemoval($em, $season);
            $em->flush();

            // Gedmo\Loggable writes its own "removed" log entry as part of the flush above, so the
            // audit-log purge must happen after — purging first would just leave that final row behind.
            $this->purgeBankQuestionAuditLog($em, $bankQuestionIds);
        });
    }

    /**
     * Purges Gedmo\SoftDeleteable rows and schedules $season for removal, without flushing — lets
     * a caller batch several seasons' removal into one flush (see UserRepository::deleteUser(),
     * which combines this with removing the user in a single flush; calling flush() separately
     * per season there confuses Doctrine's change-set detection for still-managed entities tied
     * to the bulk-deleted rows above).
     *
     * @return list<string> the season's BankQuestion ids, to purge their audit log once flushed
     */
    public function scheduleForRemoval(EntityManagerInterface $em, Season $season): array
    {
        $this->purgeSoftDeletableData($em, $season);
        $bankQuestionIds = $this->bankQuestionIds($season);
        $em->remove($season);

        return $bankQuestionIds;
    }

    /**
     * QuizCandidate, GivenAnswer, and Elimination are Gedmo\SoftDeleteable, so cascading their
     * removal through the season/quiz/candidate relations only sets deletedAt — it never removes
     * the row. That leaves personal data behind indefinitely and, since Candidate/Answer are hard
     * deleted via orphanRemoval, it also breaks their foreign keys and rolls back the whole
     * deletion. Bulk DQL deletes bypass the Gedmo listener and physically remove these rows first.
     */
    private function purgeSoftDeletableData(EntityManagerInterface $em, Season $season): void
    {
        foreach ([QuizCandidate::class, GivenAnswer::class, Elimination::class] as $class) {
            $em->createQuery(<<<DQL
                delete from {$class} e
                where e.quiz in (select q from Tvdt\Entity\Quiz q where q.season = :season)
                DQL)
                ->setParameter('season', $season)
                ->execute();
        }
    }

    /** @return list<string> */
    private function bankQuestionIds(Season $season): array
    {
        return array_values(array_map(
            static fn (BankQuestion $bankQuestion): string => $bankQuestion->id->toString(),
            $season->bankQuestions->toArray(),
        ));
    }

    /**
     * Gedmo\Loggable audit rows (ext_log_entries) aren't foreign-keyed to the entity they log —
     * object_id is a plain string — so removing a BankQuestion never cleans up its history, and
     * the editor's username/email would otherwise remain in those rows forever.
     *
     * @param list<string> $bankQuestionIds
     */
    public function purgeBankQuestionAuditLog(EntityManagerInterface $em, array $bankQuestionIds): void
    {
        if ([] === $bankQuestionIds) {
            return;
        }

        $em->createQuery(<<<'DQL'
            delete from Tvdt\Entity\LogEntry l
            where l.objectClass = :class and l.objectId in (:ids)
            DQL)
            ->setParameter('class', BankQuestion::class)
            ->setParameter('ids', $bankQuestionIds)
            ->execute();
    }
}
