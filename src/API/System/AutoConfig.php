<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class AutoConfig
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/auto';

    public function __construct(private readonly iImport $mapper, private readonly iLogger $logger)
    {
    }

    #[Post(self::URL . '[/]', name: 'system.autoconfig')]
    public function __invoke(iRequest $request): iResponse
    {
        $isEnabled = false;
        try {
            $initial_file = Config::get('path') . '/config/disable_auto_config.txt';
            if (false === file_exists($initial_file)) {
                $uc = $this->getUserContext($request, $this->mapper, $this->logger);
                $isEnabled = 'main' === $uc->name && count($uc->config) < 1;
                $stream = Stream::make($initial_file, 'w+');
                $stream->write(r('Auto configure was called and disabled at {time}', [
                    'time' => makeDate('now'),
                ]));
                $stream->close();
            }
        } catch (Throwable $e) {
            syslog(LOG_ERR, __METHOD__ . ' Exception: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
        }

        if (false === $isEnabled && false === (bool)Config::get('api.auto', false)) {
            return api_error('auto configuration is disabled.', Status::FORBIDDEN);
        }

        $data = DataUtil::fromRequest($request);

        return api_response(Status::OK, [
            'url' => $data->get('origin', ag($_SERVER, 'HTTP_ORIGIN', 'localhost')),
            'path' => Config::get('api.prefix'),
            'token' => Config::get('api.key'),
        ]);
    }
}
