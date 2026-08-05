<?php

namespace App\Domains\Auth\Jobs;

use App\Models\User;
use Illuminate\Support\Str;

class CreateOrUpdateUserFromMicrosoftProfileJob
{
    public function __invoke(array $profile): User
    {
        $email = Str::lower((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
        $name = (string) ($profile['displayName'] ?? $email);

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->email_verified_at = now();

        if (! $user->exists) {
            $user->password = Str::password(32);
        }

        $user->save();

        return $user;
    }
}
