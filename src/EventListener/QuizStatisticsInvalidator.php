<?php

declare(strict_types=1);

namespace Tvdt\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Gedmo\SoftDeleteable\Event\PostSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Symfony\Component\Uid\Uuid;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Service\QuizStatisticsCache;

/**
 * Clears a quiz's cached statistics when anything feeding them is written. The clearing happens after the
 * flush so a concurrent request cannot re-cache the old state while the write is still being committed.
 * Bulk DQL writes bypass these events, those callers must call {@see QuizStatisticsCache::invalidate()} themselves.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: SoftDeleteableListener::POST_SOFT_DELETE)]
#[AsDoctrineListener(event: Events::postFlush)]
final class QuizStatisticsInvalidator
{
    /** @var array<string, Uuid> */
    private array $pending = [];

    public function __construct(private readonly QuizStatisticsCache $cache) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    /** @param PostSoftDeleteEventArgs<EntityManagerInterface> $args */
    public function postSoftDelete(PostSoftDeleteEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postFlush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $quizId) {
            $this->cache->invalidate($quizId);
        }
    }

    private function collect(object $entity): void
    {
        $quiz = match (true) {
            $entity instanceof Quiz => $entity,
            $entity instanceof GivenAnswer, $entity instanceof QuizCandidate => $entity->quiz,
            default => null,
        };

        // A removed quiz has lost its id, and has no statistics left to clear anyway.
        if ($quiz instanceof Quiz && isset($quiz->id)) {
            $this->pending[$quiz->id->toRfc4122()] = $quiz->id;
        }
    }
}
