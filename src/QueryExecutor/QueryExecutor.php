<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\QueryExecutor;

use Http\Client\HttpAsyncClient;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\HttpAsyncClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Promise\Promise;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use XGraphQL\HttpSchema\Exception\QueryExecutionException;

final readonly class QueryExecutor implements QueryExecutorInterface
{
    private ClientInterface|HttpAsyncClient $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private string $method,
        private string|UriInterface $uri,
        private array $headers = [],
        ClientInterface|HttpAsyncClient $client = null,
        RequestFactoryInterface $requestFactory = null,
        StreamFactoryInterface $streamFactory = null,
    ) {
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        if (null === $client) {
            try {
                $this->client = HttpAsyncClientDiscovery::find();
            } catch (NotFoundException) {
                $this->client = Psr18ClientDiscovery::find();
            }
        } else {
            $this->client = $client;
        }
    }

    public function execute(string $query, ?array $variables = null, ?string $operationName = null): array|Promise
    {
        $payload = array_filter(compact('query', 'variables', 'operationName'));
        $jsonPayload = json_encode($payload);
        $stream = $this->streamFactory->createStream($jsonPayload);
        $request = $this
            ->requestFactory
            ->createRequest($this->method, $this->uri)
            ->withBody($stream);

        foreach ($this->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        if (false === $request->hasHeader('content-type')) {
            $request = $request->withHeader('content-type', 'application/json');
        }

        if ($this->client instanceof HttpAsyncClient) {
            $promise = $this->client->sendAsyncRequest($request);

            return $promise->then($this->resolveResponse(...));
        }

        $response = $this->client->sendRequest($request);

        return $this->resolveResponse($response);
    }

    private function resolveResponse(ResponseInterface $response): array
    {
        $body = $response->getBody();

        $body->rewind();

        $result = json_decode($body->getContents(), true);

        if (\JSON_ERROR_NONE !== json_last_error() || (!isset($result['data']) && !isset($result['errors']))) {
            $exception = new QueryExecutionException('Result received from upstream is invalid json format');
            $exception->setHttpResponse($response);

            throw $exception;
        }

        return $result;
    }
}
