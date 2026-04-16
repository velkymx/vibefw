<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Aux\ToolRegistry;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;

final class AgentController extends Controller
{
    public function __construct(
        private readonly ToolRegistry $tools,
    ) {}

    public function index(Request $request): Response
    {
        $abilities = $this->getCallerAbilities($request);

        $tools = $this->tools->allFor($abilities);

        $shapes = array_values(array_map(
            fn($tool) => $tool->toMcpShape(),
            $tools,
        ));

        return $this->json([
            'tools' => $shapes,
        ]);
    }

    public function invoke(Request $request, string $name): Response
    {
        $abilities = $this->getCallerAbilities($request);
        $arguments = $request->all();

        $result = $this->tools->call($name, $arguments, $abilities);

        if ($result->isErr()) {
            $error = $result->unwrapErr();

            if ($error instanceof \Fw\Aux\Exceptions\ToolNotFoundException) {
                return $this->json(['error' => [
                    'code' => -32001,
                    'message' => $error->getMessage(),
                ]], 404);
            }

            if ($error instanceof \Fw\Aux\Exceptions\ToolValidationException) {
                return $this->json(['error' => [
                    'code' => -32002,
                    'message' => $error->getMessage(),
                    'data' => $error->errors,
                ]], 422);
            }

            return $this->json(['error' => [
                'code' => -32000,
                'message' => $error->getMessage(),
            ]], 500);
        }

        $workflowResult = $result->unwrap();

        return $this->json([
            'content' => $workflowResult->toMcpContent(),
        ]);
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
