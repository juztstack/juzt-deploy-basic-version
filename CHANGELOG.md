# Changelog

All notable changes to Juzt Deploy will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Planned
- Rollback functionality
- Team collaboration features

## [1.9.0] - 2025-11-15

### Added
- GitHub API mode for hosts without Git command support
- Alternative connection method using GitHub REST API
- API-based clone, pull, and push operations

### Fixed
- Issues with Git command availability on limited hosting environments
- Compatibility with hosts that restrict shell command execution

### Changed
- Enhanced deployment flexibility with dual-mode operation (Git CLI / GitHub API)

## [1.7.0] - 2025-10-20

### Added
- Auto-commit filter for Section Builder v1 changes
- Automatic token refresh mechanism
- Queue-based commit management system
- Commit history tracking

### Fixed
- Authentication issues with private repositories
- Token expiration handling

### Improved
- Repository synchronization reliability
- Error handling for failed commits

## [1.0.0] - 2024-09-01

### Added
- Initial release
- GitHub OAuth integration
- Basic deployment functionality
- Repository connection management
- Webhook support

[Unreleased]: https://github.com/juztstack/juzt-deploy/compare/v1.9.0...HEAD
[1.9.0]: https://github.com/juztstack/juzt-deploy/compare/v1.7.0...v1.9.0
[1.7.0]: https://github.com/juztstack/juzt-deploy/compare/v1.0.0...v1.7.0
[1.0.0]: https://github.com/juztstack/juzt-deploy/releases/tag/v1.0.0
