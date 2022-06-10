<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Closure;
use Throwable;

trait CommonTrait
{
    /**
     * Wrap Closure into try catch response.
     *
     * @param Context $context Context to associate the call with.
     * @param Closure(): Response $fn Closure
     *
     * @return Response
     */
    protected function tryResponse(Context $context, Closure $fn): Response
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error:  new Error(
                            message:  'Unhandled exception was thrown in [%(client): %(backend)] context.',
                            context:  [
                                          'backend' => $context->backendName,
                                          'client' => $context->clientName,
                                          'exception' => [
                                              'file' => $e->getFile(),
                                              'line' => $e->getLine(),
                                              'kind' => get_class($e),
                                              'message' => $e->getMessage(),
                                          ]
                                      ],
                            previous: $e
                        )
            );
        }
    }
}
