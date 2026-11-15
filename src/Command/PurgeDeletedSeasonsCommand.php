<?php

declare(strict_types=1);

namespace Tvdt\Command;

use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;
use Tvdt\Helpers\SoftDeleteableFilter;
use Tvdt\Repository\SeasonRepository;

#[AsCommand(
    name: 'tvdt:purge-deleted-seasons',
    description: 'Permanently remove seasons that were soft-deleted more than the retention period ago',
)]
final readonly class PurgeDeletedSeasonsCommand
{
    public function __construct(private SeasonRepository $seasonRepository, private EntityManagerInterface $entityManager) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'List what would be purged without deleting anything')]
        bool $dryRun = false,
    ): int {
        $seasons = $this->seasonRepository->findSoftDeletedOlderThan(
            new DateTimeImmutable(\sprintf('-%d days', Season::RETENTION_DAYS)),
        );

        if ([] === $seasons) {
            $io->success('No seasons to purge.');

            return 0;
        }

        $rows = [];
        foreach ($seasons as $season) {
            $rows[] = [
                $season->seasonCode,
                implode(', ', $season->owners->map(static fn (User $owner): string => $owner->email)->toArray()),
                $season->getDeletedAt()?->format('Y-m-d H:i'),
            ];
        }

        $io->table(['Season', 'Owners', 'Deleted at'], $rows);

        if ($dryRun) {
            $io->note(\sprintf('Dry run: %d season(s) would be purged.', \count($seasons)));

            return 0;
        }

        foreach ($seasons as $season) {
            SoftDeleteableFilter::withDisabled(
                $this->entityManager,
                fn () => $this->seasonRepository->deleteSeason($season),
            );
        }

        $io->success(\sprintf('Purged %d season(s).', \count($seasons)));

        return 0;
    }
}
