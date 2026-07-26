<?php

declare(strict_types=1);

namespace Tvdt\Service;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer;
use Symfony\Component\HttpFoundation\File\File;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Exception\SpreadsheetDataException;
use Tvdt\Helpers\FormulaInjectionSafeValueBinder;

class QuizSpreadsheetService
{
    public function __construct()
    {
        Cell::setValueBinder(new FormulaInjectionSafeValueBinder());
    }

    public function generateTemplate(bool $fillExample = true): \Closure
    {
        $quiz = new Quiz();

        if ($fillExample) {
            $geslacht = new Question();
            $geslacht->question = 'Is de mol een man of een vrouw?';
            $geslacht->ordering = 1;
            $geslacht->addAnswer(new Answer('Man', true));
            $geslacht->addAnswer(new Answer('Vrouw'));
            $quiz->addQuestion($geslacht);

            $identiteit = new Question();
            $identiteit->question = 'Wie is de mol?';
            $identiteit->ordering = 2;
            foreach ([
                ['Emma', false],
                ['Jan', false],
                ['Sara', false],
                ['Piet', false],
                ['Lisa', true],
                ['Kees', false],
                ['Anna', false],
                ['Henk', false],
                ['Nina', false],
                ['Joost', false],
            ] as $i => [$name, $correct]) {
                $answer = new Answer($name, $correct);
                $answer->ordering = $i + 1;
                $identiteit->addAnswer($answer);
            }

            $quiz->addQuestion($identiteit);
        }

        return $this->quizToXlsx($quiz);
    }

    /** @throws SpreadsheetDataException */
    public function xlsxToQuiz(Quiz $quiz, File $file): void
    {
        if (!$this->isSpreadsheetFile($file)) {
            throw new \InvalidArgumentException('File must be a valid XLSX spreadsheet');
        }

        $spreadsheet = $this->readSheet($file);
        $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());

        $answerLines = \array_slice($sheet->toArray(formatData: false), 1);

        $this->fillQuizFromArray($quiz, $answerLines);
    }

    private function readSheet(File $file): Spreadsheet
    {
        return new Reader\Xlsx()->setReadDataOnly(true)->load($file->getRealPath());
    }

    /**
     * @param array<int, array<int, string|bool|int|float|null>> $sheet
     *
     * @throws SpreadsheetDataException
     */
    private function fillQuizFromArray(Quiz $quiz, array $sheet): void
    {
        $errors = [];

        $questionCounter = 1;
        foreach ($sheet as $questionArr) {
            if (null === $questionArr[0]) {
                break;
            }

            $question = new Question();
            $question->question = (string) $questionArr[0];
            $question->ordering = $questionCounter++;

            $answerCounter = 1;
            $arrCounter = 1;

            while (\array_key_exists($arrCounter, $questionArr) && null !== $questionArr[$arrCounter]) {
                $text = (string) $questionArr[$arrCounter++];
                $correctValue = $questionArr[$arrCounter++];
                $isRightAnswer = $this->parseBoolean($correctValue);

                if (null === $isRightAnswer) {
                    $errors[] = \sprintf(
                        'Question %d, answer %d: "%s" is not a valid value for the Correct column, expected TRUE/FALSE or WAAR/ONWAAR',
                        $question->ordering,
                        $answerCounter,
                        (string) $correctValue,
                    );
                    $isRightAnswer = false;
                }

                $answer = new Answer($text, $isRightAnswer);
                $answer->ordering = $answerCounter++;
                $question->addAnswer($answer);
            }

            if (1 === $answerCounter) {
                $errors[] = \sprintf('Question %d has no answers', $question->ordering);
            }

            $quiz->addQuestion($question);
        }

        if ([] !== $errors) {
            throw new SpreadsheetDataException($errors);
        }
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return match ((float) $value) {
                1.0 => true,
                0.0 => false,
                default => null,
            };
        }

        return match (mb_strtoupper((string) $value)) {
            'TRUE', 'WAAR' => true,
            'FALSE', 'ONWAAR' => false,
            default => null,
        };
    }

    public function quizToXlsx(Quiz $quiz): \Closure
    {
        $spreadsheet = new Spreadsheet();
        $this->fillQuestionsSheet($spreadsheet->getActiveSheet(), $quiz);

        return $this->toXlsx($spreadsheet);
    }

    public function fillQuestionsSheet(Worksheet $sheet, Quiz $quiz): void
    {
        // Write data rows first so we know the maximum answer count.
        $maxAnswers = 0;
        $row = 2;
        foreach ($quiz->questions as $question) {
            $sheet->setCellValue('A'.$row, $question->question);

            $col = 0;
            foreach ($question->answers as $answer) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(2 + 2 * $col).$row, $answer->text);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(3 + 2 * $col).$row, $answer->isRightAnswer);
                ++$col;
            }

            $maxAnswers = max($maxAnswers, $col);
            ++$row;
        }

        // Write headers last, sized to the widest question.
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->setCellValue('A1', 'Question');
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);

        for ($i = 0; $i < $maxAnswers; ++$i) {
            $answerCol = Coordinate::stringFromColumnIndex(2 + 2 * $i);
            $correctCol = Coordinate::stringFromColumnIndex(3 + 2 * $i);

            $sheet->setCellValue($answerCol.'1', 'Answer '.($i + 1));
            $sheet->getColumnDimension($answerCol)->setWidth(30);
            $sheet->getStyle($answerCol.':'.$answerCol)->getAlignment()->setWrapText(true);

            $sheet->setCellValue($correctCol.'1', 'Correct');
            $sheet->getColumnDimension($correctCol)->setAutoSize(true);
        }
    }

    public function toXlsx(Spreadsheet $spreadsheet): \Closure
    {
        $writer = new Writer\Xlsx($spreadsheet);

        return static fn () => $writer->save('php://output');
    }

    private function isSpreadsheetFile(File $file): bool
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' === $file->getMimeType();
    }
}
