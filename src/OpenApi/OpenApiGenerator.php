<?php

namespace Sonata\Docs\OpenApi;

use Sonata\Framework\Attributes\Controller as ControllerAttr;
use Sonata\Framework\Attributes\Route as RouteAttr;
use Sonata\Framework\Attributes\From;
use Sonata\Framework\ControllerFinder;
use Sonata\Framework\Routing\ControllerDirectoryResolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class OpenApiGenerator
{
    private array $routes = [];

    private array $schemas = [];
    private array $controllerDirectories;

    public function __construct(?array $controllerDirectories = null, ?string $basePath = null)
    {
        $basePath = $this->resolveBasePath($basePath);
        $this->controllerDirectories = $controllerDirectories
            ?? ControllerDirectoryResolver::resolve($basePath);
    }

    public function generate(): array
    {
        $this->scanControllers();
        return $this->buildSpec();
    }

    private function scanControllers(): void
    {
        $finder = new ControllerFinder();
        foreach ($this->controllerDirectories as $dir) {
            foreach ($finder->find($dir) as $controller) {
                $this->processController($controller);
            }
        }
    }

    private function processController(string $className): void
    {
        $reflection = new ReflectionClass($className);
        $controllerAttrs = $reflection->getAttributes(ControllerAttr::class);

        if (empty($controllerAttrs)) return;

        $controllerPrefix = $controllerAttrs[0]->newInstance()->prefix;

        $tagAttr = null;
        foreach ($reflection->getAttributes(\Sonata\Framework\Attributes\Tag::class) as $attr) {
            $tagAttr = $attr->newInstance();
            break;
        }

        if ($tagAttr) {
            $tagName = $tagAttr->name;
            $tagDescription = $tagAttr->description ?? "Операции над {$tagName}";
        } else {
            $tagName = 'Default';
            $tagDescription = 'Базовые операции';
        }

        foreach ($reflection->getMethods() as $method) {
            $routeAttrs = $method->getAttributes(RouteAttr::class);
            if (empty($routeAttrs)) continue;

            $route = $routeAttrs[0]->newInstance();
            $fullPath = rtrim($controllerPrefix, '/') . '/' . ltrim($route->path, '/');

            $summary = $route->summary ?? $method->getName();
            $description = $route->description ?? '';

            $this->routes[] = [
                'path' => '/' . ltrim($fullPath, '/'),
                'method' => strtolower($route->method),
                'methodReflection' => $method,
                'summary' => $summary,
                'description' => $description,
                'tagName' => $tagName,
                'tagDescription' => $tagDescription
            ];
        }
    }

    /**
     * @throws \ReflectionException
     */
    private function getResponseSchema(\ReflectionMethod $method): array
    {
        $responseAttrs = $method->getAttributes(\Sonata\Framework\Attributes\Response::class);

        if (!empty($responseAttrs)) {
            $responseAttr = $responseAttrs[0]->newInstance();

            if ($responseAttr->class && class_exists($responseAttr->class)) {
                $shortName = basename(str_replace('\\', '/', $responseAttr->class));
                $this->collectSchema($responseAttr->class);

                if ($responseAttr->isArray) {
                    return [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/' . $shortName]
                    ];
                } else {
                    return ['$ref' => '#/components/schemas/' . $shortName];
                }
            }
        }

        $returnType = $method->getReturnType();
        if (!$returnType) {
            return ['type' => 'object'];
        }

        if ($returnType->getName() === 'array') {
            return ['type' => 'array', 'items' => ['type' => 'object']];
        }

        if (!$returnType->isBuiltin()) {
            $className = $returnType->getName();
            if (class_exists($className)) {
                $shortName = basename(str_replace('\\', '/', $className));
                $this->collectSchema($className);
                return ['$ref' => '#/components/schemas/' . $shortName];
            }
        }

        return ['type' => 'object'];
    }

    /**
     * @throws \ReflectionException
     */
    private function collectSchema(string $className): void
    {
        $shortName = basename(str_replace('\\', '/', $className));
        if (isset($this->schemas[$shortName])) {
            return;
        }

        $reflection = new \ReflectionClass($className);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();
            $propertyType = $property->getType();

            if ($propertyType && $propertyType instanceof \ReflectionNamedType) {
                $typeName = $propertyType->getName();

                if (class_exists($typeName) && str_contains($typeName, 'Dto\\Response')) {

                    $nestedShortName = basename(str_replace('\\', '/', $typeName));

                    $this->collectSchema($typeName);

                    $properties[$propertyName] = ['$ref' => '#/components/schemas/' . $nestedShortName];
                    continue;
                }
            }

            // Обработка обычного типа
            $schema = ['type' => 'string'];
            if ($propertyType) {
                $typeName = $propertyType->getName();
                if (in_array($typeName, ['int', 'integer'])) {
                    $schema['type'] = 'integer';
                } elseif (in_array($typeName, ['float', 'double'])) {
                    $schema['type'] = 'number';
                } elseif (in_array($typeName, ['bool', 'boolean'])) {
                    $schema['type'] = 'boolean';
                }
            }

            $oaAttrs = $property->getAttributes(\OpenApi\Attributes\Property::class);
            if (!empty($oaAttrs)) {
                $oaProp = $oaAttrs[0]->newInstance();
                if (isset($oaProp->example)) {
                    $schema['example'] = $oaProp->example;
                }
                if (isset($oaProp->description)) {
                    $schema['description'] = $oaProp->description;
                }
            }

            $properties[$propertyName] = $schema;
        }

        $this->schemas[$shortName] = [
            'type' => 'object',
            'properties' => $properties
        ];
    }

    private function buildSpec(): array
    {
        $paths = [];
        $tagDescriptions = [];

        foreach ($this->routes as $route) {
            $params = $this->extractParameters($route['methodReflection']);

            $operation = [
                'summary' => $route['summary'],
                'operationId' => $route['methodReflection']->getName(),
                'tags' => [$route['tagName']],
                'responses' => [
                    '200' => [
                        'description' => 'Успешный ответ',
                        'content' => [
                            'application/json' => [
                                'schema' => $this->getResponseSchema($route['methodReflection'])
                            ]
                        ]
                    ]
                ]
            ];

            if (!empty($params['body'])) {
                $operation['requestBody'] = $params['body'];
            }

            if (!empty($route['description'])) {
                $operation['description'] = $route['description'];
            }

            if (!empty($params['query'])) {
                foreach ($params['query'] as $name => $schema) {
                    $operation['parameters'][] = [
                        'name' => $name,
                        'in' => 'query',
                        'required' => false,
                        'schema' => $schema
                    ];
                }
            }

            if (!empty($params['body'])) {
                $operation['requestBody'] = $params['body'];
            }

            $path = $route['path'];
            $method = $route['method'];

            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }
            $paths[$path][$method] = $operation;

            $tagDescriptions[$route['tagName']] = $route['tagDescription'];
        }

        $tags = [];
        foreach ($tagDescriptions as $name => $description) {
            $tags[] = [
                'name' => $name,
                'description' => $description
            ];
        }

        $result = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Sonata API',
                'version' => '1.0.0',
                'description' => 'Автоматически сгенерированная документация'
            ],
            'servers' => [
                [
                    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
                    'description' => 'Текущий сервер'
                ]
            ],
            'tags' => $tags,
            'paths' => $paths
        ];

        if (!empty($this->schemas)) {
            $result['components'] = ['schemas' => $this->schemas];
        }

        return $result;

    }

    private function extractParameters(\ReflectionMethod $method): array
    {
        $query = [];
        $body = null;

        foreach ($method->getParameters() as $param) {
            $fromAttr = null;

            // Ищем атрибут From
            foreach ($param->getAttributes() as $attr) {
                if ($attr->getName() === 'Sonata\\Framework\\Attributes\\From') {
                    $fromAttr = $attr->newInstance();
                    break;
                }
            }

            if (!$fromAttr) continue;

            $type = $param->getType();
            if (!$type || !$type instanceof \ReflectionNamedType || $type->isBuiltin()) continue;

            $dtoClass = $type->getName();
            if (!class_exists($dtoClass)) continue;

            $dtoReflection = new \ReflectionClass($dtoClass);
            $properties = [];

            foreach ($dtoReflection->getProperties() as $property) {
                $propertyName = $property->getName();

                // Определяем тип
                $schema = ['type' => 'string'];
                $propertyType = $property->getType();
                if ($propertyType) {
                    $typeName = $propertyType->getName();
                    if (in_array($typeName, ['int', 'integer'])) {
                        $schema['type'] = 'integer';
                    } elseif (in_array($typeName, ['float', 'double'])) {
                        $schema['type'] = 'number';
                    } elseif (in_array($typeName, ['bool', 'boolean'])) {
                        $schema['type'] = 'boolean';
                    }
                }

                foreach ($property->getAttributes() as $propAttr) {
                    if ($propAttr->getName() === 'OpenApi\\Attributes\\Property') {
                        $oaProp = $propAttr->newInstance();
                        if (isset($oaProp->example)) {
                            $schema['example'] = $oaProp->example;
                        }
                        if (isset($oaProp->description)) {
                            $schema['description'] = $oaProp->description;
                        }
                        break;
                    }
                }

                $properties[$propertyName] = $schema;
            }

            if ($fromAttr->source === 'json') {
                $body = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => $properties
                            ]
                        ]
                    ]
                ];
            } elseif ($fromAttr->source === 'query') {
                foreach ($properties as $name => $schema) {
                    $query[$name] = $schema;
                }
            }
        }

        return ['query' => $query, 'body' => $body];
    }

    /**
     * @throws \ReflectionException
     */
    private function extractNestedSchema(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();
            $propertyType = $property->getType();

            if (!$propertyType || !$propertyType instanceof \ReflectionNamedType) {
                $properties[$propertyName] = ['type' => 'string'];
                continue;
            }

            $typeName = $propertyType->getName();

            // Если это вложенный DTO
            if (class_exists($typeName) && str_contains($typeName, 'Dto\\Response')) {
                $properties[$propertyName] = ['$ref' => '#/components/schemas/' . basename(str_replace('\\', '/', $typeName))];
                // Регистрируем схему вложенного класса
                $this->collectSchema($typeName);
            } else {
                // Простой тип
                $typeMap = [
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    'string' => 'string'
                ];
                $properties[$propertyName] = ['type' => $typeMap[$typeName] ?? 'string'];
            }

            // Добавляем примеры из OA\Property
            $oaAttrs = $property->getAttributes(\OpenApi\Attributes\Property::class);
            if (!empty($oaAttrs)) {
                $oaProp = $oaAttrs[0]->newInstance();
                if (isset($oaProp->example)) {
                    $properties[$propertyName]['example'] = $oaProp->example;
                }
                if (isset($oaProp->description)) {
                    $properties[$propertyName]['description'] = $oaProp->description;
                }
            }
        }

        return $properties;
    }

    private function resolveBasePath(?string $basePath): string
    {
        return $basePath
            ?? $_ENV['SONATA_BASE_PATH']
            ?? $_SERVER['DOCUMENT_ROOT']
            ?? getcwd();
    }
}
