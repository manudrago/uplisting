<?php
/**
 * Plugin Name: Rental Listings (External Booking Redirect) - Full Edition v2.1
 * Description: Property listings with Uplisting integration, filters, map, and external booking links. Auto-syncs every 3 hours and supports live availability + total price updates with cleaning fees.
 * Version: 2.1
 * Author: IdeAgency.co.uk
 * License: GPLv2 or later
 * Text Domain: rental-listings-redirect
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-uplisting-client.php';
require_once __DIR__ . '/includes/class-uplisting-sync.php';


class Rental_Listings_Redirect {

    public function __construct() {
        // === CORE ===
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_amenity_taxonomy']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);
        add_filter('template_include', [$this, 'load_single_template']);

        // === ASSETS ===
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // === SHORTCODES & AJAX ===
        add_shortcode('rental_listings', [$this, 'shortcode_listings']);
        add_action('wp_ajax_nopriv_rental_filter', [$this, 'ajax_filter']);
        add_action('wp_ajax_rental_filter', [$this, 'ajax_filter']);

        // === ADMIN SETTINGS (Uplisting) ===
        add_action('admin_menu', [$this, 'uplisting_settings_menu']);
        add_action('admin_post_uplisting_sync_now', [$this, 'uplisting_manual_sync']);

        // === CRON SYNC EVERY 3 HOURS ===
        add_filter('cron_schedules', [$this, 'cron_three_hour']);
        add_action('uplisting_cron_sync', [$this, 'uplisting_run_cron']);
        register_activation_hook(__FILE__, [$this, 'activate_cron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']);

        // === REST API ===
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /* ----------------------------------------
     * Custom Post Type + Taxonomy
     * -------------------------------------- */
    public function register_post_type() {
        register_post_type('rental_property', [
            'labels' => [
                'name' => 'Properties',
                'singular_name' => 'Property'
            ],
            'public' => true,
            'menu_icon' => 'dashicons-admin-home',
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        ]);
    }

    public function register_amenity_taxonomy() {
        register_taxonomy('rental_amenity', 'rental_property', [
            'label' => 'Amenities',
            'public' => true,
            'show_ui' => true,
        ]);
    }

    /* ----------------------------------------
     * Meta Box (Admin)
     * -------------------------------------- */
    public function add_meta_boxes() {
        add_meta_box(
            'rental_details',
            'Property Details',
            [$this, 'render_meta_box'],
            'rental_property',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('rental_save_meta', 'rental_meta_nonce');

        $fields = [
            'rental_booking_url' => 'Booking URL',
            'rental_city'        => 'City',
            'rental_address'     => 'Address',
            'rental_max_guests'  => 'Max Guests',
            'rental_lat'         => 'Latitude',
            'rental_lng'         => 'Longitude',
        ];

        echo '<h2>🏠 Basic Property Details</h2>';
        echo '<table class="form-table">';
        foreach ($fields as $key => $label) {
            $val = get_post_meta($post->ID, "_$key", true);
            echo '<tr><th><label for="'.$key.'">'.$label.'</label></th><td><input type="text" name="'.$key.'" id="'.$key.'" value="'.esc_attr($val).'" class="regular-text"></td></tr>';
        }
        echo '</table>';

        // --- ADDITIONAL PROPERTY INFO ---
        echo '<h2>🏡 Additional Property Info</h2>';
        $extra_fields = [
            'time_zone'      => 'Time Zone',
            'check_in_time'  => 'Check-in Time',
            'check_out_time' => 'Check-out Time',
            'type'           => 'Property Type',
            'bedrooms'       => 'Bedrooms',
            'beds'           => 'Beds',
            'bathrooms'      => 'Bathrooms',
        ];

        echo '<table class="widefat striped"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';
        foreach ($extra_fields as $key => $label) {
            $val = get_post_meta($post->ID, "_rental_{$key}", true);
            echo '<tr><td><strong>' . esc_html($label) . '</strong></td><td>' . esc_html($val) . '</td></tr>';
        }
        echo '</tbody></table>';

        // --- FETCH METAS ---
        $currency   = get_post_meta($post->ID, '_rental_currency', true);
        $from_price = get_post_meta($post->ID, '_rental_from_price', true);
        $fees       = get_post_meta($post->ID, '_rental_fees', true);
        $taxes      = get_post_meta($post->ID, '_rental_taxes', true);
        $discounts  = get_post_meta($post->ID, '_rental_discounts', true);
        $deposit    = get_post_meta($post->ID, '_rental_security_deposit', true);
        $amenities  = get_post_meta($post->ID, '_rental_amenities', true);
        $gallery    = get_post_meta($post->ID, '_rental_gallery', true);

        echo '<div style="font-family:system-ui;line-height:1.6;">';

        // --- FROM PRICE ---
        echo '<h2>💰 Starting Price (Next Available Date)</h2>';
        echo $from_price
            ? '<p><strong>' . esc_html($currency) . ' ' . esc_html($from_price) . ' / night</strong></p>'
            : '<p><em>No price available.</em></p>';

        // --- FEES ---
        echo '<h2>🧾 Fees</h2>';
        if (!empty($fees)) {
            echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Label</th><th>Amount</th></tr></thead><tbody>';
            foreach ($fees as $fee) {
                echo '<tr><td>'.esc_html($fee['name']).'</td><td>'.esc_html($fee['label']).'</td><td>'.esc_html($fee['amount']).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p><em>No fees found.</em></p>';

        // --- TAXES ---
        echo '<h2>💸 Taxes</h2>';
        if (!empty($taxes)) {
            echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Label</th><th>Per</th><th>Amount</th></tr></thead><tbody>';
            foreach ($taxes as $tax) {
                echo '<tr><td>'.esc_html($tax['name']).'</td><td>'.esc_html($tax['label']).'</td><td>'.esc_html($tax['per']).'</td><td>'.esc_html($tax['amount']).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p><em>No taxes found.</em></p>';

        // --- DISCOUNTS ---
        echo '<h2>💵 Discounts</h2>';
        if (!empty($discounts)) {
            echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Days</th><th>Amount (%)</th></tr></thead><tbody>';
            foreach ($discounts as $d) {
                echo '<tr><td>'.esc_html($d['name']).'</td><td>'.esc_html($d['days']).'</td><td>'.esc_html($d['amount']).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p><em>No discounts found.</em></p>';

        // --- DEPOSIT ---
        echo '<h2>🔐 Security Deposit</h2>';
        if (!empty($deposit)) {
            echo '<p>Amount: <strong>'.esc_html($deposit['amount']).'</strong><br>Status: '.($deposit['enabled'] ? 'Enabled' : 'Disabled').'</p>';
        } else echo '<p><em>No deposit info.</em></p>';

        // --- AMENITIES ---
        echo '<h2>🏠 Amenities</h2>';
        if (!empty($amenities)) {
            echo '<ul style="columns:2;">';
            foreach ($amenities as $am) {
                echo '<li>' . esc_html($am['name']) . ' <small>(' . esc_html($am['group'] ?? '') . ')</small></li>';
            }
            echo '</ul>';
        } else echo '<p><em>No amenities found.</em></p>';

        // --- GALLERY ---
        echo '<h2>🖼️ Gallery</h2>';
        if (!empty($gallery)) {
            echo '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;">';
            foreach ($gallery as $img) {
                if (is_numeric($img)) {
                    $thumb = wp_get_attachment_image($img, 'thumbnail', false, [
                        'style' => 'border-radius:4px;box-shadow:0 0 2px #ccc;'
                    ]);
                    if ($thumb) echo '<div>' . $thumb . '</div>';
                } elseif (filter_var($img, FILTER_VALIDATE_URL)) {
                    echo '<div><img src="'.esc_url($img).'" style="width:100px;height:auto;border-radius:4px;box-shadow:0 0 2px #ccc;" /></div>';
                }
            }
            echo '</div>';
        } else echo '<p><em>No images imported.</em></p>';

        echo '</div>';
    }

    public function save_meta($post_id, $post) {
        if (!isset($_POST['rental_meta_nonce']) || !wp_verify_nonce($_POST['rental_meta_nonce'], 'rental_save_meta')) return;
        $fields = ['rental_booking_url','rental_city','rental_address','rental_max_guests','rental_lat','rental_lng'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) update_post_meta($post_id, "_$f", sanitize_text_field($_POST[$f]));
        }
    }

    public function load_single_template($template) {
        $custom = plugin_dir_path(__FILE__) . 'single-rental-template.php';
        if (is_singular('rental_property') && file_exists($custom)) return $custom;
        return $template;
    }

    /* ----------------------------------------
     * Assets
     * -------------------------------------- */
    public function enqueue_admin_assets() { wp_enqueue_media(); }

    public function enqueue_frontend_assets() {
        wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], null, true);
        wp_enqueue_style('rental-style', plugin_dir_url(__FILE__).'rental-style.css', [], filemtime(plugin_dir_path(__FILE__).'rental-style.css'));
        wp_enqueue_script('rental-frontend', plugin_dir_url(__FILE__).'rental-frontend.js', ['jquery','leaflet-js'], filemtime(plugin_dir_path(__FILE__).'rental-frontend.js'), true);
        wp_localize_script('rental-frontend', 'rentalAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => esc_url(rest_url('uplisting/v1/available')),
            'nonce'    => wp_create_nonce('rental_filter_nonce')
        ]);
    }

    /* ----------------------------------------
     * Shortcode & AJAX
     * -------------------------------------- */
    public function shortcode_listings() {
        ob_start();
        include plugin_dir_path(__FILE__).'rental-listings-template.php';
        return ob_get_clean();
    }

    public function ajax_filter() {
        check_ajax_referer('rental_filter_nonce','nonce');

        $city          = sanitize_text_field($_POST['city'] ?? '');
        $guests        = intval($_POST['guests'] ?? 0);
        $available_ids = !empty($_POST['available_ids']) ? array_map('intval', (array)$_POST['available_ids']) : [];
        $paged         = intval($_POST['paged'] ?? 1);

        $args = [
            'post_type'      => 'rental_property',
            'posts_per_page' => 12,
            'paged'          => $paged,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => ['relation' => 'AND'],
        ];

        // Show only properties with Status = Enabled
        $args['meta_query'][] = [
            'key'     => '_rental_status',
            'value'   => 'Enabled',
            'compare' => '='
        ];

        // Filter by city
        if ($city) {
            $args['meta_query'][] = [
                'key'     => '_rental_city',
                'value'   => $city,
                'compare' => 'LIKE'
            ];
        }

        // Filter by capacity (>= guests)
        if ($guests) {
            $args['meta_query'][] = [
                'key'     => '_rental_max_guests',
                'value'   => $guests,
                'compare' => '>=',
                'type'    => 'NUMERIC'
            ];
        }

        // If available Uplisting IDs arrive, restrict to those
        if (!empty($available_ids)) {
            $args['meta_query'][] = [
                'key'     => '_uplisting_id',
                'value'   => $available_ids,
                'compare' => 'IN'
            ];
        }

        // Query and print same template (design unchanged)
        $query = new WP_Query($args);
        ob_start();
        include plugin_dir_path(__FILE__) . 'rental-listings-template.php';
        echo ob_get_clean();

        wp_die();
    }

    /* ----------------------------------------
     * Uplisting Sync
     * -------------------------------------- */
    public function uplisting_settings_menu() {
        add_submenu_page(
            'edit.php?post_type=rental_property',
            'Uplisting Settings',
            'Uplisting Settings',
            'manage_options',
            'uplisting-settings',
            [$this, 'uplisting_settings_page']
        );
    }

    public function uplisting_settings_page() {
        if (isset($_POST['uplisting_save_keys']) && check_admin_referer('uplisting_keys_action','uplisting_keys_nonce')) {
            $keys = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['uplisting_api_keys']))));
            update_option('uplisting_api_keys', $keys);
            echo '<div class="notice notice-success"><p>✅ API keys saved.</p></div>';
        }

        $keys = (array)get_option('uplisting_api_keys',[]);
        ?>
        <div class="wrap">
            <h1>🔑 Uplisting API Settings</h1>
            <form method="post">
                <?php wp_nonce_field('uplisting_keys_action','uplisting_keys_nonce'); ?>
                <p>Enter one API key per line:</p>
                <textarea name="uplisting_api_keys" rows="5" cols="60"><?php echo esc_textarea(implode("\n",$keys)); ?></textarea>
                <p><input type="submit" name="uplisting_save_keys" class="button button-primary" value="Save Keys"></p>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uplisting_sync_now">
                <?php submit_button('Sync Now from Uplisting','secondary'); ?>
            </form>
            <p><em>Last sync: <?php echo esc_html(get_option('uplisting_last_sync_time') ? date('Y-m-d H:i:s', get_option('uplisting_last_sync_time')) : 'Never'); ?></em></p>
            <p>🔄 Automatic sync runs every 3 hours.</p>
        </div>
        <?php
    }

    public function uplisting_manual_sync() {
        $this->uplisting_sync_run();
        wp_redirect(admin_url('edit.php?post_type=rental_property&page=uplisting-settings&synced=1'));
        exit;
    }

    public function uplisting_sync_run() {
        @set_time_limit(0);
        @ini_set('memory_limit', '256M');
        // Queued, rate-limit-friendly sync: reset cursor and process a small first batch, then continue in the background.
        update_option('rl_sync_cursor', array('k' => 0, 'o' => 0));
        if (function_exists('rl_sync_tick')) { rl_sync_tick(2); }
        if (get_option('rl_sync_cursor', false) !== false && !wp_next_scheduled('rl_sync_continue')) {
            wp_schedule_single_event(time() + 60, 'rl_sync_continue');
        }
        update_option('uplisting_last_sync_time', time());
    }

    /* ----------------------------------------
     * CRON
     * -------------------------------------- */
    public function cron_three_hour($schedules){
        $schedules['every_three_hours'] = ['interval'=>864000,'display'=>'Every 10 Days'];
        return $schedules;
    }

    public function activate_cron(){
        if(!wp_next_scheduled('uplisting_cron_sync')){
            wp_schedule_event(time(),'every_three_hours','uplisting_cron_sync');
        }
    }

    public function deactivate_cron(){ wp_clear_scheduled_hook('uplisting_cron_sync'); }

    public function uplisting_run_cron(){ $this->uplisting_sync_run(); }

    /* ----------------------------------------
     * REST API - CONTINUED IN NEXT ARTIFACT
     * -------------------------------------- */
    public function register_rest_routes() {
        register_rest_route('uplisting/v1', '/available', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_availability'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    /**
     * REST: availability + (avg nightly & min stay) + cleaning fees
     * 1) Use calendar cache for all published properties (with Status Enabled)
     * 2) If cache missing, fallback live: call ALL API keys and merge results
     */
    /**
     * REST: availability with day-by-day pricing + 8% markup + cleaning fees
     * Calculates actual total by summing individual day rates within the date range
     */
    public function rest_availability($req) {
        $check_in  = sanitize_text_field($req->get_param('check_in'));
        $check_out = sanitize_text_field($req->get_param('check_out'));
        $guests    = intval($req->get_param('number_of_guests'));
        $city      = sanitize_text_field($req->get_param('city'));

        if (!$check_in || !$check_out) {
            return rest_ensure_response(['success' => false, 'message' => 'Check-in and check-out dates required']);
        }

        // Calculate number of nights
        $check_in_time = strtotime($check_in);
        $check_out_time = strtotime($check_out);
        $nights = max(1, ($check_out_time - $check_in_time) / 86400);

        // ---- 1) Try from local cache (faster) ----
        $cached_results = [];
        $posts = get_posts([
            'post_type'      => 'rental_property',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_rental_status',
                    'value'   => 'Enabled',
                    'compare' => '='
                ]
            ]
        ]);

        foreach ($posts as $p) {
            $post_id    = $p->ID;
            $post_city  = get_post_meta($post_id, '_rental_city', true);
            $max_guests = (int) get_post_meta($post_id, '_rental_max_guests', true);

            // Filter city and capacity
            if ($city && stripos($post_city, $city) === false) continue;
            if ($guests && $max_guests < $guests) continue;

            $calendar = get_post_meta($post_id, '_rental_calendar_cache', true);
            if (!$calendar || !is_array($calendar)) continue;

            // Check availability + calculate day-by-day pricing in requested range
            $available     = true;
            $daily_rates   = [];
            $min_stays     = [];
            $date_details  = []; // Store individual day information as indexed array

            foreach ($calendar as $day) {
                $d = $day['date'] ?? '';
                if ($d >= $check_in && $d < $check_out) {
                    // Check availability
                    if (isset($day['available']) && !$day['available']) {
                        $available = false;
                        break;
                    }
                    
                    // Check closed for arrival on check-in date
                    if ($d === $check_in && !empty($day['closed_for_arrival'])) {
                        $available = false;
                        break;
                    }
                    
                    // Check closed for departure on check-out date  
                    if ($d === date('Y-m-d', strtotime($check_out . ' -1 day')) && !empty($day['closed_for_departure'])) {
                        $available = false;
                        break;
                    }
                    
                    // Collect daily rate
                    if (isset($day['day_rate'])) {
                        $daily_rates[] = floatval($day['day_rate']);
                        // Store as indexed array for proper JSON serialization
                        $date_details[] = [
                            'date' => $d,
                            'rate' => floatval($day['day_rate'])
                        ];
                    }
                    
                    // Collect minimum stay
                    if (isset($day['minimum_length_of_stay'])) {
                        $min_stays[] = intval($day['minimum_length_of_stay']);
                    }
                }
            }

            if (!$available) continue;

            // Ensure we have rates for all nights
            if (count($daily_rates) !== $nights) continue;

            $upl_id = get_post_meta($post_id, '_uplisting_id', true);
            
            // Calculate subtotal by summing all daily rates
            $subtotal_base = array_sum($daily_rates);
            
            // Apply 8% markup to subtotal
            $markup_amount = $subtotal_base * 0.08;
            $subtotal_with_markup = $subtotal_base + $markup_amount;
            
            // Get cleaning fees
            $cleaning_fee = 0;
            $fees = get_post_meta($post_id, '_rental_fees', true);
            if (!empty($fees) && is_array($fees)) {
                foreach ($fees as $fee) {
                    $fee_name = strtolower($fee['name'] ?? '');
                    if (strpos($fee_name, 'cleaning') !== false) {
                        $cleaning_fee += floatval($fee['amount'] ?? 0);
                    }
                }
            }

            // Total = subtotal (with 8% markup) + cleaning fees
            $total_price = $subtotal_with_markup + $cleaning_fee;
            
            // Calculate average nightly rate (with markup)
            $avg_nightly_rate = $subtotal_with_markup / $nights;
            
            // Get minimum stay requirement
            $min_stay = !empty($min_stays) ? max($min_stays) : 1;

            $cached_results[] = [
                'id' => $upl_id ? (string)$upl_id : (string)$post_id,
                'attributes' => [
                    'average_day_rate'   => round($avg_nightly_rate, 2),
                    'subtotal_base'      => round($subtotal_base, 2),
                    'markup_amount'      => round($markup_amount, 2),
                    'subtotal'           => round($subtotal_with_markup, 2),
                    'cleaning_fee'       => round($cleaning_fee, 2),
                    'total_price'        => round($total_price, 2),
                    'min_stay'           => $min_stay,
                    'nights'             => $nights,
                    'daily_breakdown'    => $date_details
                ]
            ];
        }

        if (!empty($cached_results)) {
            return rest_ensure_response(['data' => $cached_results]);
        }

        // ---- 2) Fallback live: query all API keys and fetch calendar data ----
        $keys = (array)get_option('uplisting_api_keys', []);
        if (empty($keys)) {
            return rest_ensure_response(['success' => false, 'message' => 'No API keys configured.']);
        }

        $merged = [];
        $seen   = [];

        foreach ($keys as $k) {
            $client = new Uplisting_Client([$k]);

            // First get availability to find which properties are available
            $resp = $client->get_availability([
                'check_in'         => $check_in,
                'check_out'        => $check_out,
                'number_of_guests' => $guests,
                'city'             => $city
            ]);

            if (empty($resp['data']) || !is_array($resp['data'])) continue;

            foreach ($resp['data'] as $property) {
                $pid = (string)($property['id'] ?? '');
                if (!$pid || isset($seen[$pid])) continue;

                // Get detailed calendar data for day-by-day pricing
                try {
                    $calendar = $client->get_calendar_for_key($k, $pid, $check_in, $check_out);
                    $days     = $calendar['calendar']['days'] ?? [];
                    
                    if (!empty($days)) {
                        $daily_rates = [];
                        $min_stays   = [];
                        $available   = true;
                        $date_details = [];
                        
                        foreach ($days as $day) {
                            $d = $day['date'] ?? '';
                            if ($d >= $check_in && $d < $check_out) {
                                // Check availability
                                if (isset($day['available']) && !$day['available']) {
                                    $available = false;
                                    break;
                                }
                                
                                // Collect daily rate
                                if (isset($day['day_rate'])) {
                                    $daily_rates[] = floatval($day['day_rate']);
                                    $date_details[$d] = [
                                        'date' => $d,
                                        'rate' => floatval($day['day_rate'])
                                    ];
                                }
                                
                                // Collect minimum stay
                                if (isset($day['minimum_length_of_stay'])) {
                                    $min_stays[] = intval($day['minimum_length_of_stay']);
                                }
                            }
                        }
                        
                        if (!$available || count($daily_rates) !== $nights) {
                            continue;
                        }
                        
                        // Calculate subtotal by summing all daily rates
                        $subtotal_base = array_sum($daily_rates);
                        
                        // Apply 8% markup
                        $markup_amount = $subtotal_base * 0.08;
                        $subtotal_with_markup = $subtotal_base + $markup_amount;
                        
                        // Get cleaning fees from property attributes
                        $cleaning_fee = 0;
                        if (!empty($property['attributes']['cleaning_fee'])) {
                            $cleaning_fee = floatval($property['attributes']['cleaning_fee']);
                        }
                        
                        // Total = subtotal (with markup) + cleaning fees
                        $total_price = $subtotal_with_markup + $cleaning_fee;
                        
                        // Calculate average nightly rate (with markup)
                        $avg_nightly_rate = $subtotal_with_markup / $nights;
                        
                        // Get minimum stay
                        $min_stay = !empty($min_stays) ? max($min_stays) : 1;
                        
                        $property['attributes'] = [
                            'average_day_rate'   => round($avg_nightly_rate, 2),
                            'subtotal_base'      => round($subtotal_base, 2),
                            'markup_amount'      => round($markup_amount, 2),
                            'subtotal'           => round($subtotal_with_markup, 2),
                            'cleaning_fee'       => round($cleaning_fee, 2),
                            'total_price'        => round($total_price, 2),
                            'min_stay'           => $min_stay,
                            'nights'             => $nights,
                            'daily_breakdown'    => $date_details
                        ];
                    }
                } catch (\Throwable $e) {
                    error_log('Calendar fetch error for property ' . $pid . ': ' . $e->getMessage());
                    continue;
                }

                $merged[]   = $property;
                $seen[$pid] = true;
            }
        }

        return rest_ensure_response(['data' => $merged]);
    }
}

new Rental_Listings_Redirect();

/* Gallery localizer: download Filestack/Uplisting CDN images into the WP media library and store local attachment IDs (fixes 429 broken galleries). */
function rl_import_remote_image($url, $post_id) {
    if (!function_exists('download_url')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    if (!$post_id) { return false; }
    $url_clean = strtok($url, '?');
    $q = new WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_source_url', 'meta_value' => esc_url($url_clean), 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true));
    if (!empty($q->posts)) { return (int) $q->posts[0]; }
    $tmp = download_url($url, 30);
    if (is_wp_error($tmp)) { return false; }
    $name = basename(parse_url($url, PHP_URL_PATH));
    $filetype = wp_check_filetype($name);
    if (!$filetype['type']) {
        $mime = '';
        if (function_exists('getimagesize')) { $gi = @getimagesize($tmp); if (!empty($gi['mime'])) { $mime = $gi['mime']; } }
        if (!$mime && function_exists('mime_content_type')) { $mime = @mime_content_type($tmp); }
        $map = array('image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/bmp' => 'bmp');
        if ($mime && isset($map[$mime])) {
            $name = ($name !== '' && $name !== false ? $name : ('image-' . $post_id)) . '.' . $map[$mime];
            $filetype = wp_check_filetype($name);
        }
        if (!$filetype['type']) { @unlink($tmp); return false; }
    }
    $file = array('name' => $name, 'tmp_name' => $tmp);
    $aid = media_handle_sideload($file, $post_id);
    if (is_wp_error($aid)) { @unlink($tmp); return false; }
    update_post_meta($aid, '_source_url', esc_url($url_clean));
    return (int) $aid;
}
function rl_backfill_galleries($max_posts = 1) {
    $q = new WP_Query(array('post_type' => 'rental_property', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true));
    $ids = $q->posts; $need = 0; $pr = 0; $cv = 0; $fl = 0; $dn = array();
    foreach ($ids as $pid) {
        $gl = get_post_meta($pid, '_rental_gallery', true);
        if (!is_array($gl) || empty($gl)) { continue; }
        $has_url = false;
        foreach ($gl as $g) { if (!is_numeric($g) && filter_var($g, FILTER_VALIDATE_URL)) { $has_url = true; break; } }
        if (!$has_url) { continue; }
        $need++;
        if ($pr >= $max_posts) { continue; }
        $ng = array();
        foreach ($gl as $g) {
            if (is_numeric($g)) { $ng[] = (int) $g; continue; }
            if (filter_var($g, FILTER_VALIDATE_URL)) {
                $a = rl_import_remote_image($g, $pid);
                if ($a) { $ng[] = (int) $a; $cv++; usleep(750000); }
                else { $ng[] = esc_url($g); $fl++; }
            }
        }
        update_post_meta($pid, '_rental_gallery', $ng);
        foreach ($ng as $g) { if (is_numeric($g)) { set_post_thumbnail($pid, (int) $g); break; } }
        $pr++; $dn[] = $pid;
    }
    return array('total_posts' => count($ids), 'posts_needing_fix' => $need, 'processed_this_run' => $pr, 'remaining' => max(0, $need - $pr), 'images_converted' => $cv, 'images_failed' => $fl, 'done' => $dn);
}
add_action('init', function () { $n = wp_next_scheduled('rl_localize_cron'); while ($n) { wp_unschedule_event($n, 'rl_localize_cron'); $n = wp_next_scheduled('rl_localize_cron'); } });
add_action('admin_init', 'rl_fix_gallery_endpoint');
function rl_fix_gallery_endpoint() {
    if (empty($_GET['rl_fix_gallery'])) { return; }
    if (!is_user_logged_in() || !current_user_can('manage_options')) { return; }
    @set_time_limit(0); @ini_set('memory_limit', '512M');
    $n = isset($_GET['n']) ? max(1, intval($_GET['n'])) : 1;
    wp_send_json(rl_backfill_galleries($n));
}

/* Sync pause control (option-gated). Stays paused until galleries are localized, then resume via ?rl_sync_ctl=resume (admin only). */
add_action('init', function () {
    if (get_option('rl_sync_paused', '1') === '1') {
        $next = wp_next_scheduled('uplisting_cron_sync');
        while ($next) { wp_unschedule_event($next, 'uplisting_cron_sync'); $next = wp_next_scheduled('uplisting_cron_sync'); }
    }
}, 1);
add_action('admin_init', function () {
    if (empty($_GET['rl_sync_ctl'])) { return; }
    if (!current_user_can('manage_options')) { return; }
    $ctl = $_GET['rl_sync_ctl'];
    if ($ctl === 'resume') {
        update_option('rl_sync_paused', '0');
        if (!wp_next_scheduled('uplisting_cron_sync')) { wp_schedule_event(time() + 600, 'every_three_hours', 'uplisting_cron_sync'); }
    } elseif ($ctl === 'pause') {
        update_option('rl_sync_paused', '1');
        $next = wp_next_scheduled('uplisting_cron_sync');
        while ($next) { wp_unschedule_event($next, 'uplisting_cron_sync'); $next = wp_next_scheduled('uplisting_cron_sync'); }
    }
    wp_send_json(array('rl_sync_paused' => get_option('rl_sync_paused', '1'), 'scheduled' => (bool) wp_next_scheduled('uplisting_cron_sync')));
});
add_action('admin_init', function () {
    if (empty($_GET['rl_cron_status'])) { return; }
    if (!current_user_can('manage_options')) { return; }
    wp_send_json(array('uplisting_cron_sync_scheduled' => (bool) wp_next_scheduled('uplisting_cron_sync'), 'rl_sync_paused' => get_option('rl_sync_paused', '1')));
});


/* Throttled, cursor-based Uplisting sync: processes a couple of properties per call so it never times out and stays under the API rate limits (5/sec, 100/min). */
function rl_sync_tick($batch = 2, $dry = false) {
    if (!class_exists('Uplisting_Sync') || !class_exists('Uplisting_Client')) { return array('error' => 'classes not loaded'); }
    $keys = array_values(array_filter(array_map('trim', (array) get_option('uplisting_api_keys', array()))));
    if (empty($keys)) { return array('error' => 'no api keys configured'); }
    $cur = get_option('rl_sync_cursor', array('k' => 0, 'o' => 0));
    $k = isset($cur['k']) ? (int) $cur['k'] : 0;
    $o = isset($cur['o']) ? (int) $cur['o'] : 0;
    if ($k >= count($keys)) { delete_option('rl_sync_cursor'); update_option('uplisting_last_sync_time', time()); return array('all_done' => true); }
    $key = $keys[$k];
    $client = new Uplisting_Client(array($key));
    $sync = new Uplisting_Sync($client, $key);
    $res = $sync->sync_batch($o, $batch, $dry);
    if ($dry) { return array('dry' => true, 'key_index' => $k, 'key_count' => count($keys), 'properties_for_key' => isset($res['total']) ? $res['total'] : null); }

    // Remember every id the API returned during this pass, across all keys, so that when the pass
    // finishes we can draft anything that has silently disappeared from the account.
    if (!empty($res['ids']) && is_array($res['ids'])) {
        $seen = (array) get_option('rl_sync_seen_ids', array());
        update_option('rl_sync_seen_ids', array_values(array_unique(array_merge($seen, array_map('strval', $res['ids'])))), false);
    }
    if (!empty($res['done'])) { $k++; $o = 0; } else { $o = isset($res['next_offset']) ? (int) $res['next_offset'] : ($o + $batch); }
    $all_done = ($k >= count($keys));
    $reconciled = null;
    if ($all_done) {
        $reconciled = rl_reconcile_missing((array) get_option('rl_sync_seen_ids', array()));
        delete_option('rl_sync_cursor');
        delete_option('rl_sync_seen_ids');
        update_option('uplisting_last_sync_time', time());
    }
    else { update_option('rl_sync_cursor', array('k' => $k, 'o' => $o)); }
    return array('key_index' => $k, 'key_count' => count($keys), 'offset' => $o, 'properties_for_key' => isset($res['total']) ? $res['total'] : null, 'processed' => isset($res['processed']) ? $res['processed'] : 0, 'key_done' => !empty($res['done']), 'all_done' => $all_done, 'reconciled' => $reconciled);
}

/**
 * Draft any published property whose Uplisting id was not returned anywhere in the pass that just
 * finished. Without this, a property deleted from the Uplisting account is never visited by the
 * import loop and stays published on the website for ever.
 *
 * Refuses to act on an implausible id list, so one failed API pass can never unpublish the site.
 *
 * @param string[] $seen_ids Every Uplisting id returned during the pass.
 * @return array Summary.
 */
function rl_reconcile_missing($seen_ids) {
    $seen_ids = array_values(array_unique(array_map('strval', (array) $seen_ids)));

    $published = get_posts(array(
        'post_type'      => 'rental_property',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    if (empty($seen_ids)) {
        return array('skipped' => 'api returned no property ids', 'published' => count($published));
    }
    if (count($published) > 0 && count($seen_ids) < 0.4 * count($published)) {
        return array(
            'skipped'   => 'api returned ' . count($seen_ids) . ' ids against ' . count($published) . ' published — below the 40% guard',
            'published' => count($published),
        );
    }

    $lookup  = array_flip($seen_ids);
    $drafted = array();

    foreach ($published as $post_id) {
        $upl_id = (string) get_post_meta($post_id, '_uplisting_id', true);
        if ('' === $upl_id || isset($lookup[$upl_id])) continue;
        wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
        update_post_meta($post_id, '_rental_status', 'Disabled');
        update_post_meta($post_id, '_rental_gone_from_api', time());
        $drafted[] = array('post_id' => (int) $post_id, 'uplisting_id' => $upl_id, 'title' => get_the_title($post_id));
    }

    return array('api_ids' => count($seen_ids), 'published_before' => count($published), 'drafted' => $drafted);
}

/**
 * Raw attribute dump for a handful of properties, so two that Uplisting treats differently can be
 * diffed attribute by attribute. Administrators only.
 *
 *   /wp-admin/?rl_raw=1&ids=88898,88900,88901,88902
 */
add_action('admin_init', function () {
    if (empty($_GET['rl_raw'])) { return; }
    if (!current_user_can('manage_options')) { return; }
    if (!class_exists('Uplisting_Client')) { wp_send_json(array('error' => 'classes not loaded')); }

    $wanted = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['ids'] ?? '')))));
    if (empty($wanted)) { wp_send_json(array('error' => 'pass ids=1,2,3')); }
    $wanted = array_flip($wanted);

    @set_time_limit(0);

    $keys = array_values(array_filter(array_map('trim', (array) get_option('uplisting_api_keys', array()))));
    $out  = array();
    $all_keys_seen = array();

    foreach ($keys as $i => $key) {
        $client = new Uplisting_Client(array($key));
        $resp   = $client->get_properties_all();
        foreach (($resp['data'] ?? array()) as $property) {
            $id = (string) ($property['id'] ?? '');
            if ('' === $id || !isset($wanted[$id])) continue;

            $attrs = $property['attributes'] ?? array();
            foreach (array_keys($attrs) as $attr_key) { $all_keys_seen[$attr_key] = true; }

            $out[$id] = array(
                'account'       => $i + 1,
                'attributes'    => $attrs,
                'relationships' => array_keys($property['relationships'] ?? array()),
            );
        }
    }

    wp_send_json(array(
        'requested'      => array_keys($wanted),
        'found'          => array_keys($out),
        'attribute_keys' => array_keys($all_keys_seen),
        'properties'     => $out,
    ));
});

/**
 * Audit screen: what the API returns, what would be live, and what WordPress currently shows.
 * Administrators only.
 *
 *   /wp-admin/?rl_audit=1
 */
add_action('admin_init', function () {
    if (empty($_GET['rl_audit'])) { return; }
    if (!current_user_can('manage_options')) { return; }
    if (!class_exists('Uplisting_Sync') || !class_exists('Uplisting_Client')) { wp_send_json(array('error' => 'classes not loaded')); }

    @set_time_limit(0);

    $keys = array_values(array_filter(array_map('trim', (array) get_option('uplisting_api_keys', array()))));
    if (empty($keys)) { wp_send_json(array('error' => 'no api keys configured')); }

    // ?cal=1 also reads each property's calendar, so the availability-based rule can be counted.
    // That is one extra API call per property, throttled — expect ~10s for 35 properties.
    $with_cal = !empty($_GET['cal']);
    $months   = isset($_GET['months']) ? max(1, intval($_GET['months'])) : Uplisting_Sync::availability_months();

    $rows = array();
    $api_ids = array();
    $live_ids = array();
    $availability_ids = array();

    foreach ($keys as $i => $key) {
        $client = new Uplisting_Client(array($key));
        $sync   = new Uplisting_Sync($client, $key);
        $inv    = $sync->inventory($with_cal, $months);

        $api_ids  = array_merge($api_ids, $inv['all']);
        $live_ids = array_merge($live_ids, $inv['live']);
        $from_availability = $sync->live_ids();
        if (is_array($from_availability)) $availability_ids = array_merge($availability_ids, $from_availability);

        foreach ($inv['rows'] as $row) {
            $row['account'] = $i + 1;

            $existing = get_posts(array(
                'post_type'      => 'rental_property',
                'meta_key'       => '_uplisting_id',
                'meta_value'     => $row['id'],
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'post_status'    => 'any',
            ));
            $row['wp_post_id'] = !empty($existing) ? (int) $existing[0] : null;
            $row['wp_status']  = $row['wp_post_id'] ? get_post_status($row['wp_post_id']) : null;
            $row['wp_rental_status'] = $row['wp_post_id'] ? get_post_meta($row['wp_post_id'], '_rental_status', true) : null;

            $rows[] = $row;
        }
    }

    // What the unfiltered listing page actually renders today.
    $shown = get_posts(array(
        'post_type'      => 'rental_property',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(array('key' => '_rental_status', 'value' => 'Enabled', 'compare' => '=')),
    ));

    $orphans = array();
    $api_lookup = array_flip(array_map('strval', $api_ids));
    foreach ($shown as $post_id) {
        $upl_id = (string) get_post_meta($post_id, '_uplisting_id', true);
        if ('' === $upl_id || !isset($api_lookup[$upl_id])) {
            $orphans[] = array('post_id' => (int) $post_id, 'uplisting_id' => $upl_id, 'title' => get_the_title($post_id));
        }
    }

    $availability_ids = array_values(array_unique($availability_ids));

    // How many properties each candidate rule would show, so the one matching the direct booking
    // sites can be picked on evidence instead of guessed.
    $counts = array('availability_endpoint' => count($availability_ids), 'status_only' => 0, 'status_and_site' => 0);
    if ($with_cal) { $counts['status_site_availability'] = 0; $counts['calendar_unreadable'] = 0; }
    $availability_lookup = array_flip($availability_ids);
    foreach ($rows as $i => $row) {
        $rows[$i]['in_availability_endpoint'] = isset($availability_lookup[$row['id']]);
        if (!empty($row['rule_status_only'])) $counts['status_only']++;
        if (!empty($row['rule_status_site'])) $counts['status_and_site']++;
        if ($with_cal) {
            if (!empty($row['rule_status_site_availability'])) $counts['status_site_availability']++;
            if (empty($row['calendar_readable'])) $counts['calendar_unreadable']++;
        }
    }

    // The concrete work the next sync will do, so the outcome can be checked before running it.
    $to_publish = array();
    $to_draft   = array();
    foreach ($rows as $row) {
        if (true === $row['should_publish'] && 'publish' !== $row['wp_status']) {
            $to_publish[] = $row['id'] . ' — ' . $row['name'];
        }
        if (false === $row['should_publish'] && 'publish' === $row['wp_status']) {
            $to_draft[] = $row['id'] . ' — ' . $row['name'];
        }
    }

    wp_send_json(array(
        'accounts'                  => count($keys),
        'properties_from_api'       => count(array_unique($api_ids)),
        'next_sync_will_publish'    => $to_publish,
        'next_sync_will_draft'      => $to_draft,
        'forced_draft_ids'          => Uplisting_Sync::forced_ids('rl_force_draft_ids'),
        'forced_publish_ids'        => Uplisting_Sync::forced_ids('rl_force_publish_ids'),
        'active_rule'               => Uplisting_Sync::publish_rule(),
        'availability_months'       => $months,
        'counts_by_rule'            => $counts,
        'availability_endpoint_ids' => $availability_ids,
        'live_by_current_rule'      => count(array_unique($live_ids)),
        'shown_on_site_now'         => count($shown),
        'shown_but_absent_from_api' => $orphans,
        'sync_paused'               => get_option('rl_sync_paused', '1'),
        'last_sync'                 => get_option('uplisting_last_sync_time') ? gmdate('c', (int) get_option('uplisting_last_sync_time')) : null,
        'properties'                => $rows,
    ));
});

add_action('rl_sync_continue', function () {
    if (get_option('rl_sync_cursor', false) === false) { return; }
    @set_time_limit(0); @ini_set('memory_limit', '256M');
    rl_sync_tick(2);
    if (get_option('rl_sync_cursor', false) !== false && !wp_next_scheduled('rl_sync_continue')) {
        wp_schedule_single_event(time() + 120, 'rl_sync_continue');
    }
});

add_action('admin_init', function () {
    if (empty($_GET['rl_sync_run'])) { return; }
    if (!current_user_can('manage_options')) { return; }
    @set_time_limit(0); @ini_set('memory_limit', '256M');
    if (!empty($_GET['reset'])) { update_option('rl_sync_cursor', array('k' => 0, 'o' => 0)); }
    $n = isset($_GET['n']) ? max(1, intval($_GET['n'])) : 2;
    $dry = !empty($_GET['dry']);
    wp_send_json(rl_sync_tick($n, $dry));
});
