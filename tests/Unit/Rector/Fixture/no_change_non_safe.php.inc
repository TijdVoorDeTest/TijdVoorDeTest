<?php

class SomeClass
{
    public ?\DateTimeImmutable $expiresAt;

    public function setDate(\DateTimeImmutable $date): void
    {
        if ($date instanceof \DateTimeImmutable) {
            echo 'yes';
        }
    }

    public function create(): \DateTimeImmutable
    {
        return new \Safe\DateTimeImmutable();
    }
}
