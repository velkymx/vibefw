<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Aux\Mcp\McpProtocol;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Core\StreamedResponse;

final class McpSseController extends Controller
{
    public function __construct(
        private readonly McpProtocol $protocol,
    ) {}

    public function sse(Request $request): StreamedResponse
    {
        $abilities = $this->getCallerAbilities($request);
        $heartbeat = $this->app->config('aux.sse_heartbeat_seconds', 15);

        return $this->streamed(function () use ($heartbeat) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo "event: endpoint\n";
            echo "data: /mcp/messages\n\n";

            echo "data: : keepalive\n\n";

            sleep($heartbeat);
        });
    }

    public function messages(Request $request): Response
    {
        $abilities = $this->getCallerAbilities($request);
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

    private function getCallerAbilities(Request $request): array
    {
        $header = $request->header('X-Agent-Abilities', '');

        if ($header === '') {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $header)));
    }
}
