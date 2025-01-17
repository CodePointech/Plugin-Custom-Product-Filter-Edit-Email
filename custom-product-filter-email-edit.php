<?php
/*
Plugin Name: Custom Product Filter-Email-Edit
Description: Dynamically fetch and add filters by product variant attributes (size and color) to the WooCommerce Orders admin panel and send a email to customer to edit the product if it is out of stock.
Version: 2.0
Author: CP Technologies
*/
// Exit if accessed directly.
// Hook to add a custom filter dropdown to WooCommerce Orders page (admin.php?page=wc-orders)



require_once plugin_dir_path(__FILE__) . 'order-details-out-of-stock.php';
// Enqueue JavaScript for the popup
add_action('admin_enqueue_scripts', 'enqueue_bulk_action_popup_script');
function enqueue_bulk_action_popup_script() {
    wp_enqueue_script(
        'custom-bulk-action-popup',
        plugin_dir_url(__FILE__) . 'assets/custom-bulk-action-popup.js',
        array('jquery'),
        '2.0',
        true
    );

    // Localize script to pass data
    wp_localize_script('custom-bulk-action-popup', 'bulkActionData', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('send_bulk_emails')
    ));
}




add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'show_dropdown_filter', 5 );
function show_dropdown_filter() {

    $selected_product = isset($_GET['product_filter']) ? esc_attr($_GET['product_filter']) : '';
    $selected_color   = isset($_GET['color_filter']) ? esc_attr($_GET['color_filter']) : '';
    $selected_size    = isset($_GET['size_filter']) ? esc_attr($_GET['size_filter']) : '';
    
    echo '<select name="product_filter" id="dropdown_shop_order_product" class="select">';
    echo '<option value="">' . __('All Products', 'woocommerce') . '</option>';
    $products = wc_get_products(['limit' => -1]);
    foreach ($products as $product) {
        printf('<option value="%s" %s>%s</option>', $product->get_id(), selected($selected_product, $product->get_id(), false), esc_html($product->get_name()));
    }
    echo '</select>';

    echo '<select name="color_filter" id="dropdown_shop_order_color" class="select">';
    echo '<option value="">' . __('All Colors', 'woocommerce') . '</option>';

    $colors = [];
    foreach ($products as $wc_product) {
        $attributes = $wc_product->get_attributes();
        foreach ($attributes as $taxonomy => $attribute) {
            if ($attribute->is_taxonomy() && $taxonomy === 'pa_farge') {
                $terms = wp_get_post_terms($wc_product->get_id(), $taxonomy, ['fields' => 'names']);
                foreach ($terms as $term) {
                    if (!in_array($term, $colors)) {
                        $colors[] = $term;
                    }
                }
            }
        }
    }
    foreach ($colors as $color) {
        printf('<option value="%s" %s>%s</option>', $color, selected($selected_color, $color, false), esc_html($color));
    }
    echo '</select>';

    echo '<select name="size_filter" id="dropdown_shop_order_size" class="select">';
    echo '<option value="">' . __('All Sizes', 'woocommerce') . '</option>';
    $sizes = [];
    foreach ($products as $wc_product) {
        $attributes = $wc_product->get_attributes();
        foreach ($attributes as $taxonomy => $attribute) {
            if ($attribute->is_taxonomy() && $taxonomy === 'pa_storrelse') {
                $terms = wp_get_post_terms($wc_product->get_id(), $taxonomy, ['fields' => 'names']);
                foreach ($terms as $term) {
                    if (!in_array($term, $sizes)) {
                        $sizes[] = $term;
                    }
                }
            }
        }
    }
    foreach ($sizes as $size) {
        printf('<option value="%s" %s>%s</option>', $size, selected($selected_size, $size, false), esc_html($size));
    }
    echo '</select>';
}

add_filter('woocommerce_orders_table_query_clauses', 'filter_woocommerce_orders_table_by_attributes', 10, 2);
function filter_woocommerce_orders_table_by_attributes($clauses, $query) {
    global $wpdb;

    $color_filter = isset($_GET['color_filter']) ? sanitize_text_field($_GET['color_filter']) : '';
    $size_filter  = isset($_GET['size_filter']) ? sanitize_text_field($_GET['size_filter']) : '';
    $product_filter = isset($_GET['product_filter']) ? intval($_GET['product_filter']) : '';

    if ($color_filter || $size_filter || $product_filter) {

        // Filter by Product
        if ($product_filter) {
            $clauses['where'] .= $wpdb->prepare(
                "
                AND %d IN (
                    SELECT itemmeta.meta_value
                    FROM {$wpdb->prefix}woocommerce_order_items as items
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as itemmeta
                    ON itemmeta.order_item_id = items.order_item_id
                    WHERE items.order_item_type = 'line_item'
                    AND itemmeta.meta_key = '_product_id'
                    AND {$wpdb->prefix}wc_orders.id = items.order_id
                )
                ",
                $product_filter
            );
        }

        // Filter by Color
        if ($color_filter) {
            $clauses['where'] .= $wpdb->prepare(
                "
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}woocommerce_order_items as color
                    WHERE color.order_item_type = 'line_item'
                    AND color.order_item_name LIKE %s
                    AND color.order_id = {$wpdb->prefix}wc_orders.id
                )
                ",
                '%' . $wpdb->esc_like($color_filter) . '%'
            );
        }

        // Filter by Size
        if ($size_filter) {
            $clauses['where'] .= $wpdb->prepare(
                "
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}woocommerce_order_items as size
                    WHERE size.order_item_type = 'line_item'
                    AND size.order_item_name LIKE %s
                    AND size.order_id = {$wpdb->prefix}wc_orders.id
                )
                ",
                '%' . $wpdb->esc_like($size_filter) . '%'
            );
        }
    }

    return $clauses;
}

// Add the custom action to the dropdown
add_filter('bulk_actions-woocommerce_page_wc-orders', 'add_out_of_stock_email_sent_bulk_action');
function add_out_of_stock_email_sent_bulk_action($bulk_actions) {
    $bulk_actions['out_of_stock_email_sent'] = __('Out Of Stock Email Sent', 'your-plugin-textdomain');
    return $bulk_actions;
}

// Handle the custom action
// add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'handle_out_of_stock_email_sent_bulk_action', 10, 3);
// function handle_out_of_stock_email_sent_bulk_action($redirect_url, $action, $order_ids) {
//     if ($action !== 'out_of_stock_email_sent') {
//         return $redirect_url;
//     }

//     foreach ($order_ids as $order_id) {
//         // Custom logic for sending email
//         $order = wc_get_order($order_id);
//         if ($order) {
//             // Replace this with your email-sending logic
//             // wp_mail(
//             //     $order->get_billing_email(),
//             //     __('Your Order Status', 'your-plugin-textdomain'),
//             //     __('An email has been sent regarding your order.', 'your-plugin-textdomain')
//             // );

//             // // Optionally, add a custom order note
//             // $order->add_order_note(__('Email sent to the customer.', 'your-plugin-textdomain'));
//         }
//     }

//     // Add a query parameter to the redirect URL for feedback
//     $redirect_url = add_query_arg('email_sent_count', count($order_ids), $redirect_url);
//     return $redirect_url;
// }


// Handle the AJAX request
add_action('wp_ajax_send_bulk_emails', 'handle_bulk_email_sending');
function handle_bulk_email_sending() {
    check_ajax_referer('send_bulk_emails', 'nonce');

    $order_ids = isset($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : array();
    $email_content = sanitize_textarea_field($_POST['email_content']);

    if (empty($order_ids) || empty($email_content)) {
        wp_send_json_error(array('message' => 'Order IDs or email content is missing.'));
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $billing_email = $order->get_billing_email();

            if ($billing_email) {
                // Generate a unique order hash using order_id and some other unique attributes
                $order_hash = md5($order_id . $order->get_date_created()->getTimestamp() . $order->get_total());

                // Save the order_hash in the order meta
                update_post_meta($order_id, '_order_hash', $order_hash);

                // Add the order-edit link with the order_hash parameter
                $order_link = esc_url(
                    add_query_arg(
                        ['order_hash' => $order_hash],
                        home_url('/order-edit')
                    )
                );

                $email_body = $email_content . '<br><br>';
                $email_body .= '<div style="height: 70px;margin-top: 20px;">
                <a href="' . $order_link . '" style="
                    border: 2px solid black;
                    background-color: white;
                    color: black;
                    padding: 10px 20px;
                    font-size: 16px;
                    border-radius: 20px;
                    margin-bottom: 20px;
                    cursor: pointer;
                    text-decoration: none;
                ">Endre ordre</a></div>';

                // Send the email
                $email_subject = sprintf('Ordre %d - %s', $order->get_id(), get_bloginfo('name'));

                wp_mail(
                    $billing_email,
                    $email_subject,
                    $email_body,
                    ['Content-Type: text/html; charset=UTF-8']
                );

                // Add order note
                $order->add_order_note(__('Out of stock email sent to the customer.', 'your-plugin-textdomain'));
            }
        }
    }

    wp_send_json_success(array('message' => 'Emails sent successfully.'));
}


// add_action( 'woocommerce_email_footer', 'add_custom_order_button_to_order_confirmation', 10, 1 );

// function add_custom_order_button_to_order_confirmation( $email ) {
//     if ($email instanceof WC_Email && $email->id === 'customer_processing_order') {
//         $order = $email->object;
//         $order_id = $order->get_id();

//         $order_hash = md5($order_id . $order->get_date_created()->getTimestamp() . $order->get_total());
//         update_post_meta($order_id, '_order_hash', $order_hash);

//         $order_link = esc_url(
//             add_query_arg(
//                 ['order_hash' => $order_hash],
//                 home_url('/order-edit')
//             )
//         );

//         $button_html = '<a href="' . $order_link . '" style="border: 2px solid black; background-color: white; color: black; padding: 10px 20px; font-size: 16px; border-radius: 20px; margin-bottom: 20px; cursor: pointer; text-decoration: none;">Endre ordre</a>';
//         $email->email_message .= $button_html;
//     }
// }







