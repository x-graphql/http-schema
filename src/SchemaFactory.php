<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildClientSchema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaPrinter;
use Http\Promise\Promise;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use XGraphQL\HttpSchema\Exception\RuntimeException;
use XGraphQL\HttpSchema\QueryExecutor\QueryExecutorInterface;

final readonly class SchemaFactory
{
    public const SDL_CACHE_KEY = '_sdl';

    public const INTROSPECTION_QUERY_CACHE_KEY = '_introspection_query';

    public function __construct(
        private QueryExecutorInterface $queryExecutor,
        private ?CacheInterface $astCache = null,
    ) {
    }


    /**
     * @throws SerializationError
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws SyntaxError
     * @throws Error
     * @throws \ReflectionException
     */
    public function fromSDL(string $sdl, bool $force = false): Schema
    {
        $cacheKey = self::SDL_CACHE_KEY;

        if (false === $force) {
            $schema = $this->loadSchemaFromCache($cacheKey);

            if (false !== $schema) {
                return $schema;
            }
        }

        $schema = BuildSchema::build($sdl);

        $this->saveSchemaToCache($cacheKey, $schema);
        $this->addRootFieldsResolver($schema);

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
    public function fromIntrospectionQuery(string $introspectionQuery = null, bool $force = false): Schema
    {
        $introspectionQuery ??= Introspection::getIntrospectionQuery();
        $cacheKey = self::INTROSPECTION_QUERY_CACHE_KEY;

        if (false === $force) {
            $schema = $this->loadSchemaFromCache($cacheKey);

            if (false !== $schema) {
                return $schema;
            }
        }

        $introspectionResult = $this->queryExecutor->execute($introspectionQuery);

        if ($introspectionResult instanceof Promise) {
            $introspectionResult = $introspectionResult->wait();
        }

        if (isset($introspectionResult['errors'])) {
            throw new RuntimeException('Got errors when introspect schema from upstream');
        }

        $schema = BuildClientSchema::build($introspectionResult['data']);

        $this->saveSchemaToCache($cacheKey, $schema);
        $this->addRootFieldsResolver($schema);

        return $schema;
    }

    /**
     * @throws SyntaxError
     * @throws Error
     * @throws \ReflectionException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    private function loadSchemaFromCache(string $cacheKey): false|Schema
    {
        if (true !== $this->astCache?->has($cacheKey)) {
            return false;
        }

        $astCached = $this->astCache->get($cacheKey);
        $ast = AST::fromArray($astCached);
        $schema = BuildSchema::build($ast, options: ['assumeValidSDL' => true]);

        $this->addRootFieldsResolver($schema);

        return $schema;
    }

    /**
     * @throws SyntaxError
     * @throws Error
     * @throws SerializationError
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    private function saveSchemaToCache(string $cacheKey, Schema $schema): bool
    {
        if (null === $this->astCache) {
            return false;
        }

        $sdl = SchemaPrinter::doPrint($schema);
        $ast = Parser::parse($sdl, ['noLocation' => true]);
        $astNormalized = AST::toArray($ast);

        return $this->astCache->set($cacheKey, $astNormalized);
    }

    private function addRootFieldsResolver(Schema $schema): void
    {
        foreach (['query', 'mutation'] as $operation) {
            $rootFieldResolver = new RootFieldsResolver($this->queryExecutor);
            $rootType = $schema->getOperationType($operation);

            if (null !== $rootType) {
                $rootType->resolveFieldFn = $rootFieldResolver;
            }
        }

        /// Not support subscription yet.
        $schema->getConfig()->subscription = null;
    }
}
