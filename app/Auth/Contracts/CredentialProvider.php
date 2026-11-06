<?php

namespace App\Auth\Contracts;

use App\Models\User;

interface CredentialProvider
{
    public function authenticate(string $username, string $password): ?User;
}
