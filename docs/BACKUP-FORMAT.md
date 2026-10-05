# SiteVault Backup Format

## V1 Portable Package

A completed SiteVault backup is delivered as one portable file:

```
sv-YYYYMMDD-HHMMSS-xxxxxx.sitevault
```

The `.sitevault` file is a ZIP-compatible container with a fixed internal structure:

```
manifest.json
database/
└── database.sql
content/
└── wp-content.zip
checksums/
└── sha256.json
```

The nested `wp-content.zip` is stored without recompressing it inside the outer package where the server supports that operation. This avoids wasting CPU by compressing an already compressed archive again.

## Manifest

The final manifest records:

- format and format version
- backup ID and timestamps
- source site URL and home URL
- WordPress version
- PHP version
- database prefix, charset and collation
- backup type
- database table/row summary
- wp-content file/byte summary
- checksum file location and algorithm
- package filename and container format

## SHA-256 Integrity Data

`checksums/sha256.json` stores SHA-256 values and byte sizes for:

- `manifest.json`
- `database/database.sql`
- `content/wp-content.zip`

The package itself also receives an external SHA-256 value in SiteVault package state/history. It is intentionally not stored inside itself because a file cannot contain its own final checksum without changing that checksum.

## Package Verification

Before a package is marked complete, SiteVault reopens the container and confirms that exactly these required entries exist:

- `manifest.json`
- `database/database.sql`
- `content/wp-content.zip`
- `checksums/sha256.json`

The wp-content archive has already passed its own entry-count and SiteVault-runtime-exclusion verification before packaging begins.

## Compatibility

Format changes must increment the format version. Restore code must reject unsupported future formats rather than guessing.
