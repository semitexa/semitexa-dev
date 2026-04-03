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
- `deploy:bootstrap-remote` for first remote Ubuntu server deployment preflight

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

## Remote First Deployment Config

Remote first deployment is intentionally separate from framework auto-update. Operator-local target metadata belongs in `.env.local`:

```dotenv
SEMITEXA_REMOTE_DEPLOY_TARGETS=deploy@203.0.113.10,root@198.51.100.20
SEMITEXA_REMOTE_DEPLOY_PATH=/srv/semitexa/my-project
SEMITEXA_REMOTE_DEPLOY_SSH_PORT=22
SEMITEXA_REMOTE_DEPLOY_DOMAIN=my-project.example.com
SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD=false
```

Operator command:

- `bin/semitexa deploy:bootstrap-remote`

Phase 1 currently covers only the destructive preflight path for first deployment:

- interactive target selection or prompt
- destructive confirmation
- local SSH/scp/tar prerequisite check
- SSH key auth first, password fallback second
- Ubuntu 20.04+ remote OS detection
- remote initialization-state detection

Current phase 2 addition:

- local `.tar.gz` deploy artifact build
- local structured bootstrap log under `var/log/deployments/`
