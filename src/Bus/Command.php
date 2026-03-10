<?php

declare(strict_types=1);

namespace Fw\Bus;

/**
 * Marker interface for commands.
 *
 * Commands represent intentions to change system state.
 * They are immutable data objects containing all information
 * needed to perform an action.
 *
 * Commands should:
 * - Be named in imperative form (CreateUser, PlaceOrder)
 * - Be immutable (use readonly classes)
 * - Contain all data needed to execute the action
 * - Not contain business logic
 *
 * Example:
 *     final readonly class CreateUser implements Command
 *     {
 *         public function __construct(
 *             public string $email,
 *             public string $name,
 *             public string $password
 *         ) {}
 *     }
 */
interface Command {}
