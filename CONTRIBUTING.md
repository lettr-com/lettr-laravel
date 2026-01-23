# Contributing

Thank you for considering contributing to Lettr for Laravel!

## Development Setup

1. Fork and clone the repository

```bash
git clone https://github.com/TOPOL-io/lettr-laravel.git
cd lettr-laravel
```

2. Install dependencies

```bash
composer install
```

3. Create a new branch

```bash
git checkout -b feature/your-feature-name
```

## Code Style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for code formatting.

```bash
# Check code style
composer lint -- --test

# Fix code style
composer lint
```

## Static Analysis

This project uses [PHPStan](https://phpstan.org/) (via Larastan) at level 8.

```bash
composer analyse
```

## Testing

This project uses [Pest](https://pestphp.com/) for testing.

```bash
# Run tests
composer test

# Run specific test file
./vendor/bin/pest tests/Unit/LettrServiceProviderTest.php
```

## Pull Request Process

1. Ensure all tests pass

```bash
composer lint -- --test
composer analyse
composer test
```

2. Update documentation if needed

3. Create a pull request with a clear description of changes

4. Wait for review

## Commit Messages

Use clear, descriptive commit messages:

- `feat: add webhook signature verification`
- `fix: handle missing API key gracefully`
- `docs: update installation instructions`
- `test: add mail transport tests`
- `refactor: simplify service provider registration`

## Reporting Issues

When reporting issues, please include:

- PHP version
- Laravel version
- Package version
- Minimal code example to reproduce
- Expected vs actual behavior
- Error messages (if any)

## Security Vulnerabilities

If you discover a security vulnerability, please email security@lettr.com instead of using the issue tracker.
