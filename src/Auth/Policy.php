<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Model\Model;

/**
 * Base Policy Class.
 *
 * EVERY policy MUST extend this class.
 * EVERY policy method MUST return bool.
 * EVERY policy method receives the authenticated user model as the first argument.
 *
 * Naming convention:
 *   - Policy class: {ModelName}Policy (e.g., PostPolicy for Post model)
 *   - Policy location: Configurable via Gate::setPolicyNamespace() (default: App\Policies)
 *   - Method names: Match the action (view, create, edit, delete, etc.)
 *
 * Example:
 *
 *   class PostPolicy extends Policy
 *   {
 *       public function view(Model $user, Post $post): bool
 *       {
 *           return true; // Anyone can view
 *       }
 *
 *       public function create(Model $user): bool
 *       {
 *           return true; // Any authenticated user can create
 *       }
 *
 *       public function edit(Model $user, Post $post): bool
 *       {
 *           return $user->id === $post->user_id;
 *       }
 *
 *       public function delete(Model $user, Post $post): bool
 *       {
 *           return $user->id === $post->user_id;
 *       }
 *   }
 */
abstract class Policy
{
    /**
     * Override this to bypass ALL policy checks for certain users.
     * If this returns true, all actions are allowed without checking methods.
     * If this returns false, normal policy checks apply.
     * If this returns null, normal policy checks apply.
     *
     * Use this for admin bypass:
     *
     *   protected function before(Model $user, string $action): ?bool
     *   {
     *       if ($user->role === 'admin') {
     *           return true;
     *       }
     *       return null;
     *   }
     */
    public function before(Model $user, string $action): ?bool
    {
        return null;
    }
}
