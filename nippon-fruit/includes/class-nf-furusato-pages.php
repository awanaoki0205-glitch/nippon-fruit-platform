<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * カスタムメニューに登録した /furusato/{name}/ を、管理用固定ページ
 * furusato-{name} へ安全に割り当てる。
 */
class NF_Furusato_Pages {
    const QUERY_VAR = 'nf_furusato_page_alias';
    const RULE_VERSION = '2026-09-02-1';
    const RULE_OPTION = 'nf_furusato_page_rule_version';

    public static function init() {
        add_action('init', array(__CLASS__, 'rewrite_rules'), 40);
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_filter('redirect_canonical', array(__CLASS__, 'prevent_alias_canonical'), 10, 2);
        add_filter('page_link', array(__CLASS__, 'page_link'), 10, 2);
        add_action('template_redirect', array(__CLASS__, 'redirect_management_url'), 1);
        add_action('admin_init', array(__CLASS__, 'maybe_flush_rules'));
        add_action('update_option_' . NF_Settings::OPT_HEADER_CUSTOM_NAV, array(__CLASS__, 'nav_updated'), 10, 2);
        add_action('add_option_' . NF_Settings::OPT_HEADER_CUSTOM_NAV, array(__CLASS__, 'nav_added'), 10, 2);
    }

    public static function activate() {
        self::rewrite_rules();
        flush_rewrite_rules(false);
        update_option(self::RULE_OPTION, self::RULE_VERSION, false);
    }

    private static function archive_path() {
        $archive = class_exists('NF_System_Page') ? NF_System_Page::url() : (get_post_type_archive_link(NF_Core::POST_TYPE) ?: home_url('/furusato/'));
        return '/' . trim((string)wp_parse_url($archive, PHP_URL_PATH), '/');
    }

    public static function mappings() {
        $archive = class_exists('NF_System_Page') ? NF_System_Page::url() : (get_post_type_archive_link(NF_Core::POST_TYPE) ?: home_url('/furusato/'));
        $archive_path = self::archive_path();
        $home_host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        $items = NF_Settings::header_custom_nav_items($archive);
        $out = array();

        foreach ((array)$items as $item) {
            $url = isset($item['url']) ? (string)$item['url'] : '';
            $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $host !== $home_host) continue;

            $path = '/' . trim((string)wp_parse_url($url, PHP_URL_PATH), '/');
            $prefix = trailingslashit($archive_path);
            if (strpos($path . '/', $prefix) !== 0) continue;

            $child = trim(substr($path, strlen($archive_path)), '/');
            if ($child === '' || strpos($child, '/') !== false) continue;
            $child = sanitize_title($child);
            if ($child === '' || in_array($child, array('item','municipality','category','fruit'), true)) continue;

            $out[$child] = sanitize_title((class_exists('NF_System_Page') ? NF_System_Page::base() : 'furusato') . '-' . $child);
        }
        return $out;
    }

    public static function rewrite_rules() {
        $base = trim(self::archive_path(), '/');
        foreach (self::mappings() as $alias => $page_slug) {
            add_rewrite_rule(
                '^' . preg_quote($base, '#') . '/' . preg_quote($alias, '#') . '/?$',
                'index.php?pagename=' . rawurlencode($page_slug) . '&' . self::QUERY_VAR . '=' . rawurlencode($alias),
                'top'
            );
        }
    }

    public static function query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function prevent_alias_canonical($redirect, $requested) {
        return get_query_var(self::QUERY_VAR) ? false : $redirect;
    }

    private static function alias_url($alias) {
        return home_url(trailingslashit(trim(self::archive_path(), '/') . '/' . $alias));
    }

    public static function page_link($link, $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') return $link;
        $alias = array_search($post->post_name, self::mappings(), true);
        return $alias !== false ? self::alias_url($alias) : $link;
    }

    public static function redirect_management_url() {
        if (!is_page() || get_query_var(self::QUERY_VAR)) return;
        $post = get_queried_object();
        if (!$post || empty($post->post_name)) return;
        $alias = array_search($post->post_name, self::mappings(), true);
        if ($alias === false) return;
        wp_safe_redirect(self::alias_url($alias), 301);
        exit;
    }

    public static function nav_updated($old_value, $value) {
        if ((string)$old_value === (string)$value) return;
        self::rewrite_rules();
        flush_rewrite_rules(false);
    }

    public static function nav_added($option, $value) {
        self::rewrite_rules();
        flush_rewrite_rules(false);
    }

    public static function maybe_flush_rules() {
        if (get_option(self::RULE_OPTION, '') === self::RULE_VERSION) return;
        self::rewrite_rules();
        flush_rewrite_rules(false);
        update_option(self::RULE_OPTION, self::RULE_VERSION, false);
    }
}
