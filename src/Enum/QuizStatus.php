<?php

declare(strict_types=1);

namespace Tvdt\Enum;

use Symfony\Component\Translation\TranslatableMessage;

enum QuizStatus: string
{
    case New = 'new';
    case Concept = 'concept';
    case Ready = 'ready';
    case Active = 'active';
    case Finished = 'finished';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::New => new TranslatableMessage('New'),
            self::Concept => new TranslatableMessage('Concept'),
            self::Ready => new TranslatableMessage('Ready'),
            self::Active => new TranslatableMessage('Active'),
            self::Finished => new TranslatableMessage('Finished'),
        };
    }

    public function badgeColour(): LabelColour
    {
        return match ($this) {
            self::New => LabelColour::Gray,
            self::Concept => LabelColour::Cyan,
            self::Ready => LabelColour::Green,
            self::Active => LabelColour::White,
            self::Finished => LabelColour::Blue,
        };
    }
}
