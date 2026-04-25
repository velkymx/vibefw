<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Lifecycle;

use Fw\Core\Response;
use Fw\Lifecycle\Component;
use Fw\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item H8: Component json() and redirect() must capture the
 * immutable Response clone so headers and status code survive.
 *
 * Before the fix, both helpers called ->header() / ->setStatus()
 * on the shared Response and discarded the returned clone — the
 * application Response stayed at 200 with no extra headers.
 */
final class ComponentImmutableResponseTest extends TestCase
{
    #[Test]
    public function jsonHelperSetsContentTypeHeaderOnAppResponse(): void
    {
        $app = new class {
            public ?object $db = null;
            public ?object $view = null;
            public Response $response;
            public ?object $log = null;

            public function __construct()
            {
                $this->response = new Response();
            }
        };

        $component = new JsonComponent($app, new Request());
        $output = $component->render();

        $this->assertSame('application/json', $app->response->getHeaders()['Content-Type'] ?? null,
            'json() must set Content-Type on the app response by capturing the clone.');
        $this->assertSame('{"ok":true}', $output);
    }

    #[Test]
    public function redirectHelperSetsStatusAndLocationOnAppResponse(): void
    {
        $app = new class {
            public ?object $db = null;
            public ?object $view = null;
            public Response $response;
            public ?object $log = null;

            public function __construct()
            {
                $this->response = new Response();
            }
        };

        $component = new RedirectComponent($app, new Request());
        $component->render();

        $this->assertSame(302, $app->response->getStatusCode(),
            'redirect() must set the status code on the app response.');
        $this->assertSame('/target', $app->response->getHeaders()['Location'] ?? null,
            'redirect() must set the Location header on the app response.');
    }

    #[Test]
    public function redirectWithCustomStatusSetsCorrectCode(): void
    {
        $app = new class {
            public ?object $db = null;
            public ?object $view = null;
            public Response $response;
            public ?object $log = null;

            public function __construct()
            {
                $this->response = new Response();
            }
        };

        $component = new PermanentRedirectComponent($app, new Request());
        $component->render();

        $this->assertSame(301, $app->response->getStatusCode(),
            'redirect() with status 301 must set 301 on the app response.');
    }
}

class JsonComponent extends Component
{
    public function render(): string
    {
        return $this->json(['ok' => true]);
    }
}

class RedirectComponent extends Component
{
    public function render(): string
    {
        return $this->redirect('/target');
    }
}

class PermanentRedirectComponent extends Component
{
    public function render(): string
    {
        return $this->redirect('/moved', 301);
    }
}
