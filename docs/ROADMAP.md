# SiteVault Roadmap

## SITEVAULT-M1 — Core Backup Engine

Target:

> Install SiteVault → Create Backup → produce a valid backup containing database + wp-content + manifest + checksums.

Tasks:

- [x] Repository foundation
- [x] Plugin bootstrap
- [x] Runtime storage foundation
- [x] Initial manifest generator
- [ ] Database exporter
- [ ] File inventory/scanner
- [ ] wp-content archive engine
- [ ] SHA-256 checksums
- [ ] Package builder
- [ ] Backup history
- [ ] Admin Create Backup workflow
- [ ] Progress/status endpoint
- [ ] Error and operation logging
- [ ] M1 integration test

## SITEVAULT-M2 — Restore & Migration

- Package validation
- Batch extraction
- Database importer
- URL/path replacement
- Serialized-data-safe migration
- Pre-restore snapshot
- Same-domain restore
- Cross-domain clone

## SITEVAULT-M3 — Chunked Transfer

- Chunked upload
- Resume interrupted upload
- Chunk integrity validation
- Server-side assembly
- Restore without PHP single-upload limits

## SITEVAULT-M4 — Storage & Automation

- Scheduled backups
- Retention policies
- S3-compatible adapter
- Cloudflare R2 adapter
- Google Drive adapter

## SITEVAULT-M5 — Advanced Recovery

- Incremental backups
- Selective restore
- Encryption
- Standalone recovery loader
