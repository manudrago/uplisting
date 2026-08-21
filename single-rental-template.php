<?php
/**
 * Single property template served by the plugin.
 * Expects global $post to be the rental_property.
 */

if (!defined('ABSPATH')) exit;

global $post;
$id = get_the_ID();

// Get gallery
$gallery_meta = get_post_meta($id, '_rental_gallery', true);
$gallery_ids = [];
if (is_array($gallery_meta)) {
    $gallery_ids = $gallery_meta;
} elseif (is_string($gallery_meta) && strpos($gallery_meta, ',') !== false) {
    $gallery_ids = array_map('intval', explode(',', $gallery_meta));
} elseif (!empty($gallery_meta)) {
    $gallery_ids = [(int)$gallery_meta];
}

// Get property data
$day_rate    = get_post_meta($id, '_rental_day_rate', true);
$currency    = get_post_meta($id, '_rental_currency', true) ?: 'GBP';
$currency_symbol = $currency === 'GBP' ? '£' : ($currency === 'EUR' ? '€' : $currency);
$address     = get_post_meta($id, '_rental_address', true);
$city        = get_post_meta($id, '_rental_city', true);
$bedrooms    = get_post_meta($id, '_rental_bedrooms', true);
$bathrooms   = get_post_meta($id, '_rental_bathrooms', true);
$beds        = get_post_meta($id, '_rental_beds', true);
$guests      = get_post_meta($id, '_rental_max_guests', true);
$booking     = get_post_meta($id, '_rental_booking_url', true);
    // Normalize legacy booking URLs (/g/...) to the new /property/{code} structure
    if ($booking && strpos($booking, '/g/') !== false) {
        $__bk_parts  = explode('/', rtrim($booking, '/'));
        $__bk_code   = end($__bk_parts);
        $__bk_domain = substr($booking, 0, strpos($booking, '/g/'));
        if ($__bk_domain && $__bk_code) { $booking = $__bk_domain . '/property/' . $__bk_code; }
    }
$lat         = get_post_meta($id, '_rental_lat', true);
$lng         = get_post_meta($id, '_rental_lng', true);
$property_type = get_post_meta($id, '_rental_type', true);
$check_in    = get_post_meta($id, '_rental_check_in_time', true);
$check_out   = get_post_meta($id, '_rental_check_out_time', true);

// Get amenities from meta (saved by sync)
$amenities_meta = get_post_meta($id, '_rental_amenities', true);
$amenities = is_array($amenities_meta) ? $amenities_meta : [];

get_header();
?>

<style>
.rental-single-container {
    max-width: 1400px;
    margin: 40px auto;
    padding: 0 20px;
}

.rental-single-header {
    margin-bottom: 30px;
}

.rental-single-header h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 8px 0;
    color: #222;
}

.rental-header-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #666;
    font-size: 15px;
}

.rental-header-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.rental-hero-image {
    width: 100%;
    height: 500px;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 30px;
    background: #f5f5f5;
}

.rental-hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.rental-content-grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 60px;
    align-items: start;
}

.rental-left-content {
    min-width: 0;
}

.rental-right-sidebar {
    position: sticky;
    top: 20px;
}

.rental-info-section {
    margin-bottom: 40px;
    padding-bottom: 40px;
    border-bottom: 1px solid #e0e0e0;
}

.rental-info-section:last-child {
    border-bottom: none;
}

.rental-info-section h2 {
    font-size: 22px;
    font-weight: 600;
    margin: 0 0 20px 0;
    color: #222;
}

.rental-quick-stats {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}

.rental-stat {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
}

.rental-stat-icon {
    font-size: 20px;
}

.rental-description {
    font-size: 16px;
    line-height: 1.7;
    color: #484848;
}

.rental-amenities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
}

.rental-amenity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 15px;
    color: #484848;
}

.rental-amenity-icon {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
}

.rental-gallery-section {
    margin-bottom: 40px;
}

.rental-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.rental-gallery-item {
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s;
}

.rental-gallery-item:hover {
    transform: scale(1.02);
}

.rental-gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.rental-booking-card {
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    padding: 24px;
    background: #fff;
    box-shadow: 0 2px 16px rgba(0,0,0,0.08);
    margin-bottom: 24px;
}

.rental-booking-price {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 4px;
    color: #222;
}

.rental-booking-price span {
    font-size: 16px;
    font-weight: 400;
    color: #666;
}

.rental-booking-note {
    font-size: 13px;
    color: #717171;
    margin-bottom: 20px;
}

.rental-book-btn {
    display: block;
    width: 100%;
    padding: 14px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    text-align: center;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    text-decoration: none;
    transition: transform 0.2s, box-shadow 0.2s;
}

.rental-book-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    color: #fff;
}

.rental-map-card {
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
}

.rental-map-header {
    padding: 16px 20px;
    font-weight: 600;
    font-size: 16px;
    color: #222;
    border-bottom: 1px solid #e0e0e0;
}

#single-rental-map {
    width: 100%;
    height: 400px;
}

.rental-check-times {
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #f7f7f7;
    padding: 16px;
    border-radius: 8px;
    font-size: 14px;
}

.rental-check-time {
    display: flex;
    justify-content: space-between;
}

.rental-check-time strong {
    color: #222;
}

@media (max-width: 1024px) {
    .rental-content-grid {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    
    .rental-right-sidebar {
        position: static;
    }
    
    .rental-hero-image {
        height: 400px;
    }
}

@media (max-width: 768px) {
    .rental-single-header h1 {
        font-size: 24px;
    }
    
    .rental-hero-image {
        height: 300px;
    }
    
    .rental-gallery-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
}
</style>

<div class="rental-single-container">
    <!-- Header with Title -->
    <div class="rental-single-header">
        <h1><?php echo esc_html(get_the_title()); ?></h1>
        <div class="rental-header-meta">
            <?php if ($property_type): ?>
                <span>🏠 <?php echo esc_html($property_type); ?></span>
            <?php endif; ?>
            <?php if ($city): ?>
                <span>• 📍 <?php echo esc_html($city); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hero Feature Image -->
    <?php if (has_post_thumbnail()): ?>
        <div class="rental-hero-image">
            <?php the_post_thumbnail('full'); ?>
        </div>
    <?php endif; ?>

    <!-- Two Column Layout -->
    <div class="rental-content-grid">
        <!-- LEFT COLUMN: Property Info -->
        <div class="rental-left-content">
            
            <!-- Quick Stats -->
            <div class="rental-info-section">
                <div class="rental-quick-stats">
                    <?php if ($guests): ?>
                        <div class="rental-stat">
                            <span class="rental-stat-icon">👥</span>
                            <span><?php echo intval($guests); ?> guests</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($bedrooms): ?>
                        <div class="rental-stat">
                            <span class="rental-stat-icon">🛏️</span>
                            <span><?php echo intval($bedrooms); ?> bedrooms</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($beds): ?>
                        <div class="rental-stat">
                            <span class="rental-stat-icon">🛌</span>
                            <span><?php echo intval($beds); ?> beds</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($bathrooms): ?>
                        <div class="rental-stat">
                            <span class="rental-stat-icon">🚿</span>
                            <span><?php echo intval($bathrooms); ?> bathrooms</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <?php if (get_the_content()): ?>
                <div class="rental-info-section">
                    <h2>About this place</h2>
                    <div class="rental-description">
                        <?php echo wpautop(get_the_content()); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Check-in/Check-out Times -->
            <?php if ($check_in || $check_out): ?>
                <div class="rental-info-section">
                    <h2>Check-in & Check-out</h2>
                    <div class="rental-check-times">
                        <?php if ($check_in): ?>
                            <div class="rental-check-time">
                                <span>Check-in:</span>
                                <strong><?php echo esc_html($check_in); ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if ($check_out): ?>
                            <div class="rental-check-time">
                                <span>Check-out:</span>
                                <strong><?php echo esc_html($check_out); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Amenities -->
            <?php if (!empty($amenities)): ?>
                <div class="rental-info-section">
                    <h2>What this place offers</h2>
                    <div class="rental-amenities-grid">
                        <?php foreach ($amenities as $amenity): ?>
                            <div class="rental-amenity-item">
                                <span class="rental-amenity-icon">✓</span>
                                <span><?php echo esc_html($amenity['name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Gallery -->
            <?php if (!empty($gallery_ids)): ?>
                <div class="rental-info-section">
                    <h2>Gallery</h2>
                    <div class="rental-gallery-grid">
                        <?php foreach ($gallery_ids as $img_id): 
                            if (is_numeric($img_id)):
                                $img_url = wp_get_attachment_image_url($img_id, 'medium_large');
                                if ($img_url): ?>
                                    <div class="rental-gallery-item">
                                        <img src="<?php echo esc_url($img_url); ?>" alt="Property image" loading="lazy">
                                    </div>
                                <?php endif;
                            elseif (filter_var($img_id, FILTER_VALIDATE_URL)): ?>
                                <div class="rental-gallery-item">
                                    <img src="<?php echo esc_url($img_id); ?>" alt="Property image" loading="lazy">
                                </div>
                            <?php endif;
                        endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: Booking & Map -->
        <div class="rental-right-sidebar">
            
            <!-- Booking Card -->
            <?php if ($booking): ?>
                <div class="rental-booking-card">
                    <?php if ($day_rate): ?>
                        <div class="rental-booking-price">
                            <?php echo esc_html($currency_symbol . number_format($day_rate, 0)); ?>
                            <span>/ night</span>
                        </div>
                    <?php endif; ?>
                    <div class="rental-booking-note">
                        Book directly on the external website
                    </div>
                    <a href="<?php echo esc_url($booking); ?>" 
                       class="rental-book-btn" 
                       target="_blank" 
                       rel="noopener noreferrer">
                        Check Availability
                    </a>
                </div>
            <?php endif; ?>

            <!-- Map Card -->
            <?php if ($lat && $lng): ?>
                <div class="rental-map-card">
                    <div class="rental-map-header">
                        <?php echo $address ? esc_html($address) : 'Location'; ?>
                    </div>
                    <div id="single-rental-map"
                         data-lat="<?php echo esc_attr($lat); ?>"
                         data-lng="<?php echo esc_attr($lng); ?>"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Leaflet Map Script -->
<?php if ($lat && $lng): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mapEl = document.getElementById('single-rental-map');
    if (!mapEl || typeof L === 'undefined') return;

    const lat = parseFloat(mapEl.dataset.lat);
    const lng = parseFloat(mapEl.dataset.lng);

    if (!lat || !lng) return;

    const map = L.map('single-rental-map').setView([lat, lng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    L.marker([lat, lng]).addTo(map)
        .bindPopup('<strong><?php echo esc_js(get_the_title()); ?></strong>')
        .openPopup();

    setTimeout(() => map.invalidateSize(), 300);
});
</script>
<?php endif; ?>

<?php get_footer(); ?>