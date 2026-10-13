<?php

namespace App\Enums;

enum Ecosystem: string
{
    case Composer = 'composer';
    case Npm = 'npm';

    public function manifestFile(): string
    {
        return match ($this) {
            self::Composer => 'composer.json',
            self::Npm => 'package.json',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
        };
    }
}
