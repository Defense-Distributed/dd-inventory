<?php
/**
 * Webhooks management class
 *
 * @package DD_Inventory
 */

defined('ABSPATH') || exit;

/**
 * Webhooks class
 */
class DDI_Webhooks {

    /**
     * Single instance
     *
     * @var DDI_Webhooks
     */
    private static $instance = null;

    /**
     * Webhook topics to register
     *
     * @var array
     */
    private $webhook_topics = array(
        'order.created',
        'order.updated',
        'order.deleted',
    );

    /**
     * Get the single instance
     *
     * @return DDI_Webhooks
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Schedule webhook registration
        add_action('ddi_register_webhooks', array($this, 'register_webhooks'));

        // Reregister webhooks when URL changes
        add_action('update_option_ddi_settings', array($this, 'on_settings_update'), 10, 2);
    }

    /**
     * Register webhooks for order events
     *
     * @return array Result with success status and message
     */
    public function register_webhooks() {
        $settings = get_option('ddi_settings', array());
        $webhook_url = isset($settings['webhook_url']) ? $settings['webhook_url'] : '';
        $webhook_secret = get_option('ddi_webhook_secret');

        if (empty($webhook_url)) {
            $message = __('Cannot register webhooks: Webhook URL not configured.', 'dd-inventory');
            DDI()->log_sync_event('webhook', 'registration_skipped', $message);
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Delete existing DD Inventory webhooks first
        $this->delete_existing_webhooks();

        $registered = 0;
        $errors = array();

        foreach ($this->webhook_topics as $topic) {
            $result = $this->create_webhook($topic, $webhook_url, $webhook_secret);

            if ($result['success']) {
                $registered++;
            } else {
                $errors[] = $topic . ': ' . $result['message'];
            }
        }

        if ($registered === count($this->webhook_topics)) {
            $message = sprintf(
                __('Successfully registered %d webhooks.', 'dd-inventory'),
                $registered
            );
            DDI()->log_sync_event('webhook', 'registration_complete', $message);
            return array(
                'success' => true,
                'message' => $message,
            );
        } elseif ($registered > 0) {
            $message = sprintf(
                __('Registered %d of %d webhooks. Errors: %s', 'dd-inventory'),
                $registered,
                count($this->webhook_topics),
                implode('; ', $errors)
            );
            DDI()->log_sync_event('webhook', 'registration_partial', $message);
            return array(
                'success' => true,
                'message' => $message,
            );
        } else {
            $message = __('Failed to register webhooks: ', 'dd-inventory') . implode('; ', $errors);
            DDI()->log_sync_event('webhook', 'registration_failed', $message);
            return array(
                'success' => false,
                'message' => $message,
            );
        }
    }

    /**
     * Create a single webhook
     *
     * @param string $topic Webhook topic
     * @param string $url Delivery URL
     * @param string $secret Webhook secret
     * @return array Result array
     */
    private function create_webhook($topic, $url, $secret) {
        try {
            $webhook = new WC_Webhook();

            // Generate a readable name based on topic
            $topic_parts = explode('.', $topic);
            $action = ucfirst($topic_parts[1]);
            $name = sprintf('DD Inventory - Order %s', $action);

            $webhook->set_name($name);
            $webhook->set_topic($topic);
            $webhook->set_delivery_url($url);
            $webhook->set_secret($secret);
            $webhook->set_status('active');
            $webhook->set_user_id(get_current_user_id() ?: 1);
            $webhook->set_api_version('wp_api_v3');

            $webhook_id = $webhook->save();

            if ($webhook_id) {
                DDI()->log_sync_event('webhook', 'created', sprintf(
                    'Created webhook "%s" (ID: %d) for topic %s',
                    $name,
                    $webhook_id,
                    $topic
                ));
                return array('success' => true, 'webhook_id' => $webhook_id);
            } else {
                return array('success' => false, 'message' => 'Failed to save webhook');
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Delete existing DD Inventory webhooks
     */
    private function delete_existing_webhooks() {
        $data_store = WC_Data_Store::load('webhook');
        $webhook_ids = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
        ));

        foreach ($webhook_ids as $webhook_id) {
            $webhook = wc_get_webhook($webhook_id);
            if ($webhook) {
                $webhook->delete(true);
                DDI()->log_sync_event('webhook', 'deleted', sprintf(
                    'Deleted webhook ID: %d',
                    $webhook_id
                ));
            }
        }
    }

    /**
     * Handle settings update
     *
     * @param array $old_value Old settings
     * @param array $new_value New settings
     */
    public function on_settings_update($old_value, $new_value) {
        $old_url = isset($old_value['webhook_url']) ? $old_value['webhook_url'] : '';
        $new_url = isset($new_value['webhook_url']) ? $new_value['webhook_url'] : '';

        // Re-register webhooks if URL changed
        if ($old_url !== $new_url && !empty($new_url)) {
            $this->register_webhooks();
        }
    }

    /**
     * Get list of registered webhooks
     *
     * @return array Array of webhook data
     */
    public function get_registered_webhooks() {
        $data_store = WC_Data_Store::load('webhook');
        $webhook_ids = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
        ));

        $webhooks = array();
        foreach ($webhook_ids as $webhook_id) {
            $webhook = wc_get_webhook($webhook_id);
            if ($webhook) {
                $webhooks[] = array(
                    'id' => $webhook->get_id(),
                    'name' => $webhook->get_name(),
                    'topic' => $webhook->get_topic(),
                    'status' => $webhook->get_status(),
                    'delivery_url' => $webhook->get_delivery_url(),
                    'date_created' => $webhook->get_date_created()->format('Y-m-d H:i:s'),
                );
            }
        }

        return $webhooks;
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload Raw payload
     * @param string $signature Signature from header
     * @return bool
     */
    public static function verify_signature($payload, $signature) {
        $webhook_secret = get_option('ddi_webhook_secret');

        if (empty($webhook_secret) || empty($signature)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $payload, $webhook_secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Pause all webhooks
     */
    public function pause_webhooks() {
        $data_store = WC_Data_Store::load('webhook');
        $webhook_ids = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
            'status' => 'active',
        ));

        foreach ($webhook_ids as $webhook_id) {
            $webhook = wc_get_webhook($webhook_id);
            if ($webhook) {
                $webhook->set_status('paused');
                $webhook->save();
            }
        }

        DDI()->log_sync_event('webhook', 'paused', 'All webhooks paused');
    }

    /**
     * Resume all webhooks
     */
    public function resume_webhooks() {
        $data_store = WC_Data_Store::load('webhook');
        $webhook_ids = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
            'status' => 'paused',
        ));

        foreach ($webhook_ids as $webhook_id) {
            $webhook = wc_get_webhook($webhook_id);
            if ($webhook) {
                $webhook->set_status('active');
                $webhook->save();
            }
        }

        DDI()->log_sync_event('webhook', 'resumed', 'All webhooks resumed');
    }
}
