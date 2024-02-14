<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\QueryExecutor;

use Http\Promise\Promise;

interface QueryExecutorInterface
{
    public function execute(string $query, ?array $variables = null, ?string $operationName = null): array|Promise;
}
