<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as iHandler;
use Throwable;

final class SignatureMiddleware implements MiddlewareInterface
{
    /** @mago-expect lint:no-literal-password */
    private const string WITH_TOKEN = 'token';
    private const string WITH_API = 'api';

    public function process(iRequest $request, iHandler $handler): iResponse
    {
        if ('' === ($sign = trim($request->getHeaderLine('X-Signature')))) {
            return api_error('No signature was found.', Status::BAD_REQUEST);
        }

        if ('' === ($with = strtolower(trim($request->getHeaderLine('X-Sign-With'))))) {
            $with = self::WITH_API;
        }

        if (!in_array($with, [self::WITH_TOKEN, self::WITH_API], true)) {
            return api_error('Invalid signature verifier.', Status::BAD_REQUEST);
        }

        [$algo, $signature] = array_pad(explode('=', $sign, 2), 2, null);

        if (!is_string($algo) || '' === trim($algo) || !is_string($signature) || '' === trim($signature)) {
            return api_error("Invalid signature format. Expected 'algo=hash'.", Status::BAD_REQUEST);
        }

        if (!in_array($algo, hash_hmac_algos(), true)) {
            return api_error(r("The algorithm '{algo}' is not supported.", ['algo' => $algo]), Status::BAD_REQUEST);
        }

        try {
            $body = (string) $request->getBody();
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
        } catch (Throwable) {
            return api_error('Unable to verify the signature.', Status::BAD_REQUEST);
        }

        $creds = $this->filterCreds(AuthorizationMiddleware::parseAuthTokens($request), $with);
        if (count($creds) < 1) {
            return api_error('No credentials were found.', Status::BAD_REQUEST);
        }

        foreach ($creds as $credential) {
            $local = hash_hmac($algo, $body, (string) $credential);
            if (hash_equals($local, $signature)) {
                return $handler->handle($request);
            }
        }

        return api_error('Invalid Signature was detected.', Status::FORBIDDEN);
    }

    /**
     * @param array<string, string> $creds
     *
     * @return array<string, string>
     */
    private function filterCreds(array $creds, string $with): array
    {
        // @mago-expect lint:no-insecure-comparison
        $keys = self::WITH_TOKEN === $with ? ['token', 'ws_token'] : ['header', 'param', 'bearer'];

        return array_intersect_key($creds, array_flip($keys));
    }
}
