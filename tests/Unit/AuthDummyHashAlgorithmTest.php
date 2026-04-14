<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthDummyHashAlgorithmTest extends TestCase
{
    #[Test]
    public function dummyHashAlgorithmMatchesPasswordDefault(): void
    {
        $ref = new ReflectionClass(\Fw\Auth\Auth::class);

        // Retrieve the dummy hash via reflection
        $method = $ref->getMethod('getDummyHash');
        $dummyHash = $method->invoke(null);

        // The dummy hash must be verifiable by password_verify (same algorithm as PASSWORD_DEFAULT)
        // password_verify internally detects the algorithm from the hash prefix
        $this->assertTrue(
            password_verify('dummy', $dummyHash),
            'Dummy hash must be verifiable — it must use the same algorithm as password_hash(PASSWORD_DEFAULT)'
        );

        // The algorithm used in the dummy hash must match what PASSWORD_DEFAULT would produce
        $info = password_get_info($dummyHash);
        $sampleHash = password_hash('sample', PASSWORD_DEFAULT);
        $sampleInfo = password_get_info($sampleHash);

        $this->assertSame(
            $sampleInfo['algo'],
            $info['algo'],
            'Dummy hash algorithm must match PASSWORD_DEFAULT'
        );
    }
}
