# Contributing to VibeFW

Thank you for your interest in contributing to VibeFW! This document provides guidelines and information for contributors.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment. Be kind, be patient, and be constructive.

## How to Contribute

### Reporting Bugs

1. **Search existing issues** to avoid duplicates
2. **Use the bug report template** when creating an issue
3. **Include reproduction steps** with minimal code examples
4. **Specify your environment**: PHP version, OS, database

### Suggesting Features

1. **Open a discussion first** to gauge interest
2. **Explain the use case** - why is this feature needed?
3. **Consider backwards compatibility**

### Submitting Pull Requests

1. **Fork the repository** and create a feature branch
2. **Write tests** for new functionality
3. **Follow the coding standards** (run `composer lint`)
4. **Update documentation** if needed
5. **Keep commits focused** - one feature/fix per PR

## Development Setup

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/vibefw.git
cd vibefw

# Install dependencies
composer install

# Run tests
composer test

# Check code style
composer lint

# Run static analysis
composer analyse
```

## Coding Standards

We follow PSR-12 with some additional rules:

### PHP Style

- Use `declare(strict_types=1);` in all files
- Use typed properties and return types
- Prefer composition over inheritance
- Keep methods focused and small

### Naming Conventions

- Classes: `PascalCase`
- Methods/Functions: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Variables: `camelCase`

### Documentation

- Add PHPDoc for public methods
- Use `@throws` for exceptions
- Include `@example` for complex usage

### Example

```php
<?php

declare(strict_types=1);

namespace Fw\Example;

/**
 * Brief description of the class.
 *
 * Longer description if needed.
 */
final class ExampleService
{
    /**
     * Process the given data.
     *
     * @param array<string, mixed> $data Input data to process
     * @return Result<ProcessedData, ValidationError>
     * @throws \InvalidArgumentException If data is malformed
     *
     * @example
     *     $result = $service->process(['key' => 'value']);
     */
    public function process(array $data): Result
    {
        // Implementation
    }
}
```

## Testing

### Running Tests

```bash
# All tests
composer test

# Specific test file
./vendor/bin/phpunit tests/Unit/Core/RouterTest.php

# With coverage
composer test:coverage
```

### Writing Tests

- Place unit tests in `tests/Unit/`
- Place integration tests in `tests/Integration/`
- Use descriptive test method names
- Test edge cases and error conditions

```php
public function test_router_matches_route_with_parameters(): void
{
    $router = new Router();
    $router->get('/users/{id}', fn() => 'ok');

    $result = $router->dispatch('GET', '/users/123');

    $this->assertTrue($result->isOk());
    $this->assertEquals(['123'], $result->getValue()->params);
}
```

## Pull Request Process

1. **Create a feature branch**: `git checkout -b feature/my-feature`
2. **Make your changes** with tests
3. **Run the full test suite**: `composer ci`
4. **Push to your fork**: `git push origin feature/my-feature`
5. **Open a Pull Request** against `main`

### PR Checklist

- [ ] Tests pass locally (`composer test`)
- [ ] Code style is correct (`composer lint`)
- [ ] Static analysis passes (`composer analyse`)
- [ ] Documentation updated (if applicable)
- [ ] CHANGELOG.md updated (for significant changes)

## Release Process

Releases follow [Semantic Versioning](https://semver.org/):

- **MAJOR**: Breaking changes
- **MINOR**: New features (backwards compatible)
- **PATCH**: Bug fixes (backwards compatible)

## Questions?

- Open a [GitHub Discussion](https://github.com/velkymx/vibefw/discussions)
- Check existing [issues](https://github.com/velkymx/vibefw/issues)

Thank you for contributing!
