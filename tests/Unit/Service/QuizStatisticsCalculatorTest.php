<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tvdt\Dto\Statistics\AnswerCount;
use Tvdt\Dto\Statistics\RelationshipMatrix;
use Tvdt\Dto\Statistics\ReviewCell;
use Tvdt\Dto\Statistics\ReviewRow;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Service\QuizStatisticsCalculator;

#[CoversClass(QuizStatisticsCalculator::class)]
final class QuizStatisticsCalculatorTest extends TestCase
{
    private Quiz $quiz;

    /** @var array<string, Candidate> */
    private array $candidates = [];

    protected function setUp(): void
    {
        $this->quiz = new Quiz();
        $this->quiz->season = new Season();

        foreach (['Anna', 'Bram', 'Chris'] as $name) {
            $this->candidates[$name] = new Candidate($name);
            $this->quiz->candidateData->add(new QuizCandidate($this->quiz, $this->candidates[$name]));
        }
    }

    public function testCompositionCountsQuestionsByKind(): void
    {
        $this->plainQuestion('Trivia', ['Right', 'Wrong']);
        $this->boundQuestion('Mol?', ['Anna' => ['Anna'], 'Bram' => ['Bram'], 'Chris' => ['Chris']], 'Anna');
        $disabled = $this->plainQuestion('Off', ['Right', 'Wrong', 'Other']);
        $disabled->enabled = false;

        $composition = new QuizStatisticsCalculator()->composition($this->quiz);

        $this->assertSame(3, $composition->questionCount);
        $this->assertSame(1, $composition->disabledCount);
        $this->assertSame(1, $composition->candidateBoundCount);
        $this->assertSame(2, $composition->plainCount);
        $this->assertSame([2 => 1, 3 => 2], $composition->optionCounts);
    }

    public function testCompositionCoverageFlagsMissingAndDuplicateCandidates(): void
    {
        $this->boundQuestion('Mol?', ['x' => ['Anna', 'Bram'], 'y' => ['Bram']], 'x');

        $composition = new QuizStatisticsCalculator()->composition($this->quiz);

        $this->assertSame(['Anna', 'Bram', 'Chris'], $composition->candidateNames);
        $this->assertCount(1, $composition->coverage);
        $this->assertSame('Mol?', $composition->coverage[0]->question);
        $this->assertSame([1, 2, 0], $composition->coverage[0]->counts);
    }

    public function testQuestionStatisticsCountCorrectIncorrectAndDistribution(): void
    {
        $question = $this->plainQuestion('Trivia', ['Right', 'Wrong']);
        $this->answer('Anna', $question, 'Right');
        $this->answer('Bram', $question, 'Wrong');
        $this->answer('Chris', $question, 'Wrong');

        $statistics = new QuizStatisticsCalculator()->answers($this->quiz);

        $stat = $statistics->questions[0];
        $this->assertSame(1, $stat->correct);
        $this->assertSame(2, $stat->incorrect);
        $this->assertSame(['Right' => 1, 'Wrong' => 2], $this->distribution($stat->answers));
        $this->assertTrue($stat->answers[0]->isCorrect);
    }

    public function testDisabledQuestionsAreExcludedFromAnswerStatistics(): void
    {
        $question = $this->plainQuestion('Off', ['Right', 'Wrong']);
        $question->enabled = false;
        $this->answer('Anna', $question, 'Right');

        $statistics = new QuizStatisticsCalculator()->answers($this->quiz);

        $this->assertSame([], $statistics->questions);
        $this->assertNull($statistics->matrix);
    }

    public function testReviewHasOneRowPerCandidateWithNullForUnanswered(): void
    {
        $first = $this->plainQuestion('One', ['Right', 'Wrong']);
        $this->plainQuestion('Two', ['Right', 'Wrong']);
        $this->answer('Anna', $first, 'Wrong');

        $statistics = new QuizStatisticsCalculator()->answers($this->quiz);

        $this->assertSame(['Anna', 'Bram', 'Chris'], array_map(static fn (ReviewRow $row): string => $row->candidate, $statistics->review));
        $cell = $statistics->review[0]->cells[0];
        $this->assertInstanceOf(ReviewCell::class, $cell);
        $this->assertSame('Wrong', $cell->text);
        $this->assertFalse($cell->isCorrect);
        $this->assertNull($statistics->review[0]->cells[1]);
        $this->assertNull($statistics->review[1]->cells[0]);
    }

    public function testInactiveCandidatesAreLeftOut(): void
    {
        $first = $this->quiz->candidateData->first();
        $this->assertInstanceOf(QuizCandidate::class, $first);
        $first->active = false;
        $question = $this->plainQuestion('One', ['Right', 'Wrong']);
        $this->answer('Anna', $question, 'Right');

        $statistics = new QuizStatisticsCalculator()->answers($this->quiz);

        $this->assertSame(0, $statistics->questions[0]->correct);
        $this->assertCount(2, $statistics->review);
    }

    public function testMatrixCountsGroupAnswersFullWeightForEveryMember(): void
    {
        $one = $this->boundQuestion('One', ['b' => ['Bram'], 'cd' => ['Anna', 'Chris'], 'c' => ['Chris']], 'b');
        $this->answer('Anna', $one, 'cd');
        $this->answer('Bram', $one, 'b');

        $matrix = new QuizStatisticsCalculator()->answers($this->quiz)->matrix;

        $this->assertInstanceOf(RelationshipMatrix::class, $matrix);
        $this->assertSame(['Anna', 'Bram', 'Chris'], $matrix->names);
        $this->assertSame([1, 0, 1], $matrix->counts[0]);
        $this->assertSame([0, 1, 0], $matrix->counts[1]);
        $this->assertSame([0, 0, 0], $matrix->counts[2]);
        $this->assertSame(['Anna', 'Bram', null], $matrix->mostNamed);
    }

    public function testConcentrationReportsTopShareDistinctTargetsAndEntropy(): void
    {
        foreach ([['One', 'Bram'], ['Two', 'Bram'], ['Three', 'Chris']] as [$text, $pick]) {
            $question = $this->boundQuestion($text, ['Anna' => ['Anna'], 'Bram' => ['Bram'], 'Chris' => ['Chris']], 'Anna');
            $this->answer('Anna', $question, $pick);
        }

        $concentration = new QuizStatisticsCalculator()->answers($this->quiz)->concentration[0];

        $this->assertSame('Anna', $concentration->candidate);
        $this->assertSame(3, $concentration->questions);
        $this->assertSame(2, $concentration->distinctTargets);
        $this->assertEqualsWithDelta(2 / 3, (float) $concentration->topShare, 0.0001);
        $this->assertEqualsWithDelta(0.9183, (float) $concentration->entropy, 0.0001);
    }

    public function testConcentrationOfSingleTargetHasZeroEntropy(): void
    {
        foreach (['One', 'Two'] as $text) {
            $question = $this->boundQuestion($text, ['Anna' => ['Anna'], 'Bram' => ['Bram'], 'Chris' => ['Chris']], 'Anna');
            $this->answer('Anna', $question, 'Bram');
        }

        $concentration = new QuizStatisticsCalculator()->answers($this->quiz)->concentration[0];

        $this->assertSame(1, $concentration->distinctTargets);
        $this->assertEqualsWithDelta(1.0, (float) $concentration->topShare, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $concentration->entropy, 0.0001);
    }

    public function testConcentrationNeedsAtLeastTwoAnsweredCandidateBoundQuestions(): void
    {
        $question = $this->boundQuestion('One', ['Anna' => ['Anna'], 'Bram' => ['Bram'], 'Chris' => ['Chris']], 'Anna');
        $this->answer('Anna', $question, 'Bram');

        $concentration = new QuizStatisticsCalculator()->answers($this->quiz)->concentration[0];

        $this->assertSame(1, $concentration->questions);
        $this->assertNull($concentration->topShare);
        $this->assertNull($concentration->entropy);
        $this->assertNull($concentration->distinctTargets);
    }

    public function testPlainQuizHasNoMatrixOrConcentration(): void
    {
        $question = $this->plainQuestion('One', ['Right', 'Wrong']);
        $this->answer('Anna', $question, 'Right');

        $statistics = new QuizStatisticsCalculator()->answers($this->quiz);

        $this->assertNull($statistics->matrix);
        $this->assertSame([], $statistics->concentration);
    }

    /** @param list<string> $texts first one is the right answer */
    private function plainQuestion(string $text, array $texts): Question
    {
        $question = new Question();
        $question->question = $text;
        foreach ($texts as $i => $answerText) {
            $question->addAnswer(new Answer($answerText, 0 === $i));
        }

        $this->quiz->addQuestion($question);

        return $question;
    }

    /** @param array<string, list<string>> $options answer text => candidate names it represents */
    private function boundQuestion(string $text, array $options, string $right): Question
    {
        $question = new Question();
        $question->question = $text;
        foreach ($options as $answerText => $names) {
            $answer = new Answer((string) $answerText, (string) $answerText === $right);
            foreach ($names as $name) {
                $answer->addCandidate($this->candidates[$name]);
            }

            $question->addAnswer($answer);
        }

        $this->quiz->addQuestion($question);

        return $question;
    }

    private function answer(string $candidate, Question $question, string $answerText): void
    {
        foreach ($question->answers as $answer) {
            if ($answer->text === $answerText) {
                $this->candidates[$candidate]->givenAnswers->add(new GivenAnswer($this->candidates[$candidate], $this->quiz, $answer));

                return;
            }
        }

        throw new \LogicException('Unknown answer '.$answerText);
    }

    /**
     * @param list<AnswerCount> $answers
     *
     * @return array<string, int>
     */
    private function distribution(array $answers): array
    {
        $result = [];
        foreach ($answers as $answer) {
            $result[$answer->text] = $answer->count;
        }

        return $result;
    }
}
