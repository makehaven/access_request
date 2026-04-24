# Access Request Module

## Overview
The Access Request module provides functionality for users to request access to assets via an RFID card system. It interacts with an external access control system and is designed for use within the MakeHaven environment.

### Features
- Automatically submits access requests when a user visits a specific URL.
- Supports asset identification via URLs in the format `/access-request/asset/{asset_identifier}`.
- Provides error messaging for common issues, such as missing card information or invalid asset identifiers.
- Allows users to manually resend access requests via a button.
- Redirects anonymous users to the login page while preserving the intended destination URL.
- Logs server responses and errors for debugging purposes.
- **Circuit Breaker:** Implements a circuit breaker pattern to protect the site from unresponsive external gateways.

## Requirements
- Drupal 10.x
- Access to the MakeHaven access control system.

## Installation
1. Place the `access_request` module directory in the `modules/custom` directory of your Drupal installation.
2. Enable the module using the Drupal admin interface or with Drush:
   ```sh
   drush en access_request
   ```
3. Run `drush cr` to clear caches.

## Configuration
Go to **Config → System → Access Request Settings** and set the following:

-   **Python Gateway URL:** The full URL of the external Python gateway that processes access requests (e.g., `https://server.dev.access.makehaven.org/toolauth/req`). This is a critical setting for module functionality.
-   **Timeout Seconds:** The maximum time (in seconds) the module will wait for a response from the Python gateway for a single request. Set a reasonable value based on your gateway's typical response time. (Default: 5 seconds).
-   **Web HMAC Secret:** (Optional) A secret key used for signing requests to the Python gateway for security.
-   **Asset Map (YAML):** A YAML string defining how Drupal assets map to external access control system readers and permissions. This allows for flexible asset identification.
-   **Dry Run:** If enabled, the module will log what it *would* do without actually sending requests to the Python gateway. Useful for testing.

## Circuit Breaker
This module now includes a circuit breaker pattern to enhance resilience against external gateway failures.

### How it Works
The circuit breaker monitors the success and failure of calls to the **Python Gateway URL**.
-   **CLOSED State:** The default state. Requests are sent as normal. If failures occur, a counter increments.
-   **OPEN State:** If the number of consecutive failures reaches a configured `FAILURE_THRESHOLD` (default: 3), the circuit "opens." All subsequent requests will immediately fail without attempting to contact the external gateway for a `RESET_TIMEOUT` period (default: 60 seconds). This prevents resource exhaustion and cascading timeouts.
-   **HALF-OPEN State:** After the `RESET_TIMEOUT` expires, the circuit transitions to HALF-OPEN. A single test request is allowed through. If this request is successful, the circuit returns to CLOSED; otherwise, it returns to OPEN.

### Monitoring
-   Circuit breaker state changes and actions are logged to the `access_request` log channel. Monitor these logs to understand when the circuit opens or closes.
-   When the circuit is OPEN, access requests will immediately return a `503 Service Unavailable` status to avoid hanging the site.

## Debugging & Troubleshooting
-   Check the `access_request` log channel for detailed information on API calls, responses, and circuit breaker state changes.
-   Ensure the **Python Gateway URL** is correct and accessible from your Drupal environment.
-   Adjust **Timeout Seconds** if your gateway consistently takes longer to respond.

## Home Assistant direct backend (experimental)

This module can call Home Assistant directly instead of the Python gateway,
removing the cardsystem broker from the loop for any given asset. **Both
backends can run in parallel** — the Python gateway path remains fully
functional and is the default until you opt an asset in.

### Enabling

1. **Store the bearer token as a Pantheon Secret.** The module reads
   `HA_BEARER_TOKEN` via `pantheon_get_secret()` on Pantheon (the same
   pattern as `CIVICRM_SITE_KEY` in `civicrm.settings.php`), and falls back
   to `getenv()` on Lando/local. **Type must be `runtime`, scope must
   include `web`** — otherwise PHP cannot read it. A single default secret
   covers dev/test/live (no per-env override needed):

   ```sh
   terminus secret:site:set makehaven-website HA_BEARER_TOKEN "<token>" --type=runtime --scope=web
   ```

2. **Configure the base URL** at *Config → System → Access Request Settings*
   under **Home Assistant (direct backend)**. The settings form shows whether
   the token env var is detected.

3. **Per-asset opt-in.** Add `backend: home_assistant` to each asset that
   should use HA. Optionally override the activator/reader names; otherwise
   they default to `<key>activator` / `<key>reader`:

   ```yaml
   backdoor:
     name: Back Door
     category: doors
     backend: home_assistant
     # activator: backdooractivator   # default
     # reader: backdoorreader         # default
   ```

   All HA-backed assets share a single Home Assistant service (the
   *authorize service*, default `script.authorization_request`) configured
   under **Home Assistant → Authorize service**. Drupal POSTs
   `{card_serial, activator, reader}` to that service and Home Assistant
   does the rest (notifies the reader and fires the activator). To override
   the authorize service for a single asset, add `ha_service: domain.service`
   to its asset_map entry.

4. **Or flip globally.** Once every active asset's `activator`/`reader`
   defaults look right (or are explicitly set), tick the **Enable Home
   Assistant as default backend** checkbox — assets without an explicit
   `backend:` then use HA.

### Behavior

When an asset resolves to the HA backend:

1. The module calls `AccessStatusEvaluator` (from `access_control_api_logger`)
   to check payment pause, payment failure, access override, role, and door
   badge for the requesting member — fast local denial avoids a network round
   trip and HA noise for paused members.
2. If denied, logs the reason and returns **403** without calling HA.
3. If allowed, POSTs to `{base_url}/api/services/{authorize_service}` with the
   Bearer token and body `{card_serial, activator, reader}`.
4. A separate circuit breaker guards HA (state keys prefixed `ha_`), so the
   legacy Python breaker is untouched.

The `/access-request/asset/{asset}` route accepts the bare key
(`backdoor`), the activator-suffixed form (`backdooractivator`, used by the
HA-direct QR codes), or the reader-suffixed form (`backdoorreader`, the
legacy QR-code shape). All three resolve to the same asset_map entry.

Log lines include `backend=python|home_assistant` so you can see which backend
answered each request.

### Rollback

Flip `backend: python` on the asset (or clear the master switch) and
`drush cim`. No code deploy needed.

## Known open item: inbound `/api/v0/...` auth

As of this branch the inbound permission-check endpoints exposed by
`access_control_api_logger` (`/api/v0/{serial,uuid,email}/permission/...`)
are `_access: 'TRUE'` — anyone reachable can query them. The Python broker
currently calls them without a token, so requiring a shared secret here would
break the in-use cardsystem during the changeover. Hardening is deferred to a
follow-up coordinated with the Home Assistant integration (Vincent) and
contingent on retiring the Python broker's direct dependency on these
endpoints.
