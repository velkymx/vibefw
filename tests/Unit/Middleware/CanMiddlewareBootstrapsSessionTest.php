<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Middleware;

use Fw\Middleware\CanMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks in the session-write contract for CanMiddleware's guest redirect.
 * It stores an intended URL in $_SESSION, so it must start the session first.
 */
final class CanMiddlewareBootstrapsSessionTest extends TestCase
{
    #[Test]
    public function storeIntendedUrlStartsSessionBeforeWriting(): void
    {
        $method = new ReflectionMethod(CanMiddleware::class, 'storeIntendedUrl');
        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $initSessionPos = strpos($body, '$this->app->initSession()');
        $sessionWritePos = strpos($body, '$_SESSION[\'_intended_url\']');

        $this->assertNotFalse(
            $initSessionPos,
            'CanMiddleware::storeIntendedUrl() must call $this->app->initSession() before writing $_SESSION.',
        );
        $this->assertNotFalse(
            $sessionWritePos,
            'CanMiddleware::storeIntendedUrl() must write the intended URL to $_SESSION (sanity).',
        );
        $this->assertLessThan(
            $sessionWritePos,
            $initSessionPos,
            'CanMiddleware must start the session before writing _intended_url.',
        );
    }
}
