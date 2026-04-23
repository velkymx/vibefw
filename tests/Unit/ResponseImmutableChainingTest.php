<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Response;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #23: the TODO flagged a concern that `setStatus()` and `header()`
 * might not compose correctly in chains because each returns a clone.
 * Analysis (and the TODO itself) concluded the code is correct — every
 * mutator returns a fresh clone, and a caller who chains or captures
 * the return value sees all accumulated state. The actual footgun is
 * the classic immutable-API misuse of discarding the return, which is
 * a caller contract, not a framework bug.
 *
 * Lock in the contract with a regression suite so a future edit can't
 * silently turn a clone-returning method into a mutating one.
 */
final class ResponseImmutableChainingTest extends TestCase
{
    #[Test]
    public function mutatorsReturnAFreshInstance(): void
    {
        $r = new Response();
        $this->assertNotSame($r, $r->setStatus(201));
        $this->assertNotSame($r, $r->header('X-Test', '1'));
        $this->assertNotSame($r, $r->setBody('x'));
        $this->assertNotSame($r, $r->contentType('text/plain'));
        $this->assertNotSame($r, $r->headers(['X-A' => 'a']));
    }

    #[Test]
    public function mutatorsDoNotMutateTheReceiver(): void
    {
        $r = new Response();
        $r->setStatus(418);
        $r->header('X-Leaked', 'yes');
        $r->setBody('leaked');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getBody());
        $this->assertArrayNotHasKey('X-Leaked', $r->getHeaders());
    }

    #[Test]
    public function chainedMutatorsAccumulateOnFinalInstance(): void
    {
        $final = (new Response())
            ->setStatus(201)
            ->header('X-Trace', 'abc')
            ->contentType('application/json')
            ->setBody('{"ok":true}');

        $this->assertSame(201, $final->getStatusCode());
        $this->assertSame('{"ok":true}', $final->getBody());
        $this->assertSame('abc', $final->getHeaders()['X-Trace']);
        $this->assertSame('application/json; charset=UTF-8', $final->getHeaders()['Content-Type']);
    }

    #[Test]
    public function headerThenSetStatusBothSurviveInFinal(): void
    {
        $r = new Response();
        $with = $r->header('X-A', '1')->setStatus(404);
        $this->assertSame(404, $with->getStatusCode());
        $this->assertSame('1', $with->getHeaders()['X-A']);
    }

    #[Test]
    public function setStatusThenHeaderBothSurviveInFinal(): void
    {
        $r = new Response();
        $with = $r->setStatus(204)->header('X-B', '2');
        $this->assertSame(204, $with->getStatusCode());
        $this->assertSame('2', $with->getHeaders()['X-B']);
    }

    #[Test]
    public function interleavedHeaderCallsPreserveAllHeaders(): void
    {
        $with = (new Response())
            ->header('X-One', '1')
            ->header('X-Two', '2')
            ->headers(['X-Three' => '3', 'X-Four' => '4'])
            ->header('X-Five', '5');

        $hs = $with->getHeaders();
        $this->assertSame('1', $hs['X-One']);
        $this->assertSame('2', $hs['X-Two']);
        $this->assertSame('3', $hs['X-Three']);
        $this->assertSame('4', $hs['X-Four']);
        $this->assertSame('5', $hs['X-Five']);
    }
}
