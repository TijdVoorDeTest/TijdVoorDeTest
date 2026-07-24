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
    case Done = 'done';
    case Finished = 'finished';
    case Revealed = 'revealed';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::New => new TranslatableMessage('New'),
            self::Concept => new TranslatableMessage('Concept'),
            self::Ready => new TranslatableMessage('Ready'),
            self::Active => new TranslatableMessage('Active'),
            self::Done => new TranslatableMessage('Done'),
            self::Finished => new TranslatableMessage('Finished'),
            self::Revealed => new TranslatableMessage('Revealed'),
        };
    }

    public function badgeColour(): LabelColour
    {
        return match ($this) {
            self::New => LabelColour::Gray,
            self::Concept => LabelColour::Cyan,
            self::Ready => LabelColour::Green,
            self::Active => LabelColour::White,
            self::Done => LabelColour::Yellow,
            self::Finished => LabelColour::Blue,
            self::Revealed => LabelColour::Red,
        };
    }
}
