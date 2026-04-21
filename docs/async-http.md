# START HERE

No CLI for this — `AsyncHttp` is a runtime library you call from code. Read past the BEWARE marker for the API.

If you're building a tool or workflow that fans out HTTP calls, scaffold it with `php fw make:workflow` or `php fw make:tool` and call `AsyncHttp` from the handler.

# BEWARE

Only read past here if you are unable to use the CLI.

# Async HTTP Client

`AsyncHttp` is a non-blocking HTTP client built on Fibers and `EventLoop`. Requests execute concurrently without blocking the event loop.

## Basic Usage

```php
use Fw\Async\AsyncHttp;

$http = new AsyncHttp();

// GET
$deferred = $http->get('https://api.example.com/users');
$response  = $deferred->await();

echo $response->statusCode;  // 200
echo $response->body;        // JSON string
print_r($response->headers); // ['content-type' => 'application/json', ...]

// POST with JSON body
$deferred = $http->post('https://api.example.com/users', [
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);
$response = $deferred->await();

// Arbitrary method
$deferred = $http->request('PATCH', 'https://api.example.com/users/1', ['name' => 'Bob']);
```

## Keep-Alive Connections

By default `AsyncHttp` sends `Connection: close`, so response reading terminates naturally on EOF. For servers that reuse connections, call `withKeepAlive()`:

```php
$http = (new AsyncHttp())->withKeepAlive();

// The client auto-detects response completion via:
// • Content-Length header — stops reading once N bytes received
// • Transfer-Encoding: chunked — stops on the 0\r\n\r\n terminator
// Without either header it falls back to EOF (Connection: close behaviour)
```

`withKeepAlive()` returns a clone; the original instance is unchanged.

## Chunked Transfer Encoding

Chunked responses (RFC 7230 §4.1) are decoded automatically. No configuration needed — when the response carries `Transfer-Encoding: chunked`, `$response->body` contains the assembled plaintext body.

## Custom Headers

```php
$response = $http->get('https://api.example.com/data', [
    'Authorization' => 'Bearer ' . $token,
    'Accept'        => 'application/json',
])->await();
```

Headers are validated against CRLF injection before sending.

## Concurrent Requests

Because requests return `Deferred` objects, multiple requests can run in parallel:

```php
$d1 = $http->get('https://api.example.com/users');
$d2 = $http->get('https://api.example.com/posts');

$users = $d1->await();
$posts = $d2->await();
```

Both requests are in-flight during the first `await()` call.

## SSRF Protection

`AsyncHttp` blocks requests to private and internal IP ranges by default:

| Range | Reason |
|-------|--------|
| `127.0.0.0/8` | Loopback |
| `10.0.0.0/8` | Private |
| `172.16.0.0/12` | Private |
| `192.168.0.0/16` | Private |
| `169.254.0.0/16` | Link-local / cloud metadata |
| `::1/128` | IPv6 loopback |
| `fc00::/7` | IPv6 private |
| `fe80::/10` | IPv6 link-local |

The hostname is resolved before the socket is opened. If any resolved IP falls in a blocked range, a `RuntimeException` is thrown.

### Trusted Internal Services

Disable SSRF protection only for calls to services you control:

```php
$http = (new AsyncHttp())->withoutSsrfProtection();
$response = $http->get('http://internal-cache:6379/ping')->await();
```

> **Warning:** Never call `withoutSsrfProtection()` with user-supplied URLs.

## Timeout

Default socket timeout is 30 seconds. This is set at connection time and covers the full request/response cycle.

## Error Handling

Failed requests reject the `Deferred`. Wrap with `try/catch` or use `Deferred::catch()`:

```php
try {
    $response = $http->get($url)->await();
} catch (\RuntimeException $e) {
    // Connection error, SSRF block, header injection attempt, etc.
    logger()->error('HTTP request failed', ['error' => $e->getMessage()]);
}
```
