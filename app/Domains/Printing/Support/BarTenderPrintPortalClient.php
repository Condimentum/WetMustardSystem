<?php

namespace App\Domains\Printing\Support;

use App\Domains\Printing\Exceptions\BarTenderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BarTenderPrintPortalClient
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function printFromLibrary(string $relativePath, array $options = []): array
    {
        if (! (bool) config('services.bartender.enabled', false)) {
            throw new BarTenderException('BarTender integration is disabled. Set BARTENDER_ENABLED=true to print.');
        }

        $libraryId = (string) config('services.bartender.library_id', '');
        $printer = (string) ($options['printer'] ?? config('services.bartender.default_printer', ''));
        $timeout = (int) config('services.bartender.timeout_seconds', 30);

        if ($libraryId === '') {
            throw new BarTenderException('Missing BarTender library_id configuration.');
        }

        if ($printer === '') {
            throw new BarTenderException('Missing BarTender printer configuration.');
        }

        $payload = [
            'LibraryID' => $libraryId,
            'RelativePath' => $relativePath,
            'Printer' => $printer,
            'Copies' => max(1, (int) ($options['copies'] ?? 1)),
            'SerialNumbers' => max(1, (int) ($options['serial_numbers'] ?? 1)),
        ];

        if (! empty($options['named_data_sources']) && is_array($options['named_data_sources'])) {
            $payload['NamedDataSources'] = $options['named_data_sources'];
        }

        if (! empty($options['data_entry_controls']) && is_array($options['data_entry_controls'])) {
            $payload['DataEntryControls'] = $options['data_entry_controls'];
        }

        if (! empty($options['query_prompts']) && is_array($options['query_prompts'])) {
            $payload['QueryPrompts'] = $options['query_prompts'];
        }

        $token = $this->authenticate();
        $response = $this->sendPrintRequest($payload, $token, $timeout);

        if ($response->status() === 400) {
            $decoded = $response->json();

            if (is_array($decoded)
                && ($decoded['statusCode'] ?? null) === 'DataEntryRequired'
                && is_array($decoded['requiredDataEntryControls'] ?? null)
                && ! empty($decoded['printRequestID'])) {
                // First try the documented continuation flow using PrintRequestID.
                $continuationPayload = $payload;
                $continuationPayload['DataEntryControls'] = $decoded['requiredDataEntryControls'];
                $continuationPayload['printRequestID'] = $decoded['printRequestID'];
                $continuationPayload['PrintRequestID'] = $decoded['printRequestID'];

                $response = $this->sendPrintRequest($continuationPayload, $token, $timeout);

                // Fallback: some label/API combinations reject continuation but accept a fresh
                // request when the required controls are included up front.
                if ($response->status() === 400) {
                    $retryDecoded = $response->json();

                    if (is_array($retryDecoded)
                        && ($retryDecoded['statusCode'] ?? null) === 'DataEntryRequired'
                        && is_array($retryDecoded['requiredDataEntryControls'] ?? null)) {
                        $existingControls = is_array($payload['DataEntryControls'] ?? null)
                            ? $payload['DataEntryControls']
                            : [];

                        foreach ($retryDecoded['requiredDataEntryControls'] as $controlName => $requiredValue) {
                            $existingValue = $existingControls[$controlName] ?? null;

                            if (! is_scalar($existingValue) || trim((string) $existingValue) === '') {
                                $existingControls[$controlName] = $requiredValue;
                            }
                        }

                        $payload['DataEntryControls'] = $existingControls;
                        unset($payload['printRequestID'], $payload['PrintRequestID']);

                        $response = $this->sendPrintRequest($payload, $token, $timeout);
                    }
                }
            }
        }

        $result = $this->decodeResponse($response, 'print');
        $this->assertPrinterMatched($printer, $result, $options);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendPrintRequest(array $payload, string $token, int $timeout): Response
    {
        return Http::timeout($timeout)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/json-patch+json'])
            ->post($this->endpoint('services.bartender.print_path', '/api/v1/print'), $payload);
    }

    private function authenticate(): string
    {
        $timeout = (int) config('services.bartender.timeout_seconds', 30);
        $username = (string) config('services.bartender.username', '');
        $password = (string) config('services.bartender.password', '');

        if ($username === '' || $password === '') {
            throw new BarTenderException('Missing BarTender username/password configuration.');
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint('services.bartender.authenticate_path', '/api/v1/Authenticate'), [
                'UserName' => $username,
                'Password' => $password,
            ]);

        $payload = $this->decodeResponse($response, 'authenticate');
        $token = (string) ($payload['token'] ?? $payload['accessToken'] ?? $payload['Token'] ?? '');

        if ($token === '') {
            throw new BarTenderException('BarTender auth response did not include a token.');
        }

        return $token;
    }

    private function endpoint(string $pathConfigKey, string $fallbackPath): string
    {
        $baseUrl = rtrim((string) config('services.bartender.base_url', ''), '/');
        $path = (string) config($pathConfigKey, $fallbackPath);

        if ($baseUrl === '') {
            throw new BarTenderException('Missing BarTender base_url configuration.');
        }

        if ($path === '') {
            $path = $fallbackPath;
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $baseUrl.$path;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new BarTenderException(sprintf(
                'BarTender %s request failed (HTTP %d): %s',
                $context,
                $response->status(),
                $response->body(),
            ));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return ['raw' => $response->body()];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $options
     */
    private function assertPrinterMatched(string $requestedPrinter, array $result, array $options): void
    {
        $enforce = array_key_exists('enforce_printer_match', $options)
            ? (bool) $options['enforce_printer_match']
            : (bool) config('services.bartender.enforce_printer_match', false);

        if (! $enforce) {
            return;
        }

        $actualPrinter = $this->extractSpoolerPrinter($result);

        if ($actualPrinter === null) {
            throw new BarTenderException('BarTender did not report the spooler printer, so printer routing could not be verified.');
        }

        if (strcasecmp(trim($requestedPrinter), trim($actualPrinter)) === 0) {
            return;
        }

        throw new BarTenderException(sprintf(
            'BarTender printer override detected. Requested "%s" but job was spooled to "%s".',
            $requestedPrinter,
            $actualPrinter,
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractSpoolerPrinter(array $result): ?string
    {
        $messages = $result['messages'] ?? null;

        if (! is_array($messages)) {
            return null;
        }

        foreach ($messages as $message) {
            if (! is_string($message)) {
                continue;
            }

            if (preg_match('/Printer:\s*(.+)$/m', $message, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }
}
