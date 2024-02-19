<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\Test;

use GraphQL\Error\Error;
use GraphQL\Type\Schema;
use Http\Discovery\Psr17FactoryDiscovery;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use XGraphQL\HttpSchema\Exception\RuntimeException;
use XGraphQL\HttpSchema\HttpExecutionDelegator;
use XGraphQL\HttpSchema\SchemaFactory;

class SchemaFactoryTest extends TestCase
{
    public function testConstructor(): void
    {
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $cache = $this->createStub(CacheInterface::class);
        $instance = new SchemaFactory($delegator);
        $instanceWithCache = new SchemaFactory($delegator, $cache);

        $this->assertInstanceOf(SchemaFactory::class, $instance);
        $this->assertInstanceOf(SchemaFactory::class, $instanceWithCache);
    }

    public function testCreateSchemaFromSDL(): void
    {
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $instance = new SchemaFactory($delegator);
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
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $cache = new Psr16Cache(new ArrayAdapter());
        $instance = new SchemaFactory($delegator, $cache);
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
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $instance = new SchemaFactory($delegator);
        $schema = $instance->fromIntrospectionQuery();

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testCreateSchemaFromIntrospectionQueryWithCache(): void
    {
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $cache = new Psr16Cache(new ArrayAdapter());
        $instance = new SchemaFactory($delegator, $cache);

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
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $instance = new SchemaFactory($delegator);

        $this->expectException(Error::class);

        $instance->fromSDL(
            <<<'SDL'
schema {
  query: MissingType
}
SDL
        );
    }

    public function testCreateSchemaFromIntrospectionQueryError(): void
    {
        $mockClient = new MockHttpClient(
            [
                new MockResponse(
                    json_encode(
                        [
                            'errors' => [
                                ['message' => 'introspect error']
                            ]
                        ]
                    )
                )
            ]
        );
        $client = new Psr18Client($mockClient);
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/', client: $client);
        $instance = new SchemaFactory($delegator);

        $this->expectException(RuntimeException::class);

        $instance->fromIntrospectionQuery();
    }
}
