<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Aux\ToolRegistry;
use Fw\Auth\TokenGuard;
use Fw\Core\Application;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;

final class AgentController extends Controller
{
    private ToolRegistry $tools;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->tools = $app->getContainer()->get(ToolRegistry::class);
    }

    public function index(Request $request): Response
    {
        $abilities = $this->getCallerAbilities();

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
        $abilities = $this->getCallerAbilities();
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

        $workflowResult = $result->unwrapOr(null);

        return $this->json([
            'content' => $workflowResult->toMcpContent(),
        ]);
    }

    private function getCallerAbilities(): array
    {
        return TokenGuard::tokenAbilities();
    }
}
