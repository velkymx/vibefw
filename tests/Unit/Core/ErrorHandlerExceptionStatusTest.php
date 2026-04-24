<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Config;
use Fw\Core\ErrorHandler;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Log\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerExceptionStatusTest extends TestCase
{
    private function makeErrorHandler(bool $debug): ErrorHandler
    {
        $config = (new Config(BASE_PATH))
            ->set('app.debug', $debug);

        $logPath = sys_get_temp_dir() . '/fw-error-handler-' . bin2hex(random_bytes(6));

        return new ErrorHandler(
            new Response(),
            new Logger($logPath),
            $config,
        );
    }

    #[Test]
    public function exceptionResponsesUseHttp500OutsideDebugMode(): void
    {
        $handler = $this->makeErrorHandler(debug: false);
        $response = $handler->createExceptionResponse(
            new RuntimeException('boom'),
            new Request(method: 'GET', uri: '/explode'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('500 Internal Server Error', $response->getBody());
    }

    #[Test]
    public function exceptionResponsesUseHttp500InDebugMode(): void
    {
        $handler = $this->makeErrorHandler(debug: true);
        $response = $handler->createExceptionResponse(
            new RuntimeException('boom'),
            new Request(method: 'GET', uri: '/explode'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('boom', $response->getBody());
        $this->assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['Content-Type'] ?? null,
        );
    }
}
