<?php
if ( ! defined('ABSPATH') ) exit;

/** Public URL source of truth. Internal nf_* identifiers intentionally stay stable. */
class NF_System_Page {
    const OPTION_PAGE_ID = 'nf_commercial_system_page_id';
    const OPTION_OLD_BASE = 'nf_commercial_previous_base';
    const OPTION_ACTIVE_BASE = 'nf_commercial_active_base';
    const LEGACY_BASE = 'furusato';

    public static function init() {
        add_action('update_option_' . self::OPTION_PAGE_ID, array(__CLASS__, 'page_changed'), 10, 2);
        add_action('add_option_' . self::OPTION_PAGE_ID, array(__CLASS__, 'page_added'), 10, 2);
        add_action('admin_init', array(__CLASS__, 'maybe_sync_rewrite'));
        add_action('template_redirect', array(__CLASS__, 'redirect_previous_base'), 0);
    }

    public static function migrate_legacy() {
        if (get_option(self::OPTION_PAGE_ID, null) !== null) return;
        $page = get_page_by_path(self::LEGACY_BASE, OBJECT, 'page');
        add_option(self::OPTION_PAGE_ID, $page ? (int)$page->ID : 0, '', false);
        add_option(self::OPTION_OLD_BASE, self::LEGACY_BASE, '', false);
        add_option(self::OPTION_ACTIVE_BASE, self::LEGACY_BASE, '', false);
    }

    public static function page_id() {
        $id = absint(get_option(self::OPTION_PAGE_ID, 0));
        return $id && get_post_type($id) === 'page' ? $id : 0;
    }

    public static function base() {
        $id = self::page_id();
        $uri = $id ? trim((string)get_page_uri($id), '/') : '';
        return $uri !== '' ? $uri : self::LEGACY_BASE;
    }

    public static function url() {
        $id = self::page_id();
        $url = $id ? get_permalink($id) : '';
        return $url ? trailingslashit($url) : home_url('/' . trailingslashit(self::base()));
    }

    public static function item_rewrite() { return self::base() . '/item'; }
    public static function taxonomy_rewrite($name) { return self::base() . '/' . sanitize_title($name); }

    public static function is_system_page() {
        $id = self::page_id();
        return $id > 0 && is_page($id);
    }

    public static function page_changed($old, $new) {
        if ((int)$old === (int)$new) return;
        $old_uri = $old && get_post_type((int)$old) === 'page' ? trim((string)get_page_uri((int)$old), '/') : self::LEGACY_BASE;
        update_option(self::OPTION_OLD_BASE, $old_uri ?: self::LEGACY_BASE, false);
        update_option(self::OPTION_ACTIVE_BASE, self::base(), false);
        flush_rewrite_rules(false);
    }

    public static function page_added($option, $value) { flush_rewrite_rules(false); }

    public static function maybe_sync_rewrite() {
        $base = self::base();
        $active = trim((string)get_option(self::OPTION_ACTIVE_BASE, self::LEGACY_BASE), '/');
        if ($active === $base) return;
        update_option(self::OPTION_OLD_BASE, $active ?: self::LEGACY_BASE, false);
        update_option(self::OPTION_ACTIVE_BASE, $base, false);
        flush_rewrite_rules(false);
    }

    public static function redirect_previous_base() {
        if (is_admin() || !is_404()) return;
        $old = trim((string)get_option(self::OPTION_OLD_BASE, ''), '/');
        $new = self::base();
        if ($old === '' || $old === $new) return;
        $path = isset($_SERVER['REQUEST_URI']) ? (string)wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        $trimmed = trim(rawurldecode($path), '/');
        if ($trimmed !== $old && strpos($trimmed . '/', trailingslashit($old)) !== 0) return;
        $suffix = ltrim(substr($trimmed, strlen($old)), '/');
        $target = home_url('/' . trailingslashit($new . ($suffix !== '' ? '/' . $suffix : '')));
        wp_safe_redirect($target, 301);
        exit;
    }
}
