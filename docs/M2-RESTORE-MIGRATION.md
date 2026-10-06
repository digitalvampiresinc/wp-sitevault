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


## Phase 7 — Controlled Live Cutover Transaction

Phase 7 is the first destructive restore phase.

It is available only after Cutover Readiness is sealed and requires explicit administrator confirmation:

- acknowledgement checkbox
- exact confirmation phrase `RESTORE`
- browser confirmation

A Cutover Ready state alone never starts the restore.

### Single-Request Transaction

The destructive promotion runs in one server-side request.

This is required because a cross-domain restore replaces the source `users` and `usermeta` tables as part of the database restore. The administrator session from the target site may therefore stop being valid as soon as the database promotion succeeds.

The transaction sequence is:

1. Refresh and revalidate the Cutover Readiness seal.
2. Create a filesystem-backed transaction journal.
3. Enable the SiteVault restore lock for non-admin requests.
4. Preserve the currently executing SiteVault plugin code.
5. Prepare and checkpoint the database rollback map.
6. Atomically rename the live target database tables into rollback names and promote shadow tables into the target prefix.
7. Move the current live wp-content entries, except SiteVault runtime storage, into fast rollback storage.
8. Promote staged source wp-content into the live target.
9. Replace any restored SiteVault plugin copy with the currently executing SiteVault plugin build.
10. Verify the live database, target URLs, row totals and managed wp-content file/byte totals.
11. Reset stale SiteVault workflow options inside the restored database.
12. Release the restore lock.

### Database Promotion

The database cutover uses MySQL `RENAME TABLE` so the database promotion is atomic.

Current live target tables are renamed into a generated rollback namespace:

```
wp_posts
→
svbak_<transaction-token>_NNN
```

Verified shadow tables are renamed into the real target prefix in the same statement.

Extra target tables using the target prefix that are absent from the source restore are also moved into rollback storage. This gives full-site restore semantics rather than leaving unrelated target plugin tables live.

The rollback map is saved to the filesystem transaction journal before the atomic rename is executed.

### Filesystem Promotion

The SiteVault runtime directory itself is never moved.

All other current top-level wp-content entries are moved into:

```
wp-content/sitevault/cutover/<plan-id>/rollback-wp-content/
```

The staged source top-level entries are then renamed into live wp-content.

Because the source backup may contain an older SiteVault plugin build, the currently executing SiteVault plugin is copied into protected transaction storage before any destructive action. After source promotion, the restored `plugins/wp-sitevault` copy is replaced with the preserved current build.

This prevents the restore engine from downgrading itself during its own restore.

### Live Verification

Before success is declared, SiteVault verifies:

- every expected promoted source table exists under the target prefix
- promoted database total row count matches the verified shadow database
- target `home` and `siteurl` values match the restore target
- live managed wp-content files match the staged source counts and byte totals
- SiteVault runtime storage is excluded from source content verification
- the preserved current SiteVault plugin is excluded from source-file equivalence because it intentionally replaces the source copy

### Automatic Rollback

If database promotion, filesystem promotion or live verification fails, SiteVault attempts rollback inside the same request.

Filesystem rollback:

- quarantines any promoted source wp-content
- moves the original target wp-content entries back from fast rollback storage

Database rollback:

- renames promoted source tables back into their shadow names
- renames the original target rollback tables back into their original live names

If automatic rollback succeeds, the restore lock is released and the target site returns to its pre-cutover state.

If automatic rollback is incomplete, SiteVault leaves a filesystem transaction record with status `rollback_failed` and reports that manual recovery is required. The verified pre-restore `.sitevault` safety package remains available as the second recovery layer.

### Transaction Journal

The live transaction state is stored under SiteVault runtime storage, not only in WordPress options.

This is required because the live options table itself changes during database promotion.

The journal records:

- Plan ID
- source and target URLs
- restore mode
- safety snapshot ID
- database rollback and promotion maps
- filesystem entries moved from live and source staging
- current transaction stage
- live verification result
- automatic rollback attempt/result
- restore-lock state
- completion or failure timestamps

### Cross-Domain Login Behaviour

A successful cross-domain restore can invalidate the target administrator's current login because the source WordPress user tables become live.

This is expected full-site restore behaviour.

After successful cutover, the administrator may need to sign in using credentials that exist in the restored source database.



## Phase 8 — Manual Rollback to Pre-Restore Target

A successfully completed cutover retains fast rollback material until the administrator explicitly finalises the restore.

Phase 8 provides a deliberate manual reversal of a successful restore.

It requires:

- latest cutover transaction status = completed
- rollback_available = true
- original target rollback database tables still present
- original target rollback wp-content still present
- pre-restore safety manifest still readable
- current restored live source tables still present

The action requires:

- acknowledgement checkbox
- exact confirmation phrase `ROLLBACK`
- browser confirmation

### Manual Rollback Transaction

The reversal runs in one server-side request because the WordPress users table changes again during rollback.

Sequence:

1. Validate the completed transaction journal and retained rollback material.
2. Load the pre-restore safety snapshot manifest.
3. Preserve the currently executing SiteVault plugin build.
4. Enable the SiteVault maintenance lock.
5. Move the current restored wp-content into the source quarantine retained by the cutover transaction.
6. Restore the original target wp-content from fast rollback storage.
7. Replace the rolled-back SiteVault plugin copy with the current SiteVault build.
8. Atomically rename the current restored database back to the shadow namespace and the original target `svbak_*` tables back to their live names.
9. Verify the rolled-back database table count and total row count against the pre-restore safety manifest.
10. Verify managed wp-content file count and byte total against the pre-restore safety manifest, excluding SiteVault runtime and the intentionally preserved current SiteVault plugin.
11. Release the maintenance lock only after verification succeeds.

A successful result sets:

- transaction status = manually_rolled_back
- rollback_available = false
- current SiteVault plugin preserved = true
- original target DB/files restored and verified

### Login Behaviour

After a successful rollback, the original target `users` and `usermeta` tables are live again.

A login inherited from the restored source site may therefore stop working. The administrator may need to sign in with the credentials that existed on the target before the restore.

### Compensation if Manual Rollback Fails

Manual rollback itself is transactional.

If the reversal partially succeeds and a later step fails, SiteVault attempts to restore the previously successful post-cutover state.

Possible compensation:

- move the original target files back into rollback storage
- move the quarantined restored-source files back into live wp-content
- preserve the current SiteVault plugin build again
- atomically move original target live tables back into the `svbak_*` namespace
- promote source shadow tables back to the live target prefix

If this compensation succeeds:

- status = manual_rollback_reverted
- the successful restored-source state is live again
- maintenance lock is released

If compensation cannot complete safely:

- status = manual_rollback_failed
- maintenance lock remains in place
- the filesystem transaction journal and pre-restore safety package are retained
- manual recovery is required

### Finalisation Boundary

A successful restore or successful manual rollback does not automatically delete transaction material.

Cleanup/finalisation remains a separate explicit phase.
