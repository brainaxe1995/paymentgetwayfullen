<?php
/**
 * WooCommerce High-Converting Checkout Functions with Native Meta Pixel Integration and Mollie Support
 * Add this to your theme's functions.php file
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CONFIGURATION: Add your checkout page templates here
 */
function get_custom_checkout_templates() {
    return array(
        'page-checkout.php',
        'checkout-oxycodone.php',
        'viagra-checkout.php',
        'party-checkout.php',
    );
}

// Add AJAX handlers for checkout processing
add_action('wp_ajax_process_checkout_ajax', 'handle_ajax_checkout_processing');
add_action('wp_ajax_nopriv_process_checkout_ajax', 'handle_ajax_checkout_processing');

function handle_ajax_checkout_processing() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['woocommerce-process-checkout-nonce'], 'woocommerce-process_checkout')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }

    // Ensure WooCommerce is loaded
    if (!class_exists('WooCommerce')) {
        wp_send_json_error(array('message' => 'WooCommerce is not available'));
        return;
    }

    // Initialize WooCommerce components
    if (!WC()->cart) {
        WC()->initialize_cart();
    }
    if (!WC()->customer) {
        WC()->initialize_customer();
    }
    if (!WC()->session) {
        WC()->initialize_session();
    }

    // Validate required fields
    $errors = array();
    $required_fields = array(
        'billing_first_name' => 'First name is required',
        'billing_last_name' => 'Last name is required',
        'billing_email' => 'Email address is required',
        'billing_phone' => 'Phone number is required',
        'billing_address_1' => 'Address is required',
        'billing_city' => 'City is required',
        'billing_postcode' => 'Postal code is required',
        'billing_country' => 'Country is required',
        'payment_method' => 'Payment method is required'
    );

    foreach ($required_fields as $field => $message) {
        if (empty($_POST[$field])) {
            $errors[$field] = $message;
        }
    }

    // Email validation
    if (!empty($_POST['billing_email']) && !is_email($_POST['billing_email'])) {
        $errors['billing_email'] = 'Please enter a valid email address';
    }

    // Validate payment method exists and is available
    $payment_method = sanitize_text_field($_POST['payment_method']);
    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
    if (!isset($available_gateways[$payment_method])) {
        $errors['payment_method'] = 'Selected payment method is not available';
    } else {
        // Validate payment method specific fields - FIXED for Mollie
        $gateway = $available_gateways[$payment_method];
        
        // Set the payment method in the session before validation
        WC()->session->set('chosen_payment_method', $payment_method);
        
        // For Mollie, we need to validate fields differently
        if (method_exists($gateway, 'validate_fields')) {
            // Store POST data temporarily for gateway validation
            $original_post = $_POST;
            
            // Call validation and capture any errors
            ob_start();
            $gateway_validation = $gateway->validate_fields();
            $validation_output = ob_get_clean();
            
            if (!$gateway_validation) {
                // Check for WooCommerce notices (which Mollie uses for errors)
                $notices = wc_get_notices('error');
                if (!empty($notices)) {
                    $error_messages = array();
                    foreach ($notices as $notice) {
                        $error_messages[] = $notice['notice'];
                    }
                    $errors['payment_method'] = implode('; ', $error_messages);
                    wc_clear_notices(); // Clear notices after capturing them
                } else {
                    $errors['payment_method'] = 'Please fill in all required payment fields correctly';
                }
            }
        }
    }

    if (!empty($errors)) {
        wp_send_json_error(array('errors' => $errors));
        return;
    }

    // Process selected package if any
    if (isset($_POST['selected_package_id']) && !empty($_POST['selected_package_id'])) {
        $package_id = intval($_POST['selected_package_id']);
        $packages = get_order_bump_packages();
        if (isset($packages[$package_id])) {
            $package = $packages[$package_id];
            // Clear cart and add the selected package
            WC()->cart->empty_cart();
            $added = WC()->cart->add_to_cart($package['product_id'], $package['quantity']);
            if ($added) {
                // Store package info in session
                WC()->session->set('selected_package_id', $package_id);
                WC()->session->set('selected_package_data', $package);
                // Set custom price - FIXED: Apply price correctly
                add_action('woocommerce_before_calculate_totals', function($cart) use ($package) {
                    if (is_admin() && !defined('DOING_AJAX')) return;
                    foreach ($cart->get_cart() as $cart_item) {
                        $per_item_price = $package['price'] / $package['quantity'];
                        $cart_item['data']->set_price($per_item_price);
                    }
                }, 10, 1);
                WC()->cart->calculate_totals();
            }
        }
    } else {
        // Ensure cart has products
        if (WC()->cart->is_empty()) {
            $products = wc_get_products(array('limit' => 1, 'status' => 'publish'));
            if (!empty($products)) {
                WC()->cart->add_to_cart($products[0]->get_id(), 1);
                // Set the hardcoded fallback price
                add_action('woocommerce_before_calculate_totals', function($cart) {
                    if (is_admin() && !defined('DOING_AJAX')) return;
                    foreach ($cart->get_cart() as $cart_item) {
                        $cart_item['data']->set_price(2.49); // Hardcoded fallback price
                    }
                }, 10, 1);
                WC()->cart->calculate_totals();
            }
        }
    }

    // Initialize WooCommerce checkout
    $checkout = WC()->checkout();
    if (!$checkout) {
        wp_send_json_error(array('message' => 'Checkout initialization failed'));
        return;
    }

    // Set customer data
    $customer_data = array(
        'billing_first_name' => sanitize_text_field($_POST['billing_first_name']),
        'billing_last_name' => sanitize_text_field($_POST['billing_last_name']),
        'billing_email' => sanitize_email($_POST['billing_email']),
        'billing_phone' => sanitize_text_field($_POST['billing_phone']),
        'billing_address_1' => sanitize_text_field($_POST['billing_address_1']),
        'billing_city' => sanitize_text_field($_POST['billing_city']),
        'billing_postcode' => sanitize_text_field($_POST['billing_postcode']),
        'billing_country' => sanitize_text_field($_POST['billing_country']),
        'billing_state' => '',
    );

    // Set customer data
    foreach ($customer_data as $key => $value) {
        WC()->customer->{"set_$key"}($value);
    }

    // Set payment method
    WC()->session->set('chosen_payment_method', sanitize_text_field($_POST['payment_method']));

    try {
        // Create order
        $order_id = $checkout->create_order($_POST);
        if (is_wp_error($order_id)) {
            wp_send_json_error(array('message' => $order_id->get_error_message()));
            return;
        }

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Failed to create order'));
            return;
        }

        // Get the order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }

        // FIXED: Proper Mollie payment processing
        $gateway = $available_gateways[$payment_method];
        if ($gateway) {
            // Set the payment method on the order
            $order->set_payment_method($gateway->id);
            $order->set_payment_method_title($gateway->get_title());
            $order->save();

            // Process payment through the selected gateway - FIXED for Mollie
            try {
                $payment_result = $gateway->process_payment($order_id);
                
                // Log the payment result for debugging
                error_log('Payment result: ' . print_r($payment_result, true));
                
                if (isset($payment_result['result'])) {
                    if ($payment_result['result'] === 'success') {
                        // FIXED: Handle different types of success responses
                        if (isset($payment_result['redirect'])) {
                            // This is typical for Mollie - redirect to payment page
                            $order->update_status('pending', 'Awaiting payment via ' . $gateway->get_title());
                            
                            // Save package information to order meta before redirect
                            $package_id = WC()->session->get('selected_package_id');
                            if ($package_id) {
                                $packages = get_order_bump_packages();
                                if (isset($packages[$package_id])) {
                                    $package = $packages[$package_id];
                                    $order->update_meta_data('_selected_package_id', $package_id);
                                    $order->update_meta_data('_selected_package_title', $package['title']);
                                    $order->update_meta_data('_selected_package_quantity', $package['quantity']);
                                    $order->update_meta_data('_selected_package_price', $package['price']);
                                    $order->save();
                                    $order->add_order_note(sprintf(
                                        'Customer selected package: %s (Quantity: %d, Price: $%.2f)',
                                        $package['title'],
                                        $package['quantity'],
                                        $package['price']
                                    ));
                                }
                            }
                            
                            // Clear cart and session data
                            WC()->cart->empty_cart();
                            WC()->session->__unset('selected_package_id');
                            WC()->session->__unset('selected_package_data');
                            
                            // Return redirect URL for Mollie payment
                            wp_send_json_success(array(
                                'order_id' => $order_id,
                                'redirect_url' => $payment_result['redirect'],
                                'message' => 'Redirecting to payment...',
                                'payment_method' => $gateway->id
                            ));
                        } else {
                            // Direct payment completion (for non-redirect gateways)
                            $order->payment_complete();
                            $order->update_status('processing', 'Payment completed successfully.');
                            
                            // Save package information to order meta
                            $package_id = WC()->session->get('selected_package_id');
                            if ($package_id) {
                                $packages = get_order_bump_packages();
                                if (isset($packages[$package_id])) {
                                    $package = $packages[$package_id];
                                    $order->update_meta_data('_selected_package_id', $package_id);
                                    $order->update_meta_data('_selected_package_title', $package['title']);
                                    $order->update_meta_data('_selected_package_quantity', $package['quantity']);
                                    $order->update_meta_data('_selected_package_price', $package['price']);
                                    $order->save();
                                    $order->add_order_note(sprintf(
                                        'Customer selected package: %s (Quantity: %d, Price: $%.2f)',
                                        $package['title'],
                                        $package['quantity'],
                                        $package['price']
                                    ));
                                }
                            }
                            
                            // Clear cart and session data
                            WC()->cart->empty_cart();
                            WC()->session->__unset('selected_package_id');
                            WC()->session->__unset('selected_package_data');
                            
                            // Send success response with redirect URL
                            wp_send_json_success(array(
                                'order_id' => $order_id,
                                'redirect_url' => home_url('/checkout-success/?order_id=' . $order_id),
                                'message' => 'Order created successfully'
                            ));
                        }
                    } else {
                        // Payment failed
                        $error_message = 'Payment processing failed.';
                        if (isset($payment_result['messages'])) {
                            $error_message = $payment_result['messages'];
                        } elseif (isset($payment_result['message'])) {
                            $error_message = $payment_result['message'];
                        }
                        
                        $order->update_status('failed', 'Payment failed: ' . $error_message);
                        wp_send_json_error(array('message' => $error_message));
                        return;
                    }
                } else {
                    // No result provided
                    $order->update_status('failed', 'Payment gateway returned invalid response.');
                    wp_send_json_error(array('message' => 'Payment gateway error. Please try again.'));
                    return;
                }
            } catch (Exception $payment_exception) {
                error_log('Payment processing exception: ' . $payment_exception->getMessage());
                $order->update_status('failed', 'Payment exception: ' . $payment_exception->getMessage());
                wp_send_json_error(array('message' => 'Payment processing error: ' . $payment_exception->getMessage()));
                return;
            }
        } else {
            $order->update_status('pending', 'Awaiting payment.');
            wp_send_json_error(array('message' => 'Payment gateway not available.'));
            return;
        }

    } catch (Exception $e) {
        error_log('Checkout exception: ' . $e->getMessage());
        wp_send_json_error(array('message' => 'Checkout processing failed: ' . $e->getMessage()));
    }
}
function get_order_bump_packages() {
    if (function_exists("get_checkout_order_bump_packages")) {
        return get_checkout_order_bump_packages();
    }
    return array();
}



// Display package information in admin order details
add_action('woocommerce_admin_order_data_after_billing_address', 'display_package_info_in_admin');
function display_package_info_in_admin($order) {
    $package_id = $order->get_meta('_selected_package_id');
    $package_title = $order->get_meta('_selected_package_title');
    $package_quantity = $order->get_meta('_selected_package_quantity');
    $package_price = $order->get_meta('_selected_package_price');

    if ($package_id) {
        echo '<div class="order-data-column">';
        echo '<h3>Selected Package</h3>';
        echo '<p><strong>Package:</strong> ' . esc_html($package_title) . '</p>';
        echo '<p><strong>Quantity:</strong> ' . esc_html($package_quantity) . '</p>';
        echo '<p><strong>Price:</strong> $' . number_format($package_price, 2) . '</p>';
        echo '</div>';
    }
}

// Ensure proper WooCommerce initialization
add_action('wp_loaded', 'ensure_woocommerce_loaded');
function ensure_woocommerce_loaded() {
    if (class_exists('WooCommerce')) {
        WC();
        if (is_null(WC()->cart)) {
            WC()->initialize_cart();
        }
        if (is_null(WC()->customer)) {
            WC()->initialize_customer();
        }
        if (is_null(WC()->session)) {
            WC()->initialize_session();
        }
    }
}

// Helper function to check if current page is using any custom checkout template
function is_custom_checkout_page() {
    $checkout_templates = get_custom_checkout_templates();
    foreach ($checkout_templates as $template) {
        if (is_page_template($template)) {
            return true;
        }
    }
    return false;
}

// Add support for multiple custom checkout templates
add_filter('template_include', 'custom_checkout_template');
function custom_checkout_template($template) {
    if (is_page()) {
        $page_template = get_page_template_slug();
        $checkout_templates = get_custom_checkout_templates();
        if (in_array($page_template, $checkout_templates)) {
            $custom_template = locate_template($page_template);
            if ($custom_template) {
                return $custom_template;
            }
        }
    }
    return $template;
}

// Force WooCommerce to recognize all custom checkout pages
add_filter('woocommerce_is_checkout', 'custom_is_checkout_page');
function custom_is_checkout_page($is_checkout) {
    if (is_custom_checkout_page()) {
        return true;
    }
    return $is_checkout;
}

// Enqueue WooCommerce scripts on all custom checkout pages
add_action('wp_enqueue_scripts', 'enqueue_custom_checkout_scripts');
function enqueue_custom_checkout_scripts() {
    if (is_custom_checkout_page()) {
        wp_enqueue_script('wc-checkout');
        wp_enqueue_script('wc-country-select');
        wp_enqueue_script('wc-address-i18n');
    }
}

// Enable default payment gateways if none are configured
add_action('init', 'enable_default_payment_gateways');
function enable_default_payment_gateways() {
    if (class_exists('WooCommerce')) {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (empty($available_gateways)) {
            update_option('woocommerce_bacs_settings', array(
                'enabled' => 'yes',
                'title' => 'Direct bank transfer',
                'description' => 'Make your payment directly into our bank account.',
                'instructions' => 'Please use your Order ID as the payment reference.'
            ));
            update_option('woocommerce_cod_settings', array(
                'enabled' => 'yes',
                'title' => 'Cash on delivery',
                'description' => 'Pay with cash upon delivery.',
                'instructions' => 'Pay with cash when your order is delivered.'
            ));
        }
    }
}

// Enable payment gateways for all custom checkout pages
add_filter('woocommerce_available_payment_gateways', 'ensure_payment_gateways_available');
function ensure_payment_gateways_available($gateways) {
    if (is_custom_checkout_page()) {
        $all_gateways = WC()->payment_gateways->payment_gateways();
        if (empty($gateways)) {
            if (isset($all_gateways['bacs'])) {
                $all_gateways['bacs']->enabled = 'yes';
                $gateways['bacs'] = $all_gateways['bacs'];
            }
            if (isset($all_gateways['cod'])) {
                $all_gateways['cod']->enabled = 'yes';
                $gateways['cod'] = $all_gateways['cod'];
            }
        }
    }
    return $gateways;
}

// Ensure cart has products when accessing any custom checkout page
add_action('template_redirect', 'ensure_checkout_has_products');
function ensure_checkout_has_products() {
    if (!is_custom_checkout_page()) {
        return;
    }
    if (WC()->cart->is_empty()) {
        $products = wc_get_products(array('limit' => 1, 'status' => 'publish'));
        if (!empty($products)) {
            WC()->cart->add_to_cart($products[0]->get_id(), 1);
            // Set the hardcoded fallback price
            add_action('woocommerce_before_calculate_totals', function($cart) {
                if (is_admin() && !defined('DOING_AJAX')) return;
                foreach ($cart->get_cart() as $cart_item) {
                    $cart_item['data']->set_price(2.49); // Hardcoded fallback price
                }
            }, 10, 1);
            WC()->cart->calculate_totals();
        }
    }
}

// Apply custom package pricing
add_action('woocommerce_before_calculate_totals', 'apply_custom_package_pricing');
function apply_custom_package_pricing($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    $package_data = WC()->session->get('selected_package_data');
    if ($package_data && isset($package_data['price'])) {
        foreach ($cart->get_cart() as $cart_item) {
            $per_item_price = $package_data['price'] / $package_data['quantity'];
            $cart_item['data']->set_price($per_item_price);
        }
    }
}

// Log successful order creation
add_action('woocommerce_checkout_order_processed', 'log_successful_order_creation', 5, 3);
function log_successful_order_creation($order_id, $posted_data, $order) {
    error_log('=== ORDER CREATED SUCCESSFULLY ===');
    error_log('Order ID: ' . $order_id);
    error_log('Order Status: ' . $order->get_status());
    error_log('Order Total: ' . $order->get_total());
    error_log('Customer Email: ' . $order->get_billing_email());
    error_log('Payment Method: ' . $order->get_payment_method());
    error_log('=== ORDER CREATION END ===');
}

// Custom checkout processing validation
add_action('woocommerce_checkout_process', 'custom_checkout_validation');
function custom_checkout_validation() {
    if (empty($_POST['billing_first_name'])) {
        wc_add_notice('First name is required.', 'error');
    }
    if (empty($_POST['billing_last_name'])) {
        wc_add_notice('Last name is required.', 'error');
    }
    if (empty($_POST['billing_email']) || !is_email($_POST['billing_email'])) {
        wc_add_notice('Please enter a valid email address.', 'error');
    }
}

// Disable default WooCommerce checkout redirect
remove_action('template_redirect', 'wc_send_frame_options_header');
remove_action('template_redirect', 'wc_checkout_redirect');

// Add custom order processing for package pricing
add_action('woocommerce_checkout_process', 'process_selected_package_if_chosen');
function process_selected_package_if_chosen() {
    if (isset($_POST['selected_package_id']) && !empty($_POST['selected_package_id'])) {
        $package_id = intval($_POST['selected_package_id']);
        WC()->session->set('selected_package_id', $package_id);
        $packages = get_order_bump_packages();
        if (isset($packages[$package_id])) {
            $package = $packages[$package_id];
            WC()->cart->empty_cart();
            $cart_item_key = WC()->cart->add_to_cart(
                $package['product_id'],
                $package['quantity']
            );
            if ($cart_item_key) {
                add_action('woocommerce_before_calculate_totals', function($cart) use ($package, $cart_item_key) {
                    if (is_admin() && !defined('DOING_AJAX')) return;
                    foreach ($cart->get_cart() as $key => $cart_item) {
                        if ($key === $cart_item_key) {
                            $per_item_price = $package['price'] / $package['quantity'];
                            $cart_item['data']->set_price($per_item_price);
                        }
                    }
                });
            }
        }
    }
}

// Save selected package to order
add_action('woocommerce_checkout_order_processed', 'save_selected_package_to_order', 10, 3);
function save_selected_package_to_order($order_id, $posted_data, $order) {
    $package_id = WC()->session->get('selected_package_id');
    if ($package_id) {
        $packages = get_order_bump_packages();
        if (isset($packages[$package_id])) {
            $package = $packages[$package_id];
            $order->update_meta_data('_selected_package_id', $package_id);
            $order->update_meta_data('_selected_package_title', $package['title']);
            $order->update_meta_data('_selected_package_quantity', $package['quantity']);
            $order->update_meta_data('_selected_package_price', $package['price']);
            $order->save();
            $order->add_order_note(sprintf(
                'Customer selected package: %s (Quantity: %d, Price: $%.2f)',
                $package['title'],
                $package['quantity'],
                $package['price']
            ));
        }
        WC()->session->__unset('selected_package_id');
    }
}

// Setup WooCommerce checkout
add_action('init', 'setup_woocommerce_checkout');
function setup_woocommerce_checkout() {
    if (!is_admin() && class_exists('WooCommerce')) {
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        if (is_null(WC()->cart)) {
            WC()->initialize_cart();
        }
    }
}

// META PIXEL INTEGRATION - Native WooCommerce Events

// Add Meta Pixel base code
add_action('wp_head', 'add_meta_pixel_base_code');
function add_meta_pixel_base_code() {
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    ?>
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?php echo esc_js($pixel_id); ?>');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=<?php echo esc_attr($pixel_id); ?>&ev=PageView&noscript=1"
    /></noscript>
    <?php
}

// InitiateCheckout - Fire on checkout page load using WooCommerce hook
add_action('woocommerce_before_checkout_form', 'fire_initiate_checkout_event');
function fire_initiate_checkout_event() {
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id) || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    
    $cart_total = WC()->cart->get_total('edit');
    $currency = get_woocommerce_currency();
    $cart_contents = array();
    
    foreach (WC()->cart->get_cart() as $cart_item) {
        $product = $cart_item['data'];
        $cart_contents[] = array(
            'content_id' => $cart_item['product_id'],
            'content_name' => $product->get_name(),
            'quantity' => $cart_item['quantity'],
            'item_price' => $product->get_price()
        );
    }
    ?>
    <script>
    if (typeof fbq !== 'undefined') {
        fbq('track', 'InitiateCheckout', {
            value: <?php echo json_encode(floatval($cart_total)); ?>,
            currency: '<?php echo esc_js($currency); ?>',
            contents: <?php echo json_encode($cart_contents); ?>,
            content_type: 'product',
            num_items: <?php echo WC()->cart->get_cart_contents_count(); ?>
        });
    }
    </script>
    <?php
}

// AddToCart - Fire using WooCommerce native hook
add_action('woocommerce_add_to_cart', 'fire_add_to_cart_pixel_event', 10, 6);
function fire_add_to_cart_pixel_event($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }
    
    $currency = get_woocommerce_currency();
    $product_price = $product->get_price();
    $total_value = $product_price * $quantity;
    
    // Store event data in session to fire on next page load
    $add_to_cart_data = array(
        'content_id' => $product_id,
        'content_name' => $product->get_name(),
        'quantity' => $quantity,
        'value' => $total_value,
        'currency' => $currency,
        'timestamp' => time()
    );
    
    $existing_events = WC()->session->get('pending_pixel_events', array());
    $existing_events[] = array(
        'event' => 'AddToCart',
        'data' => $add_to_cart_data
    );
    WC()->session->set('pending_pixel_events', $existing_events);
}

// AddPaymentInfo - Fire when payment method is selected
add_action('wp_footer', 'add_payment_info_event_script');
function add_payment_info_event_script() {
    if (!is_checkout() && !is_custom_checkout_page()) {
        return;
    }
    
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id) || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    
    $cart_total = WC()->cart->get_total('edit');
    $currency = get_woocommerce_currency();
    ?>
    <script>
    if (typeof fbq !== 'undefined') {
        document.addEventListener('change', function(e) {
            if (e.target.name === 'payment_method') {
                fbq('track', 'AddPaymentInfo', {
                    value: <?php echo json_encode(floatval($cart_total)); ?>,
                    currency: '<?php echo esc_js($currency); ?>'
                });
            }
        });
    }
    </script>
    <?php
}

// Purchase - Fire on order completion using WooCommerce native hook
add_action('woocommerce_thankyou', 'fire_purchase_pixel_event', 10, 1);
function fire_purchase_pixel_event($order_id) {
    if (!$order_id) {
        return;
    }
    
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    $purchase_data = array(
        'value' => $order->get_total(),
        'currency' => $order->get_currency(),
        'contents' => array(),
        'content_type' => 'product'
    );
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product) {
            $purchase_data['contents'][] = array(
                'content_id' => $product->get_id(),
                'content_name' => $product->get_name(),
                'quantity' => $item->get_quantity(),
                'item_price' => $item->get_total() / $item->get_quantity()
            );
        }
    }
    
    ?>
    <script>
    if (typeof fbq !== 'undefined') {
        fbq('track', 'Purchase', {
            value: <?php echo json_encode(floatval($purchase_data['value'])); ?>,
            currency: '<?php echo esc_js($purchase_data['currency']); ?>',
            contents: <?php echo json_encode($purchase_data['contents']); ?>,
            content_type: 'product'
        });
        
        <?php if (!is_user_logged_in() || $order->get_customer_id() === 0): ?>
        fbq('track', 'CompleteRegistration');
        <?php endif; ?>
        
        fbq('track', 'Lead');
    }
    </script>
    <?php
}

// ViewContent - Fire on product pages using WooCommerce hook
add_action('woocommerce_single_product_summary', 'fire_view_content_pixel_event', 5);
function fire_view_content_pixel_event() {
    global $product;
    
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id) || !$product) {
        return;
    }
    
    $currency = get_woocommerce_currency();
    ?>
    <script>
    if (typeof fbq !== 'undefined') {
        fbq('track', 'ViewContent', {
            content_ids: ['<?php echo $product->get_id(); ?>'],
            content_name: '<?php echo esc_js($product->get_name()); ?>',
            content_category: '<?php echo esc_js(wp_strip_all_tags(wc_get_product_category_list($product->get_id()))); ?>',
            value: <?php echo json_encode(floatval($product->get_price())); ?>,
            currency: '<?php echo esc_js($currency); ?>',
            content_type: 'product'
        });
    }
    </script>
    <?php
}

// ViewContent - Fire on checkout pages using wp_footer hook
add_action('wp_footer', 'fire_checkout_view_content_event');
function fire_checkout_view_content_event() {
    if (!is_checkout() && !is_custom_checkout_page()) {
        return;
    }
    
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    
    ?>
    <script>
    if (typeof fbq !== 'undefined') {
        fbq('track', 'ViewContent', {
            content_type: 'product',
            content_category: 'checkout'
        });
    }
    </script>
    <?php
}

// ViewContent - Fire on success page using wp_footer hook
add_action('wp_footer', 'fire_success_view_content_event');
function fire_success_view_content_event() {
    if (!is_page_template('page-checkout-success.php')) {
        return;
    }
    
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    
    ?>
    <script>
    if (typeof fbq !== 'undefined') {
        fbq('track', 'ViewContent', {
            content_type: 'product',
            content_category: 'checkout_success'
        });
    }
    </script>
    <?php
}

// Fire pending pixel events on page load
add_action('wp_footer', 'fire_pending_pixel_events');
function fire_pending_pixel_events() {
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    
    $pending_events = WC()->session->get('pending_pixel_events', array());
    if (empty($pending_events)) {
        return;
    }
    
    WC()->session->__unset('pending_pixel_events');
    
    ?>
    <script>
    <?php foreach ($pending_events as $event): ?>
    if (typeof fbq !== 'undefined') {
        fbq('track', '<?php echo esc_js($event['event']); ?>', {
            content_id: '<?php echo esc_js($event['data']['content_id']); ?>',
            content_name: '<?php echo esc_js($event['data']['content_name']); ?>',
            quantity: <?php echo intval($event['data']['quantity']); ?>,
            value: <?php echo floatval($event['data']['value']); ?>,
            currency: '<?php echo esc_js($event['data']['currency']); ?>',
            content_type: 'product'
        });
    }
    <?php endforeach; ?>
    </script>
    <?php
}

// Custom AddToCart function for package selection
add_action('wp_footer', 'add_custom_addtocart_function');
function add_custom_addtocart_function() {
    if (!is_custom_checkout_page()) {
        return;
    }
    
    $pixel_id = get_option('facebook_pixel_id', '');
    if (empty($pixel_id)) {
        return;
    }
    
    $currency = get_woocommerce_currency();
    ?>
    <script>
    window.fireAddToCartEvent = function(packageData) {
        if (typeof fbq !== 'undefined') {
            fbq('track', 'AddToCart', {
                value: packageData.price,
                currency: '<?php echo esc_js($currency); ?>',
                contents: [{
                    content_id: packageData.product_id,
                    content_name: packageData.title,
                    quantity: packageData.quantity,
                    item_price: packageData.price / packageData.quantity
                }],
                content_type: 'product'
            });
        }
    };
    
    window.trackPackageSelection = function(packageId) {
        var formData = new FormData();
        formData.append('action', 'track_package_selection');
        formData.append('package_id', packageId);
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('Package selection tracked:', data);
        })
        .catch(error => {
            console.error('Error tracking package selection:', error);
        });
    };
    </script>
    <?php
}

// Server-side tracking for package selection
add_action('wp_ajax_track_package_selection', 'handle_package_selection_tracking');
add_action('wp_ajax_nopriv_track_package_selection', 'handle_package_selection_tracking');
function handle_package_selection_tracking() {
    $package_id = intval($_POST['package_id']);
    $packages = get_order_bump_packages();
    
    if (!isset($packages[$package_id])) {
        wp_send_json_error('Invalid package');
        return;
    }
    
    $package = $packages[$package_id];
    
    // Fire server-side AddToCart event
    $event_data = array(
        'content_id' => $package['product_id'],
        'content_name' => $package['title'],
        'quantity' => $package['quantity'],
        'value' => $package['price'],
        'currency' => get_woocommerce_currency()
    );
    
    fire_server_side_pixel_event('AddToCart', $event_data);
    
    wp_send_json_success(array(
        'message' => 'Package selection tracked',
        'package' => $package
    ));
}

// Server-side Conversions API integration
function fire_server_side_pixel_event($event_name, $event_data) {
    $pixel_id = get_option('facebook_pixel_id', '');
    $access_token = get_option('facebook_conversions_api_token', '');
    
    if (empty($pixel_id) || empty($access_token)) {
        return;
    }
    
    $payload = array(
        'data' => array(
            array(
                'event_name' => $event_name,
                'event_time' => time(),
                'event_source_url' => home_url($_SERVER['REQUEST_URI']),
                'user_data' => array(
                    'client_ip_address' => $_SERVER['REMOTE_ADDR'],
                    'client_user_agent' => $_SERVER['HTTP_USER_AGENT']
                ),
                'custom_data' => $event_data
            )
        )
    );
    
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $payload['data'][0]['user_data']['em'] = hash('sha256', strtolower($user->user_email));
    }
    
    wp_remote_post("https://graph.facebook.com/v18.0/{$pixel_id}/events", array(
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($payload),
        'timeout' => 30,
        'sslverify' => true,
        'method' => 'POST',
        'data_format' => 'body'
    ));
}

// Add settings for Facebook Pixel configuration
add_action('admin_menu', 'add_facebook_pixel_settings_page');
function add_facebook_pixel_settings_page() {
    add_options_page(
        'Facebook Pixel Settings',
        'Facebook Pixel',
        'manage_options',
        'facebook-pixel-settings',
        'facebook_pixel_settings_page'
    );
}

function facebook_pixel_settings_page() {
    if (isset($_POST['submit'])) {
        update_option('facebook_pixel_id', sanitize_text_field($_POST['facebook_pixel_id']));
        update_option('facebook_conversions_api_token', sanitize_text_field($_POST['facebook_conversions_api_token']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $pixel_id = get_option('facebook_pixel_id', '');
    $api_token = get_option('facebook_conversions_api_token', '');
    ?>
    <div class="wrap">
        <h1>Facebook Pixel Settings</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row">Facebook Pixel ID</th>
                    <td>
                        <input type="text" name="facebook_pixel_id" value="<?php echo esc_attr($pixel_id); ?>" class="regular-text" />
                        <p class="description">Enter your Facebook Pixel ID</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Conversions API Access Token</th>
                    <td>
                        <input type="text" name="facebook_conversions_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" />
                        <p class="description">Enter your Facebook Conversions API access token for server-side tracking</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// FIXED: Ensure all payment methods redirect to checkout-success page
add_action('woocommerce_thankyou', 'ensure_checkout_success_redirect', 1);
function ensure_checkout_success_redirect($order_id) {
    if (!$order_id) {
        return;
    }
    
    // Check if we're already on the success page
    if (is_page_template('page-checkout-success.php')) {
        return;
    }
    
    // Redirect to our custom success page
    $success_url = home_url('/checkout-success/?order_id=' . $order_id);
    
    // Only redirect if not already redirected
    if (!headers_sent()) {
        wp_redirect($success_url);
        exit;
    }
}

// FIXED: Handle Mollie webhook and redirect properly
add_action('woocommerce_order_status_changed', 'handle_mollie_payment_completion', 10, 4);
function handle_mollie_payment_completion($order_id, $old_status, $new_status, $order) {
    // Only handle paid orders
    if ($new_status !== 'processing' && $new_status !== 'completed') {
        return;
    }
    
    // Check if this is a Mollie payment
    $payment_method = $order->get_payment_method();
    if (strpos($payment_method, 'mollie') === false) {
        return;
    }
    
    // Fire purchase pixel event for Mollie payments
    fire_purchase_pixel_event($order_id);
    
    // Log successful Mollie payment
    error_log('Mollie payment completed for order: ' . $order_id);
}

// FIXED: Override WooCommerce default success page redirect
add_filter('woocommerce_get_checkout_order_received_url', 'custom_checkout_success_url', 10, 2);
function custom_checkout_success_url($order_received_url, $order) {
    return home_url('/checkout-success/?order_id=' . $order->get_id());
}

// FIXED: Ensure proper cart pricing for all payment methods
add_action('woocommerce_checkout_create_order_line_item', 'set_custom_line_item_price', 10, 4);
function set_custom_line_item_price($item, $cart_item_key, $values, $order) {
    // Check if we have a selected package
    $package_data = WC()->session->get('selected_package_data');
    if ($package_data && isset($package_data['price'])) {
        $per_item_price = $package_data['price'] / $package_data['quantity'];
        $item->set_subtotal($per_item_price * $values['quantity']);
        $item->set_total($per_item_price * $values['quantity']);
    }
}
?>