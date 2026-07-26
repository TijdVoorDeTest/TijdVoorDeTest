<?php

class SomeClass
{
    public ?\Safe\DateTimeImmutable $expiresAt;

    private \Safe\DateTimeImmutable|\Safe\DateTime $either;

    public function setDate(\Safe\DateTimeImmutable $date): void
    {
    }

    public function checkInstanceof(\Safe\DateTimeImmutable $date): void
    {
        if ($date instanceof \Safe\DateTimeImmutable) {
            echo 'yes';
        }

        is_a($date, \Safe\DateTimeImmutable::class);

        is_subclass_of($date, \Safe\DateTimeImmutable::class);

        is_a($date, '\Safe\DateTimeImmutable');
    }
}

-----
<?php

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

        is_subclass_of($date, \DateTimeImmutable::class);

        is_a($date, '\DateTimeImmutable');
    }
}
