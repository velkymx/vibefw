<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Http\ApiResponse;
use Fw\Core\Response;
use Fw\Tests\TestCase;

final class ApiResponseTest extends TestCase
{
    private function decode(Response $response): array
    {
        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSuccessReturnsCorrectStructure(): void
    {
        $api = new ApiResponse();

        $response = $api->success(['id' => 1, 'name' => 'Test']);
        $result = $this->decode($response);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('timestamp', $result['meta']);
        $this->assertEquals(['id' => 1, 'name' => 'Test'], $result['data']);
    }

    public function testSuccessDefaultStatusIs200(): void
    {
        $api = new ApiResponse();
        $response = $api->success(['test' => true]);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSuccessWithCustomStatus(): void
    {
        $api = new ApiResponse();
        $response = $api->success(['test' => true], 201);

        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testMessageReturnsMessageInData(): void
    {
        $api = new ApiResponse();

        $response = $api->message('Operation completed');
        $result = $this->decode($response);

        $this->assertEquals('Operation completed', $result['data']['message']);
    }

    public function testCreatedReturns201Status(): void
    {
        $api = new ApiResponse();
        $response = $api->created(['id' => 1]);

        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testCreatedWithLocationHeader(): void
    {
        $api = new ApiResponse();
        $response = $api->created(['id' => 1], '/api/resources/1');

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Location', $headers);
        $this->assertEquals('/api/resources/1', $headers['Location']);
    }

    public function testNoContentReturns204Status(): void
    {
        $api = new ApiResponse();
        $response = $api->noContent();

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testPaginatedReturnsCorrectStructure(): void
    {
        $api = new ApiResponse();
        $items = [['id' => 1], ['id' => 2]];

        $response = $api->paginated($items, 50, 2, 10, '/api/items');
        $result = $this->decode($response);

        // Single envelope: data/meta/links at top level
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayNotHasKey('data', $result['meta']); // no double-wrap
        $this->assertArrayHasKey('pagination', $result['meta']);
        $this->assertArrayHasKey('links', $result);
        $this->assertSame($items, $result['data']);

        $pagination = $result['meta']['pagination'];
        $this->assertEquals(50, $pagination['total']);
        $this->assertEquals(10, $pagination['per_page']);
        $this->assertEquals(2, $pagination['current_page']);
        $this->assertEquals(5, $pagination['total_pages']);
    }

    public function testPaginatedLinksIncludePrevAndNext(): void
    {
        $api = new ApiResponse();

        $response = $api->paginated([], 50, 3, 10, '/api/items');
        $result = $this->decode($response);
        $links = $result['links'];

        $this->assertArrayHasKey('self', $links);
        $this->assertArrayHasKey('first', $links);
        $this->assertArrayHasKey('last', $links);
        $this->assertArrayHasKey('prev', $links);
        $this->assertArrayHasKey('next', $links);
    }

    public function testErrorReturnsRFC9457Structure(): void
    {
        $api = new ApiResponse();

        $response = $api->error('Bad Request', 400, 'Invalid input');
        $result = $this->decode($response);

        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('detail', $result);

        $this->assertEquals('Bad Request', $result['title']);
        $this->assertEquals(400, $result['status']);
        $this->assertEquals('Invalid input', $result['detail']);
    }

    public function testErrorGeneratesTypeUrl(): void
    {
        $api = new ApiResponse();

        $response = $api->error('Not Found', 404);
        $result = $this->decode($response);

        $this->assertStringContainsString('/errors/not-found', $result['type']);
    }

    public function testErrorWithCustomType(): void
    {
        $api = new ApiResponse();

        $response = $api->error('Custom Error', 400, null, 'https://example.com/errors/custom');
        $result = $this->decode($response);

        $this->assertEquals('https://example.com/errors/custom', $result['type']);
    }

    public function testErrorWithInstance(): void
    {
        $api = new ApiResponse();

        $response = $api->error('Not Found', 404, 'User not found', null, '/api/users/123');
        $result = $this->decode($response);

        $this->assertEquals('/api/users/123', $result['instance']);
    }

    public function testErrorWithExtensions(): void
    {
        $api = new ApiResponse();

        $response = $api->error('Validation Failed', 422, 'Invalid data', null, null, [
            'errors' => ['email' => ['Invalid email format']],
        ]);
        $result = $this->decode($response);

        $this->assertArrayHasKey('errors', $result);
        $this->assertEquals(['email' => ['Invalid email format']], $result['errors']);
    }

    public function testErrorSetsContentTypeToProblemJson(): void
    {
        $api = new ApiResponse();
        $response = $api->error('Bad Request', 400);

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/problem+json; charset=UTF-8', $headers['Content-Type']);
    }

    public function testBadRequestReturns400(): void
    {
        $api = new ApiResponse();
        $response = $api->badRequest('Invalid input');

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testUnauthorizedReturns401(): void
    {
        $api = new ApiResponse();
        $response = $api->unauthorized();

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testUnauthorizedSetsWwwAuthenticateHeader(): void
    {
        $api = new ApiResponse();
        $response = $api->unauthorized();

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('WWW-Authenticate', $headers);
        $this->assertEquals('Bearer', $headers['WWW-Authenticate']);
    }

    public function testForbiddenReturns403(): void
    {
        $api = new ApiResponse();
        $response = $api->forbidden();

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testNotFoundReturns404(): void
    {
        $api = new ApiResponse();
        $response = $api->notFound();

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testValidationErrorReturns422(): void
    {
        $api = new ApiResponse();
        $response = $api->validationError(['email' => ['Invalid email']]);
        $result = $this->decode($response);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertArrayHasKey('errors', $result);
    }

    public function testTooManyRequestsReturns429(): void
    {
        $api = new ApiResponse();
        $response = $api->tooManyRequests(60);

        $this->assertEquals(429, $response->getStatusCode());
    }

    public function testTooManyRequestsSetsRetryAfterHeader(): void
    {
        $api = new ApiResponse();
        $response = $api->tooManyRequests(120);

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertEquals('120', $headers['Retry-After']);
    }

    public function testServerErrorReturns500(): void
    {
        $api = new ApiResponse();
        $response = $api->serverError();

        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testWithBaseUriAffectsTypeGeneration(): void
    {
        $api = new ApiResponse();
        $api->withBaseUri('https://api.myapp.com');

        $response = $api->error('Not Found', 404);
        $result = $this->decode($response);

        $this->assertStringStartsWith('https://api.myapp.com/errors/', $result['type']);
    }

    public function testHeaderSetsResponseHeader(): void
    {
        $api = new ApiResponse();
        $api->header('X-Custom', 'value');

        // Note: header() on ApiResponse currently stores in internal headers array
        // which are then merged during success() or error() calls.
        $response = $api->success([]);
        $headers = $response->getHeaders();
        
        $this->assertArrayHasKey('X-Custom', $headers);
        $this->assertEquals('value', $headers['X-Custom']);
    }

    public function testLinkCreatesHateoasLink(): void
    {
        $link = ApiResponse::link('/api/users/1', 'self', 'GET');

        $this->assertEquals('/api/users/1', $link['href']);
        $this->assertEquals('self', $link['rel']);
        $this->assertEquals('GET', $link['method']);
    }

    public function testLinksCreatesMultipleLinks(): void
    {
        $links = ApiResponse::links([
            'self' => '/api/users/1',
            'posts' => '/api/users/1/posts',
        ]);

        $this->assertCount(2, $links);
        $this->assertEquals('self', $links[0]['rel']);
        $this->assertEquals('posts', $links[1]['rel']);
    }

    public function testMakeCreatesNewInstance(): void
    {
        $api = ApiResponse::make();

        $this->assertInstanceOf(ApiResponse::class, $api);
    }
}
