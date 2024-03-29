<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Container;
use Psr\Log\LoggerInterface;
use Throwable;

trait CommonTrait
{
    /**
     * Wrap closure into try catch response.
     *
     * @param Context $context Context to associate the call with.
     * @param callable():Response $fn Closure
     * @param string|null $action the action name to make error message more clear.
     *
     * @return Response Response object.
     * @todo Expand the catch to include common http errors. json decode failing.
     * @todo raise the log level to error instead of warning as it's currently doing, warning imply it's ok to ignore.
     */
    protected function tryResponse(Context $context, callable $fn, string|null $action = null): Response
    {
        try {
            $response = $fn();

            if (false === ($response instanceof Response)) {
                return new Response(status: true, response: $response);
            }

            return $response;
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Exception [{error.kind}] was thrown unhandled in [{client}: {backend}] {action}. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'action' => $action ?? 'not_set',
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'message' => $e->getMessage(),
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ]
                    ],
                    level: Levels::WARNING,
                    previous: $e
                )
            );
        }
    }

    /**
     * Get Logger.
     *
     * @return LoggerInterface Return the logger.
     */
    protected function getLogger(): LoggerInterface
    {
        return Container::get(LoggerInterface::class);
    }
}
