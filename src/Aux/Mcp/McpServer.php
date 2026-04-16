<?php

declare(strict_types=1);

namespace Fw\Aux\Mcp;

final class McpServer
{
    public function __construct(
        private McpProtocol $protocol,
    ) {}

    public function run(mixed $stdin = STDIN, mixed $stdout = STDOUT): void
    {
        while (!feof($stdin)) {
            $line = fgets($stdin);

            if ($line === false || $line === '') {
                continue;
            }

            $response = $this->processLine($line);

            if ($response !== '') {
                fwrite($stdout, $response . "\n");
            }
        }
    }

    public function processLine(string $line, array $callerAbilities = []): string
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return '';
        }

        return $this->protocol->handle($trimmed, $callerAbilities);
    }
}
