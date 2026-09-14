<?php

namespace App\Enums;

enum UserRole: string
{
    /** Manages Nessus servers, projects and users; sees every project. */
    case Admin = 'admin';

    /** Sees only the projects they are a member of. */
    case Member = 'member';
}
