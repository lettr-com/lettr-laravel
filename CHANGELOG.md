# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2025-01-23

### Added

- Initial release of Lettr for Laravel
- **Laravel Mail Integration**
  - Seamless integration with Laravel's Mail system
  - Use Lettr as default mail driver or alongside other drivers
  - Full support for Mailable classes
- **LettrMailable Base Class**
  - Use Lettr templates instead of Blade views
  - Fluent API for setting template slug, version, and project ID
  - Substitution data support for template variables
- **Service Provider**
  - Auto-registration via Laravel package discovery
  - Publishes configuration via `vendor:publish`
  - Lazy-loaded Lettr client singleton
- **Lettr Facade**
  - Direct access to Email, Domain, and Webhook services
  - IDE-friendly with full PHPDoc type hints
- **Mail Transport**
  - Converts Laravel emails to Lettr API format
  - Supports HTML, plain text, and attachments
  - CC and BCC recipient support
  - Automatic Lettr template detection via headers
- **Configuration**
  - Simple API key configuration via `.env`
  - Fallback to `services.lettr.key` config
- **Laravel Support**
  - Laravel 10.x, 11.x, and 12.x compatibility
  - PHP 8.4+ required
