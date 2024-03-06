<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Printer;
use GraphQL\Type\Schema;
use Http\Client\HttpAsyncClient;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\HttpAsyncClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Promise\Promise as HttpPromise;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use XGraphQL\DelegateExecution\ExecutionDelegatorInterface;
use XGraphQL\HttpSchema\Exception\HttpExecutionException;

final readonly class HttpExecutionDelegator implements ExecutionDelegatorInterface
{
    private ClientInterface|HttpAsyncClient $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    private PromiseAdapter $promiseAdapter;

    public function __construct(
        private string|UriInterface $uri,
        private array $headers = [],
        ClientInterface|HttpAsyncClient $client = null,
        RequestFactoryInterface $requestFactory = null,
        StreamFactoryInterface $streamFactory = null,
        PromiseAdapter $promiseAdapter = null,
    ) {
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->promiseAdapter = $promiseAdapter ?? new SyncPromiseAdapter();

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

    public function getPromiseAdapter(): PromiseAdapter
    {
        return $this->promiseAdapter;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function delegate(
        Schema $executionSchema,
        OperationDefinitionNode $operation,
        array $fragments = [],
        array $variables = []
    ): Promise {
        $queryBlocks = [];

        foreach ($fragments as $fragment) {
            $queryBlocks[] = Printer::doPrint($fragment);
        }

        $queryBlocks[] = Printer::doPrint($operation);

        $query = implode(PHP_EOL, $queryBlocks);

        $promiseOrResult = $this->executeQuery($query, $variables, $operation->name?->value);

        if ($promiseOrResult instanceof HttpPromise) {
            return $this
                ->promiseAdapter
                ->create(
                    fn (callable $resolve) => $resolve($promiseOrResult->wait())
                );
        }

        return $this->promiseAdapter->createFulfilled($promiseOrResult);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws \Exception
     */
    public function executeQuery(
        string $query,
        array $variables = null,
        string $operationName = null
    ): ExecutionResult|HttpPromise {
        $body = json_encode(
            array_filter(
                compact('query', 'variables', 'operationName')
            )
        );
        $stream = $this->streamFactory->createStream($body);
        $request = $this
            ->requestFactory
            ->createRequest('POST', $this->uri)
            ->withBody($stream);

        foreach ($this->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        if (false === $request->hasHeader('content-type')) {
            $request = $request->withHeader('content-type', 'application/json');
        }

        if ($this->client instanceof HttpAsyncClient) {
            return $this->client->sendAsyncRequest($request)->then($this->resolveHttpResponse(...));
        }

        $response = $this->client->sendRequest($request);

        return $this->resolveHttpResponse($response);
    }

    private function resolveHttpResponse(ResponseInterface $response): ExecutionResult
    {
        $body = $response->getBody();

        $body->rewind();

        $resultRaw = json_decode($body->getContents(), true);

        if (
            \JSON_ERROR_NONE !== json_last_error()
            || (
                !isset($resultRaw['data'])
                && !isset($resultRaw['errors'])
                && !isset($resultRaw['extensions'])
            )
        ) {
            $exception = new HttpExecutionException('Result received from upstream is invalid json format');
            $exception->setHttpResponse($response);

            throw $exception;
        }

        $result = new ExecutionResult();
        $result->data = $resultRaw['data'] ?? null;
        $result->extensions = $resultRaw['extensions'] ?? null;

        foreach ($resultRaw['errors'] ?? [] as $error) {
            $result->errors[] = new Error(
                message: $error['message'] ?? '',
                path: $error['path'] ?? null,
                extensions: $error['extensions'] ?? null,
            );
        }

        return $result;
    }
}
