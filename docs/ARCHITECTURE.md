# SiteVault Architecture

## Design Principles

1. Never depend on one giant HTTP upload.
2. Long operations must be resumable and processed in bounded batches.
3. Backup packages must be inspectable and portable.
4. Restores must support same-domain recovery and cross-domain cloning.
5. WordPress serialized data must never be corrupted by naive string replacement.
6. Production restore must create a pre-restore recovery point whenever practical.
7. Backup storage must be abstracted so local, object-storage and cloud adapters can coexist.

## Core Layers

### Backup Engine
Creates database export, content archive, manifest and checksums.

### Package Engine
Creates and reads SiteVault portable backup packages.

### Transfer Engine
Handles chunked upload/download and resumable transfers.

### Restore Engine
Validates package, restores files/database and tracks resumable state.

### Migration Engine
Safely rewrites source URLs and paths for cross-domain/site migration.

### Storage Layer
Local storage in V1. Remote storage adapters later.

### Recovery Layer
Standalone disaster-recovery loader planned for a later milestone.

## Runtime Storage

Runtime backups must live outside the plugin source directory:

`wp-content/sitevault/`

with:

- `backups/`
- `tmp/`
- `logs/`

Direct web access to these directories must be blocked before backups contain sensitive data.
