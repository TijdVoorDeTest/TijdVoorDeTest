<?php

use Safe\DateTime;
use Safe\DateTimeImmutable;

class SomeClass
{
    public ?DateTimeImmutable $expiresAt;

    private DateTimeImmutable|DateTime $either;

    public function setDate(DateTimeImmutable $date): void
    {
    }

    public function checkInstanceof(DateTimeImmutable $date): void
    {
        if ($date instanceof DateTimeImmutable) {
            echo 'yes';
        }

        is_a($date, DateTimeImmutable::class);
    }
}

-----
<?php

use Safe\DateTime;
use Safe\DateTimeImmutable;

class SomeClass
{
    public ?\DateTimeImmutable $expiresAt;

    private \DateTimeImmutable|\DateTime $either;

    public function setDate(\DateTimeImmutable $date): void
    {
    }

    public function checkInstanceof(\DateTimeImmutable $date): void
    {
        if ($date instanceof \DateTimeImmutable) {
            echo 'yes';
        }

        is_a($date, \DateTimeImmutable::class);
    }
}
