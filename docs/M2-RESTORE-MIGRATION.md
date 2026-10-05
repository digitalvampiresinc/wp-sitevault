# SITEVAULT-M2 — Restore & Migration

## Phase 1 — Import & Validation

The first M2 phase is deliberately non-destructive.

A package can enter validation in two ways:

1. Upload a `.sitevault` package into a quarantined SiteVault import workspace.
2. Validate an existing completed package already stored in Backup History.

Validation performs:

- outer ZIP readability check
- exact V1 four-entry structure check
- unsafe/path-traversal rejection
- manifest JSON validation
- SiteVault format/version compatibility check
- SHA-256 verification for manifest, database SQL and wp-content ZIP
- payload byte-size verification
- nested wp-content ZIP readability check
- nested path-safety validation
- nested file-count comparison with manifest
- confirmation that `wp-content/sitevault/` is excluded

This phase does **not** modify:

- WordPress database tables
- wp-content files
- active theme/plugins
- site URL
- WordPress configuration

A successful validation means only:

> The package is structurally and cryptographically consistent enough to proceed to restore planning.

It does not yet mean the package is compatible with every target environment.

## Upload Limits

The first M2 validation form uses the normal PHP multipart upload path and therefore inherits the server's current PHP upload ceiling.

SiteVault's final migration workflow must not depend on that limit. Chunked/resumable package transfer remains part of the transfer layer and will be implemented before production-grade large-site migration is considered complete.

## Next Restore Phases

- extraction workspace and bounded package extraction
- restore plan / compatibility report
- mandatory pre-restore safety snapshot
- database import
- wp-content replacement strategy
- same-domain restore
- serialized-data-safe URL/path migration
- cross-domain clone
