<?php

declare(strict_types=1);

namespace Fw\Model;

/**
 * Marker interface for classes that are safe to instantiate
 * via `new $class($value)` during model attribute casting.
 *
 * Without this interface, `castToClass()` will refuse the
 * constructor fallback — even for classes in the Fw\ or App\
 * namespaces — and throw a RuntimeException instead.
 *
 * This prevents arbitrary class instantiation if a cast class
 * name is ever sourced from untrusted input (e.g. schema import,
 * admin UI, or future generator).
 */
interface Castable
{
}
