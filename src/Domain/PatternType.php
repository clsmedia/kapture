<?php

declare(strict_types=1);

namespace App\Domain;

enum PatternType: string
{
    case PERIODIC = 'periodic';
    case DAILY = 'daily';
    case OFFENDER = 'offender';
}
