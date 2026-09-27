<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

// Functions and constants for interacting with GitHub's OAuth API. No idea
// how much this generalises to other OAuth implementations.

const GITHUB_OAUTH_AUTHORIZE_URL = "https://github.com/login/oauth/authorize";
const GITHUB_OAUTH_ACCESS_TOKEN_URL = "https://github.com/login/oauth/access_token";
const GITHUB_USER_INFO_URL = "https://api.github.com/user";

function oauthReturnPath($value): string {
    if (!is_string($value) || strlen($value) > 8192) {
        return '/';
    }
    $parts = parse_url($value);
    if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) ||
        ($parts['path'] ?? '') !== '/reports/new' || isset($parts['fragment'])) {
        return '/';
    }
    return $value;
}

// A short-lived signed OAuth state preserves the prefill query without putting
// it in a cookie or allowing an external/open redirect. It contains no secret.
function createOAuthState(string $returnPath): string {
    $payload = json_encode([
        'return_to' => oauthReturnPath($returnPath),
        'issued_at' => time(),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        $payload = '{"return_to":"/","issued_at":0}';
    }
    $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $encoded, GITHUB_CLIENT_SECRET);
    return $encoded . '.' . $signature;
}

function verifyOAuthState($state): ?string {
    if (!is_string($state) || strlen($state) > 12000 || substr_count($state, '.') !== 1) {
        return NULL;
    }
    [$encoded, $signature] = explode('.', $state, 2);
    if (!preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) ||
        !preg_match('/\A[0-9a-f]{64}\z/D', $signature) ||
        !hash_equals(hash_hmac('sha256', $encoded, GITHUB_CLIENT_SECRET), $signature)) {
        return NULL;
    }
    $padding = (4 - strlen($encoded) % 4) % 4;
    $payload = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), TRUE);
    $decoded = is_string($payload) ? json_decode($payload, TRUE) : NULL;
    $issuedAt = is_array($decoded) ? ($decoded['issued_at'] ?? NULL) : NULL;
    $returnTo = is_array($decoded) ? ($decoded['return_to'] ?? NULL) : NULL;
    if (!is_int($issuedAt) || $issuedAt > time() + 60 || $issuedAt < time() - 900 ||
        !is_string($returnTo) || oauthReturnPath($returnTo) !== $returnTo) {
        return NULL;
    }
    return $returnTo;
}

function getOAuthAccessToken(string $sessionCode): string {
    $url = GITHUB_OAUTH_ACCESS_TOKEN_URL;
    $content = http_build_query([
        'client_id' => GITHUB_CLIENT_ID,
        'client_secret' => GITHUB_CLIENT_SECRET,
        'code' => $sessionCode,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'User-Agent: ' . USER_AGENT,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'content' => $content,
        ]
    ]);

    return json_decode(file_get_contents($url, FALSE, $context))->access_token;
}

function getGitHubUserInfo(string $oauthAccessToken): \stdClass {
    $url = GITHUB_USER_INFO_URL;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: ' . USER_AGENT,
                'Accept: application/vnd.github+json',
                'Authorization: token ' . $oauthAccessToken,
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]
    ]);

    return json_decode(file_get_contents($url, FALSE, $context));
}
