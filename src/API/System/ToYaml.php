<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Yaml;

final readonly class ToYaml
{
    public const string URL = '%{api.prefix}/system/yaml';

    #[Post(self::URL . '[/[{filename}[/]]]', name: 'system.to_yaml')]
    public function __invoke(iRequest $request, string|null $filename = null): iResponse
    {
        $params = DataUtil::fromArray($request->getQueryParams());
        try {
            $stream = Stream::create(Yaml::dump(
                input: $request->getParsedBody(),
                inline: (int)$params->get('inline', 4),
                indent: (int)$params->get('indent', 2),
                flags: Yaml::DUMP_OBJECT | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            ));
        } catch (DumpException $e) {
            return api_error(r("Failed to convert to yaml. '{error}'.", ['error' => $e->getMessage()]), Status::BAD_REQUEST);
        }

        return api_response(Status::OK, body:$stream, headers: [
            'Content-Type' => 'text/yaml',
            'Content-Disposition' => r('{mode}; filename="{filename}"', [
                'mode' => $filename ? 'attachment' : 'inline',
                'filename' => $filename ?? 'to_yaml.yaml',
            ])
        ]);
    }
}
