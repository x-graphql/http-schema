<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Error\SyntaxError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildClientSchema;
use GraphQL\Utils\BuildSchema;
use Http\Promise\Promise;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use XGraphQL\DelegateExecution\ErrorsReporterInterface;
use XGraphQL\DelegateExecution\Execution;
use XGraphQL\HttpSchema\Exception\RuntimeException;

final readonly class HttpSchemaFactory
{
    /**
     * @throws SerializationError
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws SyntaxError
     * @throws Error
     * @throws \ReflectionException
     */
    public static function createFromSDL(
        HttpDelegator $delegator,
        string $sdl,
        CacheInterface $cache = null,
        ErrorsReporterInterface $errorsReporter = null,
    ): Schema {
        $schemaCache = null !== $cache ? new SchemaCache($cache) : null;
        $schema = $schemaCache?->loadSchemaFromCache();

        if (null === $schema) {
            $schema = BuildSchema::build($sdl);
            $schemaCache?->saveSchemaToCache($schema);
        }

        Execution::delegate($schema, $delegator, $errorsReporter);

        return $schema;
    }

    /**
     * @throws SerializationError
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws SyntaxError
     * @throws \Throwable
     * @throws Error
     * @throws \ReflectionException
     */
    public static function createFromIntrospectionQuery(
        HttpDelegator $delegator,
        CacheInterface $cache = null,
        string $introspectionQuery = null,
        ErrorsReporterInterface $errorsReporter = null
    ): Schema {
        $introspectionQuery ??= Introspection::getIntrospectionQuery();
        $schemaCache = null !== $cache ? new SchemaCache($cache) : null;
        $schema = $schemaCache?->loadSchemaFromCache();

        if (null === $schema) {
            $promiseOrResult = $delegator->executeQuery($introspectionQuery);

            if ($promiseOrResult instanceof Promise) {
                /** @var ExecutionResult $result */
                $result = $promiseOrResult->wait();
            } else {
                $result = $promiseOrResult;
            }

            if ([] !== $result->errors) {
                throw new RuntimeException('Got errors when introspect schema from upstream');
            }

            $schema = BuildClientSchema::build($result->data);
            $schemaCache?->saveSchemaToCache($schema);
        }

        Execution::delegate($schema, $delegator, $errorsReporter);

        return $schema;
    }
}
