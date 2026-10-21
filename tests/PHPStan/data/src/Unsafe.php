<?php

declare(strict_types=1);

function unsafe(string $input): void
{
    eval($input);
    system($input);
    unserialize($input);
}
