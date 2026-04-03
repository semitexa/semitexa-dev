# semitexa/dev

Code generators and capability-aware CLI tooling for application scaffolding.

## Purpose

Provides safe file generation utilities for scaffolding modules, payloads, handlers, and other framework components. Includes conflict detection, force-overwrite support, and operational CLI tooling for framework maintenance.

## Role in Semitexa

Depends on `semitexa/core`. Used during development to generate boilerplate and operational automation commands. Does not register as a module.

## Key Features

- `SafeFileWriter` with conflict detection
- `TemplateResolverInterface` for pluggable template sources
- `NameInflectorInterface` for naming convention enforcement
- JSON-formatted generation output
- `deploy:check` for Semitexa framework update discovery
- `deploy:auto` for phase-1 Composer-based framework auto deployment

## Notes

This package is also the canonical home for Semitexa deployment and operator tooling. Phase 1 auto deployment updates only `semitexa/*` packages, supports Packagist and private Git-tag discovery, and is controlled by `SEMITEXA_AUTO_DEPLOY_*` project config.

## Auto Deployment Config

Project-level auto deployment is disabled by default and can be enabled through environment config:

```dotenv
SEMITEXA_AUTO_DEPLOY_ENABLED=true
SEMITEXA_AUTO_DEPLOY_CHANNEL=stable
SEMITEXA_AUTO_DEPLOY_SOURCE=mixed
SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL=git@github.com:semitexa/releases.git
SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL=https://example.test/health
SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND=docker compose restart
```

Operational commands:

- `bin/semitexa deploy:check`
- `bin/semitexa deploy:auto`
