<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tvdt\Entity\Candidate;
use Tvdt\Repository\CandidateRepository;

#[CoversClass(CandidateRepository::class)]
final class CandidateRepositoryTest extends DatabaseTestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function candidateHashDataProvider(): iterable
    {
        yield 'Normal' => ['Q2xhdWRpYQ'];
        yield 'lowercase' => ['Y2xhdWRpYQ'];
        yield 'UPPERCASE' => ['Q0xBVURJQQ'];
    }

    #[DataProvider('candidateHashDataProvider')]
    public function testGetCandidateByHash(string $hash): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->candidateRepository->getCandidateByHash(
            $krtekSeason,
            $hash,
        );

        $this->assertInstanceOf(Candidate::class, $candidate);

        $this->assertSame('Claudia', $candidate->name);
    }

    public function testGetCandidateByHashUnknownHashReturnsNull(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $result = $this->candidateRepository->getCandidateByHash(
            $krtekSeason,
            'TWFyaWpu',
        );
        $this->assertNotInstanceOf(Candidate::class, $result);
    }

    public function testGetCandidateByHashInvalidBase64HashReturnsNull(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $result = $this->candidateRepository->getCandidateByHash(
            $krtekSeason,
            'TWFyaWpu*',
        );
        $this->assertNotInstanceOf(Candidate::class, $result);
    }

    /** Candidate names are only unique per season, so a same-named candidate in another season must not leak in. */
    public function testGetCandidateByHashScopesByCandidateSeasonNotJustName(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $anotherSeason = $this->getSeasonByCode('bbbbb');

        $duplicateNamedCandidate = new Candidate('Claudia');
        $anotherSeason->addCandidate($duplicateNamedCandidate);
        $this->entityManager->persist($duplicateNamedCandidate);
        $this->entityManager->flush();

        $candidate = $this->candidateRepository->getCandidateByHash($krtekSeason, 'Q2xhdWRpYQ');

        $this->assertInstanceOf(Candidate::class, $candidate);
        $this->assertSame($krtekSeason, $candidate->season);
        $this->assertNotSame($duplicateNamedCandidate, $candidate);
    }
}
