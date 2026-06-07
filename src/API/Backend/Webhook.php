<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Webhook
{
    use APITraits;

    #[Post(Index::URL . '/{name:backend}/webhook[/]', name: 'backend.webhook')]
    public function __invoke(iRequest $request, string $name, iImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        try {
            $client = $this->getClient(name: $name, userContext: $userContext);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request, true);
        $webhookUrl = $params->get('webhook_url');

        if (null === $webhookUrl || '' === trim((string) $webhookUrl)) {
            return api_error('No webhook URL provided.', Status::BAD_REQUEST);
        }

        if (false === is_valid_url($webhookUrl)) {
            return api_error('Invalid webhook URL provided.', Status::BAD_REQUEST);
        }

        try {
            $response = $client->addWebhook((string) $webhookUrl);

            if (false === $response->isSuccessful()) {
                $message = $response->error?->format() ?? 'Failed to add webhook.';
                return api_error($message, Status::SERVICE_UNAVAILABLE);
            }

            return api_response(Status::OK, ['message' => 'Webhook configured successfully.']);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
