# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-27

First stable release. A full-featured admin scaffold built on Hyperf 3.1 + Swoole + Vue 3.

### Core Framework

- JWT authentication with Redis session registry: multi-device session list, per-session kick, current-device preservation on password change
- RBAC: roles / permissions (menu & button level) / menus, front-end route and directive integration
- Departments: materialized-path tree CRUD, move with subtree rewrite, cycle guards
- Role data scope: `all` / `dept` / `dept_and_children` / `self`, enforced on user list, login & operation audit (incl. export)
- Config center: DB-backed key-value store with type-aware editing, Redis cache with per-database isolation, hot reload for thresholds & feature toggles

### Security

- SVG captcha (drawn in-process, no GD dependency), one-time consumption, graceful degradation when Redis is down
- Password policy: length/complexity, username exclusion, weak-password blacklist; enforced on create / reset / change
- TOTP two-factor (self-implemented RFC 6238, ±1 time window, 90 s replay protection), bind-then-confirm flow, two-step login
- Login throttle with configurable attempt threshold
- Login risk assessment: known IP / new IP same subnet / unknown subnet / odd hours rules; medium+ risk triggers an in-app security alert
- API & operation audit logs with Excel export (5,000-row cap), prunable by retention days

### Productivity

- Async queue (Redis driver) + Job center: status ledger (waiting/executing/succeeded/failed), duration tracking, retry, result-file download, in-app notifications, header badge
- Excel import/export for products (template download, batch upsert)
- Code generator: from DB table to controller/service/model/routes/vue pages, tree-mode support, OpenAPI annotations
- Swagger export (OpenAPI 3.0 JSON + Redoc viewer at `/api/docs`)
- Notifications: per-user inbox, unread summary, event-driven pushes (crontab failure, password reset, account changes, welcome)
- Crontab manager with alerting (failure notification, configurable threshold & webhook)
- File management: upload, list, download with storage abstraction
- Health monitor: MySQL/Redis/runtime/queue backlog/disk/crontab next-run/API latency (p50/p95/slowest paths)/recent error logs
- Dark mode and layout polish

### Tooling

- Docker deployment pack (docker-compose for mysql/redis/php-swoole)
- CI: lint, strict php-cs-fixer, phpstan, phpunit (117 tests / 579 assertions), vue-tsc + build; coverage report artifact
- `demo:seed` command to populate demo data (users, departments, roles, logs) for instant preview

[1.0.0]: https://github.com/wywk/hyperf-admin/releases/tag/v1.0.0
