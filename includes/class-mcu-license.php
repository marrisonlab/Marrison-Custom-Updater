<?php
/**
 * Marrison Commander license verification for WP Master Updater.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCU_License {

    const OPT_KEY       = 'mcu_license_key';
    const OPT_STATUS    = 'mcu_license_status';
    const CRON_HOOK     = 'mcu_license_check';
    const PLUGIN_SLUG   = 'marrison-custom-updater';
    const DEFAULT_CMD_URL = 'https://www.marrisonlab.com/commander/';

    public function __construct() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'revalidate'));

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function commander_base_url() {
        return trailingslashit(self::DEFAULT_CMD_URL);
    }

    public static function commander_endpoint($path) {
        return self::commander_base_url() . ltrim((string) $path, '/');
    }

    public static function get_key() {
        return trim((string) get_option(self::OPT_KEY, ''));
    }

    public static function get_status() {
        $default = array(
            'status'     => 'unchecked',
            'expires_at' => null,
            'quota'      => null,
            'used_today' => null,
            'message'    => '',
            'checked_at' => 0,
            'site_url'   => '',
            'plugin_slug'=> '',
        );
        $saved = get_option(self::OPT_STATUS, array());
        return is_array($saved) ? array_merge($default, $saved) : $default;
    }

    public static function is_active() {
        if (self::get_key() === '') {
            return false;
        }

        $status = self::get_status();
        if (($status['site_url'] ?? '') !== get_site_url()) {
            return false;
        }
        if (($status['plugin_slug'] ?? '') !== self::PLUGIN_SLUG) {
            return false;
        }
        return $status['status'] === 'active';
    }

    public static function status_label() {
        if (self::get_key() === '') {
            return array(
                'label' => __('Nessuna licenza inserita', 'marrison-custom-updater'),
                'badge' => 'mcu-badge-warning',
            );
        }

        $status = self::get_status();
        switch ($status['status']) {
            case 'active':
                return array('label' => __('Licenza attiva', 'marrison-custom-updater'), 'badge' => 'mcu-badge-success');
            case 'expired':
                return array('label' => __('Licenza scaduta', 'marrison-custom-updater'), 'badge' => 'mcu-badge-danger');
            case 'invalid':
                return array('label' => __('Licenza non valida', 'marrison-custom-updater'), 'badge' => 'mcu-badge-danger');
            case 'unreachable':
                return array('label' => __('Commander non raggiungibile', 'marrison-custom-updater'), 'badge' => 'mcu-badge-warning');
            default:
                return array('label' => __('Da verificare', 'marrison-custom-updater'), 'badge' => 'mcu-badge-warning');
        }
    }

    public static function activate($license_key) {
        $license_key = trim((string) $license_key);
        if ($license_key === '') {
            self::clear();
            return array('success' => false, 'message' => __('Inserisci una chiave di licenza.', 'marrison-custom-updater'));
        }

        $response = wp_remote_post(self::commander_endpoint('api/activate'), array(
            'headers' => array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'license_key' => $license_key,
                'site_url'    => get_site_url(),
                'plugin_slug' => self::PLUGIN_SLUG,
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            self::save_status(array(
                'status'     => 'unreachable',
                'message'    => sprintf(__('Impossibile contattare Commander: %s', 'marrison-custom-updater'), $response->get_error_message()),
                'checked_at' => time(),
            ));
            return array('success' => false, 'message' => __('Impossibile contattare il server delle licenze. Riprova piu tardi.', 'marrison-custom-updater'));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : array();

        if ($http_code === 200 && !empty($body['success'])) {
            update_option(self::OPT_KEY, $license_key, false);
            self::save_status(array(
                'status'     => 'active',
                'expires_at' => isset($body['expires_at']) ? $body['expires_at'] : null,
                'quota'      => isset($body['quota']) ? (int) $body['quota'] : null,
                'used_today' => isset($body['used_today']) ? (int) $body['used_today'] : null,
                'message'    => __('Licenza attivata con successo.', 'marrison-custom-updater'),
                'checked_at' => time(),
                'site_url'   => get_site_url(),
                'plugin_slug'=> self::PLUGIN_SLUG,
            ));
            return array('success' => true, 'message' => __('Licenza attivata con successo.', 'marrison-custom-updater'));
        }

        $error_message = isset($body['error']) ? $body['error'] : (isset($body['message']) ? $body['message'] : __('Chiave di licenza non valida o scaduta.', 'marrison-custom-updater'));
        self::save_status(array(
            'status'     => 'invalid',
            'message'    => $error_message,
            'checked_at' => time(),
        ));

        return array('success' => false, 'message' => $error_message);
    }

    public static function revalidate() {
        $license_key = self::get_key();
        if ($license_key === '') {
            return;
        }

        $response = wp_remote_post(self::commander_endpoint('api/validate'), array(
            'headers' => array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'license_key' => $license_key,
                'site_url'    => get_site_url(),
                'plugin_slug' => self::PLUGIN_SLUG,
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return;
        }

        if (!empty($body['success'])) {
            self::save_status(array(
                'status'     => 'active',
                'expires_at' => isset($body['expires_at']) ? $body['expires_at'] : null,
                'quota'      => isset($body['quota']) ? (int) $body['quota'] : null,
                'used_today' => isset($body['used_today']) ? (int) $body['used_today'] : null,
                'message'    => '',
                'checked_at' => time(),
                'site_url'   => get_site_url(),
                'plugin_slug'=> self::PLUGIN_SLUG,
            ));
        } else {
            $status = (isset($body['status']) && $body['status'] === 'expired') ? 'expired' : 'invalid';
            self::save_status(array(
                'status'     => $status,
                'message'    => isset($body['error']) ? $body['error'] : __('Licenza non piu valida.', 'marrison-custom-updater'),
                'checked_at' => time(),
            ));
        }
    }

    public static function clear() {
        delete_option(self::OPT_KEY);
        delete_option(self::OPT_STATUS);
    }

    private static function save_status(array $data) {
        $current = self::get_status();
        update_option(self::OPT_STATUS, array_merge($current, $data), false);
    }
}
