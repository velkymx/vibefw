<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\MemoryCache;
use Fw\Cache\TaggedCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #36: `TaggedCache::taggedKey()` built its underlying cache key by
 * concatenating tag versions and the user key with `|`:
 *
 *   implode('|', ["tag:1","other:1"]) . '|' . $key
 *
 * A user key containing `|` could re-introduce the structural delimiter
 * and collide with a *different* `(tags, key)` tuple that serialises to
 * the same string. Minimal reproducer:
 *
 *   A: tags=["a"],      key="b:1|x"   →  "a:1|b:1|x"
 *   B: tags=["a","b"],  key="x"       →  "a:1|b:1|x"    (same string!)
 *
 * Both tuples mapped to the same underlying cache slot, so A's write
 * was visible to B's read and vice versa — a silent data leak between
 * tag scopes.
 *
 * After [R41] the key segment is marked with a `k:` prefix and base64-
 * encoded, so the user key can no longer re-emit `|` or `:` and the
 * tag-part / key-part boundary is unambiguous.
 */
final class TaggedCacheKeyCollisionTest extends TestCase
{
    private MemoryCache $backing;

    protected function setUp(): void
    {
        $this->backing = new MemoryCache();
    }

    #[Test]
    public function basicSetAndGetStillRoundTrips(): void
    {
        $tc = new TaggedCache($this->backing, ['users']);
        $tc->set('profile', ['name' => 'Alice']);
        $this->assertSame(['name' => 'Alice'], $tc->get('profile'));
    }

    #[Test]
    public function differentTagSetsDoNotShareKeyNamespace(): void
    {
        $users = new TaggedCache($this->backing, ['users']);
        $posts = new TaggedCache($this->backing, ['posts']);

        $users->set('1', 'user-value');
        $posts->set('1', 'post-value');

        $this->assertSame('user-value', $users->get('1'));
        $this->assertSame('post-value', $posts->get('1'));
    }

    #[Test]
    public function keyContainingPipeDoesNotCollideWithMultiTagInstance(): void
    {
        // This is the documented collision: before the fix, both of these
        // calls resolved to the same underlying cache slot ("a:1|b:1|x"),
        // so writing to `$a` with key "b:1|x" and reading from `$b` with
        // key "x" returned the write from the OTHER tagged scope.
        $a = new TaggedCache($this->backing, ['a']);
        $b = new TaggedCache($this->backing, ['a', 'b']);

        $a->set('b:1|x', 'LEAKED');

        $this->assertNull(
            $b->get('x'),
            'key "x" under tags [a,b] must not see write from key "b:1|x" under tag [a]'
        );
    }

    #[Test]
    public function keyContainingPipeAndColonRoundTripsForItsOwnScope(): void
    {
        $tc = new TaggedCache($this->backing, ['scope']);
        $tc->set('weird|key:with:chars', 'stored');
        $this->assertSame('stored', $tc->get('weird|key:with:chars'));
    }

    #[Test]
    public function emptyKeyAndKeyThatLooksLikeTagVersionAreDistinct(): void
    {
        // A user key that literally matches a plausible `tag:version`
        // string must not collide with an empty key under a different
        // tag layout.
        $single = new TaggedCache($this->backing, ['t']);
        $single->set('t:1', 'ONE');

        $double = new TaggedCache($this->backing, ['t', 't']);
        $this->assertNull($double->get(''));
    }

    #[Test]
    public function clearInvalidationStillIsolatesPerTag(): void
    {
        $users = new TaggedCache($this->backing, ['users']);
        $posts = new TaggedCache($this->backing, ['posts']);

        $users->set('1', 'A');
        $posts->set('1', 'B');

        $users->clear();

        $this->assertNull($users->get('1'));
        $this->assertSame('B', $posts->get('1'), 'clearing users tag must not touch posts tag');
    }

    #[Test]
    public function deleteOnlyAffectsOwnTaggedKey(): void
    {
        $a = new TaggedCache($this->backing, ['a']);
        $b = new TaggedCache($this->backing, ['a', 'b']);

        $a->set('k', 'in-a');
        $b->set('k', 'in-b');

        $a->delete('k');

        $this->assertNull($a->get('k'));
        $this->assertSame('in-b', $b->get('k'));
    }
}
