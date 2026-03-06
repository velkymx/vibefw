<?php

declare(strict_types=1);

namespace Fw\Async;

/**
 * Truly non-blocking HTTP client using Fibers and EventLoop.
 */
final class AsyncHttp
{
    private array $defaultHeaders = [
        'User-Agent' => 'Fw-AsyncHttp/1.0',
        'Accept' => 'application/json',
        'Connection' => 'close',
    ];

    private int $timeout = 30;

    public function request(string $method, string $url, mixed $body = null, array $headers = []): Deferred
    {
        $deferred = new Deferred();
        $loop = EventLoop::getInstance();

        $loop->defer(function () use ($deferred, $method, $url, $body, $headers, $loop) {
            try {
                $this->executeAsync($deferred, $method, $url, $body, $headers, $loop);
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    private function executeAsync(Deferred $deferred, string $method, string $url, mixed $body, array $headers, EventLoop $loop): void
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $scheme = $parsed['scheme'] ?? 'http';

        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $address = "{$transport}://{$host}:{$port}";

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        if (!$socket) {
            throw new \RuntimeException("Could not connect to {$address}: {$errstr}");
        }

        stream_set_blocking($socket, false);

        // Build request
        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $allHeaders['Host'] = $host;
        
        $content = '';
        if ($body !== null) {
            $content = is_array($body) ? json_encode($body) : (string) $body;
            $allHeaders['Content-Length'] = (string) strlen($content);
            if (is_array($body) && !isset($allHeaders['Content-Type'])) {
                $allHeaders['Content-Type'] = 'application/json';
            }
        }

        $request = "{$method} {$path} HTTP/1.1\r\n";
        foreach ($allHeaders as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        $request .= "\r\n" . $content;

        $written = 0;
        $loop->addWriteStream($socket, function ($socket) use ($request, &$written, $loop, $deferred, &$responseBuffer, $method, $url) {
            $result = fwrite($socket, substr($request, $written));
            if ($result === false) {
                $loop->removeWriteStream($socket, true);
                $deferred->reject(new \RuntimeException("Write failed"));
                return;
            }
            $written += $result;
            if ($written >= strlen($request)) {
                $loop->removeWriteStream($socket);
                $this->waitForResponse($socket, $loop, $deferred);
            }
        });
    }

    private function waitForResponse($socket, EventLoop $loop, Deferred $deferred): void
    {
        $buffer = '';
        $loop->addReadStream($socket, function ($socket) use (&$buffer, $loop, $deferred) {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                $loop->removeReadStream($socket, true);
                $deferred->reject(new \RuntimeException("Read failed"));
                return;
            }

            if ($chunk === '') {
                if (feof($socket)) {
                    $loop->removeReadStream($socket, true);
                    $this->parseResponse($buffer, $deferred);
                }
                return;
            }

            $buffer .= $chunk;
        });
    }

    private function parseResponse(string $buffer, Deferred $deferred): void
    {
        $parts = explode("\r\n\r\n", $buffer, 2);
        if (count($parts) < 1) {
            $deferred->reject(new \RuntimeException("Invalid response"));
            return;
        }

        $headerLines = explode("\r\n", $parts[0]);
        $statusLine = array_shift($headerLines);
        
        preg_match('/HTTP\/[\d.]+\s+(\d+)/', $statusLine, $matches);
        $statusCode = isset($matches[1]) ? (int) $matches[1] : 200;

        $headers = [];
        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        $body = $parts[1] ?? '';
        $deferred->resolve(new HttpResponse($statusCode, $headers, $body));
    }

    public function get(string $url, array $headers = []): Deferred
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, mixed $body = null, array $headers = []): Deferred
    {
        return $this->request('POST', $url, $body, $headers);
    }
}
