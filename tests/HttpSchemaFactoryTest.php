<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\Test;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttplugClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\TraceableHttpClient;
use XGraphQL\HttpSchema\Exception\RuntimeException;
use XGraphQL\HttpSchema\HttpDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;
use XGraphQL\SchemaCache\SchemaCache;

class HttpSchemaFactoryTest extends TestCase
{
    public function testConstructor(): void
    {
        $delegator = new HttpDelegator('https://countries.trevorblades.com/');
        $cache = $this->createStub(CacheInterface::class);
        $instance = new HttpSchemaFactory($delegator);
        $instanceWithCache = new HttpSchemaFactory($delegator, $cache);

        $this->assertInstanceOf(HttpSchemaFactory::class, $instance);
        $this->assertInstanceOf(HttpSchemaFactory::class, $instanceWithCache);
    }

    public function testCreateSchemaFromSDL(): void
    {
        $delegator = new HttpDelegator('https://countries.trevorblades.com/');
        $schema = HttpSchemaFactory::createFromSDL(
            $delegator,
            <<<'GQL'
schema {
  query: Query
}

type Query {
  dummy: String!
}
GQL,
        );

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testCreateSchemaFromSDLWithCache(): void
    {
        $delegator = new HttpDelegator('https://countries.trevorblades.com/');
        $arrayCache = new ArrayAdapter();
        $schemaCache = new SchemaCache(new Psr16Cache($arrayCache));

        $sdl = <<<'GQL'
schema {
  query: Query
}

type Query {
  dummy: String!
}
GQL;
        $this->assertEmpty($arrayCache->getValues());

        $schema = HttpSchemaFactory::createFromSDL($delegator, $sdl, $schemaCache);

        $this->assertNotEmpty($arrayCache->getValues());

        $schemaFromCache = HttpSchemaFactory::createFromSDL($delegator, $sdl, $schemaCache);

        $this->assertInstanceOf(ObjectType::class, $schema->getConfig()->query);
        $this->assertIsCallable($schemaFromCache->getConfig()->query);
    }

    public function testCreateSchemaFromIntrospectionQuery(): void
    {
        $delegator = new HttpDelegator('https://countries.trevorblades.com/');
        $schema = HttpSchemaFactory::createFromIntrospectionQuery($delegator);

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testCreateSchemaFromIntrospectionQueryWithCache(): void
    {
        $client = new TraceableHttpClient(HttpClient::create());
        $delegator = new HttpDelegator('https://countries.trevorblades.com/', client: new HttplugClient($client));
        $arrayCache = new ArrayAdapter();
        $schemaCache = new SchemaCache(new Psr16Cache($arrayCache));

        $this->assertEmpty($arrayCache->getValues());

        $schema = HttpSchemaFactory::createFromIntrospectionQuery($delegator, $schemaCache);

        $this->assertCount(1, $client->getTracedRequests());
        $this->assertNotEmpty($arrayCache->getValues());

        $schemaFromCache = HttpSchemaFactory::createFromIntrospectionQuery($delegator, $schemaCache);

        $this->assertCount(1, $client->getTracedRequests());
        $this->assertInstanceOf(ObjectType::class, $schema->getConfig()->query);
        $this->assertIsCallable($schemaFromCache->getConfig()->query);
    }

    public function testCreateSchemaFromInvalidSDL(): void
    {
        $delegator = new HttpDelegator('https://countries.trevorblades.com/');

        $this->expectException(Error::class);

        HttpSchemaFactory::createFromSDL(
            $delegator,
            <<<'SDL'
schema {
  query: MissingType
}
SDL,
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
        $delegator = new HttpDelegator('https://countries.trevorblades.com/', client: $client);

        $this->expectException(RuntimeException::class);

        HttpSchemaFactory::createFromIntrospectionQuery($delegator);
    }
}
