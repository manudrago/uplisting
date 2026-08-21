# Rental Listings (External Booking Redirect)

WordPress plugin that mirrors [Uplisting](https://uplisting.io) properties into a
`rental_property` custom post type and links each listing out to its Uplisting direct-booking
page. Provides filtering, a map, live availability and total-price lookups including cleaning fees.

- **Version:** 2.1
- **Author:** IdeAgency.co.uk
- **License:** GPLv2 or later
- **API:** `https://connect.uplisting.io/` (HTTP Basic, one or more account keys)

## Layout

| Path | What it does |
|---|---|
| `rental-listings-redirect.php` | Main plugin — CPT, meta boxes, settings screen, shortcodes, AJAX filter, REST availability endpoint, cron and the throttled sync driver (`rl_sync_tick`) |
| `includes/class-uplisting-client.php` | Uplisting REST client — properties, availability, calendar; merges results across multiple account keys |
| `includes/class-uplisting-sync.php` | Import — maps Uplisting properties onto posts, downloads images, caches calendars, derives "from" prices |
| `rental-listings-template.php` | Archive/listing grid template |
| `single-rental-template.php` | Single property template |
| `rental-frontend.js` / `rental-admin.js` / `rental-style.css` | Front-end filtering and map, admin screen, styles |

## Install

Copy the repository contents into `wp-content/plugins/rental-listings-redirect/` and activate, or
upload a zip of the repository through **Plugins → Add New → Upload Plugin**.

Then set the Uplisting API keys under the plugin's settings screen (one key per line — each key is
one Uplisting account). Keys are stored in the `uplisting_api_keys` option; none are committed here.

## Sync

Cron runs every 3 hours. The sync is cursor-based and processes a couple of properties per tick to
stay inside Uplisting's rate limits (5/sec, 100/min), so a full pass over a large account takes
several ticks.

Manual controls, administrators only:

    /wp-admin/?rl_sync_run=1&dry=1     report only, changes nothing
    /wp-admin/?rl_sync_run=1&reset=1   restart the cursor and run
    /wp-admin/?rl_cron_status=1        is cron scheduled, is sync paused

Note that `rl_sync_paused` defaults to `1` — the sync does nothing until it is switched on.
