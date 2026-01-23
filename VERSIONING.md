# Versioning

Lettr for Laravel follows [Semantic Versioning](https://semver.org/) (SemVer).

## Version Format: `MAJOR.MINOR.PATCH`

| Component | When to increment                           | Example            |
| :-------- | :------------------------------------------ | :----------------- |
| MAJOR     | Breaking changes (incompatible API changes) | `1.0.0` -> `2.0.0` |
| MINOR     | New features (backward compatible)          | `1.0.0` -> `1.1.0` |
| PATCH     | Bug fixes (backward compatible)             | `1.0.0` -> `1.0.1` |

## Pre-1.0 Versioning (Current)

While in `0.x.x`:

- **Minor version bumps may contain breaking changes** (`0.1.0` -> `0.2.0`)
- Patch versions are always backward compatible (`0.1.0` -> `0.1.1`)
- This allows API refinement based on real-world usage

## Release Workflow

### 1. Update CHANGELOG

Add a new entry to `CHANGELOG.md` following [Keep a Changelog](https://keepachangelog.com/) format:

```markdown
## [0.2.0] - 2024-01-15

### Added
- New feature description

### Changed
- Changed behavior description

### Fixed
- Bug fix description

### Removed
- Removed feature description
```

**Changelog categories:**

| Category      | Description                                      |
| :------------ | :----------------------------------------------- |
| Added         | New features                                     |
| Changed       | Changes in existing functionality                |
| Deprecated    | Soon-to-be removed features                      |
| Removed       | Removed features                                 |
| Fixed         | Bug fixes                                        |
| Security      | Vulnerability fixes                              |

**Tips:**
- Write entries from user perspective, not developer perspective
- Link to related issues/PRs when relevant
- Keep descriptions concise but informative

### 2. Update Version Constant

```php
// src/LettrServiceProvider.php
public const VERSION = '0.2.0';
```

### 3. Create Git Tag and Push

```bash
git tag -a v0.2.0 -m "Release 0.2.0"
git push origin main --tags
```

GitHub Actions will automatically:

- Run tests, linting, and static analysis
- Create GitHub Release (if all checks pass)
- Packagist updates via webhook

## Version History

| Version | Type    | Description   |
| :------ | :------ | :------------ |
| `0.1.0` | Initial | First release |

## Moving to 1.0.0

The package will move to `1.0.0` when:

- API is stable and proven in production
- No planned breaking changes
- Full test coverage
- Complete documentation

After `1.0.0`, breaking changes only happen in major versions.

## Composer Installation

```bash
# Always installs latest stable
composer require lettr/lettr-laravel

# Specific version
composer require lettr/lettr-laravel:^0.1
```
