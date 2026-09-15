<?php

namespace App\Enums;

enum Role: string
{
    case Editor = 'editor';
    case Reviewer = 'reviewer';
    case Admin = 'admin';

    /** @return array<string, string> */
    public static function options(): array
    {
        return ['editor' => 'Editor', 'reviewer' => 'Reviewer', 'admin' => 'Administrator'];
    }
}
