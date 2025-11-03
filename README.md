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

- Removes order lock meta if an error occurs.
- AJAX endpoints now have user capability checks.
- AJAX endpoints now have more structured and meaningful responses.
- AJAX endpoints now validate user inputs.
- Remove cURL options that disabled SSL validation.
- Use `$wpdb->prepare` on SQL queries.

**v1.0.1**: Remove unused atd_inventory_update WP-CLI command.

**v1.0.0**: Initial release.