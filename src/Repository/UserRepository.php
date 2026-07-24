<?php

declare(strict_types=1);

namespace Tvdt\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Tvdt\Entity\User;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly SeasonRepository $seasonRepository,
    ) {
        parent::__construct($registry, User::class);
    }

    /** Used to upgrade (rehash) the user's password automatically over time.
     * @param User $user
     * */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $user->password = $newHashedPassword;
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** Deletes all outstanding reset-password tokens for the user (e.g. after a password or email change). */
    public function invalidateResetPasswordRequests(User $user): void
    {
        $this->getEntityManager()
            ->createQuery('delete from Tvdt\Entity\ResetPasswordRequest r where r.user = :user')
            ->setParameter('user', $user)
            ->execute();
    }

    /** Deletes the user, all seasons the user is the sole owner of, and the user's ownership of shared seasons. */
    public function deleteUser(User $user): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $user): void {
            $this->invalidateResetPasswordRequests($user);

            $bankQuestionIds = [];
            foreach ($user->seasons->toArray() as $season) {
                if (1 === $season->owners->count()) {
                    array_push($bankQuestionIds, ...$this->seasonRepository->scheduleForRemoval($em, $season));

                    continue;
                }

                $season->removeOwner($user);
            }

            $em->remove($user);
            $em->flush();

            $this->seasonRepository->purgeBankQuestionAuditLog($em, $bankQuestionIds);
        });
    }

    public function makeAdmin(string $email): void
    {
        $user = $this->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            throw new \InvalidArgumentException('User not found');
        }

        $user->roles = ['ROLE_ADMIN'];
        $this->getEntityManager()->flush();
    }
}
