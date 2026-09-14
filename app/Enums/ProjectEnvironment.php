<?php

namespace App\Enums;

enum ProjectEnvironment: string
{
    case Lab = 'lab';
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';
}
