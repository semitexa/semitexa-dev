# Semitexa Dev

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
- `deploy:bootstrap-remote` for first remote Ubuntu server bootstrap over SSH

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
SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND=bin/semitexa server:start
SEMITEXA_AUTO_DEPLOY_HOME=/var/www/html/var/auto-deploy/home
SEMITEXA_AUTO_DEPLOY_COMPOSER_HOME=/var/www/html/var/auto-deploy/composer
SEMITEXA_AUTO_DEPLOY_GIT_SSH_COMMAND=ssh -i /var/www/html/var/auto-deploy/ssh/github_ed25519 -o IdentitiesOnly=yes -o UserKnownHostsFile=/var/www/html/var/auto-deploy/ssh/known_hosts
```

Operational commands:

- `bin/semitexa deploy:check`
- `bin/semitexa deploy:auto`

Production bootstrap sequence for tag-based updates:

1. Materialize a release-oriented root manifest:
   - `php vendor/bin/semitexa deploy:materialize-release-composer --write-root --json`
2. Ensure the host can authenticate to every private `semitexa/*` GitHub repository referenced by the project.
3. Configure `SEMITEXA_AUTO_DEPLOY_*` in the project environment.
4. Install a polling trigger:
   - `sudo SEMITEXA_AUTO_DEPLOY_ENABLE=1 packages/semitexa-dev/tools/install-auto-deploy-systemd.sh /srv/semitexa/my-project`

Important:

- private packages such as `semitexa/site`, `semitexa/os-site`, and `semitexa/platform-site` require working GitHub SSH access on the target host
- containerized projects should set `SEMITEXA_AUTO_DEPLOY_HOME`, `SEMITEXA_AUTO_DEPLOY_COMPOSER_HOME`, and `SEMITEXA_AUTO_DEPLOY_GIT_SSH_COMMAND` so Composer/Git auth also works inside the app container
- the systemd installer copies the host-side wrapper into `<project>/tools/run-auto-deploy-systemd.sh`, and the service runs it to rerun `./bin/semitexa server:start` when `deploy:auto` reports `restart_required=true`
- without that SSH access even `composer update --lock --no-install` will fail, so enabling the timer early is incorrect

## Remote First Deployment Config

Remote first deployment is intentionally separate from framework auto-update. Operator-local target metadata belongs in `.env`:

```dotenv
SEMITEXA_REMOTE_DEPLOY_TARGETS=deploy@203.0.113.10,root@198.51.100.20
SEMITEXA_REMOTE_DEPLOY_PATH=/srv/semitexa/my-project
SEMITEXA_REMOTE_DEPLOY_SSH_PORT=22
SEMITEXA_REMOTE_DEPLOY_DOMAIN=my-project.example.com
SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD=false
```

Operator command:

- `bin/semitexa deploy:bootstrap-remote`

Optional operator-local SSH override:

```dotenv
SEMITEXA_REMOTE_DEPLOY_SSH_IDENTITY_FILE=/home/user/.ssh/id_ed25519
```

Phase 1 currently covers the first complete remote bootstrap slice:

- interactive target selection or prompt
- destructive confirmation
- local SSH/scp/tar prerequisite check
- SSH key auth first, password fallback second
- Ubuntu 20.04+ remote OS detection
- remote initialization-state detection
- local `.tar.gz` deploy artifact build
- remote `.env` materialization from `--remote-env-file` or generated prod-safe defaults
- remote artifact/script upload to a temporary bootstrap workspace
- Ubuntu bootstrap scenario execution
- Docker install/verification, `bin/semitexa install`, `bin/semitexa server:start`, and `cache:clear`
- remote verification and deployment marker creation
- local structured bootstrap log under `var/log/deployments/`
