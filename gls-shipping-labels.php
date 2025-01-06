<?php
/**
 * Plugin Name: GLS Shipping Labels
 * Plugin URI: bencecodes.co.uk
 * Description: GLS címke generálás WooCommerce rendelésekhez
 * Version: 1.0.0
 * Author: Bence Szorgalmatos
 * Author URI: 
 * Text Domain: gls-shipping-labels
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin konstansok definiálása
define('GLS_LABELS_VERSION', '1.0.0');
define('GLS_LABELS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GLS_LABELS_PLUGIN_URL', plugin_dir_url(__FILE__));

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

// Plugin főosztály
class GLS_Shipping_Labels {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($this, 'add_gls_label_meta_box'));
        add_action('wp_ajax_generate_gls_label', array($this, 'generate_gls_label'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function enqueue_admin_scripts($hook) {
        if ('post.php' !== $hook || 'shop_order' !== get_post_type()) {
            return;
        }

        wp_enqueue_script('gls-labels-admin', 
            GLS_LABELS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            GLS_LABELS_VERSION,
            true
        );

        wp_localize_script('gls-labels-admin', 'glsLabelsAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('generate_gls_label_nonce')
        ));
    }

    public function add_gls_label_meta_box() {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'gls_shipping_label',
            __('GLS Címke Generálás', 'gls-shipping-labels'),
            array($this, 'render_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    public function render_meta_box($object) {
        $order = is_a($object, 'WP_Post') ? wc_get_order($object->ID) : $object;

        if (!$order) {
            return;
        }

        $label_url = get_post_meta($order->get_id(), '_gls_label_url', true);
        $tracking_number = get_post_meta($order->get_id(), '_gls_tracking_number', true);
        ?>
        <div class="gls-label-generator">
            <?php if ($label_url && $tracking_number): ?>
                <p>
                    <strong>Tracking szám:</strong> <?php echo esc_html($tracking_number); ?>
                </p>
                <a href="<?php echo esc_url($label_url); ?>" target="_blank" class="button">
                    <?php _e('Címke letöltése', 'gls-shipping-labels'); ?>
                </a>
                <hr>
            <?php endif; ?>
            
            <button type="button" class="button button-primary" id="generate-gls-label" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                <?php _e('Új címke generálása', 'gls-shipping-labels'); ?>
            </button>
            <div id="gls-label-status"></div>
        </div>
        <?php
    }

    public function generate_gls_label() {
        check_ajax_referer('generate_gls_label_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('Nincs jogosultsága!');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error('Érvénytelen rendelés azonosító!');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('A rendelés nem található!');
        }

        // Itt implementáljuk a GLS API hívást
        $result = $this->create_gls_label($order);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Címke sikeresen legenerálva!',
            'pdf_url' => $result['pdf_url']
        ));
    }

    private function create_gls_label($order) {
        $api_username = get_option('gls_api_username');
        $api_password = get_option('gls_api_password');
        $api_url = get_option('gls_api_url');
        
        if (!$api_username || !$api_password || !$api_url) {
            return new WP_Error('gls_config_missing', 'GLS API beállítások hiányoznak');
        }

        // HPOS kompatibilitás: Ellenőrizzük, hogy az $order egy érvényes rendelés objektum-e
        if (!is_a($order, 'WC_Order')) {
            return new WP_Error('invalid_order', 'Érvénytelen rendelés');
        }

        $shipping_data = array(
            'consignee' => array(
                'name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'address' => $order->get_shipping_address_1(),
                'city' => $order->get_shipping_city(),
                'zipcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
                'phone' => $order->get_billing_phone(),
                'email' => $order->get_billing_email()
            ),
            'parcels' => array(
                array(
                    'weight' => $this->get_order_weight($order),
                    'reference' => $order->get_order_number()
                )
            ),
            'sender' => array(
                'id' => get_option('gls_sender_id')
            )
        );

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_username . ':' . $api_password),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($shipping_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['pdf_url'])) {
            return new WP_Error('gls_api_error', 'Hiba a címke generálása során');
        }

        // Mentsük el a címke adatait a rendeléshez
        update_post_meta($order->get_id(), '_gls_label_url', $body['pdf_url']);
        update_post_meta($order->get_id(), '_gls_tracking_number', $body['tracking_number']);

        return $body;
    }

    private function get_order_weight($order) {
        $weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_weight()) {
                $weight += $product->get_weight() * $item->get_quantity();
            }
        }
        return $weight ? $weight : 1; // Alapértelmezett súly, ha nincs megadva
    }

    public function add_admin_menu() {
        add_options_page(
            'GLS Címke Beállítások',
            'GLS Címke',
            'manage_options',
            'gls-labels-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('gls_labels_settings', 'gls_api_username');
        register_setting('gls_labels_settings', 'gls_api_password');
        register_setting('gls_labels_settings', 'gls_api_url');
        register_setting('gls_labels_settings', 'gls_sender_id');
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('gls_labels_settings');
                do_settings_sections('gls_labels_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">GLS API Felhasználónév</th>
                        <td>
                            <input type="text" name="gls_api_username" 
                                   value="<?php echo esc_attr(get_option('gls_api_username')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GLS API Jelszó</th>
                        <td>
                            <input type="password" name="gls_api_password" 
                                   value="<?php echo esc_attr(get_option('gls_api_password')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GLS API URL</th>
                        <td>
                            <input type="url" name="gls_api_url" 
                                   value="<?php echo esc_attr(get_option('gls_api_url')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GLS Feladó ID</th>
                        <td>
                            <input type="text" name="gls_sender_id" 
                                   value="<?php echo esc_attr(get_option('gls_sender_id')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Plugin inicializálása
function GLS_Shipping_Labels() {
    return GLS_Shipping_Labels::get_instance();
}

add_action('plugins_loaded', 'GLS_Shipping_Labels'); 