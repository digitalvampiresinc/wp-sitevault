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


## Phase 2 — Safe Extraction Workspace & Restore Plan

After package validation succeeds, SiteVault can prepare an isolated restore-plan workspace.

The workspace extracts only the known outer payload files:

- manifest.json
- database/database.sql
- content/wp-content.zip
- checksums/sha256.json

The nested wp-content archive is not extracted into the live WordPress filesystem at this stage.

Before planning continues, SiteVault:

- revalidates the original package
- verifies extracted payload SHA-256 values again
- inspects actual CREATE TABLE statements in database.sql
- requires SQL table count to match the manifest
- rejects database tables outside the source database prefix
- compares source and target home/site URLs
- compares source and target database prefixes
- compares WordPress and PHP versions
- compares source and target wp-content filesystem paths
- checks target wp-content write access
- estimates restore disk-space requirements

The planner classifies the operation as either:

- same-domain restore
- cross-domain migration

The plan records whether URL replacement, site URL changes, database-prefix remapping or filesystem-path migration will be required.

This phase remains non-destructive. The restore execution layer stays locked until a mandatory pre-restore safety snapshot and controlled staging workflow are implemented.
