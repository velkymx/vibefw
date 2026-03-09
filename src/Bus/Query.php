<?php

declare(strict_types=1);

namespace Fw\Bus;

/**
 * Marker interface for queries.
 *
 * Queries represent requests for information.
 * They should not modify system state.
 *
 * Queries should:
 * - Be named as questions or with Get/Find/List prefix
 * - Be immutable (use readonly classes)
 * - Return data without side effects
 *
 * Example:
 *     final readonly class GetUserById implements Query
 *     {
 *         public function __construct(
 *             public UserId $id
 *         ) {}
 *     }
 */
interface Query {}
