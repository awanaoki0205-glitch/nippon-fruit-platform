<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Settings {

    const PAGE_SLUG = 'nf-display-settings';

    const OPT_PORTALS       = 'nf_ui_portals';
    const OPT_SHOW_PRICE    = 'nf_ui_show_price';
    const OPT_COLOR         = 'nf_ui_color_scheme';
    const OPT_LAYOUT        = 'nf_ui_layout';
    const OPT_SHOW_REVIEWS  = 'nf_ui_show_reviews';
    const OPT_HEADER_LOGO   = 'nf_ui_header_logo_id';
    const OPT_BRAND_NAME    = 'nf_ui_brand_name';
    const OPT_COMPANY_NAME  = 'nf_ui_company_name';
    const OPT_PROVIDER_NAME = 'nf_ui_provider_name';
    const OPT_SITE_LABEL    = 'nf_ui_site_label';
    const OPT_FRUIT_LABEL   = 'nf_ui_fruit_label';
    const OPT_SEASON_TITLE  = 'nf_ui_season_title';
    const OPT_HEADER_CUSTOM_NAV = 'nf_ui_header_custom_nav';

    const OPT_CATEGORY_NAV_MODE        = 'nf_ui_category_nav_mode';
    const OPT_CATEGORY_NAV_LIMIT       = 'nf_ui_category_nav_limit';
    const OPT_CATEGORY_NAV_HIDE_EMPTY  = 'nf_ui_category_nav_hide_empty';
    const OPT_CATEGORY_NAV_SUPPRESS    = 'nf_ui_category_nav_suppress_parent_child';
    const OPT_CATEGORY_NAV_CUSTOM      = 'nf_ui_category_nav_custom';
    const OPT_CATEGORY_ALLOWED_TOP     = 'nf_ui_category_allowed_top';
    const OPT_MUNICIPALITY_LINK_MODE   = 'nf_ui_municipality_link_mode';
    const OPT_MUNICIPALITY_NAV_MODE    = 'nf_ui_municipality_nav_mode';
    const OPT_CATEGORY_LINK_MODE       = 'nf_ui_category_link_mode';
    const OPT_SIDEBAR_CUSTOM_ENABLED   = 'nf_ui_sidebar_custom_enabled';
    const OPT_SIDEBAR_CUSTOM_HTML      = 'nf_ui_sidebar_custom_html';
    const OPT_SIDEBAR_CUSTOM_CSS       = 'nf_ui_sidebar_custom_css';
    const OPT_SIDEBAR_CUSTOM_MOBILE_HTML = 'nf_ui_sidebar_custom_mobile_html';
    const OPT_SIDEBAR_CUSTOM_MOBILE_CSS  = 'nf_ui_sidebar_custom_mobile_css';

    const OPT_MOBILE_NAV_ENABLED           = 'nf_ui_mobile_nav_enabled';
    const OPT_MOBILE_NAV_PRODUCT_LABEL     = 'nf_ui_mobile_nav_product_label';
    const OPT_MOBILE_NAV_MUNICIPALITY_LABEL= 'nf_ui_mobile_nav_municipality_label';
    const OPT_MOBILE_NAV_FRUIT_LABEL       = 'nf_ui_mobile_nav_fruit_label';
    const OPT_MOBILE_NAV_SEASON_LABEL      = 'nf_ui_mobile_nav_season_label';
    const OPT_MOBILE_NAV_SEARCH_LABEL      = 'nf_ui_mobile_nav_search_label';
    const OPT_MOBILE_NAV_ICON_SIZE         = 'nf_ui_mobile_nav_icon_size';
    const OPT_MOBILE_NAV_LABEL_SIZE        = 'nf_ui_mobile_nav_label_size';
    const OPT_MOBILE_NAV_HEIGHT            = 'nf_ui_mobile_nav_height';
    // Legacy v0.9.8/v0.9.9 options are retained only for automatic migration.
    const OPT_PROMO_ENABLED  = 'nf_ui_promo_enabled';
    const OPT_PROMO_TITLE    = 'nf_ui_promo_title';
    const OPT_PROMO_CONTENT  = 'nf_ui_promo_content';
    const OPT_PROMO_CSS      = 'nf_ui_promo_css';
    const OPT_PROMO_POSITION = 'nf_ui_promo_position';

    const OPT_PROMO_ENABLED_BEFORE = 'nf_ui_promo_enabled_before';
    const OPT_PROMO_ENABLED_AFTER  = 'nf_ui_promo_enabled_after';
    const OPT_PROMO_DUAL_MIGRATED  = 'nf_ui_promo_dual_migrated';

    const OPT_PROMO_TITLE_BEFORE   = 'nf_ui_promo_title_before';
    const OPT_PROMO_CONTENT_BEFORE = 'nf_ui_promo_content_before';
    const OPT_PROMO_CSS_BEFORE     = 'nf_ui_promo_css_before';
    const OPT_PROMO_TITLE_AFTER    = 'nf_ui_promo_title_after';
    const OPT_PROMO_CONTENT_AFTER  = 'nf_ui_promo_content_after';
    const OPT_PROMO_CSS_AFTER      = 'nf_ui_promo_css_after';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'maybe_migrate_promo_settings'), 5);
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'public_assets'), 2);
        add_filter('body_class', array(__CLASS__, 'body_classes'));
        add_filter('option_page_capability_nf_ui_settings', function(){ return 'nf_manage_display'; });
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            'ふるさと納税 かんたん設定',
            'かんたん設定',
            'nf_manage_display',
            self::PAGE_SLUG,
            array(__CLASS__, 'settings_page')
        );
    }

    public static function register_settings() {
        register_setting('nf_ui_settings', self::OPT_PORTALS, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_portals'),'default'=>'both'
        ));
        register_setting('nf_ui_settings', self::OPT_SHOW_PRICE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'1'
        ));
        register_setting('nf_ui_settings', self::OPT_COLOR, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_color'),'default'=>'green'
        ));
        register_setting('nf_ui_settings', self::OPT_LAYOUT, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_layout'),'default'=>'standard'
        ));
        register_setting('nf_ui_settings', self::OPT_SHOW_REVIEWS, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'1'
        ));
        register_setting('nf_ui_settings', self::OPT_BRAND_NAME, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_COMPANY_NAME, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROVIDER_NAME, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_SITE_LABEL, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'ふるさと納税'
        ));
        register_setting('nf_ui_settings', self::OPT_HEADER_LOGO, array(
            'type'=>'integer','sanitize_callback'=>'absint','default'=>0
        ));
        register_setting('nf_ui_settings', self::OPT_FRUIT_LABEL, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_ui_label'),'default'=>'カテゴリ'
        ));
        register_setting('nf_ui_settings', self::OPT_SEASON_TITLE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_ui_title'),'default'=>'今が旬・まもなく発送'
        ));
        register_setting('nf_ui_settings', self::OPT_HEADER_CUSTOM_NAV, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_header_custom_nav'),'default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_NAV_MODE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_category_nav_mode'),'default'=>'auto'
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_NAV_LIMIT, array(
            'type'=>'integer','sanitize_callback'=>array(__CLASS__,'sanitize_category_nav_limit'),'default'=>12
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_NAV_HIDE_EMPTY, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'1'
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_NAV_SUPPRESS, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'1'
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_NAV_CUSTOM, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_category_nav_custom'),'default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_ALLOWED_TOP, array(
            'type'=>'array','sanitize_callback'=>array(__CLASS__,'sanitize_category_allowed_top'),'default'=>array()
        ));
        register_setting('nf_ui_settings', self::OPT_MUNICIPALITY_LINK_MODE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_link_mode'),'default'=>'manual'
        ));
        register_setting('nf_ui_settings', self::OPT_MUNICIPALITY_NAV_MODE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_municipality_nav_mode'),'default'=>'grouped'
        ));
        register_setting('nf_ui_settings', self::OPT_CATEGORY_LINK_MODE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_link_mode'),'default'=>'manual'
        ));
        register_setting('nf_ui_settings', self::OPT_SIDEBAR_CUSTOM_ENABLED, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'0'
        ));
        register_setting('nf_ui_settings', self::OPT_SIDEBAR_CUSTOM_HTML, array(
            'type'=>'string','sanitize_callback'=>'wp_kses_post','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_SIDEBAR_CUSTOM_CSS, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_css'),'default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_SIDEBAR_CUSTOM_MOBILE_HTML, array(
            'type'=>'string','sanitize_callback'=>'wp_kses_post','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_SIDEBAR_CUSTOM_MOBILE_CSS, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_css'),'default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_MOBILE_NAV_ENABLED, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'1'
        ));
        foreach ( array(
            self::OPT_MOBILE_NAV_PRODUCT_LABEL => '返礼品',
            self::OPT_MOBILE_NAV_MUNICIPALITY_LABEL => '自治体',
            self::OPT_MOBILE_NAV_FRUIT_LABEL => 'カテゴリ',
            self::OPT_MOBILE_NAV_SEASON_LABEL => '旬',
            self::OPT_MOBILE_NAV_SEARCH_LABEL => '検索',
        ) as $option_name => $default_label ) {
            register_setting('nf_ui_settings', $option_name, array(
                'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_nav_label'),'default'=>$default_label
            ));
        }
        register_setting('nf_ui_settings', self::OPT_MOBILE_NAV_ICON_SIZE, array(
            'type'=>'integer','sanitize_callback'=>array(__CLASS__,'sanitize_mobile_icon_size'),'default'=>23
        ));
        register_setting('nf_ui_settings', self::OPT_MOBILE_NAV_LABEL_SIZE, array(
            'type'=>'integer','sanitize_callback'=>array(__CLASS__,'sanitize_mobile_label_size'),'default'=>10
        ));
        register_setting('nf_ui_settings', self::OPT_MOBILE_NAV_HEIGHT, array(
            'type'=>'integer','sanitize_callback'=>array(__CLASS__,'sanitize_mobile_nav_height'),'default'=>70
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_ENABLED, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'0'
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_TITLE, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'お知らせ'
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_CONTENT, array(
            'type'=>'string','sanitize_callback'=>'wp_kses_post','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_CSS, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_css'),'default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_POSITION, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_promo_position'),'default'=>'before_season'
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_ENABLED_BEFORE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'0'
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_ENABLED_AFTER, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_checkbox'),'default'=>'0'
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_TITLE_BEFORE, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_CONTENT_BEFORE, array(
            'type'=>'string','sanitize_callback'=>'wp_kses_post','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_CSS_BEFORE, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_css'),'default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_TITLE_AFTER, array(
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_CONTENT_AFTER, array(
            'type'=>'string','sanitize_callback'=>'wp_kses_post','default'=>''
        ));
        register_setting('nf_ui_settings', self::OPT_PROMO_CSS_AFTER, array(
            'type'=>'string','sanitize_callback'=>array(__CLASS__,'sanitize_css'),'default'=>''
        ));
    }

    public static function sanitize_checkbox($v) {
        return ! empty($v) ? '1' : '0';
    }

    public static function sanitize_ui_label($v) {
        $v = sanitize_text_field($v);
        return $v !== '' ? $v : 'カテゴリ';
    }

    public static function sanitize_ui_title($v) {
        $v = sanitize_text_field($v);
        return $v !== '' ? $v : '今が旬・まもなく発送';
    }

    public static function sanitize_nav_label($v) {
        $v = sanitize_text_field($v);
        if ( function_exists('mb_substr') ) $v = mb_substr($v, 0, 8, 'UTF-8');
        return $v;
    }

    public static function sanitize_header_custom_nav($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string)$value);
        $clean = array();
        foreach ((array)$lines as $line) {
            $parts = preg_split('/\s*[|｜\t]\s*/u', trim($line), 2);
            if (count($parts) < 2) continue;
            $label = sanitize_text_field($parts[0]);
            $url = esc_url_raw(trim($parts[1]));
            if ($label === '' || $url === '') continue;
            if (function_exists('mb_substr')) $label = mb_substr($label, 0, 30, 'UTF-8');
            $clean[] = $label . ' | ' . $url;
            if (count($clean) >= 8) break;
        }
        return implode("\n", $clean);
    }

    public static function sanitize_mobile_icon_size($v) {
        return max(18, min(30, absint($v)));
    }

    public static function sanitize_mobile_label_size($v) {
        return max(9, min(13, absint($v)));
    }

    public static function sanitize_mobile_nav_height($v) {
        return max(62, min(84, absint($v)));
    }

    public static function sanitize_portals($v) {
        $v = sanitize_key($v);
        return in_array($v, array('both','rakuten','yahoo'), true) ? $v : 'both';
    }

    public static function sanitize_color($v) {
        $v = sanitize_key($v);

        $allowed = array(
            'green',
            'forest',
            'teal',
            'blue',
            'navy',
            'purple',
            'wine',
            'red',
            'pink',
            'orange',
            'gold',
            'charcoal',
        );

        return in_array($v, $allowed, true)
            ? $v
            : 'green';
    }

    public static function sanitize_layout($v) {
        $v = sanitize_key($v);
        return in_array($v, array('standard','compact','wide'), true) ? $v : 'standard';
    }

    public static function sanitize_category_nav_mode($v) {
        $v = sanitize_key($v);
        return in_array($v, array('auto','parent','child','custom'), true) ? $v : 'auto';
    }

    public static function sanitize_category_nav_limit($v) {
        $v = absint($v);
        if ( $v < 4 ) $v = 4;
        if ( $v > 30 ) $v = 30;
        return $v;
    }

    public static function sanitize_category_nav_custom($v) {
        if ( is_array($v) ) {
            $v = implode("\n", $v);
        }
        $v = (string) $v;
        $lines = preg_split('/\r\n|\r|\n/', $v);
        $clean = array();
        foreach ( (array) $lines as $line ) {
            $line = trim(sanitize_text_field($line));
            if ( $line === '' ) continue;
            $clean[$line] = $line;
        }
        return implode("\n", array_values($clean));
    }

    public static function sanitize_category_allowed_top($value) {
        $clean = array();
        foreach ((array)$value as $slug) {
            $slug = sanitize_title(wp_unslash((string)$slug));
            if ($slug === '') continue;
            $clean[$slug] = $slug;
        }
        return array_values($clean);
    }

    public static function sanitize_link_mode($value) {
        $value = sanitize_key($value);
        return $value === 'assist' ? 'assist' : 'manual';
    }

    public static function sanitize_municipality_nav_mode($value) {
        $value = sanitize_key($value);
        return in_array($value, array('grouped','flat'), true) ? $value : 'grouped';
    }

    public static function sanitize_promo_position($v) {
        $v = sanitize_key($v);
        return in_array($v, array('before_season','after_season','both'), true) ? $v : 'before_season';
    }

    public static function promo_position() {
        return self::sanitize_promo_position(get_option(self::OPT_PROMO_POSITION, 'before_season'));
    }

    /**
     * Convert the former global enable/position setting into two independent
     * switches. This runs once in wp-admin after updating from v0.9.9 or older.
     */
    public static function maybe_migrate_promo_settings() {
        if ( get_option(self::OPT_PROMO_DUAL_MIGRATED, '0') === '1' ) return;

        $legacy_enabled  = get_option(self::OPT_PROMO_ENABLED, '0') === '1';
        $legacy_position = self::promo_position();

        $before_enabled = $legacy_enabled && in_array(
            $legacy_position,
            array('before_season', 'both'),
            true
        );
        $after_enabled = $legacy_enabled && in_array(
            $legacy_position,
            array('after_season', 'both'),
            true
        );

        if ( get_option(self::OPT_PROMO_ENABLED_BEFORE, null) === null ) {
            update_option(self::OPT_PROMO_ENABLED_BEFORE, $before_enabled ? '1' : '0', false);
        }
        if ( get_option(self::OPT_PROMO_ENABLED_AFTER, null) === null ) {
            update_option(self::OPT_PROMO_ENABLED_AFTER, $after_enabled ? '1' : '0', false);
        }

        $legacy_title   = (string)get_option(self::OPT_PROMO_TITLE, 'お知らせ');
        $legacy_content = (string)get_option(self::OPT_PROMO_CONTENT, '');
        $legacy_css     = (string)get_option(self::OPT_PROMO_CSS, '');

        $location_options = array(
            'before_season' => array(
                self::OPT_PROMO_TITLE_BEFORE,
                self::OPT_PROMO_CONTENT_BEFORE,
                self::OPT_PROMO_CSS_BEFORE,
            ),
            'after_season' => array(
                self::OPT_PROMO_TITLE_AFTER,
                self::OPT_PROMO_CONTENT_AFTER,
                self::OPT_PROMO_CSS_AFTER,
            ),
        );

        foreach ( $location_options as $option_names ) {
            list($title_option, $content_option, $css_option) = $option_names;

            if ( trim((string)get_option($title_option, '')) === '' && trim($legacy_title) !== '' ) {
                update_option($title_option, $legacy_title, false);
            }
            if ( trim((string)get_option($content_option, '')) === '' && trim($legacy_content) !== '' ) {
                update_option($content_option, $legacy_content, false);
            }
            if ( trim((string)get_option($css_option, '')) === '' && trim($legacy_css) !== '' ) {
                update_option($css_option, $legacy_css, false);
            }
        }

        update_option(self::OPT_PROMO_DUAL_MIGRATED, '1', false);
    }

    /**
     * Whether the upper or lower owned-content block is enabled.
     *
     * Before the one-time migration has run, this falls back to the legacy
     * global switch/position so the public page remains unchanged.
     */
    public static function promo_enabled_at($location) {
        if ( ! in_array($location, array('before_season','after_season'), true) ) {
            return false;
        }

        $option_name = $location === 'before_season'
            ? self::OPT_PROMO_ENABLED_BEFORE
            : self::OPT_PROMO_ENABLED_AFTER;

        $stored = get_option($option_name, null);
        if ( $stored !== null ) {
            return $stored === '1';
        }

        if ( get_option(self::OPT_PROMO_ENABLED, '0') !== '1' ) return false;

        $legacy_position = self::promo_position();

        return $legacy_position === 'both' || $legacy_position === $location;
    }

    /**
     * The owned-content promo is a landing-page-only element.
     * It must not appear after catalog searches/filters or on individual/taxonomy pages.
     */
    public static function is_promo_landing_request() {
        if ( ! is_post_type_archive(NF_Core::POST_TYPE) ) return false;

        $filter_keys = array(
            'q', 's', 'municipality', 'fruit', 'category', 'subcategory', 'type', 'shipping', 'shipping_month',
            'status', 'order', 'portal', 'yahoo_store', 'price', 'price_range', 'price_min', 'price_max',
            'paged', 'page', 'nf_page'
        );

        foreach ( $filter_keys as $key ) {
            if ( ! isset($_GET[$key]) ) continue;
            $value = wp_unslash($_GET[$key]);
            if ( is_array($value) ) {
                if ( array_filter($value, static function($v){ return trim((string)$v) !== ''; }) ) return false;
            } elseif ( trim((string)$value) !== '' ) {
                return false;
            }
        }

        return true;
    }

    public static function should_render_promo_at($position) {
        if ( ! in_array($position, array('before_season','after_season'), true) ) return false;
        if ( ! self::promo_enabled_at($position) ) return false;
        if ( ! self::is_promo_landing_request() ) return false;

        return true;
    }

    public static function promo_payload($location = '') {
        $legacy_payload = array(
            'title'   => (string)get_option(self::OPT_PROMO_TITLE, 'お知らせ'),
            'content' => (string)get_option(self::OPT_PROMO_CONTENT, ''),
            'css'     => (string)get_option(self::OPT_PROMO_CSS, ''),
        );

        if ( $location === 'before_season' ) {
            $payload = array(
                'title'   => (string)get_option(self::OPT_PROMO_TITLE_BEFORE, ''),
                'content' => (string)get_option(self::OPT_PROMO_CONTENT_BEFORE, ''),
                'css'     => (string)get_option(self::OPT_PROMO_CSS_BEFORE, ''),
            );
        } elseif ( $location === 'after_season' ) {
            $payload = array(
                'title'   => (string)get_option(self::OPT_PROMO_TITLE_AFTER, ''),
                'content' => (string)get_option(self::OPT_PROMO_CONTENT_AFTER, ''),
                'css'     => (string)get_option(self::OPT_PROMO_CSS_AFTER, ''),
            );
        } else {
            return $legacy_payload;
        }

        if ( get_option(self::OPT_PROMO_DUAL_MIGRATED, '0') === '1' ) {
            return $payload;
        }

        // Safe fallback until the one-time migration has run in wp-admin.
        foreach ( array('title','content','css') as $key ) {
            if ( trim((string)$payload[$key]) === '' ) {
                $payload[$key] = $legacy_payload[$key];
            }
        }

        return $payload;
    }

    public static function sanitize_css($css) {
        $css = wp_unslash((string)$css);
        $css = preg_replace('/<\/?style[^>]*>/i', '', $css);
        $css = preg_replace('/expression\s*\(|javascript\s*:/i', '', $css);
        return trim($css);
    }

    public static function portal_mode() {
        $mode = self::sanitize_portals(get_option(self::OPT_PORTALS, 'both'));
        if (class_exists('NF_Commercial_Config')) {
            $rakuten = NF_Commercial_Config::feature('feature_rakuten');
            $yahoo = NF_Commercial_Config::feature('feature_yahoo');
            if ($mode === 'both' && !$yahoo) $mode = 'rakuten';
            if ($mode === 'both' && !$rakuten) $mode = 'yahoo';
            if ($mode === 'yahoo' && !$yahoo && $rakuten) $mode = 'rakuten';
            if ($mode === 'rakuten' && !$rakuten && $yahoo) $mode = 'yahoo';
        }
        return $mode;
    }

    public static function allow_rakuten() {
        return (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_rakuten')) && in_array(self::portal_mode(), array('both','rakuten'), true);
    }

    public static function allow_yahoo() {
        return (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_yahoo')) && in_array(self::portal_mode(), array('both','yahoo'), true);
    }

    public static function show_price() {
        return (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_price')) && get_option(self::OPT_SHOW_PRICE, '1') === '1';
    }

    public static function show_reviews() {
        return (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_review_sort')) && get_option(self::OPT_SHOW_REVIEWS, '1') === '1';
    }

    public static function brand_name() {
        $value = trim((string)get_option(self::OPT_BRAND_NAME, ''));
        if ( $value !== '' ) return $value;
        $site = trim((string)get_bloginfo('name'));
        return $site !== '' ? $site : 'Furusato Catalog';
    }

    public static function company_name() {
        $value = trim((string)get_option(self::OPT_COMPANY_NAME, ''));
        return $value !== '' ? $value : self::brand_name();
    }

    public static function provider_name() {
        $value = trim((string)get_option(self::OPT_PROVIDER_NAME, ''));
        return $value !== '' ? $value : self::company_name();
    }

    public static function site_label() {
        $value = trim((string)get_option(self::OPT_SITE_LABEL, 'ふるさと納税'));
        return $value !== '' ? $value : 'ふるさと納税';
    }

    public static function header_logo_id() {
        return absint(get_option(self::OPT_HEADER_LOGO, 0));
    }

    public static function fruit_label() {
        return self::sanitize_ui_label(get_option(self::OPT_FRUIT_LABEL, 'カテゴリ'));
    }

    public static function season_title() {
        return self::sanitize_ui_title(get_option(self::OPT_SEASON_TITLE, '今が旬・まもなく発送'));
    }

    public static function header_custom_nav_raw() {
        return (string)get_option(self::OPT_HEADER_CUSTOM_NAV, '');
    }

    public static function header_custom_nav_items($archive_url = '') {
        $raw = self::header_custom_nav_raw();
        if (trim($raw) === '') {
            $base = $archive_url ?: (class_exists('NF_System_Page') ? NF_System_Page::url() : home_url('/furusato/'));
            return array(
                array('label'=>'返礼品一覧', 'url'=>$base . '#nf_catalog_products_section'),
                array('label'=>'自治体から探す', 'url'=>$base . '#nf_catalog_municipality_filter_card'),
                array('label'=>'カテゴリから探す', 'url'=>$base . '#nf_catalog_category_filter_card'),
                array('label'=>'今が旬', 'url'=>$base . '#nf_catalog_season_section'),
            );
        }
        $items = array();
        foreach (preg_split('/\r\n|\r|\n/', self::sanitize_header_custom_nav($raw)) as $line) {
            $parts = preg_split('/\s*\|\s*/', $line, 2);
            if (count($parts) === 2) $items[] = array('label'=>$parts[0], 'url'=>$parts[1]);
        }
        return $items;
    }

    public static function category_nav_mode() {
        return self::sanitize_category_nav_mode(get_option(self::OPT_CATEGORY_NAV_MODE, 'auto'));
    }

    public static function category_nav_limit() {
        return self::sanitize_category_nav_limit(get_option(self::OPT_CATEGORY_NAV_LIMIT, 12));
    }

    public static function category_nav_hide_empty() {
        return true;
    }

    public static function category_nav_suppress_parent_child() {
        return get_option(self::OPT_CATEGORY_NAV_SUPPRESS, '1') === '1';
    }

    public static function category_nav_custom() {
        return self::sanitize_category_nav_custom(get_option(self::OPT_CATEGORY_NAV_CUSTOM, ''));
    }

    public static function category_allowed_top_slugs() {
        return self::sanitize_category_allowed_top(
            get_option(self::OPT_CATEGORY_ALLOWED_TOP, array())
        );
    }

    public static function municipality_link_mode() {
        return self::sanitize_link_mode(
            get_option(self::OPT_MUNICIPALITY_LINK_MODE, 'manual')
        );
    }

    public static function municipality_nav_mode() {
        return self::sanitize_municipality_nav_mode(
            get_option(self::OPT_MUNICIPALITY_NAV_MODE, 'grouped')
        );
    }

    public static function category_link_mode() {
        return self::sanitize_link_mode(
            get_option(self::OPT_CATEGORY_LINK_MODE, 'manual')
        );
    }

    public static function municipality_assist_mode() {
        return self::municipality_link_mode() === 'assist';
    }

    public static function category_assist_mode() {
        return self::category_link_mode() === 'assist';
    }

    public static function sidebar_custom_enabled() {
        return get_option(self::OPT_SIDEBAR_CUSTOM_ENABLED, '0') === '1';
    }

    public static function sidebar_custom_html() {
        return wp_kses_post((string)get_option(self::OPT_SIDEBAR_CUSTOM_HTML, ''));
    }

    public static function sidebar_custom_css() {
        return self::sanitize_css(get_option(self::OPT_SIDEBAR_CUSTOM_CSS, ''));
    }

    public static function sidebar_custom_mobile_html() {
        $mobile = wp_kses_post((string)get_option(self::OPT_SIDEBAR_CUSTOM_MOBILE_HTML, ''));
        return trim($mobile) !== '' ? $mobile : self::sidebar_custom_html();
    }

    public static function sidebar_custom_mobile_css() {
        $mobile = self::sanitize_css(get_option(self::OPT_SIDEBAR_CUSTOM_MOBILE_CSS, ''));
        return trim($mobile) !== '' ? $mobile : self::sidebar_custom_css();
    }

    public static function mobile_nav_enabled() {
        return get_option(self::OPT_MOBILE_NAV_ENABLED, '1') === '1';
    }

    public static function mobile_nav_label($key) {
        $map = array(
            'product' => array(self::OPT_MOBILE_NAV_PRODUCT_LABEL, '返礼品'),
            'municipality' => array(self::OPT_MOBILE_NAV_MUNICIPALITY_LABEL, '自治体'),
            'fruit' => array(self::OPT_MOBILE_NAV_FRUIT_LABEL, self::fruit_label()),
            'season' => array(self::OPT_MOBILE_NAV_SEASON_LABEL, '旬'),
            'search' => array(self::OPT_MOBILE_NAV_SEARCH_LABEL, '検索'),
        );
        if ( empty($map[$key]) ) return '';
        return self::sanitize_nav_label(get_option($map[$key][0], $map[$key][1]));
    }

    public static function mobile_nav_icon_size() {
        return self::sanitize_mobile_icon_size(get_option(self::OPT_MOBILE_NAV_ICON_SIZE, 23));
    }

    public static function mobile_nav_label_size() {
        return self::sanitize_mobile_label_size(get_option(self::OPT_MOBILE_NAV_LABEL_SIZE, 10));
    }

    public static function mobile_nav_height() {
        return self::sanitize_mobile_nav_height(get_option(self::OPT_MOBILE_NAV_HEIGHT, 70));
    }

    public static function public_assets() {
        if (!class_exists('NF_Furusato_Header') || !NF_Furusato_Header::is_furusato_context()) {
            return;
        }

        wp_enqueue_style(
            'nippon-fruit-settings-public',
            NF_PLUGIN_URL . 'assets/settings-public.css',
            array(),
            NF_VERSION
        );

        $css_parts = array();

        foreach ( array('before_season','after_season') as $location ) {
            if ( ! self::promo_enabled_at($location) ) continue;

            $payload = self::promo_payload($location);
            $custom_css = trim((string)$payload['css']);

            if ( $custom_css !== '' ) {
                $css_parts[] = $custom_css;
            }
        }

        $css_parts = array_values(array_unique($css_parts));

        if ( $css_parts ) {
            wp_add_inline_style(
                'nippon-fruit-settings-public',
                implode("\n", $css_parts)
            );
        }
    }

    public static function body_classes($classes) {
        if (class_exists('NF_Furusato_Header') && NF_Furusato_Header::is_furusato_context()) {
            $classes[] = 'nf-portals-' . self::portal_mode();
            $classes[] = 'nf-color-' . self::sanitize_color(get_option(self::OPT_COLOR, 'green'));
            $classes[] = 'nf-layout-' . self::sanitize_layout(get_option(self::OPT_LAYOUT, 'standard'));
            if ( ! self::show_price() ) $classes[] = 'nf-price-hidden';
            if ( ! self::show_reviews() ) $classes[] = 'nf-reviews-hidden';
        }

        return $classes;
    }

    /**
     * Format the owned-content block without breaking custom HTML layouts.
     *
     * wpautop() inserts <p>/<br> nodes between block-level elements. Those
     * nodes become unexpected flex/grid children when administrators paste a
     * custom layout into the editor. For explicit block markup we therefore
     * preserve the HTML structure as-is; plain prose keeps WordPress' normal
     * automatic paragraph formatting.
     */
    public static function format_promo_content($content) {
        $content = trim((string)$content);
        if ( $content === '' ) return '';

        $content = wp_kses_post($content);

        $has_layout_markup = (bool) preg_match(
            '/<(?:div|section|article|aside|nav|header|footer|figure|table|ul|ol|dl|form)\b/i',
            $content
        );

        if ( ! $has_layout_markup ) {
            $content = wpautop($content);
            $content = shortcode_unautop($content);
        }

        return do_shortcode($content);
    }

    public static function render_promo_block($location = '') {
        if ( ! self::should_render_promo_at($location) ) return;

        $payload = self::promo_payload($location);
        $content = trim((string)$payload['content']);
        if ( $content === '' ) return;

        $title = trim((string)$payload['title']);
        $classes = array('nf-owned-content-block');

        if ( in_array($location, array('before_season','after_season'), true) ) {
            $classes[] = 'nf-owned-content-block--' . $location;
        }
        if ( $title === '' ) {
            $classes[] = 'nf-owned-content-block--no-title';
        }
        ?>
        <section class="<?php echo esc_attr(implode(' ', $classes)); ?>">
          <?php if ( $title !== '' ) : ?>
            <div class="nf-owned-content-block__head">
              <span>FEATURE CONTENT</span>
              <h2><?php echo esc_html($title); ?></h2>
            </div>
          <?php endif; ?>
          <div class="nf-owned-content-block__body">
            <?php echo self::format_promo_content($content); ?>
          </div>
        </section>
        <?php
    }

    public static function admin_assets($hook) {
        if (
            empty($_GET['page']) ||
            ! in_array(sanitize_key($_GET['page']), array(self::PAGE_SLUG, 'nf-customer-easy-settings', 'nf-customer-display'), true)
        ) return;

        wp_enqueue_media();
    }

    public static function settings_page() {
        if ( ! current_user_can('nf_manage_display') ) return;

        $portals = self::portal_mode();
        $show_price = self::show_price();
        $color = self::sanitize_color(get_option(self::OPT_COLOR, 'green'));
        $layout = self::sanitize_layout(get_option(self::OPT_LAYOUT, 'standard'));
        $show_reviews = self::show_reviews();
        $logo_id = self::header_logo_id();
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <div class="wrap nf-ui-admin">
          <h1>ふるさと納税サイト 表示・デザイン設定</h1>
          <p class="description">
            よく使う設定をこの画面にまとめています。設定後は「変更を保存」を押してください。
          </p>

          <div style="display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 2px">
            <?php if ( current_user_can('edit_posts') ) : ?>
              <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . NF_Core::POST_TYPE)); ?>">返礼品一覧</a>
            <?php endif; ?>
            <?php if ( current_user_can('manage_options') ) : ?>
              <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . NF_Core::POST_TYPE . '&page=nippon-fruit-auto-sync')); ?>">自動同期</a>
              <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . NF_Core::POST_TYPE . '&page=nippon-fruit-yahoo')); ?>">Yahoo!連携</a>
            <?php endif; ?>
            <a class="button" href="<?php echo esc_url(class_exists('NF_System_Page') ? NF_System_Page::url() : home_url('/furusato/')); ?>" target="_blank" rel="noopener">公開ページを確認 ↗</a>
          </div>

          <style>
            .nf-ui-admin{max-width:1120px}
            .nf-ui-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:18px 0}
            .nf-ui-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px}
            .nf-ui-card h2{margin:0 0 6px;font-size:18px}
            .nf-ui-card>p{margin:0 0 16px;color:#646970}
            .nf-ui-choice{display:grid;gap:8px}
            .nf-ui-choice label{display:flex;align-items:flex-start;gap:9px;padding:11px 12px;border:1px solid #dcdcde;border-radius:8px;background:#fafafa}
            .nf-ui-logo-preview{display:flex;align-items:center;gap:12px;margin:10px 0}
            .nf-ui-logo-preview img{max-width:220px;max-height:70px;background:#fff;border:1px solid #ddd;padding:8px}
            .nf-ui-editor-card{grid-column:1/-1}
            .nf-ui-css{width:100%;font-family:monospace}
            .nf-ui-promo-panels{display:grid;gap:18px;margin-top:16px}
            .nf-ui-promo-panel{padding:18px;border:1px solid #dcdcde;border-radius:10px;background:#fbfcfb}
            .nf-ui-promo-panel__head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}
            .nf-ui-promo-panel__head h3{margin:0 0 4px;font-size:16px}
            .nf-ui-promo-panel__head p{margin:0;color:#646970}
            .nf-ui-promo-toggle{display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;padding:8px 11px;border:1px solid #c9d3c8;border-radius:8px;background:#fff;font-weight:700}
            .nf-ui-promo-panel .wp-editor-wrap{margin-top:6px}
            .nf-category-permission-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
            .nf-category-permission-grid label{display:flex;align-items:center;gap:7px;padding:9px 10px;border:1px solid #dcdcde;border-radius:7px;background:#fff}
            .nf-category-permission-grid input{margin:0}
            @media(max-width:900px){.nf-category-permission-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
            @media(max-width:600px){.nf-category-permission-grid{grid-template-columns:1fr}}
            .nf-color-choice-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
            .nf-color-choice{position:relative;display:grid;grid-template-columns:24px minmax(0,1fr);gap:6px 8px;align-items:center;padding:11px;border:2px solid #e0e0e0;border-radius:10px;background:#fff;cursor:pointer}
            .nf-color-choice:hover,.nf-color-choice.is-selected{border-color:var(--choice-color);box-shadow:0 0 0 2px var(--choice-soft)}
            .nf-color-choice input{position:absolute;opacity:0;pointer-events:none}
            .nf-color-choice__swatch{width:22px;height:22px;border-radius:50%;background:var(--choice-color);box-shadow:inset 0 0 0 1px rgba(0,0,0,.08)}
            .nf-color-choice>strong{font-size:13px}
            .nf-color-choice__description{grid-column:2;color:#777;font-size:10px;line-height:1.4}
            .nf-color-choice__preview{grid-column:1/-1;display:flex;align-items:center;gap:6px;margin-top:3px;padding:8px;border-radius:8px;background:var(--choice-soft)}
            .nf-color-choice__preview i,.nf-color-choice__preview b,.nf-color-choice__preview em{font-style:normal;font-size:10px}
            .nf-color-choice__preview i{padding:3px 7px;border-radius:999px;background:#fff;color:var(--choice-color);font-weight:700}
            .nf-color-choice__preview b{padding:5px 10px;border-radius:6px;background:var(--choice-color);color:#fff}
            .nf-color-choice__preview em{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:6px;background:var(--choice-color);color:#fff;font-weight:700}
            @media(max-width:1000px){.nf-color-choice-grid{grid-template-columns:repeat(2,minmax(0,1fr))}} @media(max-width:800px){.nf-ui-grid{grid-template-columns:1fr}.nf-ui-editor-card{grid-column:auto}.nf-color-choice-grid{grid-template-columns:1fr}}
          </style>

          <form method="post" action="options.php">
            <?php settings_fields('nf_ui_settings'); ?>

            <div class="nf-ui-grid">
              <section class="nf-ui-card">
                <h2>① 掲載ポータル</h2>
                <p>公開ページに表示する寄附先を選びます。</p>
                <div class="nf-ui-choice">
                  <?php if ( (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_rakuten')) && (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_yahoo')) ) : ?>
                  <label><input type="radio" name="<?php echo esc_attr(self::OPT_PORTALS); ?>" value="both" <?php checked($portals,'both'); ?>> <span><strong>楽天 + Yahoo!</strong><br><small>両方を表示</small></span></label>
                  <?php endif; ?>
                  <?php if ( !class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_rakuten') ) : ?>
                  <label><input type="radio" name="<?php echo esc_attr(self::OPT_PORTALS); ?>" value="rakuten" <?php checked($portals,'rakuten'); ?>> <span><strong>楽天だけ</strong><br><small>Yahoo!の申込先を公開しない</small></span></label>
                  <?php endif; ?>
                  <?php if ( !class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_yahoo') ) : ?>
                  <label><input type="radio" name="<?php echo esc_attr(self::OPT_PORTALS); ?>" value="yahoo" <?php checked($portals,'yahoo'); ?>> <span><strong>Yahoo!だけ</strong><br><small>楽天の申込先を公開しない</small></span></label>
                  <?php endif; ?>
                </div>
              </section>

              <section class="nf-ui-card">
                <h2>② 価格・クチコミ</h2>
                <p>寄附額と星評価の表示を切り替えます。</p>
                <?php if ( !class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_price') ) : ?>
                  <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_SHOW_PRICE); ?>" value="1" <?php checked($show_price); ?>> 寄附額を表示する</label></p>
                <?php else : ?><p class="description">寄附額表示は現在の契約プランに含まれません。</p><?php endif; ?>
                <?php if ( !class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_review_sort') ) : ?>
                  <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_SHOW_REVIEWS); ?>" value="1" <?php checked($show_reviews); ?>> クチコミ星評価を表示する</label></p>
                <?php else : ?><p class="description">クチコミ表示・レビュー順は現在の契約プランに含まれません。</p><?php endif; ?>
              </section>

              <section class="nf-ui-card">
                <h2>③ カラーバリエーション</h2>
                <p>実際のボタン・ステータスに近い色見本を見ながら選べます。</p>

                <div class="nf-color-choice-grid">
                  <?php
                  $color_choices = array(
                    'green' => array('グリーン（標準）','#25963a','#edf8ef','自然・農産物'),
                    'forest' => array('フォレスト','#2f6b3d','#edf5ef','落ち着いた農産物系'),
                    'teal' => array('ティール','#16847a','#e9f7f5','爽やか・モダン'),
                    'blue' => array('ブルー','#3176c6','#edf5ff','清潔感・親しみ'),
                    'navy' => array('ネイビー','#274f77','#eef4fa','高級感・信頼感'),
                    'purple' => array('パープル','#7451a6','#f4effa','上品・個性的'),
                    'wine' => array('ワイン','#8b3448','#faeff2','百貨店・ギフト感'),
                    'red' => array('レッド','#c5292f','#fff0f0','力強い・目立つ'),
                    'pink' => array('ピンク','#c94f7c','#fff0f6','やわらか・華やか'),
                    'orange' => array('オレンジ','#d46d16','#fff5e9','温かい・活発'),
                    'gold' => array('ゴールド','#9a7a18','#fbf7e9','上質・特別感'),
                    'charcoal' => array('チャコール','#3f4641','#f1f2f1','シンプル・都会的'),
                  );

                  foreach ( $color_choices as $color_key => $color_data ) :
                  ?>
                    <label
                      class="nf-color-choice <?php echo $color === $color_key ? 'is-selected' : ''; ?>"
                      style="--choice-color:<?php echo esc_attr($color_data[1]); ?>;--choice-soft:<?php echo esc_attr($color_data[2]); ?>"
                    >
                      <input
                        type="radio"
                        name="<?php echo esc_attr(self::OPT_COLOR); ?>"
                        value="<?php echo esc_attr($color_key); ?>"
                        <?php checked($color, $color_key); ?>
                      >
                      <span class="nf-color-choice__swatch"></span>
                      <strong><?php echo esc_html($color_data[0]); ?></strong>
                      <small class="nf-color-choice__description">
                        <?php echo esc_html($color_data[3]); ?>
                      </small>
                      <span class="nf-color-choice__preview">
                        <i>受付中</i>
                        <b>検索</b>
                        <em>1</em>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </section>

              <section class="nf-ui-card">
                <h2>④ レイアウト</h2>
                <p>カード間隔やコンテンツ幅を切り替えます。</p>
                <select name="<?php echo esc_attr(self::OPT_LAYOUT); ?>">
                  <option value="standard" <?php selected($layout,'standard'); ?>>標準</option>
                  <option value="compact" <?php selected($layout,'compact'); ?>>コンパクト</option>
                  <option value="wide" <?php selected($layout,'wide'); ?>>ワイド</option>
                </select>
                <hr style="margin:20px 0">
                <h3>自治体の表示方法</h3>
                <p>事業対象の都道府県数に合わせて選択できます。</p>
                <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_MUNICIPALITY_NAV_MODE); ?>" value="flat" <?php checked(self::municipality_nav_mode(),'flat'); ?>> <strong>自治体を直接並べる</strong><br><small>主に1つの都道府県で運用する場合に適しています。</small></label></p>
                <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_MUNICIPALITY_NAV_MODE); ?>" value="grouped" <?php checked(self::municipality_nav_mode(),'grouped'); ?>> <strong>都道府県ごとにまとめる</strong><br><small>複数県で運用する場合に、都道府県を親見出しとして表示します。</small></label></p>
              </section>

              <section class="nf-ui-card">
                <h2>⑤ ブランド・ヘッダー</h2>
                <p>導入事業者ごとのブランド名・会社名・提供事業者名・ロゴを設定できます。会社固有名をコードに固定せず表示します。</p>
                <p><label><strong>ブランド名</strong></label><br><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_BRAND_NAME); ?>" value="<?php echo esc_attr(get_option(self::OPT_BRAND_NAME,'')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></p>
                <p><label><strong>会社・運営者名</strong></label><br><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_COMPANY_NAME); ?>" value="<?php echo esc_attr(get_option(self::OPT_COMPANY_NAME,'')); ?>" placeholder="例：株式会社○○"></p>
                <p><label><strong>商品検索で使う提供事業者名</strong></label><br><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_PROVIDER_NAME); ?>" value="<?php echo esc_attr(get_option(self::OPT_PROVIDER_NAME,'')); ?>" placeholder="例：株式会社○○"></p>
                <p><label><strong>サイトラベル</strong></label><br><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_SITE_LABEL); ?>" value="<?php echo esc_attr(self::site_label()); ?>" placeholder="ふるさと納税"></p>
                <input type="hidden" id="nf_ui_header_logo_id" name="<?php echo esc_attr(self::OPT_HEADER_LOGO); ?>" value="<?php echo esc_attr($logo_id); ?>">
                <div class="nf-ui-logo-preview">
                  <img id="nf_ui_header_logo_preview" src="<?php echo esc_url($logo_url); ?>" alt="" <?php echo $logo_url ? '' : 'style="display:none"'; ?>>
                </div>
                <button type="button" class="button" id="nf_ui_choose_logo">ロゴ画像を選択</button>
                <button type="button" class="button" id="nf_ui_clear_logo">標準ロゴに戻す</button>
                <hr style="margin:20px 0">
                <p><label><strong>ヘッダーのカスタムメニュー</strong></label></p>
                <textarea class="large-text" rows="7" name="<?php echo esc_attr(self::OPT_HEADER_CUSTOM_NAV); ?>" placeholder="返礼品一覧 | /furusato/#nf_catalog_products_section&#10;会社概要 | /company/&#10;特集 | /campaign/"><?php echo esc_textarea(self::header_custom_nav_raw()); ?></textarea>
                <p class="description">1行に「表示文言 | リンク先」を入力します（最大8件）。パソコンでは横並び、スマホではハンバーガーメニュー内に同じ順番で表示します。<code>/furusato/guide/</code>を登録した場合、管理用固定ページのスラッグを<code>furusato-guide</code>にすると自動接続され、専用ヘッダーも継続します。</p>
              </section>

              <section class="nf-ui-card nf-ui-editor-card" id="nf-customer-content-block">
                <h2>⑥ 自社コンテンツ誘導ブロック</h2>
                <p><strong>画像・文章・リンクをまとめて管理する誘導枠です。</strong>復興応援バナーや特集への誘導もここで編集できます。</p>
                <p>
                  /furusato/ の<strong>初期ページだけ</strong>に、自社記事・復興支援・特集などの案内を掲載できます。
                  上側・下側はそれぞれ独立して表示／非表示と内容を設定できます。
                  検索・絞り込み後のURLや返礼品個別ページでは表示しません。
                </p>

                <div class="nf-ui-promo-panels">

                  <section class="nf-ui-promo-panel">
                    <div class="nf-ui-promo-panel__head">
                      <div>
                        <h3>上側</h3>
                        <p>「自治体・カテゴリから探す」と旬特集の間に表示します。</p>
                      </div>
                      <label class="nf-ui-promo-toggle">
                        <input type="hidden" name="<?php echo esc_attr(self::OPT_PROMO_ENABLED_BEFORE); ?>" value="0">
                        <input type="checkbox" name="<?php echo esc_attr(self::OPT_PROMO_ENABLED_BEFORE); ?>" value="1" <?php checked(self::promo_enabled_at('before_season')); ?>>
                        上側を表示する
                      </label>
                    </div>

                    <p>
                      <label><strong>上用 見出し</strong></label><br>
                      <input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr(self::OPT_PROMO_TITLE_BEFORE); ?>" value="<?php echo esc_attr(get_option(self::OPT_PROMO_TITLE_BEFORE,'')); ?>" placeholder="空欄の場合は見出し枠を表示しません">
                    </p>
                    <?php
                    wp_editor(
                        (string)get_option(self::OPT_PROMO_CONTENT_BEFORE,''),
                        'nf_ui_promo_content_before_editor',
                        array(
                            'textarea_name' => self::OPT_PROMO_CONTENT_BEFORE,
                            'textarea_rows' => 12,
                            'media_buttons' => true,
                            'teeny' => false,
                            'quicktags' => true,
                        )
                    );
                    ?>
                    <p style="margin-top:16px"><label><strong>上用CSS（任意）</strong></label></p>
                    <textarea class="nf-ui-css" name="<?php echo esc_attr(self::OPT_PROMO_CSS_BEFORE); ?>" rows="7" placeholder=".nf-owned-content-block--before_season { ... }"><?php echo esc_textarea(get_option(self::OPT_PROMO_CSS_BEFORE,'')); ?></textarea>
                  </section>

                  <section class="nf-ui-promo-panel">
                    <div class="nf-ui-promo-panel__head">
                      <div>
                        <h3>下側</h3>
                        <p>旬特集の直後に表示します。</p>
                      </div>
                      <label class="nf-ui-promo-toggle">
                        <input type="hidden" name="<?php echo esc_attr(self::OPT_PROMO_ENABLED_AFTER); ?>" value="0">
                        <input type="checkbox" name="<?php echo esc_attr(self::OPT_PROMO_ENABLED_AFTER); ?>" value="1" <?php checked(self::promo_enabled_at('after_season')); ?>>
                        下側を表示する
                      </label>
                    </div>

                    <p>
                      <label><strong>下用 見出し</strong></label><br>
                      <input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr(self::OPT_PROMO_TITLE_AFTER); ?>" value="<?php echo esc_attr(get_option(self::OPT_PROMO_TITLE_AFTER,'')); ?>" placeholder="空欄の場合は見出し枠を表示しません">
                    </p>
                    <?php
                    wp_editor(
                        (string)get_option(self::OPT_PROMO_CONTENT_AFTER,''),
                        'nf_ui_promo_content_after_editor',
                        array(
                            'textarea_name' => self::OPT_PROMO_CONTENT_AFTER,
                            'textarea_rows' => 12,
                            'media_buttons' => true,
                            'teeny' => false,
                            'quicktags' => true,
                        )
                    );
                    ?>
                    <p style="margin-top:16px"><label><strong>下用CSS（任意）</strong></label></p>
                    <textarea class="nf-ui-css" name="<?php echo esc_attr(self::OPT_PROMO_CSS_AFTER); ?>" rows="7" placeholder=".nf-owned-content-block--after_season { ... }"><?php echo esc_textarea(get_option(self::OPT_PROMO_CSS_AFTER,'')); ?></textarea>
                  </section>

                </div>

                <section class="nf-ui-promo-panel" style="margin-top:22px">
                  <h3>左カテゴリ下のカスタム枠</h3>
                  <p class="description">パソコンでは「お礼品カテゴリ」の下、スマホでは返礼品一覧の後・注意書きの直前に表示されます。復興応援バナーや特集リンクはここで管理できます。</p>
                  <p><label><input type="hidden" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_ENABLED); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_ENABLED); ?>" value="1" <?php checked(self::sidebar_custom_enabled()); ?>> カスタム枠を表示する</label></p>
                  <h4>パソコン用</h4>
                  <p><label><strong>HTML</strong></label></p>
                  <textarea class="large-text code" rows="10" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_HTML); ?>" placeholder="例：&lt;a href=&quot;/campaign/&quot;&gt;&lt;img src=&quot;...&quot; alt=&quot;特集&quot;&gt;&lt;/a&gt;"><?php echo esc_textarea(self::sidebar_custom_html()); ?></textarea>
                  <p class="description">画像・リンク・見出し・文章などをHTMLで入力できます。JavaScriptなど危険なコードは保存時に除去されます。</p>
                  <p style="margin-top:16px"><label><strong>CSS（任意）</strong></label></p>
                  <textarea class="nf-ui-css" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_CSS); ?>" rows="8" placeholder=".nf-category-sidebar-custom { ... }"><?php echo esc_textarea(self::sidebar_custom_css()); ?></textarea>
                  <h4 style="margin-top:22px">スマホ用</h4>
                  <p class="description">空欄の場合はパソコン用HTML・CSSを自動的に使用します。</p>
                  <p><label><strong>HTML</strong></label></p>
                  <textarea class="large-text code" rows="10" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_MOBILE_HTML); ?>" placeholder="スマホ専用の内容がある場合のみ入力"><?php echo esc_textarea(get_option(self::OPT_SIDEBAR_CUSTOM_MOBILE_HTML, '')); ?></textarea>
                  <p style="margin-top:16px"><label><strong>CSS（任意）</strong></label></p>
                  <textarea class="nf-ui-css" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_MOBILE_CSS); ?>" rows="8" placeholder=".nf-category-sidebar-custom--mobile { ... }"><?php echo esc_textarea(self::sanitize_css(get_option(self::OPT_SIDEBAR_CUSTOM_MOBILE_CSS, ''))); ?></textarea>
                </section>
              </section>

              <?php if ( apply_filters('nf_enable_public_category_settings_ui', false) ) : ?>
              <section class="nf-ui-card nf-ui-editor-card" id="nf-customer-public-categories">
                <h2>⑦ 公開カテゴリ設定</h2>
                <p>/furusato/ の「カテゴリから探す」で、どの階層から見せるかを選べます。商品構成に合わせた自動選定と、手動のカスタム表示を併用できます。</p>

                <?php
                $allowed_top_slugs = self::category_allowed_top_slugs();
                $top_category_terms = taxonomy_exists('nf_category')
                    ? get_terms(array(
                        'taxonomy' => 'nf_category',
                        'hide_empty' => false,
                        'parent' => 0,
                        'orderby' => 'name',
                        'order' => 'ASC',
                    ))
                    : array();
                if (is_wp_error($top_category_terms)) $top_category_terms = array();
                ?>

                <section class="nf-ui-promo-panel" style="margin-bottom:18px">
                  <h3>公開を許可する大カテゴリ</h3>
                  <p>チェックした大カテゴリと、その配下の小カテゴリ・詳細分類だけを公開します。未選択の場合は、商品がある全カテゴリを公開します。</p>
                  <input type="hidden" name="<?php echo esc_attr(self::OPT_CATEGORY_ALLOWED_TOP); ?>[]" value="">
                  <?php if ($top_category_terms) : ?>
                    <div class="nf-category-permission-grid">
                      <?php foreach ($top_category_terms as $top_term) : ?>
                        <label>
                          <input
                            type="checkbox"
                            name="<?php echo esc_attr(self::OPT_CATEGORY_ALLOWED_TOP); ?>[]"
                            value="<?php echo esc_attr($top_term->slug); ?>"
                            <?php checked(in_array($top_term->slug, $allowed_top_slugs, true)); ?>
                          >
                          <span><?php echo esc_html($top_term->name); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php else : ?>
                    <p class="description">カテゴリーマスターの登録後に選択できます。</p>
                  <?php endif; ?>
                </section>

                <section class="nf-ui-promo-panel" style="margin-bottom:18px">
                  <h3>自治体・カテゴリの自動紐付け</h3>
                  <p>通常は手動登録済みの候補だけへ返礼品を紐付けます。おたすけモードでは不足する分類の自動作成も許可します。</p>
                  <p><strong>自治体</strong></p>
                  <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_MUNICIPALITY_LINK_MODE); ?>" value="manual" <?php checked(self::municipality_link_mode(),'manual'); ?>> <strong>原則手動</strong> — 登録済み自治体だけへ自動紐付け（推奨）</label></p>
                  <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_MUNICIPALITY_LINK_MODE); ?>" value="assist" <?php checked(self::municipality_link_mode(),'assist'); ?>> <strong>おたすけモード</strong> — 未登録自治体も検出時に自動作成</label></p>
                  <p style="margin-top:14px"><strong>商品カテゴリ</strong></p>
                  <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_CATEGORY_LINK_MODE); ?>" value="manual" <?php checked(self::category_link_mode(),'manual'); ?>> <strong>原則手動</strong> — 登録済みカテゴリだけへ自動分類（推奨）</label></p>
                  <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_CATEGORY_LINK_MODE); ?>" value="assist" <?php checked(self::category_link_mode(),'assist'); ?>> <strong>おたすけモード</strong> — 標準カテゴリーマスターを自動補完</label></p>
                </section>

                <div class="nf-ui-promo-panels">
                  <section class="nf-ui-promo-panel">
                    <h3>表示方式</h3>
                    <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_MODE); ?>" value="auto" <?php checked(self::category_nav_mode(),'auto'); ?>> <strong>自動</strong> — 実際に商品があるカテゴリを件数・階層から自動選定</label></p>
                    <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_MODE); ?>" value="parent" <?php checked(self::category_nav_mode(),'parent'); ?>> <strong>親カテゴリから</strong> — 大カテゴリから子カテゴリへ辿る</label></p>
                    <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_MODE); ?>" value="child" <?php checked(self::category_nav_mode(),'child'); ?>> <strong>子カテゴリから</strong> — 実商品に近い中カテゴリ・詳細分類から始める</label></p>
                    <p><label><input type="radio" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_MODE); ?>" value="custom" <?php checked(self::category_nav_mode(),'custom'); ?>> <strong>カスタム</strong> — 表示カテゴリと順番を手動指定</label></p>
                  </section>

                  <section class="nf-ui-promo-panel">
                    <h3>自動表示ルール</h3>
                    <p><label><strong>最大表示数</strong><br><input type="number" min="4" max="24" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_LIMIT); ?>" value="<?php echo esc_attr(self::category_nav_limit()); ?>"> 件</label></p>
                    <input type="hidden" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_HIDE_EMPTY); ?>" value="1">
                    <p><strong>商品0件のカテゴリは常に非表示</strong></p>
                    <p><label><input type="hidden" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_SUPPRESS); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_SUPPRESS); ?>" value="1" <?php checked(self::category_nav_suppress_parent_child()); ?>> 親子の重複表示を抑制</label></p>
                  </section>
                </div>

                <p style="margin-top:18px"><label><strong>カスタム表示カテゴリ</strong></label></p>
                <textarea class="large-text" rows="8" name="<?php echo esc_attr(self::OPT_CATEGORY_NAV_CUSTOM); ?>" placeholder="例：&#10;梨&#10;みかん・柑橘&#10;シャインマスカット&#10;野菜"><?php echo esc_textarea(self::category_nav_custom()); ?></textarea>
                <p class="description">1行に1カテゴリ名またはスラッグを入力します。上から順に表示され、親・子・孫カテゴリを混在できます。カスタムモードで使用します。</p>

                <section class="nf-ui-promo-panel" style="margin-top:22px">
                  <h3>左カテゴリ下のカスタム枠</h3>
                  <p class="description">パソコンでは「お礼品カテゴリ」の下、スマホでは返礼品一覧の後・注意書きの直前に表示されます。</p>
                  <p><label><input type="hidden" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_ENABLED); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_ENABLED); ?>" value="1" <?php checked(self::sidebar_custom_enabled()); ?>> カスタム枠を表示する</label></p>
                  <h4>パソコン用</h4>
                  <p><label><strong>HTML</strong></label></p>
                  <textarea class="large-text code" rows="10" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_HTML); ?>" placeholder="例：&lt;a href=&quot;/campaign/&quot;&gt;&lt;img src=&quot;...&quot; alt=&quot;特集&quot;&gt;&lt;/a&gt;\n&lt;p&gt;季節のおすすめ&lt;/p&gt;"><?php echo esc_textarea(self::sidebar_custom_html()); ?></textarea>
                  <p class="description">画像・リンク・見出し・文章などをHTMLで入力できます。JavaScriptなど危険なコードは保存時に除去されます。</p>
                  <p style="margin-top:16px"><label><strong>CSS（任意）</strong></label></p>
                  <textarea class="nf-ui-css" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_CSS); ?>" rows="8" placeholder=".nf-category-sidebar-custom { ... }\n.nf-category-sidebar-custom img { ... }"><?php echo esc_textarea(self::sidebar_custom_css()); ?></textarea>
                  <p class="description">パソコン枠は <code>.nf-category-sidebar-custom--desktop</code> で指定できます。</p>
                  <h4 style="margin-top:22px">スマホ用</h4>
                  <p class="description">空欄の場合はパソコン用HTML・CSSを自動的に使用します。</p>
                  <p><label><strong>HTML</strong></label></p>
                  <textarea class="large-text code" rows="10" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_MOBILE_HTML); ?>" placeholder="スマホ専用の内容がある場合のみ入力"><?php echo esc_textarea(get_option(self::OPT_SIDEBAR_CUSTOM_MOBILE_HTML, '')); ?></textarea>
                  <p style="margin-top:16px"><label><strong>CSS（任意）</strong></label></p>
                  <textarea class="nf-ui-css" name="<?php echo esc_attr(self::OPT_SIDEBAR_CUSTOM_MOBILE_CSS); ?>" rows="8" placeholder=".nf-category-sidebar-custom--mobile { ... }"><?php echo esc_textarea(self::sanitize_css(get_option(self::OPT_SIDEBAR_CUSTOM_MOBILE_CSS, ''))); ?></textarea>
                  <p class="description">スマホ枠は <code>.nf-category-sidebar-custom--mobile</code> で指定できます。</p>
                </section>
              </section>
              <?php endif; ?>

              <section class="nf-ui-card nf-ui-editor-card" id="nf-customer-labels">
                <h2>⑦ 表示文言</h2>
                <p>導入事業者に合わせてカテゴリ導線や旬見出しの文言を調整できます。</p>

                <div class="nf-ui-promo-panels">
                  <section class="nf-ui-promo-panel">
                    <h3>表示文言</h3>
                    <p><label><strong>カテゴリ導線の名称</strong></label><br>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_FRUIT_LABEL); ?>" value="<?php echo esc_attr(self::fruit_label()); ?>" placeholder="例：カテゴリ、品目、返礼品カテゴリ"></p>
                    <p class="description">ヘッダーの「○○から探す」など、カテゴリ導線の表示名に反映します。</p>

                    <p><label><strong>旬特集の見出し</strong></label><br>
                    <input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr(self::OPT_SEASON_TITLE); ?>" value="<?php echo esc_attr(self::season_title()); ?>" placeholder="今が旬・まもなく発送"></p>
                  </section>

                </div>
              </section>
            </div>

            <?php submit_button('変更を保存'); ?>
          </form>

          <script>
          jQuery(function($){
            let frame = null;
            $('#nf_ui_choose_logo').on('click', function(e){
              e.preventDefault();
              if(frame){ frame.open(); return; }
              frame = wp.media({title:'ふるさと納税ヘッダーロゴを選択',button:{text:'この画像を使う'},multiple:false,library:{type:'image'}});
              frame.on('select', function(){
                const item = frame.state().get('selection').first().toJSON();
                $('#nf_ui_header_logo_id').val(item.id || 0);
                $('#nf_ui_header_logo_preview').attr('src', item.url || '').show();
              });
              frame.open();
            });
            $('#nf_ui_clear_logo').on('click', function(e){
              e.preventDefault();
              $('#nf_ui_header_logo_id').val('0');
              $('#nf_ui_header_logo_preview').attr('src','').hide();
            });

            $('.nf-color-choice input').on('change', function(){
              $('.nf-color-choice').removeClass('is-selected');
              $(this).closest('.nf-color-choice').addClass('is-selected');
            });
          });
          </script>
        </div>
        <?php
    }
}
