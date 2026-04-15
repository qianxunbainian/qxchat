<?php
declare(strict_types=1);

function is_app_installed(): bool
{
    return is_file(__DIR__ . '/../data/install.lock');
}
