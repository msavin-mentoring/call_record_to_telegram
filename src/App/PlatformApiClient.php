<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Throwable;

final class PlatformApiClient
{
    private Client $http;

    /** @var array<int, array{id:string,telegramNick:string,fullName:string,status:string}>|null */
    private ?array $cachedStudents = null;
    private int $cachedStudentsAt = 0;

    public function __construct(
        private readonly Config $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($this->config->platformApiBaseUrl, '/') . '/',
            'timeout' => 20,
            'connect_timeout' => 5,
            'http_errors' => true,
        ]);
    }

    /**
     * @return array<int, array{id:string,telegramNick:string,fullName:string,status:string}>
     */
    public function fetchStudents(): array
    {
        $ttl = max(30, $this->config->platformStudentsCacheSeconds);
        if ($this->cachedStudents !== null && (time() - $this->cachedStudentsAt) < $ttl) {
            return $this->cachedStudents;
        }

        $cookieJar = new CookieJar();
        $token = $this->buildIdentityExchangeToken();
        $this->http->request('POST', 'api/auth/session/exchange', [
            'cookies' => $cookieJar,
            'json' => ['token' => $token],
        ]);

        $response = $this->http->request('GET', 'api/mentor/students', [
            'cookies' => $cookieJar,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Platform students response is not valid JSON.');
        }

        $students = $decoded['students'] ?? null;
        if (!is_array($students)) {
            throw new \RuntimeException('Platform students response does not contain students list.');
        }

        $result = [];
        foreach ($students as $student) {
            if (!is_array($student)) {
                continue;
            }

            $telegramNick = $this->normalizeTelegramUsername((string) ($student['telegramNick'] ?? ''));
            if ($telegramNick === '') {
                continue;
            }

            $result[] = [
                'id' => trim((string) ($student['id'] ?? '')),
                'telegramNick' => $telegramNick,
                'fullName' => trim((string) ($student['fullName'] ?? '')),
                'status' => trim((string) ($student['status'] ?? '')),
            ];
        }

        usort(
            $result,
            static fn(array $left, array $right): int => strcmp($left['fullName'], $right['fullName'])
        );

        $this->cachedStudents = $result;
        $this->cachedStudentsAt = time();

        return $this->cachedStudents;
    }

    private function buildIdentityExchangeToken(): string
    {
        $issuedAt = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $this->config->platformIdentityExchangeIssuer,
            'aud' => $this->config->platformIdentityExchangeAudience,
            'sub' => max(1, $this->config->platformApiActorSubjectId),
            'telegramUsername' => $this->normalizeTelegramUsername($this->config->platformApiTelegramUsername),
            'displayName' => $this->config->platformApiDisplayName,
            'isAdmin' => true,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 300,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            $this->config->platformIdentityExchangeSecret,
            true
        );

        return $encodedHeader . '.' . $encodedPayload . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizeTelegramUsername(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return str_starts_with($normalized, '@') ? $normalized : '@' . $normalized;
    }
}
