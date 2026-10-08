# DME-RESTORE-001 — Large Package Upload

Status: FAIL / FIX IN PROGRESS

## Reproduction
- Source package: production DME SiteVault V2 backup.
- Package size: approximately 1.6 GB.
- Target: ananyapandit WordPress installation.
- Attempt: upload package through SiteVault Restore Package Validation screen.
- Result: upload ran for some time and then failed with HTTP 403 / timeout.

## Root cause
The restore UI still used one standard WordPress multipart upload request for the entire package. At production size this is vulnerable to PHP upload/request limits, proxy/WAF limits, web-server timeouts and connection interruption.

## Fix
PR #16 now adds a resumable chunk upload transport:
- browser slices package into 8 MB chunks;
- each chunk is uploaded independently through authenticated WordPress AJAX;
- server appends chunks to a controlled SiteVault import workspace;
- server records confirmed byte offset and next chunk index;
- browser persists upload session identity locally;
- retry resumes from the last server-confirmed chunk;
- package validation begins only after expected package size has been fully received.

## Validation gate
Install the next PR #16 package on ananyapandit and upload the same 1.6 GB file. Confirm upload reaches 100%, validation completes, and retry after a deliberately interrupted upload resumes rather than restarts.
