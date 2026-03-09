<?php

declare(strict_types=1);

namespace Fw\Bus;

/**
 * Marker interface for command/query handlers.
 *
 * Handlers contain the business logic for processing commands and queries.
 * Each handler should have a single handle() method that accepts its
 * associated command/query.
 *
 * Handlers should:
 * - Have a single public handle() method
 * - Receive dependencies through constructor injection
 * - Contain the actual business logic for the operation
 * - Return a result (for queries) or void/result (for commands)
 *
 * Example:
 *     final class CreateUserHandler implements Handler
 *     {
 *         public function __construct(
 *             private UserRepository $users,
 *             private PasswordHasher $hasher
 *         ) {}
 *
 *         public function handle(CreateUser $command): User
 *         {
 *             $user = new User(
 *                 UserId::generate(),
 *                 Email::from($command->email),
 *                 $command->name,
 *                 $this->hasher->hash($command->password)
 *             );
 *
 *             $this->users->save($user);
 *
 *             return $user;
 *         }
 *     }
 */
interface Handler {}
