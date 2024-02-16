<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema;

use GraphQL\Deferred;
use GraphQL\Error\Error;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Introspection;
use Http\Promise\Promise as HttpPromise;
use XGraphQL\HttpSchema\Exception\LogicException;
use XGraphQL\HttpSchema\QueryExecutor\QueryExecutorInterface;
use XGraphQL\Utils\SelectionSet;

final class RootFieldsResolver
{
    /**
     * @var \WeakMap<OperationDefinitionNode, Deferred>
     */
    private \WeakMap $delegatedResults;

    public function __construct(private QueryExecutorInterface $queryExecutor)
    {
        $this->delegatedResults = new \WeakMap();
    }

    public function __invoke(mixed $value, array $args, mixed $context, ResolveInfo $info): SyncPromise
    {
        if (!isset($this->delegatedResults[$info->operation])) {
            $this->delegatedResults[$info->operation] = $this->delegateQuery(
                $info->operation->cloneDeep(),
                array_map(fn(FragmentDefinitionNode $def) => $def->cloneDeep(), $info->fragments),
                $info->variableValues
            );
        }

        return $this->resolve($info);
    }

    private function delegateQuery(OperationDefinitionNode $operation, array $fragments, array $variables): Deferred
    {
        /// Add typename for detecting object type of interface or union
        SelectionSet::addTypename($operation->getSelectionSet());
        SelectionSet::addTypenameToFragments($fragments);

        $queryBlocks = [];

        foreach ($fragments as $fragment) {
            $queryBlocks[] = Printer::doPrint($fragment);
        }

        $queryBlocks[] = Printer::doPrint($operation);
        $query = implode(PHP_EOL, $queryBlocks);

        $promiseOrResult = $this->queryExecutor->execute($query, $variables, $operation->name?->value);

        return new Deferred(
            fn() => $promiseOrResult instanceof HttpPromise ? $promiseOrResult->wait() : $promiseOrResult
        );
    }

    private function resolve(ResolveInfo $info): SyncPromise
    {
        $defer = $this->delegatedResults[$info->operation];
        $type = $info->returnType;

        if ($type instanceof WrappingType) {
            $type = $type->getInnermostType();
        }

        if ($type instanceof AbstractType) {
            $type->config['resolveType'] ??= $this->resolveAbstractType(...);
        }

        if ($type instanceof ObjectType) {
            $type->resolveFieldFn ??= $this->resolveObjectFields(...);
        }

        return $defer->then(fn(array $result) => $this->accessResultDataByPath($info->path, $result));
    }

    private function resolveAbstractType(array $value, mixed $context, ResolveInfo $info): Type
    {
        /// __typename field should be existed in $value
        ///  because we have added it to delegated query
        $implTypename = $value[Introspection::TYPE_NAME_FIELD_NAME];
        $abstractType = $info->fieldDefinition->getType();

        if ($abstractType instanceof WrappingType) {
            $abstractType = $abstractType->getInnermostType();
        }

        foreach ($info->schema->getPossibleTypes($abstractType) as $type) {
            if ($type->name() !== $implTypename) {
                continue;
            }

            if ($type instanceof AbstractType) {
                $type->config['resolveType'] ??= $this->resolveAbstractType(...);
            }

            if ($type instanceof ObjectType) {
                $type->resolveFieldFn ??= $this->resolveObjectFields(...);
            }

            return $type;
        }

        throw new LogicException(
            sprintf('Expect type: `%s` implementing `%s` should be exist in schema', $implTypename, $abstractType)
        );
    }

    private function resolveObjectFields(array $value, array $args, mixed $context, ResolveInfo $info): SyncPromise
    {
        return $this->resolve($info);
    }

    /**
     * @throws Error
     */
    private function accessResultDataByPath(array $path, array $result): mixed
    {
        $data = $result['data'] ?? [];
        $errors = $result['errors'] ?? [];
        $pathAccessed = $path;

        $this->throwErrorByPathIfExists($path, $errors);

        while ([] !== $pathAccessed) {
            $pos = array_shift($pathAccessed);

            if (false === array_key_exists($pos, $data)) {
                throw new Error(
                    sprintf('Response data from upstream is missing field value at path: `%s`', implode('.', $path))
                );
            }

            $data = $data[$pos];
        }

        return $data;
    }

    /**
     * @throws Error
     */
    private function throwErrorByPathIfExists(array $path, array $errors): void
    {
        foreach ($errors as $error) {
            if (isset($error['path']) && $error['path'] === $path) {
                throw new Error($error['message'] ?? 'Unknown error');
            }
        }
    }
}
