<?php

declare(strict_types=1);

namespace Tvdt\Service;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Tvdt\Dto\Statistics\AnswerCount;
use Tvdt\Dto\Statistics\CandidateConcentration;
use Tvdt\Dto\Statistics\QuestionStatistic;

final readonly class QuizStatisticsChartBuilder
{
    private const string GREEN = '#198754';

    private const string RED = '#dc3545';

    private const string GREY = '#6c757d';

    private const string BLUE = '#0d6efd';

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
        private TranslatorInterface $translator,
    ) {}

    /** @param list<QuestionStatistic> $questions */
    public function correctness(array $questions): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_map(static fn (int $i): string => '#'.($i + 1), array_keys($questions)),
            'datasets' => [
                ['label' => $this->translator->trans('Correct'), 'backgroundColor' => self::GREEN, 'data' => array_map(static fn (QuestionStatistic $q): int => $q->correct, $questions)],
                ['label' => $this->translator->trans('Incorrect'), 'backgroundColor' => self::RED, 'data' => array_map(static fn (QuestionStatistic $q): int => $q->incorrect, $questions)],
            ],
        ]);
        $chart->setOptions(['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]]]]);

        return $chart;
    }

    public function distribution(QuestionStatistic $question): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_map(static fn (AnswerCount $a): string => $a->text, $question->answers),
            'datasets' => [[
                'label' => $this->translator->trans('Times chosen'),
                'data' => array_map(static fn (AnswerCount $a): int => $a->count, $question->answers),
                'backgroundColor' => array_map(static fn (AnswerCount $a): string => $a->isCorrect ? self::GREEN : self::GREY, $question->answers),
            ]],
        ]);
        $chart->setOptions(['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]]);

        return $chart;
    }

    /**
     * @param list<CandidateConcentration> $concentration
     *
     * @return Chart|null null when nobody answered enough candidate-bound questions to chart
     */
    public function concentration(array $concentration): ?Chart
    {
        $measured = array_values(array_filter($concentration, static fn (CandidateConcentration $c): bool => null !== $c->topShare));
        if ([] === $measured) {
            return null;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_map(static fn (CandidateConcentration $c): string => $c->candidate, $measured),
            'datasets' => [
                ['label' => $this->translator->trans('Top share'), 'backgroundColor' => self::RED, 'data' => array_map(static fn (CandidateConcentration $c): float => round((float) $c->topShare, 3), $measured)],
                ['label' => $this->translator->trans('Spread (entropy)'), 'backgroundColor' => self::BLUE, 'data' => array_map(static fn (CandidateConcentration $c): float => round((float) $c->entropy, 3), $measured)],
            ],
        ]);
        $chart->setOptions(['scales' => ['y' => ['min' => 0, 'max' => 1]]]);

        return $chart;
    }
}
