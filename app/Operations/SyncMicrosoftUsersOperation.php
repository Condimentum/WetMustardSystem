<?php

namespace App\Operations;

use App\Domains\Auth\Jobs\CreateOrUpdateUserFromMicrosoftProfileJob;
use App\Support\FeatureSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMicrosoftUsersOperation
{
    public function __construct(
        private readonly CreateOrUpdateUserFromMicrosoftProfileJob $upsertUser,
    ) {
    }

    /**
     * @return array{synced:int, skipped:int}
     */
    public function __invoke(): array
    {
        $tenantId = trim((string) config('services.microsoft.tenant_id'));
        $clientId = trim((string) config('services.microsoft.client_id'));
        $clientSecret = trim((string) config('services.microsoft.client_secret'));
        $groupId = trim((string) (FeatureSettings::value('microsoft.operator_group_id', (string) config('services.microsoft.operator_group_id', '')) ?? ''));

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Microsoft sync is not configured. Set MICROSOFT_TENANT_ID, MICROSOFT_CLIENT_ID, and MICROSOFT_CLIENT_SECRET.');
        }

        if ($groupId === '') {
            throw new RuntimeException('Microsoft operator group is not configured. Set MICROSOFT_OPERATOR_GROUP_ID or save it in Settings Admin.');
        }

        $tokenResponse = Http::asForm()
            ->timeout(30)
            ->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json();

        $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Microsoft sync did not return an access token.');
        }

        $url = 'https://graph.microsoft.com/v1.0/groups/'.$groupId.'/members/microsoft.graph.user?$select=displayName,mail,userPrincipalName,accountEnabled&$top=999';
        $synced = 0;
        $skipped = 0;

        while ($url !== '') {
            try {
                $payload = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(30)
                    ->get($url)
                    ->throw()
                    ->json();
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $response = $e->response;
                if ($response !== null && $response->status() === 403) {
                    throw new RuntimeException('Microsoft Graph denied access. Grant admin consent for GroupMember.Read.All and ensure the configured operator group can be read.', previous: $e);
                }

                throw $e;
            }

            foreach (($payload['value'] ?? []) as $profile) {
                $email = trim((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
                $enabled = (bool) ($profile['accountEnabled'] ?? false);

                if (! $enabled || $email === '') {
                    $skipped++;
                    continue;
                }

                ($this->upsertUser)($profile);
                $synced++;
            }

            $url = trim((string) ($payload['@odata.nextLink'] ?? ''));
        }

        return [
            'synced' => $synced,
            'skipped' => $skipped,
        ];
    }
}
