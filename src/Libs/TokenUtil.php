<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;

final class TokenUtil
{
    /**
     * Sign the given data using HMAC.
     *
     * @param string $data The data to sign.
     * @param string $algo The hashing algorithm to use (default: 'sha256').
     *
     * @return string The HMAC signature.
     */
    public static function sign(string $data, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $data, static::getSecret());
    }

    /**
     * Verify the given signature against the data.
     *
     * @param string $data The data to verify.
     * @param string $signature The signature to verify against.
     * @param string $algo The hashing algorithm to use (default: 'sha256').
     *
     * @return bool True if the signature is valid, false otherwise.
     */
    public static function verify(string $data, string $signature, $algo = 'sha256'): bool
    {
        return hash_equals(static::sign($data, $algo), $signature);
    }

    /**
     * URL-safe Base64 Encode.
     *
     * @param string $data The data to encode.
     *
     * @return string The encoded data.
     */
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe Base64 Decode.
     *
     * @param string $input The URL-safe Base64 string to decode.
     *
     * @return string|false The decoded data, or false on failure.
     */
    public static function decode(string $input): string|false
    {
        $base64 = strtr($input, '-_', '+/');

        $pad = strlen($base64) % 4;

        if ($pad > 0) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($base64);
    }

    /**
     * Generate a random string.
     *
     * @param int $length The length. (default: 16).
     *
     *
     * @return string The generated random string.
     */
    public static function generateSecret(int $length = 16): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Get the secret key from the config file or generate a new one if it doesn't exist.
     *
     * @return string The secret key.
     */
    private static function getSecret(): string
    {
        if (null !== ($secret = Config::get('system.secret'))) {
            return $secret;
        }

        $secret = static::generateSecret();
        Config::save('system.secret', $secret);

        $response = APIRequest('POST', '/system/env/WS_SYSTEM_SECRET', [
            'value' => $secret,
        ]);

        if (Status::OK !== $response->status) {
            throw new RuntimeException('Failed to set the new secret key.');
        }

        return $secret;
    }
}
