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
use Psr\SimpleCache\InvalidArgumentException;
use XGraphQL\DelegateExecution\ErrorsReporterInterface;
use XGraphQL\DelegateExecution\Execution;
use XGraphQL\HttpSchema\Exception\RuntimeException;
use XGraphQL\SchemaCache\SchemaCache;

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
        SchemaCache $cache = null,
        ErrorsReporterInterface $errorsReporter = null,
    ): Schema {
        $schema = $cache?->load();

        if (null === $schema) {
            $schema = BuildSchema::build($sdl);
            $cache?->save($schema);
        }

        return Execution::delegate($schema, $delegator, $errorsReporter);
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
        SchemaCache $cache = null,
        string $introspectionQuery = null,
        ErrorsReporterInterface $errorsReporter = null
    ): Schema {
        $schema = $cache?->load();

        if (null === $schema) {
            $introspectionQuery ??= Introspection::getIntrospectionQuery();
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
            $cache?->save($schema);
        }

        return Execution::delegate($schema, $delegator, $errorsReporter);
    }
}
