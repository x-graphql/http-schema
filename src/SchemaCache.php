<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaPrinter;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

final readonly class SchemaCache
{
    public const CACHE_KEY = '_x_graphql_ast_http_schema';

    public function __construct(private CacheInterface $psr16Cache)
    {
    }


    /**
     * @throws SyntaxError
     * @throws Error
     * @throws \ReflectionException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function loadSchemaFromCache(): ?Schema
    {
        if (!$this->psr16Cache->has(self::CACHE_KEY)) {
            return null;
        }

        $astCached = $this->psr16Cache->get(self::CACHE_KEY);

        return BuildSchema::build(AST::fromArray($astCached), options: ['assumeValidSDL' => true]);
    }

    /**
     * @throws SyntaxError
     * @throws Error
     * @throws SerializationError
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function saveSchemaToCache(Schema $schema): bool
    {
        $sdl = SchemaPrinter::doPrint($schema);
        $ast = Parser::parse($sdl, ['noLocation' => true]);
        $astNormalized = AST::toArray($ast);

        return $this->psr16Cache->set(self::CACHE_KEY, $astNormalized);
    }
}
