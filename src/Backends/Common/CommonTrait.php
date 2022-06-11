<?php

declare(strict_types=1);

namespace App\Backends\Common;

trait CommonTrait
{
    /**
     * Wrap Closure into try catch response.
     *
     * @param Context $context Context to associate the call with.
     * @param callable():Response $fn Closure
     * @param string|null $action the action name to personalize the message.
     *
     * @return Response We should Expand the catch to include common http errors. json decode failing.
     */
    protected function tryResponse(Context $context, callable $fn, string|null $action = null): Response
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return new Response(
                status: false,
                error:  new Error(
                            message:  'Unhandled exception was thrown in [%(client): %(backend)] %(action). %(message)',
                            context:  [
                                          'action' => $action ?? 'context',
                                          'backend' => $context->backendName,
                                          'client' => $context->clientName,
                                          'message' => $e->getMessage(),
                                          'exception' => [
                                              'file' => $e->getFile(),
                                              'line' => $e->getLine(),
                                              'kind' => get_class($e),
                                              'message' => $e->getMessage(),
                                          ]
                                      ],
                            level:    Levels::WARNING,
                            previous: $e
                        )
            );
        }
    }
}
