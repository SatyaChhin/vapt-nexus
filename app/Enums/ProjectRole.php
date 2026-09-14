<?php

namespace App\Enums;

enum ProjectRole: string
{
    /** Edits the project and runs scans. */
    case Manager = 'manager';

    /** Runs scans and triages findings. */
    case Analyst = 'analyst';

    /** Read-only access to results and reports. */
    case Viewer = 'viewer';
}
