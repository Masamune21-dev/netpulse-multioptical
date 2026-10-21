<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmService
{
    /** Short HTTP limits: pushes run inside the poller, which has a per-device timeout. */
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 10;

    /** Access token memoized for this process (the cache store shares it across processes). */
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    /** FCM rejected the token for good (app uninstalled / token rotated); safe to delete it. */
    public static function isUnregistered(\Throwable $e): bool
    {
        return $e->getCode() === 404 && str_contains($e->getMessage(), 'UNREGISTERED');
    }

    /**
     * @return array{client_email:string, private_key:string, project_id:string}
     */
    private function credentials(): array
    {
        $path = (string) (config('services.firebase.service_account_json') ?: env('FIREBASE_SERVICE_ACCOUNT_JSON', ''));
        if ($path === '') {
            throw new \RuntimeException('FIREBASE_SERVICE_ACCOUNT_JSON is not set');
        }

        if (!is_file($path)) {
            throw new \RuntimeException("Firebase service account json not found at: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid Firebase service account json');
        }

        $clientEmail = (string) ($decoded['client_email'] ?? '');
        $privateKey = (string) ($decoded['private_key'] ?? '');
        $projectId = (string) ($decoded['project_id'] ?? '');

        if ($clientEmail === '' || $privateKey === '' || $projectId === '') {
            throw new \RuntimeException('Firebase service account json missing client_email/private_key/project_id');
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'project_id' => $projectId,
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Google OAuth access token, reused until shortly before it expires. Fetching one
     * per device token used to double the HTTP calls of every alert.
     */
    private function googleAccessToken(): string
    {
        $now = time();
        if ($this->accessToken !== null && $now < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        $creds = $this->credentials();
        $cacheKey = 'fcm:access_token:' . sha1($creds['client_email']);
        try {
            $cached = Cache::get($cacheKey);
        } catch (\Throwable $e) {
            $cached = null;
        }
        if (is_array($cached) && ($cached['token'] ?? '') !== '' && $now < (int) ($cached['expires_at'] ?? 0)) {
            $this->accessToken = (string) $cached['token'];
            $this->accessTokenExpiresAt = (int) $cached['expires_at'];
            return $this->accessToken;
        }

        $token = $this->fetchGoogleAccessToken($creds, $now, $expiresIn);
        // Refresh 5 minutes early so a token never expires mid-send.
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = $now + max(60, $expiresIn - 300);
        try {
            Cache::put($cacheKey, ['token' => $token, 'expires_at' => $this->accessTokenExpiresAt], $this->accessTokenExpiresAt - $now);
        } catch (\Throwable $e) {
            // Cache is an optimisation only.
        }

        return $token;
    }

    /**
     * @param array{client_email:string, private_key:string, project_id:string} $creds
     */
    private function fetchGoogleAccessToken(array $creds, int $now, ?int &$expiresIn): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header . '.' . $claims;

        $signature = '';
        $key = openssl_pkey_get_private($creds['private_key']);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key');
        }

        $ok = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        if (!$ok) {
            throw new \RuntimeException('Failed to sign JWT');
        }

        $jwt = $signingInput . '.' . $this->base64UrlEncode($signature);

        $res = Http::asForm()->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (!$res->ok()) {
            $body = $res->json();
            $err = is_array($body) ? json_encode($body) : (string) $res->body();
            throw new \RuntimeException("Failed to fetch Google access token: HTTP {$res->status()} {$err}");
        }

        $token = (string) ($res->json('access_token') ?? '');
        if ($token === '') {
            throw new \RuntimeException('Missing access_token from Google');
        }
        $expiresIn = (int) ($res->json('expires_in') ?? 3600);

        return $token;
    }

    /**
     * @param array<string,string> $data
     * @return array<string,mixed>
     */
    public function sendToToken(string $deviceToken, string $title, string $body, array $data = [], string $imageUrl = ''): array
    {
        $creds = $this->credentials();
        $accessToken = $this->googleAccessToken();

        $notification = [
            'title' => $title,
            'body' => $body,
        ];

        $android = [
            'priority' => 'HIGH',
        ];

        // FCM renders the image as an expandable "big picture" on Android/iOS.
        // It must be a publicly reachable HTTPS URL.
        if ($imageUrl !== '') {
            $notification['image'] = $imageUrl;
            $android['notification'] = ['image' => $imageUrl];
            $data['image'] = $imageUrl;
        }

        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => $notification,
                'data' => (object) $data,
                'android' => $android,
            ],
        ];

        $url = "https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send";

        $res = Http::withToken($accessToken)->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->post($url, $payload);

        $json = $res->json();
        if (!$res->ok()) {
            $err = is_array($json) ? json_encode($json) : (string) $res->body();
            throw new \RuntimeException("FCM send failed: HTTP {$res->status()} {$err}", $res->status());
        }

        return is_array($json) ? $json : ['success' => true];
    }
}

