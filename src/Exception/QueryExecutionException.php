<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\Exception;

use Psr\Http\Message\ResponseInterface;

final class QueryExecutionException extends RuntimeException implements ExceptionInterface
{
    public readonly ResponseInterface $httpResponse;

    public function setHttpResponse(ResponseInterface $httpResponse): void
    {
        $this->httpResponse = $httpResponse;
    }
}
