# Pressable API Client for WordPress

A comprehensive PHP class designed to simplify interactions with the Pressable REST API v1 from within a WordPress environment. This class provides a structured and easy-to-use wrapper for handling authentication and making requests to various API endpoints.

## Features

* **Simple Authentication:** Handles OAuth2 client credentials flow to obtain an access token.
* **Structured Methods:** Provides clear, well-documented methods for all major Pressable API endpoints.
* **WordPress Native:** Built to be used within a WordPress theme or plugin, utilizing `wp_remote_request` and returning standard `WP_Error` objects on failure.
* **Full API Coverage:** Includes methods for managing:
    * Account Details & Settings
    * Sites (Create, Read, Update, Delete)
    * Collaborators (Account & Site level)
    * DNS Zones and Records
    * Edge Cache & Defensive Mode
    * Must-Use (MU) Plugins
    * On-Demand & Regular Backups
    * Site Plugins & Themes
    * Security Alerts
    * SFTP Users
    * And much more.

## Requirements

* WordPress 5.0 or higher
* PHP 7.2 or higher
* A Pressable account with API credentials (Client ID and Client Secret).

## Installation

1.  Place the `pressable-api-client.php` file into your WordPress theme or plugin directory.
2.  Include the file where you need to use it, for example, in your theme's `functions.php` or your main plugin file:

```php
require_once( __DIR__ . '/pressable-api-client.php' );
