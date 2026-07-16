<?php

namespace App\Domains\Auth\Jobs;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FetchMicrosoftUserProfileJob
{
    public function __invoke(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://graph.microsoft.com/v1.0/me', [
                '$select' => 'displayName,mail,userPrincipalName',
            ]);

        if ($response->failed()) {
            throw new AuthenticationException('Failed to fetch Microsoft user profile.');
        }

        $profile = $response->json();
        $email = Str::lower((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));

        if (blank($email)) {
            throw new AuthenticationException('Microsoft account did not provide an email address.');
        }

        if (! Str::endsWith($email, '@condimentum.co.uk')) {
            throw new AuthenticationException('Only @condimentum.co.uk accounts are allowed to sign in.');
        }

        $allowlist = array_values(array_filter(array_map(
            static fn (string $value): string => Str::lower(trim($value)),
            (array) config('dbmts.temporary_login_allow_emails', [])
        )));

        if ($allowlist !== [] && ! in_array($email, $allowlist, true)) {
            throw new AuthenticationException('This account is temporarily not permitted to sign in.');
        }

        return $profile;
    }
}
