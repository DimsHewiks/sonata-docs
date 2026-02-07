<?php

namespace Sonata\Docs\Controllers;

use Sonata\Framework\Attributes\Controller;
use Sonata\Framework\Attributes\NoAuth;
use Sonata\Framework\Attributes\Route;
use Sonata\Framework\Attributes\Tag;
use Sonata\Framework\Cache\OpenApiCache;
use Sonata\Docs\OpenApi\OpenApiGenerator;

#[Controller(prefix: '')]
#[Tag('Swagger (Документация)', 'Методы работы над документацией')]
class SwaggerController
{
    #[Route(
        path: '/openapi.json', method: 'GET',
        summary: 'Получение документации',
        description: 'Метод, позволяющий получить документацию для отображения'
    )]
    #[NoAuth]
    public function openapiSpec(): array
    {
        $debug = getenv('APP_ENV') === 'dev';

        if (!$debug) {
            $cache = new OpenApiCache();
            $spec = $cache->get();
            if ($spec) {
                return $spec;
            }
        }

        $generator = new OpenApiGenerator();
        $spec = $generator->generate();

        if (!$debug) {
            $cache = $cache ?? new OpenApiCache();
            $cache->store($spec);
        }

        return $spec;
    }
}
