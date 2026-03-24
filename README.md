# semitexa/dev

Code generators and capability-aware CLI tooling for application scaffolding.

## Purpose

Provides safe file generation utilities for scaffolding modules, payloads, handlers, and other framework components. Includes conflict detection and force-overwrite support.

## Role in Semitexa

Depends on `semitexa/core`. Used during development to generate boilerplate. Does not register as a module and has no runtime footprint.

## Key Features

- `SafeFileWriter` with conflict detection
- `TemplateResolverInterface` for pluggable template sources
- `NameInflectorInterface` for naming convention enforcement
- JSON-formatted generation output

## Notes

This is a development-time library. It does not register as a module and has no runtime footprint in production.
