<?php

declare(strict_types=1);

namespace Tvdt\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Tvdt\Repository\UserRepository;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const int PASSWORD_MIN_LENGTH = 8;

    public const int PASSWORD_MAX_LENGTH = 4096;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) Uuid $id;

    #[ORM\Column(length: 180)]
    public string $email;

    /** @var list<string> The user roles */
    #[ORM\Column(type: Types::JSON)]
    public array $roles = [];

    /** @var string The hashed password */
    #[ORM\Column]
    public string $password;

    /** @var Collection<int, Season> */
    #[ORM\ManyToMany(targetEntity: Season::class, mappedBy: 'owners')]
    public private(set) Collection $seasons;

    #[ORM\Column]
    public bool $isVerified = false;

    #[ORM\Column(length: 5, options: ['default' => 'nl'])]
    public string $locale = 'nl';

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    public private(set) \DateTimeImmutable $created;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $lastActivity = null;

    public bool $isAdmin {
        get => \in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    public function __construct()
    {
        $this->seasons = new ArrayCollection();
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        /** @var non-empty-string $identifier */
        $identifier = $this->email;

        return $identifier;
    }

    /**
     * @see UserInterface
     *
     * @return non-empty-array<int<0, max>, string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /** @see PasswordAuthenticatedUserInterface */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function addSeason(Season $season): static
    {
        if (!$this->seasons->contains($season)) {
            $this->seasons->add($season);
            $season->addOwner($this);
        }

        return $this;
    }
}
