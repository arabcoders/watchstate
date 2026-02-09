<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Enums\Http\Method;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;

/**
 * RetryableHttpClient proxy. This Class acts as proxy in front of the Symfony RetryableHttpClient.
 *
 */
class RetryableHttpClient extends \Symfony\Component\HttpClient\RetryableHttpClient
{
    public function request(string|Method $method, string $url, array $options = []): iResponse
    {
        if (true === $method instanceof Method) {
            $method = $method->value;
        }

        return parent::request($method, $url, $options);
    }
}
