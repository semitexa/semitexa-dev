# Semitexa Dev

Code generators and capability-aware CLI tooling for Semitexa development.

## Purpose

Provides safe file generation utilities for scaffolding modules, payloads, handlers, and other framework components. Includes conflict detection, force-overwrite support, and the agent-facing `ai:*` workflow surface.

This package is for **developer tooling only**. It does **not** own the production update lifecycle — package version detection, framework auto-deploy, remote bootstrap, and data patches live in [`semitexa/update`](../semitexa-update/README.md).

## Role in Semitexa

Depends on `semitexa/core`. Used during development to generate boilerplate and to drive the agent workflow. Does not register as a module.

## Key Features

- `SafeFileWriter` with conflict detection
- `TemplateResolverInterface` for pluggable template sources
- `NameInflectorInterface` for naming convention enforcement
- `make:*` generators (`make:payload`, `make:handler`, `make:resource`, `make:page`, `make:module`, `make:service`, `make:contract`, `make:event-listener`, `make:command`)
- `ai:*` workflow + memory commands (`ai:orient`, `ai:task`, `ai:epic`, `ai:work`, `ai:context`, `ai:plan`, `ai:verify`, `ai:trace`, `ai:backlog`, `ai:invoke`, `ai:ask`)
- `dev:graph:*` introspection commands
- `logs:app` log inspection
- `scaffold:sync-docs` for keeping the framework scaffold docs synchronized

## What lives elsewhere

| Concern | Owner |
|---|---|
| Schema migrations (table/column changes) | [`semitexa/orm`](../semitexa-orm/README.md) — `orm:diff`, `orm:sync` |
| Data patches (post-schema data work) | [`semitexa/update`](../semitexa-update/README.md) — `#[AsDataPatch]`, `update` |
| Framework auto-deploy + package updates | [`semitexa/update`](../semitexa-update/README.md) — `update:packages:auto`, `update:packages:check` |
| Remote first-deployment bootstrap (SSH) | [`semitexa/update`](../semitexa-update/README.md) — `update:packages:bootstrap-remote` |
