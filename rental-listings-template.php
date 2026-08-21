<?php
/**
 * Template Name: Rental Listings (Uplisting Synced)
 */
if (!defined('ABSPATH')) exit;

// Use existing query from AJAX if available
if (!isset($query) || !$query instanceof WP_Query) {
    $meta_query = ['relation' => 'AND'];

    $city   = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $guests = isset($_GET['guests']) ? intval($_GET['guests']) : 0;
    $paged  = get_query_var('paged') ?: 1;

    if ($city) {
        $meta_query[] = ['key' => '_rental_city', 'value' => $city, 'compare' => 'LIKE'];
    }
    
    $meta_query[] = [
        'key'     => '_rental_status',
        'value'   => 'Enabled',
        'compare' => '='
    ];

    $args = [
        'post_type'      => 'rental_property',
        'post_status'    => 'publish',
        'posts_per_page' => 12,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => $meta_query,
    ];
    $query = new WP_Query($args);
}
?>

<div class="rental-container">
    <!-- Filters -->
    <div class="rental-filters">
        <input type="date" id="rental-start-date" placeholder="Check-in" />
        <input type="date" id="rental-end-date" placeholder="Check-out" />
        <select id="rental-city-select">
            <option value="">All cities</option>
            <?php
            $cities = get_posts([
                'post_type'      => 'rental_property',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            $unique_cities = [];
            foreach ($cities as $cid) {
                $city_name = get_post_meta($cid, '_rental_city', true);
                if ($city_name && !in_array($city_name, $unique_cities, true)) {
                    $unique_cities[] = $city_name;
                }
            }
            sort($unique_cities);
            foreach ($unique_cities as $city_name) {
                echo '<option value="' . esc_attr($city_name) . '">' . esc_html($city_name) . '</option>';
            }
            ?>
        </select>

        <?php
        // Calculate max guests
        $max_guests = 1;
        $guest_values = get_posts([
            'post_type'      => 'rental_property',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($guest_values as $pid) {
            $g = (int) get_post_meta($pid, '_rental_max_guests', true);
            if ($g > $max_guests) $max_guests = $g;
        }
        ?>

        <select id="rental-guests-select">
            <option value="">Guests</option>
            <?php for ($i = 1; $i <= $max_guests; $i++): ?>
                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
            <?php endfor; ?>
        </select>
        <button id="rental-filter-search">Search</button>
    </div>

    <!-- Main layout -->
    <div class="rental-main">
        <div id="rental-results" class="col-sm-6">
            <div class="rental-grid">
                <?php if ($query->have_posts()): ?>
                    <?php while ($query->have_posts()): $query->the_post();
                        $post_id = get_the_ID();
                        $upl_id  = get_post_meta($post_id, '_uplisting_id', true);
                        $city    = get_post_meta($post_id, '_rental_city', true);
                        $address = get_post_meta($post_id, '_rental_address', true);
                        $guests  = get_post_meta($post_id, '_rental_max_guests', true);
                        $lat     = get_post_meta($post_id, '_rental_lat', true);
                        $lng     = get_post_meta($post_id, '_rental_lng', true);
                        $booking = get_post_meta($post_id, '_rental_booking_url', true);
                        // Normalize legacy booking URLs (/g/city/title/code) to the new /property/{code} structure
                        if ($booking && strpos($booking, '/g/') !== false) {
                            $__bk_parts  = explode('/', rtrim($booking, '/'));
                            $__bk_code   = end($__bk_parts);
                            $__bk_domain = substr($booking, 0, strpos($booking, '/g/'));
                            if ($__bk_domain && $__bk_code) { $booking = $__bk_domain . '/property/' . $__bk_code; }
                        }
                        
                        // Get "from" price and currency
                        $from_price = get_post_meta($post_id, '_rental_from_price', true);
                        $currency = get_post_meta($post_id, '_rental_currency', true) ?: 'GBP';
                        $currency_symbol = $currency === 'GBP' ? '£' : $currency;
                    ?>
                    <div class="rental-card"
                        data-id="<?php echo esc_attr($upl_id); ?>"
                        data-lat="<?php echo esc_attr($lat); ?>"
                        data-lng="<?php echo esc_attr($lng); ?>">

                        <a href="<?php the_permalink(); ?>" class="rental-thumb">
                            <?php if (has_post_thumbnail()) the_post_thumbnail('medium_large');
                            else echo '<div class="no-thumb">No Image</div>'; ?>
                        </a>

                        <div class="rental-info">
                            <h3 class="rental-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                            <p class="rental-meta">
                                <?php echo esc_html($city); ?>
                                <br>
                                <?php echo intval($guests); ?> guests
                            </p>
                            
                            <!-- Show "from" price by default, replaced with actual rate when dates selected -->
                            <div class="rental-dayrate">
                                <?php if ($from_price): ?>
                                    <span class="from-price">From <?php echo esc_html($currency_symbol . number_format($from_price, 0)); ?> / night</span>
                                <?php endif; ?>
                            </div>
                            <div class="rental-minstay"></div>
                            <div class="rental-total"></div>

                            <div class="rental-buttons">
                                <?php if ($booking): ?>
                                    <a href="<?php echo esc_url($booking); ?>" target="_blank" class="rental-btn book-btn">Book Now</a>
                                <?php endif; ?>
                                <a href="<?php the_permalink(); ?>" class="rental-btn small">View Details</a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>

                    <?php if ($query->max_num_pages > 1): ?>
                        <div class="rental-pagination">
                            <?php
                            echo paginate_links([
                                'total'     => (int) $query->max_num_pages,
                                'current'   => max(1, get_query_var('paged', 1)),
                                'format'    => '?paged=%#%',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'add_args'  => false,
                                'type'      => 'list'
                            ]);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php wp_reset_postdata(); ?>

                <?php else: ?>
                    <p class="no-results">No properties found. Try adjusting your filters.</p>
                <?php endif; ?>
            </div>
        </div>

        <div id="rental-map" class="rental-map"></div>
    </div>
</div>

<?php
// Map data: ALL enabled properties (not just the current page)
$map_query = new WP_Query([
    'post_type'      => 'rental_property',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'meta_query'     => [[ 'key' => '_rental_status', 'value' => 'Enabled', 'compare' => '=' ]],
]);
$map_props = [];
while ($map_query->have_posts()) { $map_query->the_post();
    $mid  = get_the_ID();
    $mlat = get_post_meta($mid, '_rental_lat', true);
    $mlng = get_post_meta($mid, '_rental_lng', true);
    if (!$mlat || !$mlng) continue;
    $mprice = get_post_meta($mid, '_rental_from_price', true);
    $mcur   = get_post_meta($mid, '_rental_currency', true) ?: 'GBP';
    $msym   = $mcur === 'GBP' ? '£' : $mcur;
    $map_props[] = [
        'id'    => (string) get_post_meta($mid, '_uplisting_id', true),
        'lat'   => (float) $mlat,
        'lng'   => (float) $mlng,
        'title' => get_the_title(),
        'link'  => get_permalink(),
        'price' => $mprice ? 'From ' . $msym . number_format((float) $mprice, 0) . ' / night' : '',
        'img'   => get_the_post_thumbnail_url($mid, 'medium') ?: '',
    ];
}
wp_reset_postdata();
?>
<script>window.rentalAllProperties = <?php echo wp_json_encode($map_props); ?>;</script>
