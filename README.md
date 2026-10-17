# Million Dollar Script - Launch Wall

Launch Wall is a local-first Million Dollar Script 3.0 extension for selling and moderating launch slots, featured placements, and sponsored launch boards.

## Features

- Multiple launch walls with shortcode rendering.
- Public launch submission form with nonce and honeypot protection, explicit terms acceptance, strict URL/date/email validation, atomic capacity enforcement, and configurable hourly throttling.
- Admin moderation for approve, reject, feature, and unfeature actions.
- Paid placement disclosure and terms link support.
- Optional Million Dollar Script 3.0 payment-provider checkout without direct WooCommerce calls.
- Independent moderation and payment gates keep provider-mode launches private until both requirements are satisfied.
- Visitor voting with nonce validation, atomic counters, publication checks, and configurable daily limits using one-way IP-derived keys.
- Paginated WordPress personal-data export and erasure callbacks plus scheduled per-wall retention cleanup.
- Paginated REST API endpoints registered in the Million Dollar Script API manifest without private data in read-scoped responses.
- MDS2-compatible submenu fallback for older installs.

## Shortcode

```text
[mds_launch_wall id="1"]
```

## REST API

- `GET /wp-json/million-dollar-script/v1/launch-wall/walls`
- `GET /wp-json/million-dollar-script/v1/launch-wall/launches?wall_id=1`
- `POST /wp-json/million-dollar-script/v1/launch-wall/launches`
- `POST /wp-json/million-dollar-script/v1/launch-wall/launches/{id}/moderate`
- `POST /wp-json/million-dollar-script/v1/launch-wall/launches/{id}/vote`

## Changelog

### 0.2.0

- Added capacity enforcement, strict submission validation, public voting, scalable pagination, private REST payloads, checkout failure handling, email notifications, scheduled retention, and accessible semantic status styling.

### 0.1.0

- Initial local-first Launch Wall implementation.
