<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Throwable;

trait CommonTrait
{
    /**
     * This method Wrap $fn() call in to a try catch.
     *
     * @param Context $context
     * @param callable $fn
     *
     * @return Response
     */
    protected function tryResponse(Context $context, callable $fn): Response
    {
        try {
            return new Response(status: true, response: $fn());
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error:  new Error(
                            message:  'Unhandled exception was thrown during [%(backend)] request inspection.',
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
