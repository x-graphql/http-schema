<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\Test\QueryExecutor;

use Http\Promise\Promise;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttplugClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\TraceableHttpClient;
use XGraphQL\HttpSchema\Exception\QueryExecutionException;
use XGraphQL\HttpSchema\QueryExecutor\QueryExecutor;

class QueryExecutorTest extends TestCase
{
    public function testExecuteWithPsr18Client(): void
    {
        $executor = new QueryExecutor(
            'POST',
            'https://countries.trevorblades.com/',
            client: new Psr18Client(HttpClient::create()),
        );
        $gql = <<<'GQL'
query getCountries {
  countries {
    name
  }
}
query getCountry($code: ID!) {
  country(code: $code) {
    name
  }
}
GQL;
        $getCountryResult = $executor->execute($gql, ['code' => 'VN'], 'getCountry');

        $this->assertIsArray($getCountryResult);
        $this->assertNotEmpty($getCountryResult['data']);

        $getCountriesResult = $executor->execute($gql, null, 'getCountries');

        $this->assertIsArray($getCountriesResult);
        $this->assertNotEmpty($getCountriesResult['data']);
    }

    public function testExecuteWithAsyncClient(): void
    {
        $executor = new QueryExecutor(
            'POST',
            'https://countries.trevorblades.com/',
            client: new HttplugClient(HttpClient::create()),
        );

        $promise = $executor->execute('query { country(code: "VN") { name } }');

        $this->assertInstanceOf(Promise::class, $promise);

        $result = $promise->wait();

        $this->assertEquals('Vietnam', $result['data']['country']['name']);
    }

    public function testExecuteWithHeaders(): void
    {
        $traceableClient = new TraceableHttpClient(HttpClient::create());
        $psr18Client = new Psr18Client($traceableClient);
        $executor = new QueryExecutor(
            'POST',
            'https://countries.trevorblades.com/',
            [
                'user-agent' => 'x-graphql'
            ],
            $psr18Client,
        );

        $executor->execute('query { countries { name } }');

        $traceRequests = $traceableClient->getTracedRequests();

        $this->assertEquals(['x-graphql'], $traceRequests[0]['options']['headers']['user-agent']);
    }

    public function testExecuteReceiveErrors(): void
    {
        $executor = new QueryExecutor(
            'POST',
            'https://httpbin.org/status/502',
            client: new Psr18Client(HttpClient::create()),
        );

        $this->expectException(QueryExecutionException::class);

        $executor->execute('query { country(code: "VN") { name } }');
    }
}
