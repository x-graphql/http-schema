<?php

declare(strict_types=1);

namespace XGraphQL\HttpSchema\Test;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XGraphQL\HttpSchema\HttpExecutionDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;

class SchemaExecutionTest extends TestCase
{
    #[DataProvider(methodName: 'queriesProvider')]
    public function testExecuteSchema(Schema $schema, string $query, array $expectingResult): void
    {
        $executionResult = GraphQL::executeQuery($schema, $query);
        $result = $executionResult->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

        if (isset($result['errors'])) {
            $errors = array_map(
                fn (array $error) => array_filter(
                    [
                        'message' => $error['message'] ?? null,
                        'extensions' => $error['extensions'] ?? null
                    ]
                ),
                $result['errors']
            );

            $this->assertEquals($expectingResult, ['errors' => $errors]);
        } else {
            $this->assertEquals($expectingResult, $result);
        }
    }

    public static function queriesProvider(): array
    {
        $delegator = new HttpExecutionDelegator('POST', 'https://countries.trevorblades.com/');
        $schemaFromIntrospect = HttpSchemaFactory::createFromIntrospectionQuery($delegator);
        $schemaFromCustomSDL = HttpSchemaFactory::createFromSDL(
            $delegator,
            <<<'SDL'
type Query {
  country(code: ID!): ICountry
  continent(code: ID!): IContinent
}

interface ICountry {
  code: ID!
}

type Country implements ICountry {
  code: ID!
  name: String!
  phone: String!
  languages: [ULanguage!]!
  continent: IContinent!
}

type Language {
  name: String!
}

type CustomLanguage {
  code: String!
}

union ULanguage = Language | CustomLanguage

interface IContinent {
  code: String!
}

type XContinent implements IContinent {
  code: String!
  name: String!
}
SDL,
        );
        return [
            'get country' => [
                $schemaFromIntrospect,
                <<<'GQL'
fragment languageData on Language {
  name
}

query {
  country(code: "VN") {
    code
    name
    phone
    languages {
      ...languageData
    }
  }
}
GQL,
                [
                    'data' => [
                        'country' => [
                            'code' => 'VN',
                            'name' => 'Vietnam',
                            'phone' => '84',
                            'languages' => [
                                [
                                    'name' => 'Vietnamese'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'get country with alias' => [
                $schemaFromIntrospect,
                <<<'GQL'
query {
  c: country(code: "VN") {
    a: code
    b: name
    d: phone
    e: languages {
      f: name
    }
  }
}
GQL,
                [
                    'data' => [
                        'c' => [
                            'a' => 'VN',
                            'b' => 'Vietnam',
                            'd' => '84',
                            'e' => [
                                [
                                    'f' => 'Vietnamese'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'custom sdl get country' => [
                $schemaFromCustomSDL,
                <<<'GQL'
query {
  country(code: "VN") {
    code
    ... on Country {
      name
      phone
    }
  }
}
GQL,
                [
                    'data' => [
                        'country' => [
                            'code' => 'VN',
                            'name' => 'Vietnam',
                            'phone' => '84',
                        ]
                    ]
                ]
            ],
            'get country with custom union language' => [
                $schemaFromCustomSDL,
                <<<'GQL'
query {
  country(code: "VN") {
    code
    ... on Country {
      name
      phone
      languages {
        ... on Language {
           name
        }
      }
    }
  }
}
GQL,
                [
                    'data' => [
                        'country' => [
                            'code' => 'VN',
                            'name' => 'Vietnam',
                            'phone' => '84',
                            'languages' => [
                                [
                                    'name' => 'Vietnamese'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'custom sdl get country will cause errors with message refer to use inline fragment' => [
                $schemaFromCustomSDL,
                <<<'GQL'
query {
  country(code: "VN") {
    code
    name
    phone
    languages {
      name
    }
  }
}
GQL,
                [
                    'errors' => [
                        [
                            'message' => 'Cannot query field "name" on type "ICountry". Did you mean to use an inline fragment on "Country"?',
                        ],
                        [
                            'message' => 'Cannot query field "phone" on type "ICountry". Did you mean to use an inline fragment on "Country"?'
                        ],
                        [
                            'message' => 'Cannot query field "languages" on type "ICountry". Did you mean to use an inline fragment on "Country"?'
                        ]
                    ]
                ]
            ],
            'conflict sdl will cause errors' => [
                $schemaFromCustomSDL,
                <<<'GQL'
query {
  country(code: "VN") {
    ... on Country {
      continent {
        ... on XContinent {
          code
          name
        }
      }
    }
  }
}
GQL,
                [
                    'errors' => [
                        [
                            'message' => 'Delegated execution result is missing field value at path: `country`',
                        ],
                    ]
                ]
            ],
            'missing type in custom sdl will cause error' => [
                $schemaFromCustomSDL,
                <<<'GQL'
query {
  continent(code: "AS") {
    code
  }
}
GQL,
                [
                    'errors' => [
                        [
                            'message' => 'Internal server error',
                            'extensions' => [
                                'debugMessage' => 'Expect type: `Continent` implementing `IContinent` should be exist in schema'
                            ]
                        ],
                    ]
                ]
            ],
        ];
    }
}
