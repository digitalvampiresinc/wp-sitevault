# SiteVault Backup Format

## V1 Logical Package

A SiteVault backup is logically composed as:

```
sitevault-backup/
├── manifest.json
├── database/
│   └── database.sql
├── content/
│   └── wp-content.tar.gz
└── checksums/
    └── sha256.json
```

The final downloadable package may use the extension:

`.sitevault`

The internal format should remain transparent and recoverable using standard tooling wherever practical.

## Manifest

The manifest records:

- format version
- SiteVault version
- backup ID and creation date
- original site URL/home URL
- WordPress version
- PHP version
- database prefix/charset/collation
- archive inventory
- backup type
- package checksums

## Compatibility

Format changes must be versioned. Restore code must reject unsupported future formats rather than guessing.
