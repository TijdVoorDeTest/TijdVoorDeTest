<?php

declare(strict_types=1);

namespace Tvdt\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Tvdt\Dto\Statistics\QuizAnswerStatistics;
use Tvdt\Dto\Statistics\QuizComposition;
use Tvdt\Entity\Quiz;
use Tvdt\EventListener\QuizStatisticsInvalidator;
use Tvdt\Repository\QuizRepository;

/**
 * Cached entry point for quiz statistics. Entries never expire, they are cleared by
 * {@see QuizStatisticsInvalidator} or by calling {@see self::invalidate()} after a bulk write.
 */
final readonly class QuizStatisticsCache
{
    public function __construct(
        #[Autowire(service: 'cache.stats')]
        private CacheInterface $cache,
        private QuizStatisticsCalculator $calculator,
        private QuizRepository $quizRepository,
    ) {}

    /** Composition only depends on questions, so it is final once the quiz is locked and recomputed live before that. */
    public function composition(Quiz $quiz): QuizComposition
    {
        $compute = fn (): QuizComposition => $this->calculator->composition($this->quizRepository->fetchWithQuestionsAndCandidates($quiz->id));

        return $quiz->isLocked ? $this->cache->get($this->key('pre', $quiz->id), $compute) : $compute();
    }

    /** Never frozen: candidates keep answering after the quiz is finalized, so this relies on invalidation alone. */
    public function answers(Quiz $quiz): QuizAnswerStatistics
    {
        return $this->cache->get(
            $this->key('post', $quiz->id),
            fn (): QuizAnswerStatistics => $this->calculator->answers($this->quizRepository->fetchWithQuestionsAndCandidates($quiz->id)),
        );
    }

    public function invalidate(Uuid $quizId): void
    {
        $this->cache->delete($this->key('pre', $quizId));
        $this->cache->delete($this->key('post', $quizId));
    }

    private function key(string $stage, Uuid $quizId): string
    {
        return \sprintf('quiz_stats.%s.%s', $stage, $quizId->toRfc4122());
    }
}
