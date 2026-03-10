<?php

declare(strict_types=1);

namespace Fw\Domain;

/**
 * UserId Value Object.
 *
 * UUID-based identifier for User entities.
 * Extends Id to inherit generation and validation.
 */
final readonly class UserId extends Id {}
