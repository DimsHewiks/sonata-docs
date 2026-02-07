# Sonata Docs

Модуль OpenAPI для Sonata Framework. Генерирует спецификацию и отдает ее через эндпоинт.

## Установка
```bash
composer require sonata/docs
```

## Эндпоинты
Контроллер `Sonata\Docs\Controllers\SwaggerController` подключается автоматически:
- `GET /openapi.json` — OpenAPI спецификация

## Логика
- Генератор `OpenApiGenerator` сканирует контроллеры и DTO.
- Использует атрибуты `#[Route]`, `#[Controller]`, `#[Tag]`, `#[Response]`, `#[From]`.
- В `prod` режиме применяет `OpenApiCache`.

## Атрибуты для документации
Используйте атрибуты Sonata Framework и OpenAPI:
- `#[Tag('Название', 'Описание')]` — группировка методов
- `#[Response(Dto::class, isArray: true)]` — схема ответа
- `#[From('json'|'query')]` — источник данных
- `#[OpenApi\Attributes\Property(...)]` — описание полей DTO

Пример:
```php
use OpenApi\Attributes as OA;
use Sonata\Framework\Attributes\Response;
use Sonata\Framework\Attributes\Tag;

#[Tag('Пользователи')]
class UserController
{
    #[Response(UserResponse::class, isArray: true)]
    public function list(): array { /* ... */ }
}

final class UserResponse
{
    #[OA\Property(example: 1, description: 'ID пользователя')]
    public int $id;
}
```

## Swagger UI
UI в комплект не входит. Можно:
1) Использовать локальные ассеты (как в приложении `view/swagger`).
2) Подключить Swagger UI через CDN и указывать `/openapi.json` как источник.
