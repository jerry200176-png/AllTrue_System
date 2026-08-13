# Controlled status revert: in-app bug #235

This is a one-time corrective workflow. It changes only in-app bug **#235**.

## Authorization and evidence

- Authorized purpose: restore the accidental `in_progress` -> `resolved` write
  from Phase-C run `31705615016`.
- Read-only verification run `31706120019` confirmed that #235 is currently
  `resolved`, and its latest status-log record is `713`, from `in_progress` to
  `resolved`.
- The production service permits the reversible `resolved` -> `in_progress`
  transition. No business data, comments, attachments, or other bug IDs may be
  changed.

## Fail-closed contract

The workflow will only proceed if #235 is still `resolved` and status-log #713
is still the exact Phase-C transition recorded against production revision
`1852913e67445b8aa502664558b409520f00b07e`. It re-checks those facts while
holding a row lock, then records a single audited `resolved` -> `in_progress`
transition. Any different state fails without a write.

The same change removes only #235 from the legacy Phase-C allowlist. That
prevents a later generic Phase-C run from resolving #235 again. No other
allowlist entry is changed.

The legacy workflow is no longer triggered by edits to its own workflow file;
only its dedicated request file may start it. This merge therefore cannot
start a generic Phase-C write-back. The dedicated #235 workflow remains the
only production mutation path in this PR.
