# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- MIT License
- Community health files (CONTRIBUTING, SECURITY, CODE_OF_CONDUCT)
- GitHub issue and PR templates

### Fixed
- `content_save_pre` filters now unslash before parsing blocks, so block attributes are no longer dropped on save
- `require_alt` reads core/image alt text from the `<img alt>` markup, so images with alt text are no longer stripped
- `score-trend`, `video-caption-check` and `accessibility-score` REST routes require `edit_post` on the requested post
- `GET /gae/v1/logs?per_page=0` no longer fatals with `DivisionByZeroError`
- CI lints every PHP file and runs tests on PHP 8.1–8.4

## [1.0.0] - 2026-07-17

### Added
- Bulk accessibility validator
- Alt text editor
- WCAG rule profiles
- Accessibility trend chart
- Video captioning support
- WCAG-EM report export
- Voice-over simulator with SSML/transcript REST endpoint
- Block hierarchy sidebar REST endpoint
