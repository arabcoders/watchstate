<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class UrlChecker
{
    public const string URL = '%{api.prefix}/system/url/check';

    #[Post(self::URL . '[/]', name: 'system.url.check')]
    public function __invoke(iRequest $request, iHttp $client): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($url = $params->get('url', null))) {
            return api_error('No url was given.', Status::BAD_REQUEST);
        }

        if (false === is_valid_url($url)) {
            return api_error('Invalid url.', Status::BAD_REQUEST);
        }

        if (null === ($method = Method::tryFrom(strtoupper($params->get('method', 'GET'))))) {
            return api_error('Invalid request method.', Status::BAD_REQUEST);
        }

        $headers = [];

        $timeout = 10;

        foreach ($params->get('headers', []) as $header) {
            $headerKey = ag($header, 'key');
            $headerValue = ag($header, 'value');
            if (empty($headerKey) || empty($headerValue)) {
                continue;
            }
            if ('ws-timeout' === $headerKey) {
                $timeout = (int) $headerValue;
                continue;
            }
            $headers[$headerKey] = $headerValue;
        }

        try {
            set_time_limit(60 * 10);

            $response = $client->request($method->value, $url, [
                'timeout' => $timeout,
                'headers' => $headers,
            ]);
            $flattenedHeaders = [];
            foreach ($response->getHeaders(false) as $key => $value) {
                if (is_array($value)) {
                    $flattenedHeaders[$key] = implode(', ', $value);
                } else {
                    $flattenedHeaders[$key] = $value;
                }
            }

            return api_response(Status::OK, [
                'request' => [
                    'url' => $url,
                    'method' => $method->value,
                    'headers' => $headers,
                ],
                'response' => [
                    'status' => $response->getStatusCode(),
                    'headers' => $flattenedHeaders,
                    'body' => $response->getContent(false),
                ],
            ]);
        } catch (Throwable $e) {
            return api_response(Status::OK, [
                'request' => [
                    'url' => $url,
                    'method' => $method->value,
                    'headers' => $headers,
                ],
                'response' => [
                    'status' => Status::INTERNAL_SERVER_ERROR->value,
                    'headers' => [
                        'WS-Exception' => $e::class,
                        'WS-Error' => $e->getMessage(),
                    ],
                    'body' => $e->getMessage(),
                ],
            ]);
        }
    }
}
