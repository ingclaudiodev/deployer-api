<?php

namespace App\Enums;

enum RepositoryProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
}
