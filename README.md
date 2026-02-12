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
