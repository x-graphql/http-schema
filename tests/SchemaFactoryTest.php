<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\Test;

use GraphQL\Error\Error;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use Http\Discovery\Psr18ClientDiscovery;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\TraceableHttpClient;
use XGraphQL\HttpSchema\Exception\RuntimeException;
use XGraphQL\HttpSchema\QueryExecutor\QueryExecutor;
use XGraphQL\HttpSchema\QueryExecutor\QueryExecutorInterface;
use XGraphQL\HttpSchema\SchemaFactory;

class SchemaFactoryTest extends TestCase
{
    public function testConstructor(): void
    {
        $executor = $this->createStub(QueryExecutorInterface::class);
        $cache = $this->createStub(CacheInterface::class);
        $instance = new SchemaFactory($executor);
        $instanceWithCache = new SchemaFactory($executor, $cache);

        $this->assertInstanceOf(SchemaFactory::class, $instance);
        $this->assertInstanceOf(SchemaFactory::class, $instanceWithCache);
    }

    public function testCreateSchemaFromSDL(): void
    {
        $executor = $this->createStub(QueryExecutorInterface::class);
        $instance = new SchemaFactory($executor);
        $schema = $instance->fromSDL(
            <<<'GQL'
schema {
  query: Query
}

type Query {
  dummy: String!
}
GQL
        );

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testCreateSchemaFromSDLWithCache(): void
    {
        $executor = $this->createStub(QueryExecutorInterface::class);
        $cache = new Psr16Cache(new ArrayAdapter());
        $instance = new SchemaFactory($executor, $cache);
        $sdl = <<<'GQL'
schema {
  query: Query
}

type Query {
  dummy: String!
}
GQL;
        $this->assertFalse($cache->has(SchemaFactory::SDL_CACHE_KEY));

        $schema = $instance->fromSDL($sdl);

        $this->assertTrue($cache->has(SchemaFactory::SDL_CACHE_KEY));
        $this->assertInstanceOf(Schema::class, $schema);

        $schemaFromCache = $instance->fromSDL($sdl);

        $this->assertNotSame($schema, $schemaFromCache);
    }

    public function testCreateSchemaFromIntrospectionQuery(): void
    {
        $executor = new QueryExecutor('POST', 'https://countries.trevorblades.com/');
        $instance = new SchemaFactory($executor);
        $schema = $instance->fromIntrospectionQuery();

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testCreateSchemaFromIntrospectionQueryWithCache(): void
    {
        $executor = new QueryExecutor('POST', 'https://countries.trevorblades.com/');
        $cache = new Psr16Cache(new ArrayAdapter());
        $instance = new SchemaFactory($executor, $cache);

        $this->assertFalse($cache->has(SchemaFactory::INTROSPECTION_QUERY_CACHE_KEY));

        $schema = $instance->fromIntrospectionQuery();

        $this->assertTrue($cache->has(SchemaFactory::INTROSPECTION_QUERY_CACHE_KEY));
        $this->assertInstanceOf(Schema::class, $schema);

        $schemaFromCache = $instance->fromIntrospectionQuery();

        $this->assertNotSame($schema, $schemaFromCache);

        $instance->fromIntrospectionQuery(force: true);
    }

    public function testCreateSchemaFromInvalidSDL(): void
    {
        $executor = $this->createStub(QueryExecutorInterface::class);
        $instance = new SchemaFactory($executor);

        $this->expectException(Error::class);

        $instance->fromSDL(
            <<<'SDL'
schema {
  query: MissingType
}
SDL
        );
    }

    public function testCreateSchemaFromIntrospectionQueryAndReceivedErrors(): void
    {
        $executor = $this->createMock(QueryExecutorInterface::class);

        $executor
            ->expects($this->once())
            ->method('execute')
            ->willReturn(
                [
                    'errors' => [
                        ['message' => 'introspect error']
                    ]
                ]
            );

        $instance = new SchemaFactory($executor);

        $this->expectException(RuntimeException::class);

        $instance->fromIntrospectionQuery();
    }
}
