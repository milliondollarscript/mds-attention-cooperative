# Changelog

## Unreleased

- Added opt-in uninstall cleanup through the core extension policy for extension-owned records, settings, transients, credentials, replay data, and schedules.

## 0.3.0

- Implemented the Million Dollar Script v1 `service_signature` contract for incoming placement requests.
- Added opaque approved-partner credential IDs and random one-time secrets stored with authenticated encryption.
- Added administrator-only creation, 1–365 day expiry, 90-day defaults, 24-hour rotation overlap, and immediate revocation.
- Bound signatures to service ID, method, canonical route, timestamp, random nonce, exact body hash, and required placement idempotency key.
- Added strict timestamp validation, atomic replay claims, partner hourly rate enforcement, scope and relationship checks, and fail-closed verifier behavior.
- Added idempotent remote placement retries while keeping every accepted placement pending local review.
- Revoked credentials when partners are no longer approved, move pools, or are anonymized.
- Added OpenAPI metadata, privacy-safe credential administration, integration coverage, and signing/lifecycle documentation.
- Kept credential creation functional in WordPress Playground when the sodium compatibility shim cannot securely wipe PHP-managed memory.

## 0.2.0

- Open existing cooperatives on Placements, relabel the settings-only Overview tab as Settings, and preserve explicit edit-tab links.
- Split cooperative administration into directly addressable Overview, Inventory, Partners, Placements, Providers, and Activity tabs while preserving the selected cooperative and action context.
- Removed private cooperative contact email from public inventory manifests.
- Enforced active reviewed pools, approved same-pool partners, allowed exposed partner inventory, accepted terms, approval gates, and available capacity for placements.
- Added transactional, idempotent inventory consumption and restoration.
- Added complete paged privacy export and erasure plus scheduled retention cleanup for terminal records and audit IP hashes.
- Schedule retention during `init` so first activation remains warning-free alongside plugins that add translated cron intervals.
- Enforced HTTPS partner service endpoints and site-secret HMAC audit identifiers.
- Added editable partner and inventory forms, collapsed creation tools, accurate totals, responsive pagination, and explicit semantic notice colors.

## 0.1.0

- Added the initial local-first Attention Cooperative extension.
