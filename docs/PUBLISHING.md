# Public source publication

Requested on 2026-10-26 (Asia/Manila): publish GroceryOne source and complete
system information in a public GitHub repository using the owner's supplied
author/contact address, `jmoarceo@pcu.edu.ph`.

Repository: [jmoarceo-cloud/manage-grocery-main](https://github.com/jmoarceo-cloud/manage-grocery-main).

**Published publicly.** The initial 771-file source snapshot is commit
[`fc6bc9e`](https://github.com/jmoarceo-cloud/manage-grocery-main/commit/fc6bc9ed8db4481c80cfcf575c417decb9533140)
on `main`, authored with the supplied email. Anonymous API access confirmed
public visibility, the expected branch/commit/file count and zero forbidden
paths; an unauthenticated repository-page request returned HTTP 200. The
working-tree and clean-history Gitleaks scans found no secrets. See PROGRESS
for exact checks and limits. Subsequent documentation commits record this result.

This is a source publication. It does not host the PHP application, expose its
databases, complete the Laravel migration or verify production availability.
No announcement email is sent as part of publication.

## Included source

The publication contains the current Laravel application, preserved original
PHP/PDO application, reviewed schema/migration source, tests, original-interface
assets and project documentation. The root README explains both entry paths,
feature coverage, dependencies, setup, evidence and remaining work.

A separate repository under ignored `storage/publishing/` is prepared from a
filtered working-tree snapshot. The existing local Git history, staging area
and uncommitted work are preserved. The public repository starts with a clean
history rather than uploading earlier local commits that were not audited for
public disclosure. Future publication must use the reviewed snapshot workflow;
do not push the original local history indiscriminately.

## Exclusions and sanitization

- Private `.env` variants, credentials, keys, database dumps/backups, snapshots,
  logs, sessions, caches, local databases and local migration marker files.
- Employee/applicant/customer uploads, avatars, payroll files, receipts and
  other uploaded documents. Upload-directory protection files may be retained.
- Installed dependencies, local tooling, `.kombai`, browser state, generated
  build outputs and unrelated local files outside the source selection.
- The legacy real-data homepage screenshot and earlier unclassified storefront
  screenshots; source-derived and explicitly synthetic UI evidence is retained.
- Local values in two legacy CLI account setup/reset utilities are replaced in
  the exported copy with generic edit-before-use values. Their existing default
  password refusal remains. The working application's scripts are unchanged.
- A sample mail address in a legacy mailer comment is made an `example.com`
  placeholder in the public copy; upstream attribution notices are retained.

Example environment files contain placeholders, not working credentials.
Do not copy private environment files into a clone to make a public demo.
Original source-hash audit artifacts describe their historical source snapshot;
publication-only sanitization can change exported source hashes.

## Publication checks

Before pushing, compare the export against the selected source, check forbidden
paths and schema-only artifacts, search for configured secret values without
printing them, and run a redacted Gitleaks scan. Check documentation links and
syntax of sanitized PHP utilities. Keep scan reports under ignored storage.
Run the configured documentation gate on the public repository's staged files.

After pushing, confirm GitHub visibility is public, the default branch and
commit match the prepared snapshot, and unauthenticated access works. Record
exact results in [PROGRESS](PROGRESS.md). A clean automated scan is bounded
evidence and does not prove the absence of every possible sensitive string.

PHPUnit and browser results linked in the README predate publication; do not
describe them as rerun merely because source upload succeeded.
