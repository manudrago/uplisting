<?php
if (!defined('ABSPATH')) exit;
require_once ABSPATH . 'wp-admin/includes/file.php';

class Uplisting_Sync {
    private $client;
    private $current_api_key;

    public function __construct($client, $api_key = null) {
        $this->client = $client;
        $this->current_api_key = $api_key;
    }

    public function sync_all_properties() {
        error_log('Fetching properties for API key: ' . substr($this->current_api_key ?? 'N/A', 0, 6));
        
        $properties = $this->client->get_properties_all();
        if (empty($properties['data'])) return;

        foreach ($properties['data'] as $property) {
            try {
                $this->import_property($property, $properties['included'] ?? []);
            } catch (\Throwable $e) {
                error_log('Uplisting import failed for property ' . ($property['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
    }

    public function sync_batch($offset = 0, $limit = 2, $dry = false) {
        $properties = $this->client->get_properties_all();
        $data = (isset($properties['data']) && is_array($properties['data'])) ? $properties['data'] : array();
        $included = isset($properties['included']) ? $properties['included'] : array();
        $total = count($data);
        if ($dry) { return array('total' => $total, 'next_offset' => $offset, 'processed' => 0, 'done' => ($offset >= $total), 'dry' => true); }
        $end = min($offset + $limit, $total);
        $processed = 0;
        for ($i = $offset; $i < $end; $i++) {
            try { $this->import_property($data[$i], $included); $processed++; }
            catch (\Throwable $e) { error_log('Uplisting batch import failed: ' . $e->getMessage()); }
            usleep(500000);
        }
        $ids = array();
        foreach ($data as $row) { if (!empty($row['id'])) $ids[] = (string) $row['id']; }

        return array('total' => $total, 'next_offset' => $end, 'processed' => $processed, 'done' => ($end >= $total), 'ids' => $ids);
    }

    /** Values of the Uplisting status attribute that mean "not live". */
    const INACTIVE_STATUSES = array('disabled', 'inactive', 'paused', 'archived', 'deleted', 'unlisted', 'draft');

    /**
     * @param string $status Lower-cased Uplisting status.
     * @return bool
     */
    public static function is_enabled($status) {
        return !in_array($status, self::INACTIVE_STATUSES, true);
    }

    /**
     * The single place that decides whether a property is live. Filterable so the rule can be
     * tuned against the real property count without touching the importer.
     *
     * @param string $status     Lower-cased Uplisting status.
     * @param string $upl_domain Direct booking domain from the API.
     * @param string $upl_slug   Property slug from the API.
     * @return bool
     */
    /**
     * Which rule decides visibility. Set with: wp option update rl_publish_rule <value>
     *
     *   status_only              enabled on the Uplisting account
     *   status_and_site          + published to a direct booking domain          (default)
     *   status_site_availability + at least one bookable day in the window
     */
    public static function publish_rule() {
        $rule = (string) get_option('rl_publish_rule', 'status_and_site');
        return in_array($rule, array('status_only', 'status_and_site', 'status_site_availability'), true)
            ? $rule
            : 'status_and_site';
    }

    /** Months of calendar the availability arm of the rule looks at. */
    public static function availability_months() {
        return max(1, (int) get_option('rl_availability_months', 4));
    }

    /**
     * @param string   $status     Lower-cased Uplisting status.
     * @param string   $upl_domain Direct booking domain from the API.
     * @param string   $upl_slug   Property slug from the API.
     * @param bool|null $has_availability Null when unknown (calendar unreadable).
     * @return bool|null True publish, false draft, null undecidable — leave the post alone.
     */
    public static function should_publish($status, $upl_domain, $upl_slug, $has_availability = null) {
        $rule = self::publish_rule();

        $publish = self::is_enabled($status);

        if ('status_only' !== $rule) {
            $publish = $publish && '' !== trim((string) $upl_domain) && '' !== trim((string) $upl_slug);
        }

        if ('status_site_availability' === $rule && $publish) {
            // Unknown availability must never demote a live property.
            if (null === $has_availability) return apply_filters('rl_property_should_publish', null, $status, $upl_domain, $upl_slug);
            $publish = (bool) $has_availability;
        }

        return apply_filters('rl_property_should_publish', (bool) $publish, $status, $upl_domain, $upl_slug);
    }

    /**
     * Every property id the current key returns, and the subset that should be live. Used by the
     * reconciliation pass and by the audit screen.
     *
     * @return array{all: string[], live: string[], rows: array[]}
     */
    public function inventory($with_availability = false, $months = 4) {
        $properties = $this->client->get_properties_all();
        $data = (isset($properties['data']) && is_array($properties['data'])) ? $properties['data'] : array();

        $all = array();
        $live = array();
        $rows = array();

        foreach ($data as $property) {
            $id = (string) ($property['id'] ?? '');
            if ('' === $id) continue;

            $attrs = $property['attributes'] ?? array();

            $status_raw = $attrs['status'] ?? (isset($attrs['enabled']) ? ($attrs['enabled'] ? 'enabled' : 'disabled') : 'enabled');
            if (is_bool($status_raw)) { $status_raw = $status_raw ? 'enabled' : 'disabled'; }
            $status = strtolower(trim((string) $status_raw));

            $domain = (string) ($attrs['uplisting_domain'] ?? '');
            $slug   = (string) ($attrs['property_slug'] ?? '');

            $row = array(
                'id'               => $id,
                'name'             => (string) ($attrs['name'] ?? ''),
                'status'           => $status,
                'uplisting_domain' => $domain,
                'property_slug'    => $slug,
                'rule_status_only' => self::is_enabled($status),
                'rule_status_site' => self::is_enabled($status) && '' !== trim($domain) && '' !== trim($slug),
            );

            if ($with_availability) {
                $window = $this->availability_window($id, $months);
                $row['calendar_readable'] = (null !== $window);
                $row['days_total']        = $window ? $window['days_total'] : null;
                $row['days_available']    = $window ? $window['days_available'] : null;
                $row['rule_status_site_availability'] = $row['rule_status_site'] && $window && $window['days_available'] > 0;
                usleep(250000); // 4 req/sec, inside Uplisting's 5/sec and 100/min limits.
            }

            $should = self::should_publish($status, $domain, $slug, isset($row['days_available']) && null !== $row['days_available'] ? ($row['days_available'] > 0) : null);
            $row['should_publish'] = $should;

            $all[] = $id;
            if (true === $should) $live[] = $id;

            $rows[] = $row;
        }

        return array('all' => $all, 'live' => $live, 'rows' => $rows);
    }

    private function import_property($property, $included) {
        $attrs = $property['attributes'] ?? [];
        $rels  = $property['relationships'] ?? [];

        $uplisting_id = intval($property['id']);
        $title        = sanitize_text_field($attrs['name'] ?? 'Untitled Property');
        $desc         = wp_kses_post($attrs['description'] ?? '');

        $city         = '';
        $address      = '';
        $lat          = '';
        $lng          = '';
        $currency     = sanitize_text_field($attrs['currency'] ?? '');
        $capacity     = intval($attrs['maximum_capacity'] ?? 0);
        $upl_domain   = esc_url($attrs['uplisting_domain'] ?? '');
        $upl_slug     = sanitize_text_field($attrs['property_slug'] ?? '');

        $status_raw   = $attrs['status'] ?? (isset($attrs['enabled']) ? ($attrs['enabled'] ? 'enabled' : 'disabled') : 'enabled');
        if (is_bool($status_raw)) { $status_raw = $status_raw ? 'enabled' : 'disabled'; }
        $status       = strtolower(trim((string) $status_raw));

        // Address
        if (!empty($rels['address']['data']['id'])) {
            $addr_id = $rels['address']['data']['id'];
            foreach ($included as $item) {
                if ($item['type'] === 'addresses' && $item['id'] === $addr_id) {
                    $address = trim(($item['attributes']['street'] ?? '') . ' ' . ($item['attributes']['suite'] ?? ''));
                    $city    = $item['attributes']['city'] ?? '';
                    $lat     = $item['attributes']['latitude'] ?? '';
                    $lng     = $item['attributes']['longitude'] ?? '';
                    break;
                }
            }
        }

        // Check existing post
        $existing = get_posts([
            'post_type'      => 'rental_property',
            'meta_key'       => '_uplisting_id',
            'meta_value'     => $uplisting_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any'
        ]);
        $post_id = !empty($existing) ? intval($existing[0]) : 0;

        // Mirror the Uplisting direct booking site (*.bookeddirectly.host). Which test does that is
        // set by the rl_publish_rule option; only the availability arm needs a calendar call.
        $has_availability = null;
        if ('status_site_availability' === self::publish_rule()) {
            $window = $this->availability_window($uplisting_id, self::availability_months());
            $has_availability = (null === $window) ? null : ($window['days_available'] > 0);
        }

        $decision = self::should_publish($status, $upl_domain, $upl_slug, $has_availability);

        // Undecidable (calendar unreadable) leaves an existing post exactly as it is; a brand new
        // one is parked as a draft rather than guessed into publication.
        if (null === $decision) {
            $post_status = $post_id ? get_post_status($post_id) : 'draft';
        } else {
            $post_status = $decision ? 'publish' : 'draft';
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => $desc,
            'post_status'  => $post_status,
            'post_type'    => 'rental_property',
        ];

        if ($post_id) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
            if ($post_id && !is_wp_error($post_id)) {
                update_post_meta($post_id, '_uplisting_id', $uplisting_id);
            } else {
                return;
            }
        }

        // Store API key association
        if (!empty($this->current_api_key)) {
            update_post_meta($post_id, '_uplisting_account_key', md5($this->current_api_key));
        }

        // Basic meta. _rental_status is normalised to exactly Enabled/Disabled because the
        // listing and map queries compare against 'Enabled'; the untouched API value is kept
        // alongside it for debugging.
        update_post_meta($post_id, '_rental_status', self::is_enabled($status) ? 'Enabled' : 'Disabled');
        update_post_meta($post_id, '_rental_status_raw', $status);
        update_post_meta($post_id, '_rental_on_booking_site', ($upl_domain && $upl_slug) ? 1 : 0);
        update_post_meta($post_id, '_rental_city', $city);
        update_post_meta($post_id, '_rental_address', $address);
        update_post_meta($post_id, '_rental_lat', $lat);
        update_post_meta($post_id, '_rental_lng', $lng);
        update_post_meta($post_id, '_rental_currency', $currency);
        update_post_meta($post_id, '_rental_max_guests', $capacity);
        
        // Generate correct booking URL format: domain/g/city/property-name-with-dashes/property_slug
        if ($upl_domain && $city && $upl_slug) {
            $city_slug = $this->create_url_slug($city);
            $title_slug = $this->create_url_slug($title);
            $booking_url = trailingslashit($upl_domain) . "g/{$city_slug}/{$title_slug}/{$upl_slug}";
            update_post_meta($post_id, '_rental_booking_url', $booking_url);
        }

        // Extra property info
        update_post_meta($post_id, '_rental_time_zone', $attrs['time_zone'] ?? '');
        update_post_meta($post_id, '_rental_check_in_time', $attrs['check_in_time'] ?? '');
        update_post_meta($post_id, '_rental_check_out_time', $attrs['check_out_time'] ?? '');
        update_post_meta($post_id, '_rental_type', $attrs['type'] ?? '');
        update_post_meta($post_id, '_rental_bedrooms', $attrs['bedrooms'] ?? '');
        update_post_meta($post_id, '_rental_beds', $attrs['beds'] ?? '');
        update_post_meta($post_id, '_rental_bathrooms', $attrs['bathrooms'] ?? '');
        update_post_meta($post_id, '_rental_bed_types', isset($attrs['bed_types']) ? implode(', ', (array)$attrs['bed_types']) : '');

        // FETCH CALENDAR AND SAVE CALENDAR DATA + FROM PRICE
        $this->sync_calendar_data($post_id, $uplisting_id);

        // Amenities
        $amenities = [];
        if (!empty($rels['amenities']['data'])) {
            foreach ($rels['amenities']['data'] as $am) {
                foreach ($included as $item) {
                    if ($item['type'] === 'amenities' && $item['id'] === $am['id']) {
                        $amenities[] = [
                            'name'  => $item['attributes']['name'] ?? '',
                            'group' => $item['attributes']['group'] ?? ''
                        ];
                    }
                }
            }
        }
        update_post_meta($post_id, '_rental_amenities', $amenities);

        // Gallery
        $gallery = [];
        if (!empty($rels['photos']['data'])) {
            foreach ($rels['photos']['data'] as $photo_ref) {
                $photo_id = $photo_ref['id'] ?? null;
                if (!$photo_id) continue;
        
                $photo_item = $this->find_included_item($included, 'photos', $photo_id);
                $img_url = $photo_item['attributes']['url'] ?? '';
        
                if ($img_url) {
                    $attachment_id = $this->import_image($img_url, $post_id);
                    if ($attachment_id) {
                        $gallery[] = $attachment_id;
                    } else {
                        $gallery[] = esc_url($img_url);
                    }
                }
            }
        }
        update_post_meta($post_id, '_rental_gallery', $gallery);

        // Featured image = FIRST image in the Uplisting gallery
        if (!empty($gallery) && is_numeric($gallery[0])) {
            set_post_thumbnail($post_id, intval($gallery[0]));
        }

        // Fees
        $fees = [];
        if (!empty($rels['fees']['data'])) {
            foreach ($rels['fees']['data'] as $fee) {
                foreach ($included as $item) {
                    if ($item['type'] === 'property_fees' && $item['id'] === $fee['id']) {
                        $fees[] = [
                            'name'   => $item['attributes']['name'] ?? '',
                            'label'  => $item['attributes']['label'] ?? '',
                            'amount' => $item['attributes']['amount'] ?? 0,
                        ];
                    }
                }
            }
        }
        update_post_meta($post_id, '_rental_fees', $fees);

        // Taxes
        $taxes = [];
        if (!empty($rels['taxes']['data'])) {
            foreach ($rels['taxes']['data'] as $tax) {
                foreach ($included as $item) {
                    if ($item['type'] === 'property_taxes' && $item['id'] === $tax['id']) {
                        $taxes[] = [
                            'name'   => $item['attributes']['name'] ?? '',
                            'label'  => $item['attributes']['label'] ?? '',
                            'type'   => $item['attributes']['type'] ?? '',
                            'per'    => $item['attributes']['per'] ?? '',
                            'amount' => $item['attributes']['amount'] ?? 0,
                        ];
                    }
                }
            }
        }
        update_post_meta($post_id, '_rental_taxes', $taxes);

        // Discounts
        $discounts = [];
        if (!empty($rels['discounts']['data'])) {
            foreach ($rels['discounts']['data'] as $disc) {
                foreach ($included as $item) {
                    if ($item['type'] === 'property_discounts' && $item['id'] === $disc['id']) {
                        $discounts[] = [
                            'name'   => $item['attributes']['name'] ?? '',
                            'label'  => $item['attributes']['label'] ?? '',
                            'type'   => $item['attributes']['type'] ?? '',
                            'days'   => $item['attributes']['days'] ?? '',
                            'amount' => $item['attributes']['amount'] ?? 0,
                        ];
                    }
                }
            }
        }
        update_post_meta($post_id, '_rental_discounts', $discounts);

        // Security deposit
        $deposit = null;
        if (!empty($rels['protect_security_deposit_setting']['data']['id'])) {
            $dep_id = $rels['protect_security_deposit_setting']['data']['id'];
            foreach ($included as $item) {
                if ($item['type'] === 'protect_security_deposit_settings' && $item['id'] === $dep_id) {
                    $deposit = [
                        'amount'  => $item['attributes']['amount'] ?? 0,
                        'enabled' => $item['attributes']['enabled'] ?? false,
                    ];
                    break;
                }
            }
        }
        update_post_meta($post_id, '_rental_security_deposit', $deposit);
    }

    /**
     * Bookable days in the next N months.
     *
     * Returns null when the calendar could not be read at all — an API error, an empty envelope,
     * a missing key. The old code returned false in that case, which was indistinguishable from a
     * genuinely full calendar and silently drafted live properties whenever Uplisting hiccuped.
     *
     * @param int|string $uplisting_id
     * @param int        $months
     * @return array{days_total:int, days_available:int}|null
     */
    public function availability_window($uplisting_id, $months = 4) {
        if (!$this->current_api_key || !$uplisting_id) return null;

        $months = max(1, (int) $months);
        $from = date('Y-m-d');
        $to   = date('Y-m-d', strtotime('+' . $months . ' months'));

        $calendar_data = $this->client->get_calendar_for_key(
            $this->current_api_key,
            $uplisting_id,
            $from,
            $to
        );

        if (!is_array($calendar_data) || isset($calendar_data['error'])) return null;
        if (!isset($calendar_data['calendar']['days']) || !is_array($calendar_data['calendar']['days'])) return null;

        $days = $calendar_data['calendar']['days'];
        $available = 0;
        foreach ($days as $day) {
            if (!empty($day['available']) && isset($day['day_rate'])) $available++;
        }

        return array('days_total' => count($days), 'days_available' => $available);
    }

    /**
     * Fetch calendar data and calculate "from" price using closest available date
     */
    private function sync_calendar_data($post_id, $uplisting_id) {
        if (!$this->current_api_key || !$uplisting_id) return;

        // Fetch 12 months of calendar data
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+12 months'));

        $calendar_data = $this->client->get_calendar_for_key(
            $this->current_api_key,
            $uplisting_id,
            $from,
            $to
        );

        if (empty($calendar_data['calendar']['days'])) return;

        $days = $calendar_data['calendar']['days'];
        
        // Extract and store account_id if available in calendar response
        if (!empty($calendar_data['account_id'])) {
            update_post_meta($post_id, '_rental_account_id', $calendar_data['account_id']);
        }
        
        // Store full calendar cache
        update_post_meta($post_id, '_rental_calendar_cache', $days);
        update_post_meta($post_id, '_rental_calendar_updated', time());

        // Calculate "from" price using the closest available date to today
        $from_price = null;
        $today = date('Y-m-d');
        
        foreach ($days as $day) {
            $day_date = $day['date'] ?? '';
            if ($day_date >= $today) {
                if (!empty($day['available']) && isset($day['day_rate'])) {
                    $from_price = floatval($day['day_rate']);
                    break; // Use the first available date (closest to today)
                }
            }
        }

        if ($from_price) {
            update_post_meta($post_id, '_rental_from_price', $from_price);
        }
    }

    private function import_image($url, $post_id) {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!$post_id) return false;

        // Strip query string so CDN token changes don't cause duplicate downloads
        $url_clean = strtok($url, '?');

        $existing = $this->find_existing_attachment($url_clean);
        if ($existing) return $existing;

        $tmp = download_url($url);
        if (is_wp_error($tmp)) return false;

        $file = [
            'name'     => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];

        $filetype = wp_check_filetype($file['name']);
        if (!$filetype['type']) {
            // CDN URLs (e.g. Filestack/Uplisting) have no extension in the path.
            // Detect the real MIME type from the downloaded file and add an extension.
            $detected_mime = '';
            if (function_exists('getimagesize')) { $gis = @getimagesize($tmp); if (!empty($gis['mime'])) { $detected_mime = $gis['mime']; } }
            if (!$detected_mime && function_exists('mime_content_type')) { $detected_mime = @mime_content_type($tmp); }
            $mime_ext = array('image/jpeg'=>'jpg','image/pjpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/avif'=>'avif','image/bmp'=>'bmp');
            if ($detected_mime && isset($mime_ext[$detected_mime])) {
                $base_name = $file['name'];
                if ($base_name === '' || $base_name === false) { $base_name = 'image-' . $post_id; }
                $file['name'] = $base_name . '.' . $mime_ext[$detected_mime];
                $filetype = wp_check_filetype($file['name']);
            }
            if (!$filetype['type']) {
                @unlink($tmp);
                return false;
            }
        }

        $attachment_id = media_handle_sideload($file, $post_id);
        if (is_wp_error($attachment_id)) return false;

        // Save the clean URL (no query string) so future syncs find it reliably
        update_post_meta($attachment_id, '_source_url', esc_url($url_clean));
        return $attachment_id;
    }

    private function find_existing_attachment($url_clean) {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'meta_key'       => '_source_url',
            'meta_value'     => esc_url($url_clean),
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ]);
        return !empty($q->posts) ? $q->posts[0] : false;
    }
    
    /**
     * Create URL slug - double URL-encodes special characters to match Uplisting format
     */
    private function create_url_slug($text) {
        // Convert to lowercase
        $text = strtolower($text);
        // Replace spaces with dashes
        $text = str_replace(' ', '-', $text);
        // First encode
        $text = rawurlencode($text);
        // Second encode (this is what creates %2528 from ( for example)
        return rawurlencode($text);
    }

    private function find_included_item($included, $type, $id) {
        if (empty($included) || !is_array($included)) return null;
    
        foreach ($included as $item) {
            if (
                isset($item['type'], $item['id']) &&
                $item['type'] === $type &&
                $item['id'] == $id
            ) {
                return $item;
            }
        }
    
        return null;
    }
}