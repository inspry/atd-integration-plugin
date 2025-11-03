# ATD Integration

A WordPress plugin that allows placing orders via ATD's API.

## Usage

Requires the following PHP constants to be defined. It is recommended to place these constants in the `wp-config.php` file.

- `ATD_API_KEY`: Your ATD API key.
- `ATD_USERNAME`: ATD account username.
- `ATD_PASSWORD`: ATD account password.

## Known issues

- [ ] Not extensible.

## Changelog

**v2.0.0**:

- Remove cURL options that disabled SSL validation.
- Use `$wpdb->prepare` on SQL queries.

**v1.0.1**: Remove unused atd_inventory_update WP-CLI command.

**v1.0.0**: Initial release.