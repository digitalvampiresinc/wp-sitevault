# SiteVault Roadmap

## SITEVAULT-M1 — Core Backup Engine

Target:

> Install SiteVault → Create Backup → produce a valid backup containing database + wp-content + manifest + checksums.

Tasks:

- [x] Repository foundation
- [x] Plugin bootstrap
- [x] Runtime storage foundation
- [x] Initial manifest generator
- [x] Resumable database exporter foundation
- [x] Resumable file inventory/scanner foundation
- [x] Resumable wp-content ZIP archive foundation
- [x] SHA-256 checksum foundation
- [x] Portable .sitevault package builder foundation
- [x] Backup history and protected download foundation
- [x] Automatic chained database backup workflow
- [x] Runtime validation of wp-content scanner/archive
- [x] Full Admin Create Backup workflow
- [ ] Progress/status endpoint
- [ ] Error and operation logging
- [x] Final M1 package/download integration test

## SITEVAULT-M2 — Restore & Migration

- [x] Package validation foundation
- [x] Safe outer-package extraction workspace
- [x] Restore compatibility plan foundation
- [x] Shadow database importer foundation
- [ ] URL/path replacement
- [x] Serialized-safe migration staging foundation
- [x] Mandatory pre-restore snapshot foundation
- [x] Controlled restore staging seal foundation
- [ ] Same-domain restore
- [ ] Cross-domain clone

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

- [ ] Live database promotion/swap
