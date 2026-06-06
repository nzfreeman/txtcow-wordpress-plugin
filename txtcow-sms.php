<?php
/**
 * Plugin Name: TxtCow SMS Gateway
 * Plugin URI: https://txtcow.com
 * Description: WooCommerce integration for TxtCow SMS notifications
 * Version: 1.1.3
 * Author: TxtCow
 * Author URI: https://txtcow.com
 * License: GPL v2 or later
 * Text Domain: txtcow-sms
 * Update URI: https://txtcow.com/downloads/txtcow-wordpress-plugin.json
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Declare HPOS (High-Performance Order Storage) compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// Public plugin update metadata (Plugin Update Checker)
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
$txtcow_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://txtcow.com/downloads/txtcow-wordpress-plugin.json',
    __FILE__,
    'txtcow-sms'
);

// 플러그인 상수 정의
define('TXTCOW_VERSION', '1.1.3');
define('TXTCOW_LEGACY_TEST_SOURCE', 'woocommerce_admin_test');
define('TXTCOW_API_BASE_URL', 'https://txtcow.com');
define('TXTCOW_BLOCKLIST_OPTION', 'txtcow_blocklist_numbers');

function txtcow_sms_load_textdomain() {
    load_plugin_textdomain('txtcow-sms', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('init', 'txtcow_sms_load_textdomain');

function txtcow_sms_is_korean_locale() {
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    return strpos(strtolower((string) $locale), 'ko') === 0;
}

function txtcow_sms_default_message($type) {
    $messages = txtcow_sms_is_korean_locale() ? array(
        'processing' => '주문이 접수되었습니다. 주문번호: {order_number}',
        'completed' => '주문이 완료되었습니다. 주문번호: {order_number}',
        'cancelled' => '주문이 취소되었습니다. 주문번호: {order_number}',
    ) : array(
        'processing' => 'Your order has been received. Order number: {order_number}',
        'completed' => 'Your order has been completed. Order number: {order_number}',
        'cancelled' => 'Your order has been cancelled. Order number: {order_number}',
    );
    return isset($messages[$type]) ? $messages[$type] : '';
}

class TxtCow_SMS_Gateway {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_store_id() {
        return untrailingslashit(get_site_url());
    }

    private function get_store_name() {
        return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    private function get_template_variables_help_html() {
        return implode('<br>', array(
            '<code>{order_number}</code> - ' . __( '{order_number} - Order number', 'txtcow-sms' ),
            '<code>{customer_name}</code> - ' . __( '{customer_name} - Customer name', 'txtcow-sms' ),
            '<code>{total}</code> - ' . __( '{total} - Order total', 'txtcow-sms' ),
            '<code>{items_list}</code> - ' . __( '{items_list} - Product list (e.g., Apple x 1, Pear x 2)', 'txtcow-sms' ),
            '<code>{billing_address}</code> - ' . __( '{billing_address} - Billing/shipping address', 'txtcow-sms' ),
            '<code>{tracking_number}</code> - ' . __( '{tracking_number} - Tracking number', 'txtcow-sms' ),
            '<code>{tracking_link}</code> - ' . __( '{tracking_link} - Shipping tracking link', 'txtcow-sms' ),
        ));
    }

    private function to_plain_text($value) {
        return html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function build_order_template_context($order) {
        if (!$order) {
            return array(
                '{order_number}' => '1001',
                '{customer_name}' => __('John Doe', 'txtcow-sms'),
                '{total}' => 'KRW 19,900',
                '{items_list}' => __('Sample product x 1', 'txtcow-sms'),
                '{billing_address}' => 'Seoul, KR',
                '{tracking_number}' => 'TRACK-1234',
                '{tracking_link}' => 'https://txtcow.com/track/demo',
            );
        }

        $items_list = array();
        foreach ((method_exists($order, 'get_items') ? $order->get_items() : array()) as $item) {
            $items_list[] = $this->to_plain_text($item->get_name()) . ' x ' . $item->get_quantity();
        }

        $tracking = $this->get_tracking_info($order);
        $order_number = $this->to_plain_text(method_exists($order, 'get_order_number') ? $order->get_order_number() : $order->get_id());
        $formatted_total = method_exists($order, 'get_formatted_order_total') ? $order->get_formatted_order_total() : '';
        $billing_first_name = $this->to_plain_text(method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '');
        $billing_last_name = $this->to_plain_text(method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '');
        $billing_address = method_exists($order, 'get_formatted_billing_address') ? $order->get_formatted_billing_address() : '';

        return array(
            '{order_number}' => $order_number,
            '{customer_name}' => trim($billing_first_name . ' ' . $billing_last_name),
            '{total}' => $this->to_plain_text($formatted_total),
            '{items_list}' => implode(', ', $items_list),
            '{billing_address}' => $this->to_plain_text($billing_address),
            '{tracking_number}' => $this->to_plain_text($tracking['number']),
            '{tracking_link}' => $tracking['link'],
        );
    }

    private function render_message_template($template, $order = null) {
        $context = $this->build_order_template_context($order);
        return str_replace(array_keys($context), array_values($context), $template);
    }

    private function get_preview_order_summary($order) {
        if (!$order) {
            return __('No order found. Rendering with sample values.', 'txtcow-sms');
        }

        $order_number = $this->to_plain_text(method_exists($order, 'get_order_number') ? $order->get_order_number() : $order->get_id());
        $customer_name = trim($this->to_plain_text((method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '') . ' ' . (method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '')));

        return sprintf(__('Preview order: #%s / %s', 'txtcow-sms'), $order_number, $customer_name);
    }

    private function get_message_rules() {
        $raw = get_option('txtcow_message_rules_json', '');
        if (empty($raw)) {
            return array();
        }

        $decoded = json_decode(wp_unslash($raw), true);
        return is_array($decoded) ? $decoded : array();
    }

    private function get_default_message_rule() {
        return array(
            'name' => '',
            'enabled' => true,
            'event_type' => 'order_created',
            'country' => '',
            'min_total' => '',
            'max_total' => '',
            'item_keyword' => '',
            'customer_name_pattern' => '',
            'template' => '',
        );
    }

    private function normalize_message_rules_from_post($posted_rules) {
        $normalized_rules = array();
        $errors = array();

        if (!is_array($posted_rules)) {
            return array($normalized_rules, $errors);
        }

        foreach ($posted_rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $normalized = array(
                'name' => sanitize_text_field($rule['name'] ?? ''),
                'enabled' => !empty($rule['enabled']),
                'event_type' => sanitize_text_field($rule['event_type'] ?? ''),
                'country' => strtoupper(sanitize_text_field($rule['country'] ?? '')),
                'min_total' => $rule['min_total'] === '' ? '' : (float) $rule['min_total'],
                'max_total' => $rule['max_total'] === '' ? '' : (float) $rule['max_total'],
                'item_keyword' => sanitize_text_field($rule['item_keyword'] ?? ''),
                'customer_name_pattern' => sanitize_text_field($rule['customer_name_pattern'] ?? ''),
                'template' => sanitize_textarea_field($rule['template'] ?? ''),
            );

            $is_effectively_empty = empty($normalized['name'])
                && empty($normalized['country'])
                && $normalized['min_total'] == ''
                && $normalized['max_total'] == ''
                && empty($normalized['item_keyword'])
                && empty($normalized['customer_name_pattern'])
                && empty($normalized['template']);
            if ($is_effectively_empty) {
                continue;
            }

            if (empty($normalized['template'])) {
                $errors[] = sprintf(__('Rule %d: Template cannot be empty.', 'txtcow-sms'), $index + 1);
                continue;
            }

            if (!in_array($normalized['event_type'], array('order_created', 'order_shipped', 'order_cancelled'), true)) {
                $errors[] = sprintf(__('Rule %d: Unsupported event type.', 'txtcow-sms'), $index + 1);
                continue;
            }

            if ($normalized['min_total'] != '' && $normalized['max_total'] != '' && $normalized['min_total'] > $normalized['max_total']) {
                $errors[] = sprintf(__('Rule %d: Minimum order amount cannot be greater than maximum order amount.', 'txtcow-sms'), $index + 1);
                continue;
            }

            if (!empty($normalized['customer_name_pattern'])) {
                $pattern = '/' . str_replace('/', '\/', $normalized['customer_name_pattern']) . '/iu';
                if (@preg_match($pattern, '') == false) {
                    $errors[] = sprintf(__('Rule %d: Customer name regex pattern is invalid.', 'txtcow-sms'), $index + 1);
                    continue;
                }
            }

            $normalized_rules[] = $normalized;
        }

        return array($normalized_rules, $errors);
    }

    private function find_matching_message_rule($event_type, $order) {
        $rules = $this->get_message_rules();
        if (empty($rules) || !$order) {
            return null;
        }

        $order_total = (float) $order->get_total();
        $country = strtoupper((string) $order->get_billing_country());
        $items_text = strtolower($this->render_message_template('{items_list}', $order));
        $customer_name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (($rule['enabled'] ?? true) == false) {
                continue;
            }
            if (!empty($rule['event_type']) && $rule['event_type'] !== $event_type) {
                continue;
            }
            if (isset($rule['min_total']) && $rule['min_total'] != '' && $order_total < (float) $rule['min_total']) {
                continue;
            }
            if (isset($rule['max_total']) && $rule['max_total'] != '' && $order_total > (float) $rule['max_total']) {
                continue;
            }
            if (!empty($rule['country']) && strtoupper((string) $rule['country']) !== $country) {
                continue;
            }
            if (!empty($rule['item_keyword']) && strpos($items_text, strtolower((string) $rule['item_keyword'])) === false) {
                continue;
            }
            if (!empty($rule['customer_name_pattern'])) {
                $pattern = '/' . str_replace('/', '\/', (string) $rule['customer_name_pattern']) . '/iu';
                if (@preg_match($pattern, $customer_name) !== 1) {
                    continue;
                }
            }
            if (empty($rule['template'])) {
                continue;
            }
            return $rule;
        }

        return null;
    }

    private function resolve_message_template($event_type, $default_template, $order) {
        $rule = $this->find_matching_message_rule($event_type, $order);
        if ($rule && !empty($rule['template'])) {
            return $rule['template'];
        }
        return $default_template;
    }

    private function get_preview_order($requested_order_id = 0) {
        if (!class_exists('WooCommerce')) {
            return null;
        }
        if ($requested_order_id > 0) {
            $order = wc_get_order($requested_order_id);
            if ($order && $order instanceof WC_Order && !($order instanceof WC_Order_Refund)) {
                return $order;
            }
        }
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'type' => 'shop_order',
        ));
        if (!empty($orders)) {
            return $orders[0];
        }
        return null;
    }

    private function ensure_commerce_connection($api_key, $default_device_id = null) {
        $payload = array(
            'store_id' => $this->get_store_id(),
            'store_name' => $this->get_store_name(),
            'config' => array(
                'source' => 'wordpress_plugin',
                'plugin_version' => TXTCOW_VERSION,
            ),
        );

        if (!empty($default_device_id)) {
            $payload['default_device_id'] = $default_device_id;
        }

        $response = wp_remote_post(TXTCOW_API_BASE_URL . '/api/integrations/commerce/woocommerce/connect', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
            ),
            'body' => wp_json_encode($payload),
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code >= 200 && $http_code < 300) {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        $error = is_array($body) && !empty($body['error']) ? $body['error'] : 'Failed to connect commerce integration';
        return array(
            'success' => false,
            'error' => sprintf('HTTP %d: %s', $http_code, $error),
        );
    }

    private function is_remote_blocked($api_key, $phone) {
        $response = wp_remote_get('https://txtcow.com/api/blocklist?phone=' . urlencode($phone), array(
            'headers' => array(
                'X-API-Key' => $api_key,
            ),
            'timeout' => 10,
            'sslverify' => true,
        ));
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['blocked']) && $body['blocked'] === true;
    }

    private function sync_blocklist_remote($api_key, $blocklist_raw) {
        $numbers = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $blocklist_raw)));
        foreach ($numbers as $num) {
            wp_remote_post('https://txtcow.com/api/blocklist', array(
                'headers' => array(
                    'X-API-Key' => $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'phone' => $num,
                    'note' => 'synced from WooCommerce',
                )),
                'timeout' => 10,
                'sslverify' => true,
            ));
        }
    }

    private function __construct() {
        // WooCommerce가 활성화되어 있는지 확인
        add_action('plugins_loaded', array($this, 'check_woocommerce'));

        // 관리자 메뉴 추가
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // 설정 등록
        add_action('admin_init', array($this, 'register_settings'));

        // 주문 상태 변경 시 SMS 전송
        $this->setup_order_hooks();

        // 관리자 알림
        add_action('admin_notices', array($this, 'admin_notices'));

        // AJAX 핸들러
        add_action('wp_ajax_txtcow_get_qr_payload', array($this, 'ajax_get_qr_payload'));
        add_action('wp_ajax_txtcow_get_connection_status', array($this, 'ajax_get_connection_status'));
        add_action('wp_ajax_txtcow_get_commerce_logs', array($this, 'ajax_get_commerce_logs'));
        add_action('wp_ajax_txtcow_get_preview_context', array($this, 'ajax_get_preview_context'));
    }

    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('TxtCow SMS Gateway:', 'txtcow-sms'); ?></strong> <?php esc_html_e('WooCommerce is required to use this plugin.', 'txtcow-sms'); ?></p>
        </div>
        <?php
    }

    private function setup_order_hooks() {
        // 훅은 항상 등록하고, 각 핸들러에서 설정값을 확인 (플러그인 재활성화 없이 설정 반영)
        add_action('woocommerce_order_status_processing', array($this, 'send_order_processing_sms'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'send_order_completed_sms'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array($this, 'send_order_cancelled_sms'), 10, 1);
    }

    public function send_order_processing_sms($order_id) {
        if (get_option('txtcow_enable_processing', '1') !== '1') {
            return;
        }
        $message = get_option('txtcow_message_processing', 'Order received: Order #' . $order_id);
        $this->send_commerce_event($order_id, 'order_created', $message);

        $this->send_admin_notification($order_id, 'new_order');
    }

    public function send_order_completed_sms($order_id) {
        if (get_option('txtcow_enable_completed', '0') !== '1') {
            return;
        }
        $message = get_option('txtcow_message_completed', 'Order completed: Order #{order_number}');
        $this->send_commerce_event($order_id, 'order_shipped', $message);
    }

    public function send_order_cancelled_sms($order_id) {
        if (get_option('txtcow_enable_cancelled', '0') !== '1') {
            return;
        }
        $message = get_option('txtcow_message_cancelled', 'Order cancelled: Order #{order_number}');
        $this->send_commerce_event($order_id, 'order_cancelled', $message);
    }

    private function send_commerce_event($order_id, $event_type, $message_template) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            $this->log_error($order_id, __( 'Phone number is missing.', 'txtcow-sms' ));
            return;
        }

        $phone = $this->format_phone_number($phone, $order->get_billing_country());
        if (empty($phone)) {
            $this->log_error($order_id, __( 'Cannot normalize phone number.', 'txtcow-sms' ));
            return;
        }

        if ($this->is_blocked_number($phone)) {
            $order->add_order_note(sprintf( __( 'TxtCow SMS not sent: opt-out number (%s)', 'txtcow-sms' ), $phone ));
            return;
        }

        // 메시지 템플릿이 비어있으면 기본값 설정
        if (empty($message_template)) {
            if ($event_type === 'order_created') {
                $message_template = 'Order received: Order #{order_number}';
            } elseif ($event_type === 'order_shipped') {
                $message_template = 'Order completed: Order #{order_number}';
            } elseif ($event_type === 'order_cancelled') {
                $message_template = 'Order cancelled: Order #{order_number}';
            }
        }

        $resolved_template = $this->resolve_message_template($event_type, $message_template, $order);
        $message = $this->render_message_template($resolved_template, $order);

        $api_key = get_option('txtcow_api_key');
        if (empty($api_key)) {
            $this->log_error($order_id, __( 'API key is not configured.', 'txtcow-sms' ));
            return;
        }

        if ($this->is_remote_blocked($api_key, $phone)) {
            $order->add_order_note(sprintf( __( 'TxtCow SMS not sent: central blocklist (%s)', 'txtcow-sms' ), $phone ));
            return;
        }

        $delay_mode = get_option('txtcow_delay_mode', 'instant');
        $delay_value = null;
        if ($delay_mode === 'fixed_interval_seconds') {
            $delay_value = intval(get_option('txtcow_delay_seconds', 0));
        } elseif ($delay_mode === 'random_range_seconds') {
            $delay_min = intval(get_option('txtcow_delay_min', 0));
            $delay_max = intval(get_option('txtcow_delay_max', 0));
            $delay_value = array('min_seconds' => $delay_min, 'max_seconds' => $delay_max);
        }

        $payload = array(
            'integration_type' => 'woocommerce',
            'store_id' => $this->get_store_id(),
            'store_name' => $this->get_store_name(),
            'event_type' => $event_type,
            'recipient_phone' => $phone,
            'message_body' => $message,
            'reference_id' => sprintf('wc-%s-%s', $order_id, $event_type),
            'delay_mode' => $delay_mode,
            'delay_value' => $delay_value,
            'metadata' => array(
                'order_id' => $order_id,
                'order_number' => $this->to_plain_text($order->get_order_number()),
                'customer_name' => trim($this->to_plain_text($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())),
                'total' => $this->to_plain_text($order->get_formatted_order_total()),
                'currency' => $order->get_currency(),
            ),
        );

        $response = $this->send_normalized_event($api_key, $payload);

        if ($response['success']) {
            $status = isset($response['data']['status']) ? $response['data']['status'] : 'queued';
            if ($status === 'draft') {
                $order->add_order_note(sprintf( __( 'TxtCow SMS pending review in app: %s', 'txtcow-sms' ), $phone ));
            } else {
                $order->add_order_note(sprintf( __( 'TxtCow SMS queued: %s', 'txtcow-sms' ), $phone ));
            }
        } else {
            $order->add_order_note(sprintf( __( 'TxtCow SMS failed: %s', 'txtcow-sms' ), $response['error'] ));
            $this->log_error($order_id, $response['error']);
        }
    }

    private function send_admin_notification($order_id, $type) {
        if (get_option('txtcow_enable_admin_alert', '0') !== '1') {
            return;
        }

        $admin_phone = $this->format_phone_number(get_option('txtcow_admin_phone'), get_option('woocommerce_default_country'));
        if (empty($admin_phone)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $api_key = get_option('txtcow_api_key');
        if (empty($api_key)) {
            return;
        }

        $message = "";
        if ($type === 'new_order') {
            $total = $this->to_plain_text($order->get_formatted_order_total());
            $message = sprintf( __( '[Admin Alert] New order (#%s) received. Amount: %s', 'txtcow-sms' ), $this->to_plain_text($order->get_order_number()), $total );
        }

        if (!empty($message)) {
            $payload = array(
                'integration_type' => 'woocommerce',
                'store_id' => $this->get_store_id(),
                'store_name' => $this->get_store_name(),
                'event_type' => 'admin_new_order_alert',
                'recipient_phone' => $admin_phone,
                'message_body' => $message,
                'reference_id' => sprintf('wc-admin-%s-new_order', $order_id),
                'delay_mode' => 'instant',
                'metadata' => array(
                    'order_id' => $order_id,
                    'order_number' => $this->to_plain_text($order->get_order_number()),
                    'total' => $this->to_plain_text($order->get_formatted_order_total()),
                ),
            );
            $this->send_normalized_event($api_key, $payload);
        }
    }

    private function send_normalized_event($api_key, $payload, $allow_retry = true) {
        $connection = $this->ensure_commerce_connection($api_key);
        if (empty($connection['success'])) {
            return array(
                'success' => false,
                'error' => $connection['error'],
            );
        }

        $url = TXTCOW_API_BASE_URL . '/api/integrations/commerce/events';

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
            ),
            'body' => wp_json_encode($payload),
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($allow_retry && $http_code === 404 && is_array($decoded) && ($decoded['error_code'] ?? '') === 'not_configured') {
            $reconnected = $this->ensure_commerce_connection($api_key);
            if (!empty($reconnected['success'])) {
                return $this->send_normalized_event($api_key, $payload, false);
            }
        }

        if ($http_code === 201) {
            return array(
                'success' => true,
                'data' => $decoded,
            );
        }

        $error = is_array($decoded) && !empty($decoded['error']) ? $decoded['error'] : $body;
        return array(
            'success' => false,
            'error' => sprintf('HTTP %d: %s', $http_code, $error),
        );
    }

    private function get_tracking_info($order) {
        $tracking_number = '';
        $tracking_link = '';

        // Popular plugin meta (_wc_shipment_tracking_items)
        $tracking_items = $order->get_meta('_wc_shipment_tracking_items', true);
        if (is_array($tracking_items) && !empty($tracking_items)) {
            $first = reset($tracking_items);
            if (is_array($first)) {
                $tracking_number = !empty($first['tracking_number']) ? $first['tracking_number'] : $tracking_number;
                $tracking_link = !empty($first['tracking_link']) ? $first['tracking_link'] : $tracking_link;
                if (empty($tracking_link) && !empty($first['formatted_tracking_link'])) {
                    $tracking_link = $first['formatted_tracking_link'];
                }
            }
        }

        // Fallback common meta keys
        if (empty($tracking_number)) {
            $tracking_number = $order->get_meta('_tracking_number');
        }
        if (empty($tracking_link)) {
            $tracking_link = $order->get_meta('_tracking_link');
        }

        return array(
            'number' => $this->to_plain_text($tracking_number),
            'link' => !empty($tracking_link) ? esc_url_raw($tracking_link) : ''
        );
    }

    private function format_phone_number($phone, $country_code) {
        if (empty($phone)) {
            return '';
        }

        $has_plus = strpos(trim($phone), '+') === 0;
        $digits = preg_replace('/\D+/', '', $phone);

        if (empty($digits)) {
            return '';
        }

        // Already includes country code
        if ($has_plus) {
            return '+' . ltrim($digits, '0');
        }

        // 00-prefixed international format
        if (strpos($digits, '00') === 0) {
            return '+' . substr($digits, 2);
        }

        // Default to store country calling code
        $calling_code = '';
        // WooCommerce 기본 국가가 "US:CA" 형태일 수 있어 국가만 추출
        if (!empty($country_code) && strpos($country_code, ':') !== false) {
            $country_parts = explode(':', $country_code);
            $country_code = $country_parts[0];
        }

        $wc = function_exists('WC') ? WC() : null;
        if ($wc && !empty($wc->countries)) {
            $code = $wc->countries->get_country_calling_code($country_code);
            if (is_array($code)) {
                $code = reset($code);
            }
            $calling_code = preg_replace('/\D+/', '', $code);
        }

        if (!empty($calling_code)) {
            // Remove a single leading zero (local format) then prepend country code
            if (substr($digits, 0, 1) === '0') {
                $digits = substr($digits, 1);
            }
            return '+' . $calling_code . $digits;
        }

        // Fallback to digits-only
        return $digits;
    }

    private function is_blocked_number($phone) {
        $blocklist_raw = get_option(TXTCOW_BLOCKLIST_OPTION, '');
        if (empty($blocklist_raw)) {
            return false;
        }
        $blocked = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $blocklist_raw)));
        $normalized_phone = preg_replace('/\D+/', '', $phone);
        foreach ($blocked as $entry) {
            $normalized_entry = preg_replace('/\D+/', '', $entry);
            if (!empty($normalized_entry) && $normalized_entry === $normalized_phone) {
                return true;
            }
        }
        return false;
    }

    private function send_sms($api_key, $phone, $message) {
        if ($this->is_blocked_number($phone)) {
            return array(
                'success' => false,
                'error' => 'Suppressed: recipient opted out'
            );
        }

        $formatted_phone = $this->format_phone_number($phone, get_option('woocommerce_default_country'));
        if (empty($formatted_phone)) {
            return array(
                'success' => false,
                'error' => 'Invalid phone number'
            );
        }

        $device_id = get_option('txtcow_default_device_id', '');
        $connection = $this->ensure_commerce_connection($api_key, $device_id ? $device_id : null);
        if (empty($connection['success'])) {
            return array(
                'success' => false,
                'error' => $connection['error']
            );
        }

        $response = wp_remote_post(TXTCOW_API_BASE_URL . '/api/integrations/commerce/woocommerce/test', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
            ),
            'body' => wp_json_encode(array(
                'store_id' => $this->get_store_id(),
                'store_name' => $this->get_store_name(),
                'recipient_phone' => $formatted_phone,
                'message_body' => $message,
                'device_id' => $device_id ? $device_id : null,
                'delay_mode' => 'instant',
                'metadata' => array('source' => TXTCOW_LEGACY_TEST_SOURCE),
            )),
            'timeout' => 15,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($http_code === 201) {
            return array(
                'success' => true,
                'data' => $decoded
            );
        }

        $error = is_array($decoded) && !empty($decoded['error']) ? $decoded['error'] : $body;
        return array(
            'success' => false,
            'error' => sprintf('HTTP %d: %s', $http_code, $error)
        );
    }

    private function log_error($order_id, $error) {
        error_log(sprintf('[TxtCow SMS] Order #%s: %s', $order_id, $error));
    }

    public function ajax_get_qr_payload() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        if (empty($api_key)) {
            wp_send_json_error('API key required');
        }

        $this->ensure_commerce_connection($api_key);

        $payload_url = TXTCOW_API_BASE_URL . '/api/integrations/commerce/woocommerce/qr-payload?store_id=' . urlencode($this->get_store_id()) . '&store_name=' . urlencode($this->get_store_name());
        $response = wp_remote_get($payload_url, array(
            'headers' => array(
                'X-API-Key' => $api_key,
            ),
            'timeout' => 10,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
    }

    public function ajax_get_connection_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        if (empty($api_key)) {
            wp_send_json_error('API key required');
        }

        $this->ensure_commerce_connection($api_key);

        $status_url = TXTCOW_API_BASE_URL . '/api/integrations/commerce/woocommerce/status?store_id=' . urlencode($this->get_store_id());
        $response = wp_remote_get($status_url, array(
            'headers' => array(
                'X-API-Key' => $api_key,
            ),
            'timeout' => 10,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
    }

    public function ajax_get_commerce_logs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $page_size = isset($_POST['page_size']) ? intval($_POST['page_size']) : 20;

        if (empty($api_key)) {
            wp_send_json_error('API key required');
        }

        $this->ensure_commerce_connection($api_key);

        $logs_url = TXTCOW_API_BASE_URL . '/api/integrations/commerce/logs?integration_type=woocommerce&store_id=' . urlencode($this->get_store_id()) . '&page=' . $page . '&page_size=' . $page_size;
        $response = wp_remote_get($logs_url, array(
            'headers' => array(
                'X-API-Key' => $api_key,
            ),
            'timeout' => 10,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
    }

    public function ajax_get_preview_context() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $requested_order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = $this->get_preview_order($requested_order_id);

        wp_send_json_success(array(
            'context' => $this->build_order_template_context($order),
            'summary' => $this->get_preview_order_summary($order),
            'order_id' => $order ? $order->get_id() : 0,
        ));
    }

    public function add_admin_menu() {
        add_options_page(
            __('TxtCow SMS Settings', 'txtcow-sms'),
            __('TxtCow SMS', 'txtcow-sms'),
            'manage_options',
            'txtcow-sms',
            array($this, 'options_page')
        );
    }

    public function register_settings() {
        // API 설정
        register_setting('txtcow_settings', 'txtcow_api_key');

        // 관리자 알림 설정
        register_setting('txtcow_settings', 'txtcow_enable_admin_alert');
        register_setting('txtcow_settings', 'txtcow_admin_phone');

        // 알림 활성화 옵션
        register_setting('txtcow_settings', 'txtcow_enable_processing');
        register_setting('txtcow_settings', 'txtcow_enable_completed');
        register_setting('txtcow_settings', 'txtcow_enable_cancelled');

        // 메시지 템플릿
        register_setting('txtcow_settings', 'txtcow_message_processing');
        register_setting('txtcow_settings', 'txtcow_message_completed');
        register_setting('txtcow_settings', 'txtcow_message_cancelled');
        register_setting('txtcow_settings', 'txtcow_message_rules_json');

        // 지연 설정
        register_setting('txtcow_settings', 'txtcow_delay_mode');
        register_setting('txtcow_settings', 'txtcow_delay_seconds');
        register_setting('txtcow_settings', 'txtcow_delay_min');
        register_setting('txtcow_settings', 'txtcow_delay_max');

        // 발신 제한 리스트
        register_setting('txtcow_settings', TXTCOW_BLOCKLIST_OPTION);
    }

    public function admin_notices() {
        if (empty(get_option('txtcow_api_key')) && isset($_GET['page']) && $_GET['page'] !== 'txtcow-sms') {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'TxtCow SMS Gateway:', 'txtcow-sms' ); ?></strong>
                    <a href="<?php echo admin_url('options-general.php?page=txtcow-sms'); ?>"><?php esc_html_e( 'API key settings', 'txtcow-sms' ); ?></a> <?php esc_html_e( 'to start sending SMS.', 'txtcow-sms' ); ?>
                </p>
            </div>
            <?php
        }
    }

    public function options_page() {
        // 설정 저장 메시지
        $message_rule_errors = array();
        if (isset($_POST['txtcow_save_settings']) && check_admin_referer('txtcow_settings_action', 'txtcow_settings_nonce')) {
            update_option('txtcow_api_key', sanitize_text_field($_POST['txtcow_api_key'] ?? get_option('txtcow_api_key', '')));
            update_option('txtcow_enable_admin_alert', isset($_POST['txtcow_enable_admin_alert']) ? '1' : '0');
            update_option('txtcow_admin_phone', sanitize_text_field($_POST['txtcow_admin_phone'] ?? get_option('txtcow_admin_phone', '')));
            update_option('txtcow_enable_processing', isset($_POST['txtcow_enable_processing']) ? '1' : '0');
            update_option('txtcow_enable_completed', isset($_POST['txtcow_enable_completed']) ? '1' : '0');
            update_option('txtcow_enable_cancelled', isset($_POST['txtcow_enable_cancelled']) ? '1' : '0');
            update_option('txtcow_message_processing', sanitize_textarea_field($_POST['txtcow_message_processing'] ?? get_option('txtcow_message_processing', '')));
            update_option('txtcow_message_completed', sanitize_textarea_field($_POST['txtcow_message_completed'] ?? get_option('txtcow_message_completed', '')));
            update_option('txtcow_message_cancelled', sanitize_textarea_field($_POST['txtcow_message_cancelled'] ?? get_option('txtcow_message_cancelled', '')));
            list($normalized_rules, $message_rule_errors) = $this->normalize_message_rules_from_post($_POST['txtcow_message_rules'] ?? array());
            update_option('txtcow_message_rules_json', wp_json_encode($normalized_rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_option('txtcow_delay_mode', sanitize_text_field($_POST['txtcow_delay_mode'] ?? get_option('txtcow_delay_mode', 'instant')));
            update_option('txtcow_delay_seconds', intval($_POST['txtcow_delay_seconds'] ?? get_option('txtcow_delay_seconds', 0)));
            update_option('txtcow_delay_min', intval($_POST['txtcow_delay_min'] ?? get_option('txtcow_delay_min', 0)));
            update_option('txtcow_delay_max', intval($_POST['txtcow_delay_max'] ?? get_option('txtcow_delay_max', 0)));
            update_option(TXTCOW_BLOCKLIST_OPTION, sanitize_textarea_field($_POST[TXTCOW_BLOCKLIST_OPTION] ?? get_option(TXTCOW_BLOCKLIST_OPTION, '')));

            $api_key_sync = sanitize_text_field($_POST['txtcow_api_key'] ?? get_option('txtcow_api_key', ''));
            $blocklist_sync = sanitize_textarea_field($_POST[TXTCOW_BLOCKLIST_OPTION] ?? get_option(TXTCOW_BLOCKLIST_OPTION, ''));
            if (!empty($api_key_sync) && !empty($blocklist_sync)) {
                $this->sync_blocklist_remote($api_key_sync, $blocklist_sync);
            }

            echo '<div class="updated"><p>' . esc_html__( 'Settings saved!', 'txtcow-sms' ) . '</p></div>';
            if (!empty($message_rule_errors)) {
                echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Message Rule Validation Results', 'txtcow-sms' ) . '</strong><br>' . esc_html(implode(' | ', $message_rule_errors)) . '</p></div>';
            }
        }

        if (isset($_POST['txtcow_test_sms']) && check_admin_referer('txtcow_test_action', 'txtcow_test_nonce')) {
            $test_phone = sanitize_text_field($_POST['txtcow_test_phone']);
            $test_message = sanitize_textarea_field($_POST['txtcow_test_message']);
            $api_key = get_option('txtcow_api_key');

            if (!empty($api_key) && !empty($test_phone) && !empty($test_message)) {
                $result = $this->send_sms($api_key, $test_phone, $test_message);

                if ($result['success']) {
                    $status = isset($result['data']['status']) ? $result['data']['status'] : '';
                    if ($status === 'draft') {
                        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Test SMS is pending review in TxtCow app. Please open the app to approve and send it.', 'txtcow-sms' ) . '</p></div>';
                    } else {
                        echo '<div class="updated"><p>' . esc_html__( 'Test SMS sent!', 'txtcow-sms' ) . '</p></div>';
                    }
                } else {
                    echo '<div class="error"><p>' . esc_html__( 'SMS send failed: %s', 'txtcow-sms' ) . ' ' . esc_html($result['error']) . '</p></div>';
                }
            } else {
                echo '<div class="error"><p>' . esc_html__( 'Please fill in all fields.', 'txtcow-sms' ) . '</p></div>';
            }
        }

        $api_key = get_option('txtcow_api_key', '');
        $enable_admin_alert = get_option('txtcow_enable_admin_alert', '0');
        $admin_phone = get_option('txtcow_admin_phone', '');
        $enable_processing = get_option('txtcow_enable_processing', '1');
        $enable_completed = get_option('txtcow_enable_completed', '0');
        $enable_cancelled = get_option('txtcow_enable_cancelled', '0');
        $message_processing = get_option('txtcow_message_processing', 'Order received: Order #{order_number}');
        $message_completed = get_option('txtcow_message_completed', 'Order completed: Order #{order_number}');
        $message_cancelled = get_option('txtcow_message_cancelled', 'Order cancelled: Order #{order_number}');
        $message_rules_json = get_option('txtcow_message_rules_json', '');
        $message_rules = $this->get_message_rules();
        if (empty($message_rules)) {
            $message_rules = array($this->get_default_message_rule());
        }
        $delay_mode = get_option('txtcow_delay_mode', 'instant');
        $delay_seconds = intval(get_option('txtcow_delay_seconds', 0));
        $delay_min = intval(get_option('txtcow_delay_min', 0));
        $delay_max = intval(get_option('txtcow_delay_max', 0));
        $blocklist = get_option(TXTCOW_BLOCKLIST_OPTION, "");
        $preview_order_id = isset($_POST['txtcow_preview_order_id']) ? intval($_POST['txtcow_preview_order_id']) : 0;
        $preview_order = $this->get_preview_order($preview_order_id);
        $preview_context = $this->build_order_template_context($preview_order);
        $preview_summary = $this->get_preview_order_summary($preview_order);
        $allowed_tabs = array('api-settings', 'message-settings', 'message-rules', 'delay-settings', 'connection-status', 'delivery-logs', 'test-sms');
        $active_tab = isset($_POST['txtcow_active_tab']) ? sanitize_key($_POST['txtcow_active_tab']) : 'api-settings';
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'api-settings';
        }
        $txtcow_admin_i18n = array(
            'unknown' => __('Unknown', 'txtcow-sms'),
            'connected' => __('Connected', 'txtcow-sms'),
            'awaiting_device_connection' => __('Awaiting device connection', 'txtcow-sms'),
            'needs_attention' => __('Needs attention', 'txtcow-sms'),
            'store' => __('Store:', 'txtcow-sms'),
            'active_device' => __('Active device:', 'txtcow-sms'),
            'no_active_device' => __('No active device connected yet.', 'txtcow-sms'),
            'default_device' => __('Default device:', 'txtcow-sms'),
            'last_tested' => __('Last tested:', 'txtcow-sms'),
            'recent_error' => __('Recent error:', 'txtcow-sms'),
            'event' => __('Event', 'txtcow-sms'),
            'recipient' => __('Recipient', 'txtcow-sms'),
            'status' => __('Status', 'txtcow-sms'),
            'sent_at' => __('Sent At', 'txtcow-sms'),
            'error' => __('Error', 'txtcow-sms'),
            'no_logs' => __('No logs found.', 'txtcow-sms'),
            'logs_summary' => __('Showing %1 of %2 messages', 'txtcow-sms'),
        );
        ?>

        <script>
        var txtcowAllowedTabs = <?php echo wp_json_encode($allowed_tabs); ?>;
        var txtcowInitialTab = <?php echo wp_json_encode($active_tab); ?>;
        var txtcowPreviewContext = <?php echo wp_json_encode($preview_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var txtcowAdminI18n = <?php echo wp_json_encode($txtcow_admin_i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        function txtcowEscapeHtml(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;'}[character];
            });
        }
        function txtcowSetActiveTab(tabName) {
            if (txtcowAllowedTabs.indexOf(tabName) === -1) return;
            Array.from(document.getElementsByClassName('tab-content')).forEach(function(tab) {
                tab.style.display = tab.id === tabName ? 'block' : 'none';
            });
            Array.from(document.getElementsByClassName('nav-tab')).forEach(function(tabLink) {
                var isActive = tabLink.getAttribute('data-tab') === tabName;
                tabLink.classList.toggle('nav-tab-active', isActive);
            });
            Array.from(document.getElementsByClassName('txtcow-active-tab-input')).forEach(function(input) {
                input.value = tabName;
            });
            try {
                window.localStorage.setItem('txtcow_active_tab', tabName);
            } catch (error) {}
        }

        function switchTab(evt, tabName) {
            if (evt) {
                evt.preventDefault();
            }
            txtcowSetActiveTab(tabName);
        }

        function txtcowRenderPreviewTemplate(template) {
            var rendered = template || '';
            Object.keys(txtcowPreviewContext || {}).forEach(function(key) {
                rendered = rendered.split(key).join(txtcowPreviewContext[key] || '');
            });
            return rendered;
        }

        function txtcowUpdateMessagePreview() {
            var processingField = document.getElementById('txtcow_message_processing');
            var completedField = document.getElementById('txtcow_message_completed');
            var cancelledField = document.getElementById('txtcow_message_cancelled');
            var processingPreview = document.getElementById('txtcow-preview-processing');
            var completedPreview = document.getElementById('txtcow-preview-completed');
            var cancelledPreview = document.getElementById('txtcow-preview-cancelled');
            if (!processingField || !completedField || !cancelledField || !processingPreview || !completedPreview || !cancelledPreview) {
                return;
            }
            processingPreview.textContent = txtcowRenderPreviewTemplate(processingField.value);
            completedPreview.textContent = txtcowRenderPreviewTemplate(completedField.value);
            cancelledPreview.textContent = txtcowRenderPreviewTemplate(cancelledField.value);
        }

        function txtcowUpdatePreviewSummary(summary) {
            var summaryElement = document.getElementById('txtcow-preview-order-summary');
            if (summaryElement) {
                summaryElement.textContent = summary || '';
            }
        }

        function txtcowFetchPreviewContext(orderId) {
            var request = new window.XMLHttpRequest();
            var params = new window.URLSearchParams();
            params.append('action', 'txtcow_get_preview_context');
            params.append('order_id', orderId || '0');
            request.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
            request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            request.onload = function() {
                if (request.status < 200 || request.status >= 300) {
                    return;
                }
                try {
                    var response = JSON.parse(request.responseText);
                    if (!response.success || !response.data || !response.data.context) {
                        return;
                    }
                    txtcowPreviewContext = response.data.context;
                    txtcowUpdatePreviewSummary(response.data.summary || '');
                    txtcowUpdateMessagePreview();
                } catch (error) {}
            };
            request.send(params.toString());
        }

        function toggleDelaySettings() {
            var mode = document.getElementById('txtcow_delay_mode').value;
            document.getElementById('delay-seconds-row').style.display = (mode === 'fixed_interval_seconds') ? '' : 'none';
            document.getElementById('delay-range-row').style.display = (mode === 'random_range_seconds') ? '' : 'none';
        }

        function txtcowRenumberRuleRows() {
            var body = document.getElementById('txtcow-message-rules-body');
            if (!body) return;
            var rows = body.querySelectorAll('tr');
            rows.forEach(function(row, index) {
                var firstCell = row.querySelector('td');
                if (firstCell) firstCell.textContent = String(index + 1);
                row.querySelectorAll('input, select, textarea').forEach(function(field) {
                    if (!field.name) return;
                    field.name = field.name.replace(/txtcow_message_rules\[\d+\]/, 'txtcow_message_rules[' + index + ']');
                });
            });
        }

        function txtcowAddRuleRow() {
            var body = document.getElementById('txtcow-message-rules-body');
            if (!body) return;
            var index = body.querySelectorAll('tr').length;
            var template = document.getElementById('txtcow-rule-row-template').innerHTML;
            template = template.replace(/__INDEX_PLUS_ONE__/g, String(index + 1));
            template = template.replace(/__INDEX__/g, String(index));
            body.insertAdjacentHTML('beforeend', template);
            txtcowRenumberRuleRows();
        }

        function txtcowDeleteRuleRow(button) {
            var row = button.closest('tr');
            var body = document.getElementById('txtcow-message-rules-body');
            if (!row || !body) return;
            if (body.querySelectorAll('tr').length === 1) {
                row.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(function(field) { field.value = ''; });
                row.querySelectorAll('input[type="checkbox"]').forEach(function(field) { field.checked = true; });
                row.querySelectorAll('select').forEach(function(field) { field.value = 'order_created'; });
                return;
            }
            row.remove();
            txtcowRenumberRuleRows();
        }

        function txtcowMoveRuleRow(button, direction) {
            var row = button.closest('tr');
            if (!row) return;
            var sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
            if (!sibling) return;
            if (direction < 0) {
                row.parentNode.insertBefore(row, sibling);
            } else {
                row.parentNode.insertBefore(sibling, row);
            }
            txtcowRenumberRuleRows();
        }

        document.addEventListener('DOMContentLoaded', function() {
            var savedTab = null;
            try {
                savedTab = window.localStorage.getItem('txtcow_active_tab');
            } catch (error) {}
            txtcowSetActiveTab(txtcowAllowedTabs.indexOf(txtcowInitialTab) !== -1 ? txtcowInitialTab : (txtcowAllowedTabs.indexOf(savedTab) !== -1 ? savedTab : 'api-settings'));

            var previewInputs = [
                document.getElementById('txtcow_message_processing'),
                document.getElementById('txtcow_message_completed'),
                document.getElementById('txtcow_message_cancelled')
            ].filter(Boolean);
            previewInputs.forEach(function(field) {
                field.addEventListener('input', txtcowUpdateMessagePreview);
            });
            txtcowUpdateMessagePreview();

            var previewOrderField = document.getElementById('txtcow_preview_order_id');
            var previewFetchTimer = null;
            if (previewOrderField) {
                previewOrderField.addEventListener('input', function() {
                    window.clearTimeout(previewFetchTimer);
                    previewFetchTimer = window.setTimeout(function() {
                        txtcowFetchPreviewContext(previewOrderField.value);
                    }, 250);
                });
            }

            Array.from(document.querySelectorAll('.tab-content form')).forEach(function(form) {
                form.addEventListener('submit', function() {
                    var parentTab = form.closest('.tab-content');
                    if (parentTab) {
                        Array.from(form.querySelectorAll('.txtcow-active-tab-input')).forEach(function(input) {
                            input.value = parentTab.id;
                        });
                    }
                });
            });
        });
        </script>

        <div class="wrap">
            <h1><?php esc_html_e( 'TxtCow SMS Gateway Settings', 'txtcow-sms' ); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="javascript:void(0);" class="nav-tab nav-tab-active" data-tab="api-settings" onclick="switchTab(event, 'api-settings')"><?php esc_html_e( 'Basic Settings', 'txtcow-sms' ); ?></a>
                <a href="javascript:void(0);" class="nav-tab" data-tab="message-settings" onclick="switchTab(event, 'message-settings')"><?php esc_html_e( 'Message Settings', 'txtcow-sms' ); ?></a>
                <a href="javascript:void(0);" class="nav-tab" data-tab="message-rules" onclick="switchTab(event, 'message-rules')"><?php esc_html_e( 'Message Rules', 'txtcow-sms' ); ?></a>
                <a href="javascript:void(0);" class="nav-tab" data-tab="delay-settings" onclick="switchTab(event, 'delay-settings')"><?php esc_html_e( 'Delay Settings', 'txtcow-sms' ); ?></a>
                <a href="javascript:void(0);" class="nav-tab" data-tab="connection-status" onclick="switchTab(event, 'connection-status')"><?php esc_html_e( 'Connection Status', 'txtcow-sms' ); ?></a>
                <a href="javascript:void(0);" class="nav-tab" data-tab="delivery-logs" onclick="switchTab(event, 'delivery-logs')"><?php esc_html_e( 'Delivery Logs', 'txtcow-sms' ); ?></a>
                <a href="javascript:void(0);" class="nav-tab" data-tab="test-sms" onclick="switchTab(event, 'test-sms')"><?php esc_html_e( 'Test SMS', 'txtcow-sms' ); ?></a>
            </h2>

            <!-- API 설정 탭 -->
            <div id="api-settings" class="tab-content">
                <form method="post" action="">
                    <?php wp_nonce_field('txtcow_settings_action', 'txtcow_settings_nonce'); ?>
                    <input type="hidden" name="txtcow_active_tab" class="txtcow-active-tab-input" value="<?php echo esc_attr($active_tab); ?>" />

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="txtcow_api_key"><?php esc_html_e( 'API Key', 'txtcow-sms' ); ?></label></th>
                            <td>
                                <input type="text" id="txtcow_api_key" name="txtcow_api_key"
                                       value="<?php echo esc_attr($api_key); ?>"
                                       class="regular-text" required />
                                <p class="description">
                                    <?php esc_html_e( 'Enter the API key issued in Integrations from TxtCow Dashboard.', 'txtcow-sms' ); ?><br>
                                    <a href="https://txtcow.com/integrations" target="_blank"><?php esc_html_e( 'Open TxtCow Dashboard', 'txtcow-sms' ); ?></a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e( 'Admin Notifications', 'txtcow-sms' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Send admin SMS for new orders', 'txtcow-sms' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="txtcow_enable_admin_alert" value="1"
                                           <?php checked($enable_admin_alert, '1'); ?> />
                                    <?php esc_html_e( 'Receive text messages on admin phone when new orders arrive', 'txtcow-sms' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Enter your phone number to stay on top of orders.', 'txtcow-sms' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="txtcow_admin_phone"><?php esc_html_e( 'Admin Phone Number', 'txtcow-sms' ); ?></label></th>
                            <td>
                                <input type="text" id="txtcow_admin_phone" name="txtcow_admin_phone"
                                       value="<?php echo esc_attr($admin_phone); ?>"
                                       class="regular-text" placeholder="e.g., 010-0000-0000" />
                            </td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e( 'Enable Customer Notifications', 'txtcow-sms' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Order Received (Processing)', 'txtcow-sms' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="txtcow_enable_processing" value="1"
                                           <?php checked($enable_processing, '1'); ?> />
                                    <?php esc_html_e( 'Send SMS to customer when order is received', 'txtcow-sms' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Order Completed', 'txtcow-sms' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="txtcow_enable_completed" value="1"
                                           <?php checked($enable_completed, '1'); ?> />
                                    <?php esc_html_e( 'Send SMS to customer when order is completed', 'txtcow-sms' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Order Cancelled', 'txtcow-sms' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="txtcow_enable_cancelled" value="1"
                                           <?php checked($enable_cancelled, '1'); ?> />
                                    <?php esc_html_e( 'Send SMS to customer when order is cancelled', 'txtcow-sms' ); ?>
                                </label>
                            </td>
                        </tr>
            </table>

            <h3><?php esc_html_e( 'SMS Send Restrictions (Opt-out Numbers)', 'txtcow-sms' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Enter one phone number per line. Numbers listed here will not receive SMS in any status.', 'txtcow-sms' ); ?></p>
            <div class="notice notice-success inline" style="margin: 10px 0;">
                <p>
                    <strong><?php esc_html_e( '✓ Automatic Opt-out Enabled!', 'txtcow-sms' ); ?></strong><br>
                    <?php esc_html_e( 'When customers reply with "STOP", "Unsubscribe", etc., TxtCow system automatically detects it and adds to the central blocklist.', 'txtcow-sms' ); ?>
                </p>
                <p class="description">
                    <strong><?php esc_html_e( 'Supported Keywords: STOP, UNSUBSCRIBE, CANCEL, END, QUIT, OPT-OUT, REMOVE', 'txtcow-sms' ); ?></strong>
                </p>
            </div>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="txtcow_blocklist_numbers"><?php esc_html_e( 'Manual Opt-out Numbers', 'txtcow-sms' ); ?></label></th>
                    <td>
                        <textarea id="txtcow_blocklist_numbers" name="<?php echo esc_attr(TXTCOW_BLOCKLIST_OPTION); ?>" rows="5" class="large-text" placeholder="+6421..., 010-...."><?php echo esc_textarea($blocklist); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Numbers you enter here will be automatically synced to TxtCow\'s central blocklist.', 'txtcow-sms' ); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="txtcow_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'txtcow-sms' ); ?>" />
            </p>
            <div class="notice notice-info inline">
                <p>
                    <?php esc_html_e( 'In New Zealand, send messages only for purposes allowed by the Unsolicited Electronic Messages Act 2007, such as quote requests, existing transaction progress or confirmation, warranty, recall, safety, membership or account notices, and government or court notices. Comply with local spam and telecommunications rules in every country, do not send to numbers that opted out, and accept responsibility for all messages you send.', 'txtcow-sms' ); ?>
                </p>
            </div>
        </form>
        </div>

        <!-- 메시지 설정 탭 -->
        <div id="message-settings" class="tab-content" style="display:none;">
            <form method="post" action="">
                <?php wp_nonce_field('txtcow_settings_action', 'txtcow_settings_nonce'); ?>
                    <input type="hidden" name="txtcow_active_tab" class="txtcow-active-tab-input" value="<?php echo esc_attr($active_tab); ?>" />

                <input type="hidden" name="txtcow_api_key" value="<?php echo esc_attr($api_key); ?>" />
                <input type="hidden" name="txtcow_enable_admin_alert" value="<?php echo esc_attr($enable_admin_alert); ?>" />
                <input type="hidden" name="txtcow_admin_phone" value="<?php echo esc_attr($admin_phone); ?>" />
                <input type="hidden" name="txtcow_enable_processing" value="<?php echo esc_attr($enable_processing); ?>" />
                <input type="hidden" name="txtcow_enable_completed" value="<?php echo esc_attr($enable_completed); ?>" />
                <input type="hidden" name="txtcow_enable_cancelled" value="<?php echo esc_attr($enable_cancelled); ?>" />
                <input type="hidden" name="txtcow_delay_mode" value="<?php echo esc_attr($delay_mode); ?>" />
                <input type="hidden" name="txtcow_delay_seconds" value="<?php echo esc_attr($delay_seconds); ?>" />
                <input type="hidden" name="txtcow_delay_min" value="<?php echo esc_attr($delay_min); ?>" />
                <input type="hidden" name="txtcow_delay_max" value="<?php echo esc_attr($delay_max); ?>" />
                <input type="hidden" name="<?php echo esc_attr(TXTCOW_BLOCKLIST_OPTION); ?>" value="<?php echo esc_attr($blocklist); ?>" />

                <div class="notice notice-info inline">
                    <p>
                        <strong><?php esc_html_e( 'Available Variables:', 'txtcow-sms' ); ?></strong><br>
                        <?php echo $this->get_template_variables_help_html(); ?>
                    </p>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="txtcow_message_processing"><?php esc_html_e( 'Order Received Message', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <textarea id="txtcow_message_processing" name="txtcow_message_processing"
                                      rows="3" class="large-text"><?php echo esc_textarea($message_processing); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="txtcow_message_completed"><?php esc_html_e( 'Order Completed Message', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <textarea id="txtcow_message_completed" name="txtcow_message_completed"
                                      rows="3" class="large-text"><?php echo esc_textarea($message_completed); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="txtcow_message_cancelled"><?php esc_html_e( 'Order Cancelled Message', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <textarea id="txtcow_message_cancelled" name="txtcow_message_cancelled"
                                      rows="3" class="large-text"><?php echo esc_textarea($message_cancelled); ?></textarea>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e( 'Message Preview', 'txtcow-sms' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="txtcow_preview_order_id"><?php esc_html_e( 'Preview Order', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <input type="number" id="txtcow_preview_order_id" name="txtcow_preview_order_id" value="<?php echo esc_attr($preview_order ? $preview_order->get_id() : 0); ?>" min="0" />
                            <p class="description"><?php esc_html_e( 'Leave empty to use the most recent order. If no order exists, sample values are used.', 'txtcow-sms' ); ?></p>
                        </td>
                    </tr>
                </table>

                <div class="txtcow-preview-grid">
                    <div class="txtcow-preview-card">
                        <h4><?php esc_html_e( 'Order Received', 'txtcow-sms' ); ?></h4>
                        <pre id="txtcow-preview-processing"><?php
                            $template = $this->resolve_message_template('order_created', $message_processing, $preview_order);
                            echo esc_html($this->render_message_template($template, $preview_order));
                        ?></pre>
                    </div>
                    <div class="txtcow-preview-card">
                        <h4><?php esc_html_e( 'Order Completed', 'txtcow-sms' ); ?></h4>
                        <pre id="txtcow-preview-completed"><?php
                            $template = $this->resolve_message_template('order_shipped', $message_completed, $preview_order);
                            echo esc_html($this->render_message_template($template, $preview_order));
                        ?></pre>
                    </div>
                    <div class="txtcow-preview-card">
                        <h4><?php esc_html_e( 'Order Cancelled', 'txtcow-sms' ); ?></h4>
                        <pre id="txtcow-preview-cancelled"><?php
                            $template = $this->resolve_message_template('order_cancelled', $message_cancelled, $preview_order);
                            echo esc_html($this->render_message_template($template, $preview_order));
                        ?></pre>
                    </div>
                </div>

                <p id="txtcow-preview-order-summary" class="description"><?php echo esc_html($preview_summary); ?></p>

                <p class="submit">
                    <input type="submit" name="txtcow_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Messages', 'txtcow-sms' ); ?>" />
                </p>
            </form>
        </div>

        <!-- 메시지 규칙 탭 -->
        <div id="message-rules" class="tab-content" style="display:none;">
            <form method="post" action="">
                <?php wp_nonce_field('txtcow_settings_action', 'txtcow_settings_nonce'); ?>
                    <input type="hidden" name="txtcow_active_tab" class="txtcow-active-tab-input" value="<?php echo esc_attr($active_tab); ?>" />

                <input type="hidden" name="txtcow_api_key" value="<?php echo esc_attr($api_key); ?>" />
                <input type="hidden" name="txtcow_enable_admin_alert" value="<?php echo esc_attr($enable_admin_alert); ?>" />
                <input type="hidden" name="txtcow_admin_phone" value="<?php echo esc_attr($admin_phone); ?>" />
                <input type="hidden" name="txtcow_enable_processing" value="<?php echo esc_attr($enable_processing); ?>" />
                <input type="hidden" name="txtcow_enable_completed" value="<?php echo esc_attr($enable_completed); ?>" />
                <input type="hidden" name="txtcow_enable_cancelled" value="<?php echo esc_attr($enable_cancelled); ?>" />
                <input type="hidden" name="txtcow_message_processing" value="<?php echo esc_attr($message_processing); ?>" />
                <input type="hidden" name="txtcow_message_completed" value="<?php echo esc_attr($message_completed); ?>" />
                <input type="hidden" name="txtcow_message_cancelled" value="<?php echo esc_attr($message_cancelled); ?>" />
                <input type="hidden" name="txtcow_delay_mode" value="<?php echo esc_attr($delay_mode); ?>" />
                <input type="hidden" name="txtcow_delay_seconds" value="<?php echo esc_attr($delay_seconds); ?>" />
                <input type="hidden" name="txtcow_delay_min" value="<?php echo esc_attr($delay_min); ?>" />
                <input type="hidden" name="txtcow_delay_max" value="<?php echo esc_attr($delay_max); ?>" />
                <input type="hidden" name="<?php echo esc_attr(TXTCOW_BLOCKLIST_OPTION); ?>" value="<?php echo esc_attr($blocklist); ?>" />

                    <div class="notice notice-info inline">
                        <p><strong><?php esc_html_e( 'Conditional Message Rules', 'txtcow-sms' ); ?></strong><br><?php esc_html_e( 'Rules are evaluated from top to bottom. The first matching rule is applied; if none match, the default message template is used.', 'txtcow-sms' ); ?></p>
                    </div>

                    <p class="description"><?php esc_html_e( 'Rule order is the priority. Place specific customer group rules above general rules.', 'txtcow-sms' ); ?></p>
                    <table class="widefat striped txtcow-rules-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Priority', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Enabled', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Rule Name', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Event', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Country', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Minimum Amount', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Maximum Amount', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Product Keyword', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Customer Name Regex', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Message Template', 'txtcow-sms' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'txtcow-sms' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="txtcow-message-rules-body">
                            <?php foreach ($message_rules as $index => $rule): ?>
                                <tr>
                                    <td><?php echo esc_html($index + 1); ?></td>
                                    <td><input type="checkbox" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked(!isset($rule['enabled']) || $rule['enabled']); ?> /></td>
                                    <td><input type="text" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($rule['name'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., VIP order', 'txtcow-sms' ); ?>" /></td>
                                    <td>
                                        <select name="txtcow_message_rules[<?php echo esc_attr($index); ?>][event_type]">
                                            <option value="order_created" <?php selected($rule['event_type'] ?? '', 'order_created'); ?>><?php esc_html_e( 'Order Received', 'txtcow-sms' ); ?></option>
                                            <option value="order_shipped" <?php selected($rule['event_type'] ?? '', 'order_shipped'); ?>><?php esc_html_e( 'Order Completed', 'txtcow-sms' ); ?></option>
                                            <option value="order_cancelled" <?php selected($rule['event_type'] ?? '', 'order_cancelled'); ?>><?php esc_html_e( 'Order Cancelled', 'txtcow-sms' ); ?></option>
                                        </select>
                                    </td>
                                    <td><input type="text" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][country]" value="<?php echo esc_attr($rule['country'] ?? ''); ?>" style="width:70px;" placeholder="KR" /></td>
                                    <td><input type="number" step="0.01" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][min_total]" value="<?php echo esc_attr($rule['min_total'] ?? ''); ?>" style="width:100px;" /></td>
                                    <td><input type="number" step="0.01" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][max_total]" value="<?php echo esc_attr($rule['max_total'] ?? ''); ?>" style="width:100px;" /></td>
                                    <td><input type="text" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][item_keyword]" value="<?php echo esc_attr($rule['item_keyword'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Apple', 'txtcow-sms' ); ?>" /></td>
                                    <td><input type="text" name="txtcow_message_rules[<?php echo esc_attr($index); ?>][customer_name_pattern]" value="<?php echo esc_attr($rule['customer_name_pattern'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., ^Kim', 'txtcow-sms' ); ?>" /></td>
                                    <td><textarea name="txtcow_message_rules[<?php echo esc_attr($index); ?>][template]" rows="3" style="width:100%;"><?php echo esc_textarea($rule['template'] ?? ''); ?></textarea></td>
                                    <td class="txtcow-rule-actions">
                                        <button type="button" class="button button-small" onclick="txtcowMoveRuleRow(this, -1)"><?php esc_html_e( 'Move Up', 'txtcow-sms' ); ?></button>
                                        <button type="button" class="button button-small" onclick="txtcowMoveRuleRow(this, 1)"><?php esc_html_e( 'Move Down', 'txtcow-sms' ); ?></button>
                                        <button type="button" class="button button-small" onclick="txtcowDeleteRuleRow(this)"><?php esc_html_e( 'Delete', 'txtcow-sms' ); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" class="button" onclick="txtcowAddRuleRow()"><?php esc_html_e( 'Add Rule', 'txtcow-sms' ); ?></button></p>
                    <script type="text/html" id="txtcow-rule-row-template">
                        <tr>
                            <td>__INDEX_PLUS_ONE__</td>
                            <td><input type="checkbox" name="txtcow_message_rules[__INDEX__][enabled]" value="1" checked /></td>
                            <td><input type="text" name="txtcow_message_rules[__INDEX__][name]" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., VIP order', 'txtcow-sms' ); ?>" /></td>
                            <td>
                                <select name="txtcow_message_rules[__INDEX__][event_type]">
                                    <option value="order_created"><?php esc_html_e( 'Order Received', 'txtcow-sms' ); ?></option>
                                    <option value="order_shipped"><?php esc_html_e( 'Order Completed', 'txtcow-sms' ); ?></option>
                                    <option value="order_cancelled"><?php esc_html_e( 'Order Cancelled', 'txtcow-sms' ); ?></option>
                                </select>
                            </td>
                            <td><input type="text" name="txtcow_message_rules[__INDEX__][country]" style="width:70px;" placeholder="KR" /></td>
                            <td><input type="number" step="0.01" name="txtcow_message_rules[__INDEX__][min_total]" style="width:100px;" /></td>
                            <td><input type="number" step="0.01" name="txtcow_message_rules[__INDEX__][max_total]" style="width:100px;" /></td>
                            <td><input type="text" name="txtcow_message_rules[__INDEX__][item_keyword]" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Apple', 'txtcow-sms' ); ?>" /></td>
                            <td><input type="text" name="txtcow_message_rules[__INDEX__][customer_name_pattern]" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., ^Kim', 'txtcow-sms' ); ?>" /></td>
                            <td><textarea name="txtcow_message_rules[__INDEX__][template]" rows="3" style="width:100%;"></textarea></td>
                            <td class="txtcow-rule-actions">
                                <button type="button" class="button button-small" onclick="txtcowMoveRuleRow(this, -1)"><?php esc_html_e( 'Move Up', 'txtcow-sms' ); ?></button>
                                <button type="button" class="button button-small" onclick="txtcowMoveRuleRow(this, 1)"><?php esc_html_e( 'Move Down', 'txtcow-sms' ); ?></button>
                                <button type="button" class="button button-small" onclick="txtcowDeleteRuleRow(this)"><?php esc_html_e( 'Delete', 'txtcow-sms' ); ?></button>
                            </td>
                        </tr>
                    </script>

                    <p class="description"><?php esc_html_e( 'Available Variables:', 'txtcow-sms' ); ?><br><?php echo $this->get_template_variables_help_html(); ?></p>
                    <p class="description"><?php esc_html_e( 'Advanced option: enter only the PCRE pattern body for Customer Name Regex. Examples:', 'txtcow-sms' ); ?> <code>^Kim</code>, <code>(VIP|Priority)</code></p>
                    <input type="hidden" name="txtcow_message_rules_json" value="<?php echo esc_attr($message_rules_json); ?>" />

                    <p class="submit">
                        <input type="submit" name="txtcow_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Rules', 'txtcow-sms' ); ?>" />
                    </p>
                </form>
        </div>

        <!-- 지연 설정 탭 -->
        <div id="delay-settings" class="tab-content" style="display:none;">
            <form method="post" action="">
                <?php wp_nonce_field('txtcow_settings_action', 'txtcow_settings_nonce'); ?>
                    <input type="hidden" name="txtcow_active_tab" class="txtcow-active-tab-input" value="<?php echo esc_attr($active_tab); ?>" />

                <input type="hidden" name="txtcow_api_key" value="<?php echo esc_attr($api_key); ?>" />
                <input type="hidden" name="txtcow_enable_admin_alert" value="<?php echo esc_attr($enable_admin_alert); ?>" />
                <input type="hidden" name="txtcow_admin_phone" value="<?php echo esc_attr($admin_phone); ?>" />
                <input type="hidden" name="txtcow_enable_processing" value="<?php echo esc_attr($enable_processing); ?>" />
                <input type="hidden" name="txtcow_enable_completed" value="<?php echo esc_attr($enable_completed); ?>" />
                <input type="hidden" name="txtcow_enable_cancelled" value="<?php echo esc_attr($enable_cancelled); ?>" />
                <input type="hidden" name="txtcow_message_processing" value="<?php echo esc_attr($message_processing); ?>" />
                <input type="hidden" name="txtcow_message_completed" value="<?php echo esc_attr($message_completed); ?>" />
                <input type="hidden" name="txtcow_message_cancelled" value="<?php echo esc_attr($message_cancelled); ?>" />
                <input type="hidden" name="txtcow_message_rules_json" value="<?php echo esc_attr($message_rules_json); ?>" />
                <input type="hidden" name="<?php echo esc_attr(TXTCOW_BLOCKLIST_OPTION); ?>" value="<?php echo esc_attr($blocklist); ?>" />

                <div class="notice notice-info inline">
                    <p><strong><?php esc_html_e( 'Message Delay Policy', 'txtcow-sms' ); ?></strong><br><?php esc_html_e( 'Send customer and admin messages immediately or after a configured delay.', 'txtcow-sms' ); ?></p>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="txtcow_delay_mode"><?php esc_html_e( 'Delay Mode', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <select id="txtcow_delay_mode" name="txtcow_delay_mode" onchange="toggleDelaySettings()">
                                <option value="instant" <?php selected($delay_mode, 'instant'); ?>><?php esc_html_e( 'Send Immediately', 'txtcow-sms' ); ?></option>
                                <option value="fixed_interval_seconds" <?php selected($delay_mode, 'fixed_interval_seconds'); ?>><?php esc_html_e( 'Fixed Delay', 'txtcow-sms' ); ?></option>
                                <option value="random_range_seconds" <?php selected($delay_mode, 'random_range_seconds'); ?>><?php esc_html_e( 'Random Range Delay', 'txtcow-sms' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr id="delay-seconds-row" style="<?php echo $delay_mode === 'fixed_interval_seconds' ? '' : 'display:none;'; ?>">
                        <th scope="row"><label for="txtcow_delay_seconds"><?php esc_html_e( 'Delay Time (seconds)', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <input type="number" id="txtcow_delay_seconds" name="txtcow_delay_seconds" value="<?php echo esc_attr($delay_seconds); ?>" min="0" />
                            <p class="description"><?php esc_html_e( 'Seconds to wait before sending the message.', 'txtcow-sms' ); ?></p>
                        </td>
                    </tr>
                    <tr id="delay-range-row" style="<?php echo $delay_mode === 'random_range_seconds' ? '' : 'display:none;'; ?>">
                        <th scope="row"><label><?php esc_html_e( 'Delay Range (seconds)', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <?php esc_html_e( 'Minimum:', 'txtcow-sms' ); ?> <input type="number" id="txtcow_delay_min" name="txtcow_delay_min" value="<?php echo esc_attr($delay_min); ?>" min="0" style="width:100px;" />
                            <?php esc_html_e( 'Maximum:', 'txtcow-sms' ); ?> <input type="number" id="txtcow_delay_max" name="txtcow_delay_max" value="<?php echo esc_attr($delay_max); ?>" min="0" style="width:100px;" />
                            <p class="description"><?php esc_html_e( 'A random delay is selected within the configured range.', 'txtcow-sms' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="txtcow_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Delay Settings', 'txtcow-sms' ); ?>" />
                    </p>
            </form>
        </div>

        <!-- 연결 상태 탭 -->
        <div id="connection-status" class="tab-content" style="display:none;">
            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e( 'Quick Connect (QR Code)', 'txtcow-sms' ); ?></strong><br><?php esc_html_e( 'Scan the QR code in the mobile app to connect quickly.', 'txtcow-sms' ); ?></p>
            </div>

            <?php if (!empty($api_key)): ?>
                <div id="qr-container" style="margin: 20px 0; text-align: center;">
                    <p><?php esc_html_e( 'Generating QR code...', 'txtcow-sms' ); ?></p>
                </div>

                <h3><?php esc_html_e( 'Connection Status', 'txtcow-sms' ); ?></h3>
                <div id="connection-status-info" style="padding: 15px; border: 1px solid #ddd; margin: 10px 0; border-radius: 4px;">
                    <p><?php esc_html_e( 'Checking status...', 'txtcow-sms' ); ?></p>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    // QR 코드 생성
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'txtcow_get_qr_payload',
                            api_key: '<?php echo esc_js($api_key); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.qr_string) {
                                var html = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(response.data.qr_string) + '" style="max-width:300px;" />';
                                $('#qr-container').html(html);
                            }
                        }
                    });

                    // 연결 상태 확인
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'txtcow_get_connection_status',
                            api_key: '<?php echo esc_js($api_key); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.status) {
                                var status = response.data.status;
                                var statusText = txtcowAdminI18n.unknown;
                                var statusColor = '#999';

                                if (status === 'connected') {
                                    statusText = '✓ ' + txtcowAdminI18n.connected;
                                    statusColor = '#28a745';
                                } else if (status === 'awaiting_device_connection') {
                                    statusText = '⏳ ' + txtcowAdminI18n.awaiting_device_connection;
                                    statusColor = '#ffc107';
                                } else if (status === 'needs_attention') {
                                    statusText = '⚠ ' + txtcowAdminI18n.needs_attention;
                                    statusColor = '#dc3545';
                                }

                                var html = '<p style="font-size: 18px; color: ' + statusColor + '; font-weight: bold;">' + txtcowEscapeHtml(statusText) + '</p>';
                                if (response.data.store_name) {
                                    html += '<p><strong>' + txtcowEscapeHtml(txtcowAdminI18n.store) + '</strong> ' + txtcowEscapeHtml(response.data.store_name) + '</p>';
                                }
                                if (response.data.active_device && response.data.active_device.device_name) {
                                    html += '<p><strong>' + txtcowEscapeHtml(txtcowAdminI18n.active_device) + '</strong> ' + txtcowEscapeHtml(response.data.active_device.device_name) + ' (' + txtcowEscapeHtml(response.data.active_device.device_id) + ')</p>';
                                } else {
                                    html += '<p><strong>' + txtcowEscapeHtml(txtcowAdminI18n.active_device) + '</strong> ' + txtcowEscapeHtml(txtcowAdminI18n.no_active_device) + '</p>';
                                }
                                if (response.data.default_device && response.data.default_device.device_name) {
                                    html += '<p><strong>' + txtcowEscapeHtml(txtcowAdminI18n.default_device) + '</strong> ' + txtcowEscapeHtml(response.data.default_device.device_name) + ' (' + txtcowEscapeHtml(response.data.default_device.device_id) + ')</p>';
                                }
                                if (response.data.last_tested_at) {
                                    html += '<p><strong>' + txtcowEscapeHtml(txtcowAdminI18n.last_tested) + '</strong> ' + txtcowEscapeHtml(response.data.last_tested_at) + '</p>';
                                }
                                if (response.data.last_error) {
                                    html += '<p style="color: #dc3545;"><strong>' + txtcowEscapeHtml(txtcowAdminI18n.recent_error) + '</strong> ' + txtcowEscapeHtml(response.data.last_error) + '</p>';
                                }
                                $('#connection-status-info').html(html);
                            }
                        }
                    });
                });
                </script>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'Please configure the API key first.', 'txtcow-sms' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- 발송 로그 탭 -->
        <div id="delivery-logs" class="tab-content" style="display:none;">
            <h3><?php esc_html_e( 'Delivery History', 'txtcow-sms' ); ?></h3>
            <p><?php esc_html_e( 'Review recent message delivery records.', 'txtcow-sms' ); ?></p>

            <?php if (!empty($api_key)): ?>
                <div id="logs-container" style="margin: 20px 0;">
                    <p><?php esc_html_e( 'Loading logs...', 'txtcow-sms' ); ?></p>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'txtcow_get_commerce_logs',
                            api_key: '<?php echo esc_js($api_key); ?>',
                            page: 1,
                            page_size: 20
                        },
                        success: function(response) {
                            if (response.success && response.data.logs) {
                                var logs = response.data.logs;
                                var html = '<table class="widefat"><thead><tr><th><?php esc_html_e( 'Event', 'txtcow-sms' ); ?></th><th>' + txtcowEscapeHtml(txtcowAdminI18n.recipient) + '</th><th>' + txtcowEscapeHtml(txtcowAdminI18n.status) + '</th><th>' + txtcowEscapeHtml(txtcowAdminI18n.sent_at) + '</th><th>' + txtcowEscapeHtml(txtcowAdminI18n.error) + '</th></tr></thead><tbody>';

                                if (logs.length === 0) {
                                    html += '<tr><td colspan="5" style="text-align:center; padding: 20px;">' + txtcowEscapeHtml(txtcowAdminI18n.no_logs) + '</td></tr>';
                                } else {
                                    logs.forEach(function(log) {
                                        html += '<tr>';
                                        html += '<td>' + txtcowEscapeHtml(log.event_type) + '</td>';
                                        html += '<td>' + txtcowEscapeHtml(log.recipient_phone) + '</td>';
                                        html += '<td><strong>' + txtcowEscapeHtml(log.status) + '</strong></td>';
                                        html += '<td>' + txtcowEscapeHtml(log.created_at || '-') + '</td>';
                                        html += '<td>' + txtcowEscapeHtml(log.failure_reason || '-') + '</td>';
                                        html += '</tr>';
                                    });
                                }

                                html += '</tbody></table>';
                                var logsSummary = String(txtcowAdminI18n.logs_summary || 'Showing %1 of %2 messages').replace('%1', String(logs.length)).replace('%2', String(response.data.total));
                                html += '<p style="margin-top: 10px; color: #666;">' + txtcowEscapeHtml(logsSummary) + '</p>';
                                $('#logs-container').html(html);
                            }
                        }
                    });
                });
                </script>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'Please configure the API key first.', 'txtcow-sms' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- 테스트 SMS 탭 -->
        <div id="test-sms" class="tab-content" style="display:none;">
            <form method="post" action="">
                <?php wp_nonce_field('txtcow_test_action', 'txtcow_test_nonce'); ?>
                <input type="hidden" name="txtcow_active_tab" class="txtcow-active-tab-input" value="<?php echo esc_attr($active_tab); ?>" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="txtcow_test_phone"><?php esc_html_e( 'Phone Number', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <input type="text" id="txtcow_test_phone" name="txtcow_test_phone"
                                   class="regular-text" placeholder="+6421..." required />
                            <p class="description"><?php esc_html_e( 'Enter the phone number that will receive the SMS.', 'txtcow-sms' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="txtcow_test_message"><?php esc_html_e( 'Message', 'txtcow-sms' ); ?></label></th>
                        <td>
                            <textarea id="txtcow_test_message" name="txtcow_test_message"
                                      rows="3" class="large-text" required><?php echo esc_textarea(__( 'This is a test message.', 'txtcow-sms' )); ?></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="txtcow_test_sms" class="button-primary" value="<?php esc_attr_e( 'Send Test SMS', 'txtcow-sms' ); ?>" />
                </p>
            </form>
        </div>
        </div>


        <style>
        .tab-content {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-top: none;
        }
        .txtcow-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        .txtcow-preview-card {
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            padding: 12px;
            background: #f8f9fa;
        }
        .txtcow-preview-card pre {
            white-space: pre-wrap;
            font-family: inherit;
            margin: 0;
        }
        .txtcow-rules-table th,
        .txtcow-rules-table td {
            vertical-align: top;
        }
        .txtcow-rule-actions {
            min-width: 150px;
        }
        .txtcow-rule-actions .button {
            margin: 0 4px 4px 0;
        }
        </style>
        <?php
    }
}

// 플러그인 초기화
function txtcow_sms_init() {
    TxtCow_SMS_Gateway::get_instance();
}
add_action('plugins_loaded', 'txtcow_sms_init');

// 플러그인 활성화 시
register_activation_hook(__FILE__, 'txtcow_sms_activate');
function txtcow_sms_activate() {
    // 기본 설정값 설정
    if (!get_option('txtcow_enable_processing')) {
        add_option('txtcow_enable_processing', '1');
    }
    if (!get_option('txtcow_enable_admin_alert')) {
        add_option('txtcow_enable_admin_alert', '0');
    }
    if (!get_option('txtcow_admin_phone')) {
        add_option('txtcow_admin_phone', '');
    }
    if (!get_option('txtcow_message_processing')) {
        add_option('txtcow_message_processing', txtcow_sms_default_message('processing'));
    }
    if (!get_option('txtcow_message_completed')) {
        add_option('txtcow_message_completed', txtcow_sms_default_message('completed'));
    }
    if (!get_option('txtcow_message_cancelled')) {
        add_option('txtcow_message_cancelled', txtcow_sms_default_message('cancelled'));
    }
    if (!get_option('txtcow_message_rules_json')) {
        add_option('txtcow_message_rules_json', '');
    }
    if (!get_option('txtcow_delay_mode')) {
        add_option('txtcow_delay_mode', 'instant');
    }
    if (!get_option('txtcow_delay_seconds')) {
        add_option('txtcow_delay_seconds', '0');
    }
    if (!get_option('txtcow_delay_min')) {
        add_option('txtcow_delay_min', '0');
    }
    if (!get_option('txtcow_delay_max')) {
        add_option('txtcow_delay_max', '0');
    }
    if (!get_option(TXTCOW_BLOCKLIST_OPTION)) {
        add_option(TXTCOW_BLOCKLIST_OPTION, '');
    }
}

// 플러그인 비활성화 시
register_deactivation_hook(__FILE__, 'txtcow_sms_deactivate');
function txtcow_sms_deactivate() {
    // 필요한 정리 작업
}
