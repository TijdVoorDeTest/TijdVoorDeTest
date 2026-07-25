<?php

declare(strict_types=1);

namespace Tvdt\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Tvdt\Repository\SeasonSettingsRepository;

#[ORM\Entity(repositoryClass: SeasonSettingsRepository::class)]
class SeasonSettings
{
    #[ORM\Column(type: UuidType::NAME)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) Uuid $id;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $showNumbers = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $confirmAnswers = false;

    #[ORM\Column(length: 5, options: ['default' => 'nl'])]
    public string $locale = 'nl';
}
