<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Auth\TokenGuard;
use Fw\Aux\Mcp\McpProtocol;
use Fw\Core\Application;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Core\StreamedResponse;

final class McpSseController extends Controller
{
    private McpProtocol $protocol;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->protocol = $app->getContainer()->get(McpProtocol::class);
    }

    public function sse(Request $request): StreamedResponse
    {
        $heartbeat = (int) $this->app->config('aux.sse_heartbeat_seconds', 15);

        return $this->streamed(function () use ($heartbeat): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo "event: endpoint\ndata: /mcp/messages\n\n";
            flush();

            // Schedule heartbeats against wall-clock. A tight usleep()
            // tick lets the worker notice `connection_aborted()`
            // within ~100ms — a full-interval blocking wait would pin
            // the worker fiber for the whole heartbeat and starve
            // peer connections under load.
            $tickMicros = 100_000;
            $nextPingAt = microtime(true) + $heartbeat;

            while (!connection_aborted()) {
                if (microtime(true) >= $nextPingAt) {
                    echo ": ping\n\n";
                    flush();
                    $nextPingAt = microtime(true) + $heartbeat;
                }
                usleep($tickMicros);
            }
        });
    }

    public function messages(Request $request): Response
    {
        $abilities = TokenGuard::tokenAbilities();
        $body = $request->rawBody();

        if (empty($body)) {
            return $this->json(['error' => [
                'code' => -32700,
                'message' => 'Empty request body',
            ]], 400);
        }

        $response = $this->protocol->handle($body, $abilities);

        if (empty($response)) {
            return $this->json(['error' => [
                'code' => -32600,
                'message' => 'Invalid request',
            ]], 400);
        }

        $decoded = json_decode($response, true);

        if ($decoded === null) {
            return $this->json(['error' => [
                'code' => -32700,
                'message' => 'Invalid JSON response',
            ]], 500);
        }

        if (isset($decoded['error'])) {
            return $this->json($decoded, 400);
        }

        return $this->json($decoded);
    }
}
