# SiteVault – WordPress Backup, Restore & Migration

SiteVault is an independent WordPress backup, restore and migration plugin designed for reliable operation on normal shared/cloud hosting without depending on very large single-request uploads.

## Current Status

**Milestone:** SITEVAULT-M1 — Core Backup Engine  
**Stage:** Foundation / early development  
**Version:** 0.1.0-dev

## V1 Goals

- Full database backup
- Full `wp-content` backup
- Backup manifest and checksums
- Downloadable backup package
- Chunked upload/import
- Batch extraction and restore
- Cross-domain migration
- Serialized-data-safe URL replacement
- Backup history and logs
- Pre-restore safety snapshot

## Planned Later

- Scheduled backups
- Remote storage adapters
- Incremental backups
- Selective restore
- Backup encryption
- Standalone disaster recovery loader

## Architecture

SiteVault is intentionally independent of any one website. It can be used on DME, Rauaab, jGlam, FME and other WordPress installations.

See the `docs/` directory for architecture and backup-format notes.

## Development

Do not use SiteVault on production sites until a release is explicitly marked stable.
