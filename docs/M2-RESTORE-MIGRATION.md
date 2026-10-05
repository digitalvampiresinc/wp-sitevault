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


## Phase 3 — Mandatory Pre-Restore Safety Snapshot

A ready restore plan does not unlock destructive restore execution.

Before SiteVault can proceed, it must create a fresh `pre_restore` backup of the target site as it exists immediately before restoration.

The safety snapshot uses the proven SiteVault backup engine:

- bounded database export
- bounded wp-content scan/archive
- archive verification
- SHA-256 checksums
- portable .sitevault package
- package verification

The snapshot is linked to:

- restore Plan ID
- source backup ID
- target home URL
- restore mode
- rollback purpose

The safety workflow has separate state from ordinary user-created backups and does not replace the normal active-backup state.

### Controlled Restore Staging Seal

After the target safety package verifies successfully, SiteVault creates a sealed restore-staging record containing:

- restore Plan ID
- SHA-256 of the restore plan
- source backup ID
- target URL
- restore mode
- safety snapshot backup ID
- safety package path and SHA-256
- package verification result
- restore_execution_locked = true
- destructive_actions_taken = false

This seal proves the target rollback package exists before the future execution layer can begin.

The execution lock remains in place in this phase. Database import and live wp-content replacement are not yet implemented.


## Phase 4 — Shadow Database Staging

After the restore plan is Ready and the mandatory target safety snapshot is Safety Ready, SiteVault may stage the source database into isolated shadow tables.

This phase still does not replace any live WordPress table.

### Shadow Namespace

Every source table is mapped to a unique staging namespace derived from the restore Plan ID and target safety snapshot.

Example:

```
wp_posts
→
svstg_<token>_posts
```

The staging engine validates every generated identifier and drops only tables in its own generated namespace when restarting the same staging run.

### SQL Execution Restrictions

The SiteVault V1 database dump is streamed in bounded batches.

The staging executor accepts only the SQL statement types produced by the SiteVault exporter:

- CREATE TABLE
- INSERT INTO

Session SET statements are ignored and comment-prefixed DROP statements are not executed because SiteVault controls the shadow namespace itself.

Unexpected SQL types such as UPDATE, DELETE, ALTER, procedure creation or other commands are blocked.

### Import Verification

After import, SiteVault verifies:

- every expected shadow table exists
- staged table count matches the restore plan
- total staged row count matches the manifest row count

If any count differs, staging fails and live promotion remains locked.

### Migration Transform Staging

For cross-domain restores, URL and filesystem-path transformations run only against shadow tables.

The transformer:

- discovers text/blob/json-like columns
- requires a primary or unique key for safe row updates
- processes rows in bounded batches
- replaces source home/site URLs with target URLs
- replaces source wp-content filesystem paths with target paths
- handles JSON/plain-text strings
- detects PHP-serialized values
- unserializes arrays/scalars, replaces nested values, and reserializes them so PHP string lengths remain valid
- blocks serialized object data that requires a dedicated object-safe migration layer
- skips serialized decoding entirely when the value contains none of the migration strings

After transformation, total staged row count is checked again.

### Prefix Remapping

The shadow table namespace itself is independent of the target WordPress prefix.

If source and target WordPress prefixes differ, live promotion remains blocked until the dedicated option/usermeta prefix-data remapping layer is implemented. This avoids unsafe global prefix replacement inside arbitrary content.

### Safety Result

A successful Phase 4 ends with:

- status = verified
- live_tables_modified = false
- verified table count
- verified row count
- migration replacement counters
- optional promotion blocker

Live table promotion/swap remains a separate later phase.


## Phase 5 — Shadow wp-content Staging

After the matching shadow database reaches Verified status, SiteVault may stage the source wp-content archive into an isolated filesystem area.

This phase still does not overwrite, rename or delete anything inside the live target wp-content directory.

### Preconditions

wp-content staging requires all of the following for the same restore Plan ID:

- Restore Compatibility Plan = Ready
- Mandatory target safety snapshot = Safety Ready
- Shadow database = Verified

### Controlled Extraction

The nested `wp-content.zip` from the restore-plan workspace is opened directly.

Each archive entry must:

- begin with `wp-content/`
- remain free from parent-directory traversal
- remain free from absolute/drive-letter paths
- never target `wp-content/sitevault/`

The outer `wp-content/` container prefix is stripped and files are written beneath:

```
wp-content/sitevault/restore-staging/<plan-id>/shadow-wp-content/
```

Each file is:

1. streamed from the archive
2. written to a temporary staging file
3. checked against the ZIP entry's expected uncompressed size
4. atomically renamed into its final shadow path

Extraction is bounded to a maximum of 100 files or approximately 20 MB of uncompressed file data per browser-driven request. A single large file may occupy its own batch.

### Final Verification

After extraction completes, SiteVault independently walks the shadow wp-content directory and verifies:

- staged regular-file count equals the backup manifest file count
- total staged uncompressed bytes equal the backup manifest byte count
- every verified file resolves inside the controlled shadow root

The target site's own pre-restore file/byte totals are retained from the mandatory safety snapshot for comparison.

### Safety Result

A successful Phase 5 ends with:

- status = verified
- source files staged and verified
- source bytes staged and verified
- target-before-restore file/byte totals preserved
- live_files_modified = false
- ready_for_promotion = true

At this point both the source database and source wp-content have been reconstructed and verified outside the live site.

Live database promotion and live wp-content promotion remain separate future cutover phases.


## Phase 6 — Cutover Readiness Gate

The cutover readiness gate is the final non-destructive checkpoint before SiteVault gains a live promotion transaction.

It requires, for the same Restore Plan ID:

- Restore Compatibility Plan = Ready
- Mandatory Safety Snapshot = complete and safety_ready
- Safety rollback package = verified and still readable
- Shadow Database = Verified and ready_for_live_promotion
- Shadow wp-content = Verified and ready_for_promotion
- live_tables_modified = false
- live_files_modified = false

Before sealing readiness, SiteVault rechecks:

- restore-plan SHA-256 against the earlier safety staging seal
- safety rollback package SHA-256 against the current package bytes
- every shadow database table still exists
- total shadow database rows still match the verified staging state
- shadow wp-content root remains inside controlled SiteVault staging
- shadow wp-content file count and byte total still match verified staging state

If any staged component changed after verification, the cutover seal is refused.

A successful cutover-readiness record stores:

- Plan ID and Plan SHA-256
- source and target URLs
- restore mode
- safety snapshot ID and package fingerprint
- shadow database prefix, table count and row count
- shadow wp-content root, file count and byte count
- source wp-content archive SHA-256
- migration requirements
- destructive_actions_taken = false
- execution_locked = true
- next_stage = controlled-live-cutover

This phase remains non-destructive. Live database promotion and live wp-content promotion are still absent.
