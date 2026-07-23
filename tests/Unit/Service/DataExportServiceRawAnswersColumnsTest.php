<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Repository\QuizRepository;
use Tvdt\Service\DataExportService;
use Tvdt\Service\QuizSpreadsheetService;

/**
 * Reproduces a Sentry warning (PHP-SYMFONY-3Z): with more than 25 questions, the last raw-answers
 * column goes past 'Z' (e.g. 'AA'), and range('A', 'AA') is invalid because range()'s second argument
 * must be a single byte.
 */
#[CoversClass(DataExportService::class)]
final class DataExportServiceRawAnswersColumnsTest extends TestCase
{
    public function testFillRawAnswersSheetHandlesMoreThanTwentyFiveQuestions(): void
    {
        $subject = new DataExportService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(QuizSpreadsheetService::class),
            $this->createStub(QuizRepository::class),
        );

        $quiz = new Quiz();
        for ($i = 0; $i < 26; ++$i) {
            $question = new Question();
            $question->question = 'Question '.($i + 1);
            $quiz->addQuestion($question);
        }

        $sheet = new Spreadsheet()->getActiveSheet();

        $method = new \ReflectionMethod(DataExportService::class, 'fillRawAnswersSheet');
        $method->invoke($subject, $sheet, $quiz);

        $this->assertEqualsWithDelta(30.0, $sheet->getColumnDimension('AA')->getWidth(), \PHP_FLOAT_EPSILON);
    }
}
