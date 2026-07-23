<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Tvdt\Entity\QuestionLabel;
use Tvdt\Repository\BankQuestionRepository;

#[CoversClass(BankQuestionRepository::class)]
final class BankQuestionRepositoryTest extends DatabaseTestCase
{
    private BankQuestionRepository $bankQuestionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bankQuestionRepository = self::getContainer()->get(BankQuestionRepository::class);
    }

    public function testFindBySeasonReturnsAllQuestions(): void
    {
        $season = $this->getSeasonByCode('krtek');

        $bankQuestions = $this->bankQuestionRepository->findBySeason($season);

        $this->assertCount(70, $bankQuestions);
    }

    public function testFindBySeasonFiltersByLabel(): void
    {
        $season = $this->getSeasonByCode('krtek');
        $label = $this->entityManager->getRepository(QuestionLabel::class)
            ->findOneBy(['season' => $season, 'name' => 'Locatie']);
        $this->assertInstanceOf(QuestionLabel::class, $label);

        $bankQuestions = $this->bankQuestionRepository->findBySeason($season, $label);

        $this->assertCount(2, $bankQuestions);
        foreach ($bankQuestions as $bankQuestion) {
            $this->assertTrue($bankQuestion->labels->contains($label));
        }
    }

    public function testFindBySeasonIgnoresOtherSeasons(): void
    {
        $season = $this->getSeasonByCode('bbbbb');

        $this->assertCount(0, $this->bankQuestionRepository->findBySeason($season));
    }
}
