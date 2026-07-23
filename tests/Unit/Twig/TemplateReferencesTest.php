<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Twig;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Safe\file_get_contents;
use function Safe\preg_match_all;

final class TemplateReferencesTest extends TestCase
{
    private static string $templatesDir;

    public static function setUpBeforeClass(): void
    {
        self::$templatesDir = \dirname(__DIR__, 3).'/templates';
    }

    /** @return iterable<string, array{string, string}> */
    public static function templateReferenceProvider(): iterable
    {
        $templatesDir = \dirname(__DIR__, 3).'/templates';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            Assert::assertInstanceOf(\SplFileInfo::class, $file);
            if ('twig' !== $file->getExtension()) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $sourceFile = str_replace($templatesDir.'/', '', $file->getPathname());

            // Match extends, include(), and embed tags — capture the quoted template name
            preg_match_all(
                '/(?:extends|include|embed)\s*\(?[\'"]([^\'"]+)[\'"]\)?/',
                $content,
                $matches,
            );

            foreach ($matches[1] as $referencedTemplate) {
                yield \sprintf('%s → %s', $sourceFile, $referencedTemplate) => [$sourceFile, $referencedTemplate];
            }
        }
    }

    #[DataProvider('templateReferenceProvider')]
    public function testReferencedTemplateExists(string $sourceFile, string $referencedTemplate): void
    {
        $absolutePath = self::$templatesDir.'/'.$referencedTemplate;

        $this->assertFileExists(
            $absolutePath,
            \sprintf("Template '%s' references '%s' which does not exist at '%s'.", $sourceFile, $referencedTemplate, $absolutePath),
        );
    }
}
