HTTP Schema
===========

![unit tests](https://github.com/x-graphql/http-schema/actions/workflows/unit_tests.yml/badge.svg)
[![codecov](https://codecov.io/gh/x-graphql/http-schema/graph/badge.svg?token=AliZkXTb4E)](https://codecov.io/gh/x-graphql/http-schema)

Help to build and execute [GraphQL schema](https://webonyx.github.io/graphql-php/) over HTTP (aka remote schema).

Getting Started
---------------

Install this package via [Composer](https://getcomposer.org)

```shell
composer require x-graphql/http-schema
```

This library require [PSR-18 Client](https://www.php-fig.org/psr/psr-18/) or [Httplug Async Client](https://docs.php-http.org/en/latest/index.html) for sending async requests. Run the command bellow if you don't have yet.

```shell
composer require php-http/guzzle7-adapter
```

Usages
------

This library offers to you 2 strategy to build schema:

* Build from schema definition language (SDL), this strategy use for limiting fields user can access.
* Build from introspection query, with this strategy it will make a http request for [introspecting schema](https://graphql.org/learn/introspection/), user can access all the fields.


### From SDL

```php
use GraphQL\GraphQL;
use XGraphQL\HttpSchema\HttpDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;

$delegator = new HttpDelegator('https://countries.trevorblades.com/');
$schema = HttpSchemaFactory::createFromSDL(
$delegator,
<<<'SDL'
type Query {
  countries: [Country!]!
}

type Country {
  name: String!
}
SDL
);

$result = GraphQL::executeQuery($schema, 'query { countries { name } }');

var_dump($result->toArray());
```

### From introspection query

```php
use GraphQL\GraphQL;
use XGraphQL\HttpSchema\HttpDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;

$delegator = new HttpDelegator('https://countries.trevorblades.com/');
$schema = HttpSchemaFactory::createFromIntrospectionQuery($delegator);
$result = GraphQL::executeQuery($schema, 'query { countries { name } }');

var_dump($result->toArray());
```

### Caching schema

For optimizing time to build schema from SDL or introspection query, you can put [PSR-16](https://www.php-fig.org/psr/psr-16/) instance to 
factory methods for caching schema after built:

```php
use XGraphQL\HttpSchema\HttpDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;

/// $psr16Cache = ....
$delegator = new HttpDelegator('https://countries.trevorblades.com/');
$schemaFromSDL = HttpSchemaFactory::createFromSDL($delegator, /// $sdl, $psr16Cache);
$schemaFromIntrospection = HttpSchemaFactory::createFromIntrospectionQuery($delegator, /// $psr16Cache);

/// ........
```

Credits
-------

Created by [Minh Vuong](https://github.com/vuongxuongminh)
