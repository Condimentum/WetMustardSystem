<?php

namespace App\Domains\Auth\Jobs;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Http;

class ExchangeMicrosoftCodeForTokenJob
{
    public function __invoke(string $code): array
    {
        $response = Http::asForm()->timeout(15)->connectTimeout(5)->post(
            'https://login.microsoftonline.com/'.config('services.microsoft.tenant_id').'/oauth2/v2.0/token',
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.microsoft.redirect_uri'),
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'scope' => implode(' ', config('services.microsoft.scopes', [])),
            ]
        );

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new AuthenticationException((string) ($response->json('error_description') ?? 'Failed to retrieve Microsoft access token.'));
        }

        return $response->json();
    }
}
