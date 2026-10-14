# Changelog

## 1.9.5 - 2026-10-14

- Add an Off-by-default, three-certificate/five-post cleanup gate that invokes
  the native XenForo Spam Cleaner as a moderator with the required permission.
- Soft-delete authored replies and profile messages through the native content
  cleaner and restoration log, including messages in restricted forums.
- Apply a seven-day native ban while preserving avatars, profile banners,
  thread starters and conversations; skip native user spam submission.
- Honor completed cleanup receipts after moderator restoration.
- Keep cleanup evidence and completion receipts separate from ban-only events.

## 1.9.4 - 2026-10-12

- Add an Off-by-default native seven-day ban after two independent eligible BLOCKs in 24 hours.
- Deduplicate both decision IDs and identical submitted bodies using atomic local evidence.
- Apply bans only in a separate job after rechecking ownership, current settings and staff/new-member exemptions.
- Preserve existing credentials, identity, routing, approval metadata and options on upgrades.
- Retain native reversal/expiry and local action audit; add no spam-clean or account deletion.

## 1.9.3 - 2026-10-02

- Preserve the persisted pre-edit post/thread body long enough for Forum Fortress to detect links injected during XenForo edits, including quote-link laundering.
- Send content age for edit-timing analysis while keeping the previous raw body transient and out of stored decision payloads.

## 1.9.2 - 2026-09-18

- Regenerate the XenForo integrity map for the resilient lifecycle release.

## 1.9.1 - 2026-09-18

- Route lifecycle and bootstrap requests through the resilient public API path.

## 1.9.0 - 2026-09-11

- Replace endpoint discovery and client-side health probing with deterministic
  routing: global uses `api.ffapi.net` then `fortress.ffapi.net`; regional mode
  uses only its configured endpoint unless global fallback is enabled, in which
  case it adds those two global routes in that order.
- Send standard-plan heartbeats hourly and Pro-plan heartbeats every ten minutes
  for multi-moderator coordination, while retaining the five-minute moderation
  queue job.
- Store runtime routing and sync state in XenForo's simple cache instead of
  rewriting global options after API requests, and report failed heartbeats.
- Make ACP connection status depend on a recent successful heartbeat, remove
  GET-time bootstrap side effects, hide managed credentials from generic option
  editing, and avoid exposing internal moderation or connection errors.
- Keep rejected-registration cleanup scoped to the active registration service
  instance so a failed attempt cannot affect a later registration.

## 1.8.14 - 2026-09-11

- Add XenForo phrases that give all Forum Fortress cron entries clear names in
  the Admin control panel.

## 1.8.13 - 2026-09-11

- Accept XenForo's manual and scheduled cron callback argument shapes so all
  Forum Fortress jobs can run from the ACP and the normal scheduler.

## 1.8.12 - 2026-09-11

- Fix XenForo cron metadata export so the heartbeat, moderation sync, and
  endpoint catalog jobs are imported from the standard `_data/cron.xml` file.

## 1.8.11 - 2026-09-07

- Regenerate XenForo's integrity manifest after the GPL release notice changed.
- This is the first installable public GPL release; it supersedes the source-only
  `v1.8.10` tag, whose stale integrity manifest was detected before binary release.

## 1.8.10 - 2026-09-07

- Initial source tag licensed under `GPL-2.0-or-later`.
- Add the complete GPLv2 text, project notice and same-licence contribution
  terms while keeping the hosted service separate.
- Forum Fortress approved GPL publication on 2026-09-07; no linking exception
  is granted or asserted.
