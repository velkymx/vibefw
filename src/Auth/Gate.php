<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Model\Model;
use RuntimeException;

/**
 * Authorization Gate.
 *
 * The ONLY way to check permissions in this framework.
 *
 * Policies are classes named {Model}Policy in a configurable namespace
 * (defaults to App\Policies).
 * Policy methods receive the authenticated user and optionally a model instance.
 * Policy methods MUST return bool.
 *
 * Usage:
 *   Gate::allows('edit', $post)    - Returns bool
 *   Gate::denies('edit', $post)    - Returns bool
 *   Gate::authorize('edit', $post) - Throws ForbiddenException if denied
 *
 * Policy resolution:
 *   Gate::allows('edit', $post)        -> PostPolicy::edit(User, Post)
 *   Gate::allows('create', Post::class) -> PostPolicy::create(User)
 *
 * Configuration:
 *   Gate::setPolicyNamespace('App\\Policies');
 */
final class Gate
{
    private static string $policyNamespace = 'App\\Policies';

    private static array $policyCache = [];

    /**
     * Set the namespace where policy classes are resolved from.
     */
    public static function setPolicyNamespace(string $namespace): void
    {
        self::$policyNamespace = rtrim($namespace, '\\');
        self::$policyCache = [];
    }

    /**
     * Check if the current user is allowed to perform an action.
     */
    public static function allows(string $action, object|string $target): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return self::check($user, $action, $target);
    }

    /**
     * Check if the current user is denied from performing an action.
     */
    public static function denies(string $action, object|string $target): bool
    {
        return !self::allows($action, $target);
    }

    /**
     * Authorize an action or throw ForbiddenException.
     *
     * @throws ForbiddenException
     */
    public static function authorize(string $action, object|string $target): void
    {
        if (self::denies($action, $target)) {
            $targetClass = is_object($target) ? get_class($target) : $target;
            $userId = Auth::id() ?? 'guest';
            error_log("Authorization denied: user={$userId} action={$action} target={$targetClass}");
            throw new ForbiddenException();
        }
    }

    /**
     * Check authorization for a specific user (used internally and for testing).
     */
    public static function check(Model $user, string $action, object|string $target): bool
    {
        $policy = self::resolvePolicy($target);

        if ($policy === null) {
            return false;
        }

        // Check before() hook for admin bypass
        $before = $policy->before($user, $action);
        if ($before !== null) {
            return $before;
        }

        // Check the specific action method
        if (!method_exists($policy, $action)) {
            return false;
        }

        return is_object($target)
            ? $policy->$action($user, $target)
            : $policy->$action($user);
    }

    /**
     * Clear the policy cache (for testing).
     */
    public static function flushCache(): void
    {
        self::$policyCache = [];
    }

    /**
     * Resolve the policy class for a given target.
     */
    private static function resolvePolicy(object|string $target): ?Policy
    {
        $class = is_object($target) ? get_class($target) : $target;

        if (isset(self::$policyCache[$class])) {
            return self::$policyCache[$class];
        }

        $modelName = self::classBasename($class);
        $policyClass = self::$policyNamespace . "\\{$modelName}Policy";

        if (!class_exists($policyClass)) {
            return null;
        }

        $policy = new $policyClass();

        if (!$policy instanceof Policy) {
            throw new RuntimeException("$policyClass must extend " . Policy::class);
        }

        return self::$policyCache[$class] = $policy;
    }

    private static function classBasename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
