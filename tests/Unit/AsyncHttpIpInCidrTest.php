<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #32: `ipInCidr()`'s mask was computed as `0xFF << (8-R)` with
 * no explicit byte-truncation. Empirically the downstream
 * `ord($byte) & $mask` is still correct (ord ≤ 0xFF erases the high
 * bits), but the code *reads* like it could overflow — easy to
 * mis-edit, hard to audit. The TODO also flagged "IPv6 handling
 * incomplete" — the byte-loop actually works for both families but
 * had no tests proving it.
 *
 * After [R37] the mask is explicitly `(0xFF << (8-R)) & 0xFF` and
 * `filter_var` gates the IP inputs up front. This suite locks the
 * boundary cases for both address families so a future refactor
 * can't regress the subtle-but-correct byte-mask behaviour.
 */
final class AsyncHttpIpInCidrTest extends TestCase
{
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $http = new AsyncHttp();
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('ipInCidr');
        return (bool) $m->invoke($http, $ip, $cidr);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function ipv4Provider(): array
    {
        return [
            'loopback /8 hit'           => ['127.0.0.1', '127.0.0.0/8', true],
            'loopback /8 miss'          => ['128.0.0.1', '127.0.0.0/8', false],
            'private /8 hit'            => ['10.255.255.255', '10.0.0.0/8', true],
            'private /12 low'           => ['172.16.0.0', '172.16.0.0/12', true],
            'private /12 high'          => ['172.31.255.255', '172.16.0.0/12', true],
            'private /12 just-outside'  => ['172.32.0.0', '172.16.0.0/12', false],
            'private /16 hit'           => ['192.168.5.10', '192.168.0.0/16', true],
            'link-local /16'            => ['169.254.169.254', '169.254.0.0/16', true],
            'zero net /8'               => ['0.1.2.3', '0.0.0.0/8', true],
            '/0 always hits'            => ['8.8.8.8', '0.0.0.0/0', true],
            '/32 exact'                 => ['8.8.8.8', '8.8.8.8/32', true],
            '/32 miss'                  => ['8.8.8.9', '8.8.8.8/32', false],
            '/1 top-bit match'          => ['200.0.0.0', '128.0.0.0/1', true],
            '/1 top-bit miss'           => ['127.255.255.255', '128.0.0.0/1', false],
            '/7 inside fc00-equiv'      => ['252.0.0.0', '252.0.0.0/7', true],
            '/7 outside fc00-equiv'     => ['254.0.0.0', '252.0.0.0/7', false],
        ];
    }

    /** @return array<string, array{string, string, bool}> */
    public static function ipv6Provider(): array
    {
        return [
            'ipv6 loopback'              => ['::1', '::1/128', true],
            'ipv6 loopback miss'         => ['::2', '::1/128', false],
            'ipv6 private fc00 hit'      => ['fc00::1', 'fc00::/7', true],
            'ipv6 private fd00 hit'      => ['fd00::abcd', 'fc00::/7', true],
            'ipv6 outside fc00/7'        => ['fe00::1', 'fc00::/7', false],
            'ipv6 link-local'            => ['fe80::1', 'fe80::/10', true],
            'ipv6 link-local miss'       => ['feC0::1', 'fe80::/10', false],
            'ipv6 /0 always'             => ['2001:db8::1', '::/0', true],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function mismatchedFamilyProvider(): array
    {
        return [
            'ipv4 vs ipv6 range'   => ['127.0.0.1', '::1/128'],
            'ipv6 vs ipv4 range'   => ['::1', '127.0.0.0/8'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidInputProvider(): array
    {
        return [
            'garbage ip'          => ['not-an-ip', '127.0.0.0/8'],
            'garbage range'       => ['127.0.0.1', 'not-a-range/24'],
            'range has no mask'   => ['127.0.0.1', '127.0.0.0'],
            'non-numeric mask'    => ['127.0.0.1', '127.0.0.0/abc'],
        ];
    }

    #[Test]
    #[DataProvider('ipv4Provider')]
    public function ipv4CidrMatchesExpectedBehavior(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame($expected, self::ipInCidr($ip, $cidr), "{$ip} in {$cidr}");
    }

    #[Test]
    #[DataProvider('ipv6Provider')]
    public function ipv6CidrMatchesExpectedBehavior(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame($expected, self::ipInCidr($ip, $cidr), "{$ip} in {$cidr}");
    }

    #[Test]
    #[DataProvider('mismatchedFamilyProvider')]
    public function mismatchedFamiliesNeverMatch(string $ip, string $cidr): void
    {
        $this->assertFalse(self::ipInCidr($ip, $cidr));
    }

    #[Test]
    #[DataProvider('invalidInputProvider')]
    public function invalidInputNeverMatches(string $ip, string $cidr): void
    {
        $this->assertFalse(self::ipInCidr($ip, $cidr));
    }
}
