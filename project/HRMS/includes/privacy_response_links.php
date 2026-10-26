<?php
declare(strict_types=1);

function privacyResponseDirectory(): string
{
    return dirname(__DIR__) . '/private/data_subject_responses';
}

function privacyResponseSigningKey(): string
{
    $keyFile = dirname(__DIR__) . '/private/.privacy_response_signing_key';
    if (!is_file($keyFile)) {
        $key = random_bytes(32);
        $handle = @fopen($keyFile, 'xb');
        if ($handle !== false) {
            try {
                if (fwrite($handle, $key) !== strlen($key)) {
                    throw new RuntimeException('Could not persist privacy response signing key.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($keyFile, 0600);
        }
    }

    $storedKey = @file_get_contents($keyFile);
    if (!is_string($storedKey) || strlen($storedKey) !== 32) {
        throw new RuntimeException('Privacy response signing key is unavailable or invalid.');
    }
    return $storedKey;
}

function privacyResponseSignature(int $requestId, int $expiresAt, string $filename): string
{
    return hash_hmac(
        'sha256',
        $requestId . "\n" . $expiresAt . "\n" . $filename,
        privacyResponseSigningKey()
    );
}

function privacyResponseDownloadUrl(array $request): ?string
{
    if (empty($request['response_filename']) || empty($request['response_expires_at'])) {
        return null;
    }
    $expiresAt = strtotime((string) $request['response_expires_at']);
    if ($expiresAt === false || $expiresAt <= time()) {
        return null;
    }

    $requestId = (int) $request['request_id'];
    $filename = (string) $request['response_filename'];
    $signature = privacyResponseSignature($requestId, $expiresAt, $filename);
    return 'privacy_response_download.php?id=' . rawurlencode(enc_id($requestId))
        . '&expires=' . $expiresAt
        . '&signature=' . rawurlencode($signature);
}
