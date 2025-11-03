# ATD Integration

A WordPress plugin that allows placing orders via ATD's API.

## Usage

Requires the following PHP constants to be defined. It is recommended to place these constants in the `wp-config.php` file.

- `ATD_API_KEY`: Your ATD API key.
- `ATD_USERNAME`: ATD account username.
- `ATD_PASSWORD`: ATD account password.

## Architecture

The plugin follows a modular object-oriented architecture:

- `ATD_API_Client` - Handles all ATD API communications
- `ATD_Order_Manager` - Manages order placement and tracking
- `ATD_Inventory_Manager` - Handles inventory synchronization
- `ATD_Admin` - Manages admin interface and AJAX handlers

## Known issues

- [ ] Location number is hardcoded (should be configurable).

## Changelog

**v2.0.0**:

- **MAJOR REFACTOR**: Completely restructured plugin into modular classes
- **SECURITY**: Fixed SSL verification vulnerabilities
- **SECURITY**: Secured all database queries with prepared statements
- **SECURITY**: Added input validation and capability checks
- **ARCHITECTURE**: Separated concerns into focused classes
- **MAINTAINABILITY**: Each class now in its own file
- **ERROR HANDLING**: Comprehensive error handling with WP_Error
- **API**: Structured JSON responses with proper HTTP status codes
- **WP-CLI**: Improved CLI command with better error reporting
- **UX**: Enhanced admin interface with real-time AJAX feedback
- **UX**: Replaced AJAX links with buttons for better UX and accessibility
- **UX**: Added loading states, success/error messages with visual indicators
- **SECURITY**: Added nonce verification for AJAX requests
- **PERFORMANCE**: Conditional asset loading (only on order edit pages)

**v1.0.1**: Remove unused atd_inventory_update WP-CLI command.

**v1.0.0**: Initial release.
