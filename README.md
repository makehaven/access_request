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

## Requirements
- Drupal 10.x
- Access to the MakeHaven access control system.

## Installation
1. Place the `access_request` module directory in the `modules/custom` directory of your Drupal installation.
2. Enable the module using the Drupal admin interface or with Drush:
   ```sh
   drush en access_request

