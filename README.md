# Site Admin Toolkit

A lightweight WordPress administration plugin built for site owners,
developers, and support specialists.

## Features

- Custom WordPress admin menu
- Site overview dashboard
- WordPress version display
- PHP version display
- Database version display
- Active plugin count
- User count
- Post count
- Page count
- Active theme information
- Debug status
- WordPress dashboard widget
- Configurable site announcement
- Announcement enable/disable option
- `[sat_announcement]` shortcode
- WordPress Settings API
- Administrator capability checks
- WordPress nonce protection through the Settings API
- Safe option sanitization
- Clean uninstall

## Installation

Download or clone this repository.

Copy the folder:

site-admin-toolkit

into:

wp-content/plugins/

Then open:

WordPress Admin
? Plugins
? Installed Plugins
? Site Admin Toolkit
? Activate

## Usage

After activation, open:

Site Toolkit

from the WordPress admin menu.

### Announcement

Enter an announcement and enable it.

Then add this shortcode to any page or post:

[sat_announcement]

## Security

- Direct PHP file access is blocked
- Admin pages require `manage_options`
- Settings use the WordPress Settings API
- WordPress automatically handles settings nonces
- Input is sanitized before storage
- Output is escaped before display
- Plugin options are removed during uninstall

## Future Roadmap

- Maintenance mode
- Site health checks
- Plugin update summary
- Theme update summary
- Database maintenance tools
- Error-log viewer
- Backup status
- Admin notes
- Optional REST API tools
- Optional Zoho integration

## Technology

- PHP
- WordPress Plugin API
- WordPress Settings API
- WordPress Dashboard API
- Shortcodes
- Hooks / Actions
- Capability checks
- Sanitization / escaping

## Author

William Murphy / CraftEarth

GitHub:
https://github.com/CraftEarth

## v1.1.0 - Maintenance Mode

Site Admin Toolkit now includes a built-in maintenance mode for temporarily
taking a WordPress site offline while administrators continue working.

### Features

- Enable or disable maintenance mode from Site Toolkit
- Custom maintenance page title
- Custom visitor message
- Optional estimated return message
- Logged-in administrators automatically bypass maintenance mode
- HTTP 503 Service Unavailable response
- Retry-After header
- WordPress AJAX requests remain available
- WordPress cron remains available
- REST API requests remain available

### Testing Maintenance Mode

Open:

Site Toolkit ? Maintenance Mode

Enable maintenance mode and save your settings.

Logged-in administrators will continue seeing the normal website.

To view the visitor experience, open the website in an incognito/private
browser window while logged out.


## v1.2.0 - Site Health & Update Monitor

Site Admin Toolkit now includes a maintenance dashboard for common
WordPress support and troubleshooting tasks.

### Health Checks

- WordPress core update status
- Plugin update count
- Theme update count
- PHP version
- HTTPS detection
- WordPress debug mode
- WP-Cron configuration
- WordPress REST API availability

### System Information

The dashboard also displays:

- WordPress version
- PHP version
- Database version
- Active theme
- Theme version
- PHP memory limit
- Maximum upload size
- Site URL
- Home URL

### Status Levels

Checks are categorized as:

- Good
- Warning
- Critical

The dashboard automatically calculates an overall site health status
based on the individual checks.


## v1.3.0 - Error Log & Diagnostics

Site Admin Toolkit now includes a read-only diagnostics panel for
WordPress support and troubleshooting.

### Diagnostics

- WP_DEBUG status
- WP_DEBUG_LOG status
- WP_DEBUG_DISPLAY status
- PHP display_errors status
- PHP error log configuration
- WordPress debug.log detection
- Debug log size
- Debug log last modified time
- Debug log readability
- Recent WordPress debug log viewer

### Debug Log Viewer

When `wp-content/debug.log` exists and is readable, the toolkit displays
the most recent 50 log entries directly inside the WordPress admin area.

The viewer reads from the end of the file rather than loading the entire
log into memory.

### Security

The diagnostics panel is restricted to users with the WordPress
`manage_options` capability.

Debug logs can contain sensitive technical information and should never
be exposed publicly.


## v1.3.1 - Diagnostics UI Polish

- Converted PHP boolean values from `1/0` to `Enabled/Disabled`
- Added readable status badges to diagnostics
- Added contextual explanations for debug settings
- Clarified WP_DEBUG_DISPLAY behavior when WP_DEBUG is disabled
- Added production warnings for browser-displayed PHP errors
- Improved diagnostic card styling

