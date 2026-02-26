# Changelog

All notable changes to Juzt Deploy will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Notes]

This version have complete feature planed on roadmap. The next versions is to improve a few things about the security or UX/UI.

Comming soom, the PRO version have more features a new UX/UI more ease to implement cloning the repositories, in addition, the PRO Version, were able to have, integrations with GitbLab and Azure Devops.

## [1.14.0] - 2016-02-24

- Fix issues for clone througth API
- Implement CronJob every 4 hours to refresh token authentication.
- Update UI for repositories list.
- Implemente Search on select repositories for improve performance on installation.

## [1.13.0] - 2025-12-23

- Fix issues with download wp-content path
- Fix issues with load paginate repositories
- Add button on settings page for set up Github App on other github accounts.

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
