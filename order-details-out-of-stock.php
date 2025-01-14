<?php
/**
 * Plugin Name: Order Details with Out-of-Stock Products
 * Description: A plugin to render order details including out-of-stock products and allow users to update sizes and colors.
 * Version: 1.0
 * Author: Your Name
 * License: GPL2
 */

// Register the shortcode to display order details and out-of-stock products
function render_order_details_with_out_of_stock_products_shortcode() {
    global $wpdb;
    $order_hash = isset($_GET['order_hash']) ? sanitize_text_field($_GET['order_hash']) : '';

    if (!$order_hash) {
        return 'No valid order hash provided.';
    }

    $order_id = getorderid($order_hash);

    if(!$order_id){
        return 'Order not found or you do not have permission to view it.';
    }

    // Fetch order details for the specified order ID and current user
    $order_details = $wpdb->get_row($wpdb->prepare("
        SELECT 
            CONCAT(first_name, ' ', last_name) AS customer_name,
            company AS company_name,
            address_1,
            address_2,
            city,
            state,
            postcode,
            country,
            email,
            phone
        FROM {$wpdb->prefix}wc_order_addresses
        WHERE order_id = %d AND address_type = 'billing'
    ", $order_id));

    if (!$order_details) {
        return 'Order not found or you do not have permission to view it.';
    }

    // Fetch out-of-stock products for the specified order
    $query = $wpdb->prepare("
    SELECT 
    p.ID AS product_id,
    p.post_title AS product_name,
    pm2.meta_value AS size,
    pm3.meta_value AS color,
    pm4.meta_value AS LeggTilNavn,
    oi.order_item_id
FROM {$wpdb->prefix}woocommerce_order_items AS oi
INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim 
    ON oi.order_item_id = oim.order_item_id
INNER JOIN {$wpdb->prefix}postmeta AS pm 
    ON pm.post_id = oim.meta_value
LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pm2 
    ON pm2.order_item_id = oim.order_item_id AND pm2.meta_key = 'pa_storrelse' 
LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pm3 
    ON pm3.order_item_id = oim.order_item_id AND pm3.meta_key = 'pa_farge' 
LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pm4 
    ON pm4.order_item_id = oim.order_item_id AND pm4.meta_key = 'Legg til navn' 
INNER JOIN {$wpdb->prefix}posts AS p 
    ON p.ID = pm.post_id
WHERE oim.meta_key = '_product_id'
AND oi.order_id = %d
Group by oi.order_item_id
", $order_id);

    $results = $wpdb->get_results($query);

    ob_start();
    ?>
    <div class="container-edit">
        <div class="order-details">
            <h2>Ordredetaljer</h2>
            <p><strong><?php echo esc_html($order_details->customer_name); ?></strong></p>
            <p><?php echo esc_html($order_details->address_1); ?></p>
            <p><?php echo esc_html($order_details->postcode . ' ' . $order_details->city); ?></p>
            <p><b>Email address:</b><br> <a href="mailto:<?php echo esc_attr($order_details->email); ?>"><?php echo esc_html($order_details->email); ?></a></p>
            <p><b>Phone:</b><br> <a href="tel:<?php echo esc_attr($order_details->phone); ?>"><?php echo esc_html($order_details->phone); ?></a></p>
        </div>

        <h2>Endre størrelse</h2>
        <div class="change-size-section">
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $product) : ?>
                    <?php
                    $wc_product = wc_get_product($product->product_id);
                    $sizes = [];
                    $colors = [];
                    if ($wc_product->is_type('variable')) {
                        $attributes = $wc_product->get_attributes();
                        foreach ($attributes as $taxonomy => $attribute) {
                            if ($attribute->is_taxonomy()) {
                                $terms = wp_get_post_terms($wc_product->get_id(), $taxonomy, ['fields' => 'names']);
                                foreach ($terms as $term) {
                                    if ($taxonomy === 'pa_storrelse' && !in_array($term, $sizes)) {
                                        $sizes[] = $term;
                                    }
                                    if ($taxonomy === 'pa_farge' && !in_array($term, $colors)) {
                                        $colors[] = $term;
                                    }
                                }
                            } else {
                                $values = wc_get_product_terms($wc_product->get_id(), $taxonomy, ['fields' => 'names']);
                                foreach ($values as $value) {
                                    if ($taxonomy === 'pa_storrelse' && !in_array($value, $sizes)) {
                                        $sizes[] = $value;
                                    }
                                    if ($taxonomy === 'pa_farge' && !in_array($value, $colors)) {
                                        $colors[] = $value;
                                    }
                                }
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($sizes)) : ?>
                        <div class="product-card">
                            <img src="<?php echo get_the_post_thumbnail_url($product->product_id, 'thumbnail') ? get_the_post_thumbnail_url($product->product_id, 'thumbnail') : 'https://via.placeholder.com/200'; ?>" alt="<?php echo esc_attr($product->product_name); ?>">
                            <div class="product-details">
                                <p><strong><?php echo esc_html($product->product_name); ?></strong></p>
                                <p>Farge: <?php echo esc_html($product->color); ?></p>
                                <p>Størrelse: <?php echo strtoupper(esc_html($product->size)); ?></p>
                                <select name="product_size[<?php echo $product->order_item_id; ?>]" onchange="updateSelectedSize(this, '<?php echo $product->order_item_id; ?>')">
                                    <option value="">Endre størrelse</option>
                                    <?php foreach ($sizes as $size) : ?>
                                        <option value="<?php echo esc_attr($size); ?>"><?php echo esc_html($size); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="product_color[<?php echo $product->order_item_id; ?>]" onchange="updateSelectedColor(this, '<?php echo $product->order_item_id; ?>')">
                                    <option value="">Endre Farge</option>
                                    <?php foreach ($colors as $color) : ?>
                                        <option value="<?php echo esc_attr($color); ?>"><?php echo esc_html($color); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="hidden" class="LeggTilNavn" name="LeggTilNavn[<?php echo $product->order_item_id; ?>]" value="<?php echo $product->LeggTilNavn; ?>">
                                <p id="selectedcolor-<?php echo $product->order_item_id; ?>"></p>
                                <p id="selectedSize-<?php echo $product->order_item_id; ?>"></p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else : ?>
                <p>Ingen produkter med denne størrelsen er utsolgt.</p>
            <?php endif; ?>
        </div>

        <div class="notice">
            Etter størrelser er endret må du trykke på knappen under for at endringene skal bli lagret.
        </div>

        <div class="save-button">
            <button type="button" id="Update_order">Lagre</button>
        </div>
    </div>
    <script>
        function updateSelectedSize(selectElement, productId) {
            const selectedSize = selectElement.value;
            const sizeDisplay = document.getElementById('selectedSize-' + productId);
            sizeDisplay.textContent = 'Ny størrelse: ' + (selectedSize.toUpperCase() || 'Ikke valgt');
        }

        function updateSelectedColor(selectElement, productId) {
            const selectedColor = selectElement.value;
            const colorDisplay = document.getElementById('selectedcolor-' + productId);
            colorDisplay.textContent = 'Ny farge: ' + (selectedColor.toUpperCase() || 'Ikke valgt');
        }

        document.getElementById('Update_order').addEventListener('click', function () {
            var selectedSizes = {};
            var selectedColors = {};
            var legTilNavs = {};
            var selects = document.querySelectorAll('select[name^="product_size"]');
            selects.forEach(function (select) {
                var productId = select.name.match(/\d+/)[0];
                var selectedSize = select.value;
                selectedSizes[productId] = selectedSize;
            });

            var selectscolor = document.querySelectorAll('select[name^="product_color"]');
            selectscolor.forEach(function (select) {
                var productId = select.name.match(/\d+/)[0];
                var selectedColor = select.value;
                selectedColors[productId] = selectedColor;
            });

            const elements = document.querySelectorAll('input.LeggTilNavn');
            elements.forEach((element) => {
                const match = element.name.match(/\d+/);
                if (match) {
                    const productId = match[0];
                    const value = element.value.trim();
                    legTilNavs[productId] = value;
                }
            });

            var order_id = <?php echo $order_id; ?>;
            var data = {
                action: 'update_product_sizes',
                order_id: order_id,
                product_sizes: selectedSizes,
                product_colors: selectedColors,
                leg_Til_Navs: legTilNavs,
            };

            jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function (response) {
                if (response.success) {
                    alert('Order sizes updated successfully!');
                    window.location.reload();
                } else {
                    alert('Failed to update order sizes.');
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('order_details_out_of_stock', 'render_order_details_with_out_of_stock_products_shortcode');

// Hook into the WordPress AJAX handler
add_action('wp_ajax_update_product_sizes', 'update_product_sizes');
add_action('wp_ajax_nopriv_update_product_sizes', 'update_product_sizes');

// Function to update product sizes
function update_product_sizes() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $product_sizes = isset($_POST['product_sizes']) ? $_POST['product_sizes'] : [];
    $product_colors = isset($_POST['product_colors']) ? $_POST['product_colors'] : [];
    $legTilNavs = isset($_POST['leg_Til_Navs']) ? $_POST['leg_Til_Navs'] : [];

    if ($order_id && !empty($product_sizes)) {
        global $wpdb;

        foreach ($product_sizes as $product_id => $size) {
            $item_id = get_order_item_id_by_product_id($order_id, $product_id);
            if ($item_id) {
                $wpdb->update(
                    $wpdb->prefix . 'woocommerce_order_itemmeta',
                    ['meta_value' => $size],
                    ['order_item_id' => $item_id, 'meta_key' => 'pa_storrelse']
                );
            }
        }

        foreach ($product_colors as $product_id => $color) {
            $item_id = get_order_item_id_by_product_id($order_id, $product_id);
            if ($item_id) {
                $wpdb->update(
                    $wpdb->prefix . 'woocommerce_order_itemmeta',
                    ['meta_value' => $color],
                    ['order_item_id' => $item_id, 'meta_key' => 'pa_farge']
                );
            }
        }

        foreach ($legTilNavs as $product_id => $value) {
            $item_id = get_order_item_id_by_product_id($order_id, $product_id);
            if ($item_id) {
                $wpdb->update(
                    $wpdb->prefix . 'woocommerce_order_itemmeta',
                    ['meta_value' => $value],
                    ['order_item_id' => $item_id, 'meta_key' => 'LeggTilNavn']
                );
            }
        }

        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

// Helper function to get order item ID by product ID
function get_order_item_id_by_product_id($order_id, $product_id) {
    global $wpdb;
    $result = $wpdb->get_var($wpdb->prepare("
        SELECT order_item_id
        FROM {$wpdb->prefix}woocommerce_order_items
        WHERE order_id = %d AND order_item_type = 'line_item'
    ", $order_id));

    return $result;
}

function getorderid($order_hash) {
    global $wpdb;
    $result = $wpdb->get_var($wpdb->prepare("
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = %d AND meta_value = %s
    ",  '_order_hash',$order_hash));

    return $result;
}