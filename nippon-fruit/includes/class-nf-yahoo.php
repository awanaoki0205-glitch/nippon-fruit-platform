<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Yahoo {

    const OPTION_CLIENT_ID = 'nf_yahoo_client_id';
    const OPTION_VC_AFFILIATE_ID = 'nf_yahoo_vc_affiliate_id';
    const OPTION_SEARCH_KEYWORD = 'nf_yahoo_search_keyword'; // legacy
    const OPTION_SEARCH_ROUTES = 'nf_yahoo_search_routes';
    const OPTION_SELLER_ID = 'nf_yahoo_seller_id';
    const OPTION_AUTO_DISCOVERY = 'nf_yahoo_auto_discovery';
    const OPTION_SATOFULL_MERGE_POLICY = 'nf_yahoo_satofull_merge_policy';
    const OPTION_UNMATCHED = 'nf_yahoo_unmatched';
    const OPTION_CURSOR = 'nf_yahoo_sync_cursor';
    const OPTION_LAST_DISCOVERY = 'nf_yahoo_last_discovery';
    const OPTION_LAST_SYNC = 'nf_yahoo_last_sync';
    const PAGE_SLUG = 'nippon-fruit-yahoo';

    const META_QUARANTINED_VARIANTS = '_nf_yahoo_quarantined_variants';
    const META_REVIEW_VARIANTS = '_nf_yahoo_review_variants';
    const META_AUDIT_OVERRIDES = '_nf_yahoo_audit_overrides';
    const OPTION_LAST_AUDIT = 'nf_yahoo_last_audit';

    // v0.7.1 content matching
    const AUTO_MATCH_THRESHOLD = 82;
    const AUTO_MATCH_MARGIN = 8;

    const SEARCH_ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V3/itemSearch';
    const LOOKUP_ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V1/itemLookup';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));

        add_action(
            'add_meta_boxes_' . NF_Core::POST_TYPE,
            array(__CLASS__, 'add_meta_box')
        );
        add_action(
            'save_post_' . NF_Core::POST_TYPE,
            array(__CLASS__, 'save_post_meta'),
            20
        );

        add_action(
            'admin_post_nf_yahoo_test',
            array(__CLASS__, 'admin_test')
        );
        add_action(
            'admin_post_nf_yahoo_discover',
            array(__CLASS__, 'admin_discover')
        );
        add_action(
            'admin_post_nf_yahoo_sync_all',
            array(__CLASS__, 'admin_sync_all')
        );

        add_action(
            'admin_post_nf_yahoo_sync_missing_images',
            array(__CLASS__, 'admin_sync_missing_images')
        );

        add_action(
            'admin_post_nf_yahoo_sync_post_image',
            array(__CLASS__, 'admin_sync_post_image')
        );
        add_action(
            'admin_post_nf_yahoo_manual_link',
            array(__CLASS__, 'admin_manual_link')
        );
        add_action(
            'admin_post_nf_yahoo_unlink',
            array(__CLASS__, 'admin_unlink')
        );

        // v0.6.0の15分Cronに相乗り。
        add_action(
            'nf_auto_sync_tick',
            array(__CLASS__, 'auto_sync_tick'),
            30
        );

        add_action('init', array(__CLASS__, 'maybe_upgrade_072'), 25);
        add_action('init', array(__CLASS__, 'maybe_upgrade_075'), 26);
        add_action('init', array(__CLASS__, 'maybe_upgrade_0941'), 27);
        add_action('init', array(__CLASS__, 'maybe_upgrade_0942'), 28);
        add_action(
            'update_option_' . self::OPTION_SATOFULL_MERGE_POLICY,
            array(__CLASS__, 'merge_policy_updated'),
            10,
            3
        );

        add_action(
            'admin_post_nf_yahoo_reset_routes',
            array(__CLASS__, 'admin_reset_routes')
        );

        add_action(
            'admin_post_nf_yahoo_audit_all',
            array(__CLASS__, 'admin_audit_all')
        );

        add_action(
            'admin_post_nf_yahoo_restore_quarantine',
            array(__CLASS__, 'admin_restore_quarantine')
        );

        add_action(
            'admin_notices',
            array(__CLASS__, 'admin_image_sync_notice')
        );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            'Yahoo!ショッピング連携',
            'Yahoo!連携',
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'settings_page')
        );
    }

    public static function register_settings() {
        register_setting(
            'nf_yahoo_settings',
            self::OPTION_CLIENT_ID,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_simple_secret'),
                'default' => '',
            )
        );

        register_setting(
            'nf_yahoo_settings',
            self::OPTION_VC_AFFILIATE_ID,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_affiliate_id'),
                'default' => '',
            )
        );

        register_setting(
            'nf_yahoo_settings',
            self::OPTION_SEARCH_KEYWORD,
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            )
        );

        register_setting(
            'nf_yahoo_settings',
            self::OPTION_SEARCH_ROUTES,
            array(
                'type' => 'array',
                'sanitize_callback' => array(__CLASS__, 'sanitize_search_routes'),
                'default' => array(),
            )
        );

        register_setting(
            'nf_yahoo_settings',
            self::OPTION_SELLER_ID,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_seller_id'),
                'default' => 'y-sf',
            )
        );

        register_setting(
            'nf_yahoo_settings',
            self::OPTION_AUTO_DISCOVERY,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_checkbox'),
                'default' => '1',
            )
        );

        register_setting(
            'nf_yahoo_settings',
            self::OPTION_SATOFULL_MERGE_POLICY,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_merge_policy'),
                'default' => 'separate',
            )
        );
    }

    public static function sanitize_simple_secret( $value ) {
        return trim(preg_replace('/[\r\n\t]+/', '', (string)$value));
    }

    public static function sanitize_affiliate_id( $value ) {
        $value = trim((string)$value);

        // ValueCommerce広告コードを丸ごと貼った場合はhrefだけ抽出。
        if (
            strpos($value, '<') !== false &&
            preg_match('/href=["\']([^"\']+)["\']/i', $value, $m)
        ) {
            $value = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $value = preg_replace('/[\r\n\t]+/', '', $value);

        return trim($value);
    }

    public static function sanitize_checkbox( $value ) {
        return ! empty($value) ? '1' : '0';
    }

    public static function sanitize_seller_id( $value ) {
        return sanitize_key(trim((string)$value));
    }

    public static function sanitize_merge_policy( $value ) {
        $value = sanitize_key((string)$value);
        return in_array($value, array('separate','management','normal'), true)
            ? $value
            : 'separate';
    }

    private static function satofull_merge_policy() {
        return self::sanitize_merge_policy(get_option(
            self::OPTION_SATOFULL_MERGE_POLICY,
            'separate'
        ));
    }

    private static function is_satofull_hit( $hit ) {
        $seller_id = isset($hit['sellerId']) ? sanitize_key($hit['sellerId']) : '';
        $seller_name = isset($hit['sellerName']) ? (string)$hit['sellerName'] : '';
        $url = isset($hit['url']) ? (string)$hit['url'] : '';

        return $seller_id === 'y-sf' ||
            mb_stripos($seller_name, 'さとふる', 0, 'UTF-8') !== false ||
            stripos($seller_name, 'satofull') !== false ||
            strpos($url, '/y-sf/') !== false;
    }

    private static function default_search_routes() {
        $legacy_keyword = trim((string)get_option(
            self::OPTION_SEARCH_KEYWORD,
            class_exists('NF_Settings') ? NF_Settings::brand_name() : ''
        ));

        if ( $legacy_keyword === '' ) {
            $legacy_keyword = class_exists('NF_Settings') ? NF_Settings::provider_name() : '';
        }

        return array(
            array(
                'id' => 'route_default_keyword',
                'enabled' => 1,
                'name' => '通常検索',
                'type' => 'keyword',
                'query' => $legacy_keyword,
                'municipality' => '',
                'providerPrefix' => '',
            ),
            array(
                'id' => 'route_yatsushiro_232',
                'enabled' => 1,
                'name' => '八代市 232',
                'type' => 'provider-prefix',
                'query' => '八代市 232',
                'municipality' => '八代市',
                'providerPrefix' => '232',
            ),
        );
    }

    public static function sanitize_search_routes( $value ) {
        if ( ! is_array($value) ) {
            return array();
        }

        $municipalities = self::kumamoto_municipalities();
        $clean = array();

        foreach ( array_slice(array_values($value), 0, 40) as $index => $route ) {
            if ( ! is_array($route) ) {
                continue;
            }

            $type = isset($route['type'])
                ? sanitize_key($route['type'])
                : 'keyword';

            if ( ! in_array($type, array('keyword','provider-prefix'), true) ) {
                $type = 'keyword';
            }

            $name = isset($route['name'])
                ? sanitize_text_field($route['name'])
                : '';

            $query = isset($route['query'])
                ? sanitize_text_field($route['query'])
                : '';

            $municipality = isset($route['municipality'])
                ? sanitize_text_field($route['municipality'])
                : '';

            if (
                $municipality !== '' &&
                ! in_array($municipality, $municipalities, true)
            ) {
                $municipality = '';
            }

            $prefix = isset($route['providerPrefix'])
                ? trim((string)$route['providerPrefix'])
                : '';

            $prefix = preg_replace(
                '/[^A-Za-z0-9_-]/',
                '',
                $prefix
            );

            if (
                $type === 'provider-prefix' &&
                $query === '' &&
                $municipality !== '' &&
                $prefix !== ''
            ) {
                $query = trim($municipality . ' ' . $prefix);
            }

            if ( $query === '' ) {
                continue;
            }

            $id = isset($route['id'])
                ? sanitize_key($route['id'])
                : '';

            if ( $id === '' ) {
                $id =
                    'route_' .
                    substr(
                        md5(
                            $type . '|' .
                            $query . '|' .
                            $municipality . '|' .
                            $prefix . '|' .
                            $index
                        ),
                        0,
                        12
                    );
            }

            if ( $name === '' ) {
                $name = $type === 'provider-prefix'
                    ? '事業者識別検索'
                    : '通常検索';
            }

            $clean[] = array(
                'id' => $id,
                'enabled' => ! empty($route['enabled']) ? 1 : 0,
                'name' => $name,
                'type' => $type,
                'query' => $query,
                'municipality' => $municipality,
                'providerPrefix' => $prefix,
            );
        }

        return $clean;
    }

    private static function search_routes( $include_disabled = false ) {
        $routes = get_option(
            self::OPTION_SEARCH_ROUTES,
            array()
        );

        $routes = self::sanitize_search_routes($routes);

        if ( ! $routes ) {
            $routes = self::default_search_routes();
        }

        if ( $include_disabled ) {
            return array_values($routes);
        }

        return array_values(array_filter(
            $routes,
            function($route) {
                return ! empty($route['enabled']);
            }
        ));
    }

    public static function maybe_upgrade_075() {
        if ( get_option('nf_yahoo_075_migrated', '') === '1' ) {
            return;
        }

        $existing = get_option(
            self::OPTION_SEARCH_ROUTES,
            null
        );

        if ( ! is_array($existing) || ! $existing ) {
            update_option(
                self::OPTION_SEARCH_ROUTES,
                self::default_search_routes(),
                false
            );
        }

        update_option(
            'nf_yahoo_075_migrated',
            '1',
            false
        );
    }

    /** v0.9.41: 既存の楽天カードから、さとふるストアのYahoo!掲載を分離する。 */
    public static function maybe_upgrade_0941() {
        if (get_option('nf_yahoo_0941_separated', '') === '1') return;
        if (self::satofull_merge_policy() !== 'separate') {
            update_option('nf_yahoo_0941_separated', '1', false);
            return;
        }

        if (self::separate_existing_satofull_variants()) {
            update_option('nf_yahoo_0941_separated', '1', false);
        }
    }

    public static function merge_policy_updated($old_value, $value, $option) {
        if (self::sanitize_merge_policy($value) === 'separate') {
            self::separate_existing_satofull_variants();
            self::split_existing_satofull_cards();
            update_option('nf_yahoo_0941_separated', '1', false);
            update_option('nf_yahoo_0942_split_variants', '1', false);
        }
    }

    /** v0.9.42: 容量・玉数・サイズ別のYahoo!商品コードを1カードずつに分割する。 */
    public static function maybe_upgrade_0942() {
        if (get_option('nf_yahoo_0942_split_variants', '') === '1') return;
        if (self::satofull_merge_policy() !== 'separate') {
            update_option('nf_yahoo_0942_split_variants', '1', false);
            return;
        }

        if (
            self::separate_existing_satofull_variants() &&
            self::split_existing_satofull_cards()
        ) {
            update_option('nf_yahoo_0942_split_variants', '1', false);
        }
    }

    private static function separate_existing_satofull_variants() {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array('publish','draft','pending','private','future'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ((array)$ids as $post_id) {
            if (get_post_meta($post_id, '_nf_yahoo_only', true) === '1') continue;

            $has_rakuten = trim((string)get_post_meta($post_id, '_nf_rakuten_item_code', true)) !== '' ||
                trim((string)get_post_meta($post_id, '_nf_rakuten_url', true)) !== '';
            if (!$has_rakuten) continue;

            $variants = self::get_variants($post_id);
            $satofull = array();
            $remaining = array();
            foreach ($variants as $code => $variant) {
                if (self::is_satofull_hit(self::variant_to_hit($variant))) {
                    $satofull[$code] = $variant;
                } else {
                    $remaining[$code] = $variant;
                }
            }
            if (!$satofull) continue;

            // 先に元カードから外すことで、商品コード重複チェックを安全に通す。
            self::save_variants($post_id, $remaining);
            foreach ($satofull as $code => $variant) {
                $hit = self::variant_to_hit($variant);
                $new_id = self::create_yahoo_only_post($hit, array(
                    'score' => 0,
                    'reasons' => array('さとふるストアを楽天カードから個別分離'),
                ));

                if (is_wp_error($new_id) || !$new_id) {
                    // 作成できなかった掲載だけ元カードへ戻し、商品情報の欠損を防ぐ。
                    $remaining[$code] = $variant;
                    self::save_variants($post_id, $remaining);
                }
            }
        }

        return true;
    }

    private static function split_existing_satofull_cards() {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array('publish','draft','pending','private','future'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_nf_yahoo_only',
            'meta_value' => '1',
        ));

        foreach ((array)$ids as $post_id) {
            $variants = self::get_variants($post_id);
            $satofull = array();
            foreach ($variants as $code => $variant) {
                if (self::is_satofull_hit(self::variant_to_hit($variant))) {
                    $satofull[$code] = $variant;
                }
            }
            if (count($satofull) < 2) continue;

            $primary = self::choose_primary_variant($satofull);
            $primary_code = $primary && !empty($primary['code'])
                ? (string)$primary['code']
                : (string)array_key_first($satofull);
            $keep = array($primary_code => $satofull[$primary_code]);
            self::save_variants($post_id, $keep);
            self::sync_yahoo_only_identity($post_id, self::variant_to_hit($satofull[$primary_code]));

            foreach ($satofull as $code => $variant) {
                if ((string)$code === $primary_code) continue;
                $hit = self::variant_to_hit($variant);
                $new_id = self::create_yahoo_only_post($hit, array(
                    'score' => 0,
                    'reasons' => array('容量・玉数・サイズ別に個別カード化'),
                ));
                if (is_wp_error($new_id) || !$new_id) {
                    $keep[$code] = $variant;
                    self::save_variants($post_id, $keep);
                }
            }
        }

        return true;
    }

    private static function sync_yahoo_only_identity($post_id, $hit) {
        $title = !empty($hit['name'])
            ? sanitize_text_field($hit['name'])
            : get_the_title($post_id);
        $capacity = self::capacity_text(
            $title . ' ' . (isset($hit['description']) ? $hit['description'] : '')
        );

        wp_update_post(array('ID' => (int)$post_id, 'post_title' => $title));
        update_post_meta((int)$post_id, '_nf_yahoo_only', '1');
        update_post_meta(
            (int)$post_id,
            '_nf_status',
            !empty($hit['inStock']) ? '受付中' : '受付終了'
        );
        if ($capacity !== '') {
            update_post_meta((int)$post_id, '_nf_capacity', $capacity);
        } else {
            delete_post_meta((int)$post_id, '_nf_capacity');
        }
    }

    private static function client_id() {
        return trim((string)get_option(self::OPTION_CLIENT_ID, ''));
    }

    private static function affiliate_option() {
        return trim((string)get_option(self::OPTION_VC_AFFILIATE_ID, ''));
    }

    private static function auto_discovery_enabled() {
        return get_option(self::OPTION_AUTO_DISCOVERY, '1') === '1';
    }

    private static function seller_id() {
        $seller_id = sanitize_key(
            get_option(self::OPTION_SELLER_ID, 'y-sf')
        );

        return $seller_id ?: 'y-sf';
    }

    /**
     * Yahoo APIのaffiliate_idはURLエンコード済み文字列を要求する。
     * 生のValueCommerce referral URLでも、すでにエンコード済みでも受け付ける。
     */
    private static function encoded_affiliate_id() {
        $value = self::affiliate_option();

        if ( $value === '' ) {
            return '';
        }

        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 「...&vc_url=」までの基底URLを利用。
        if ( preg_match('~^https?://~i', $value) ) {
            if ( strpos($value, 'vc_url=') === false ) {
                $value .= (strpos($value, '?') === false ? '?' : '&') . 'vc_url=';
            }

            return rawurlencode($value);
        }

        // すでにURLエンコード済みとして扱う。
        return $value;
    }

    private static function build_url( $endpoint, $params, $affiliate = true ) {
        $url = add_query_arg($params, $endpoint);

        if ( $affiliate ) {
            $affiliate_id = self::encoded_affiliate_id();

            if ( $affiliate_id !== '' ) {
                $url .=
                    (strpos($url, '?') === false ? '?' : '&') .
                    'affiliate_type=vc&affiliate_id=' .
                    $affiliate_id;
            }
        }

        return $url;
    }

    private static function remote_get( $url, $timeout = 20 ) {
        $response = wp_remote_get($url, array(
            'timeout' => $timeout,
            'redirection' => 5,
            'headers' => array(
                'User-Agent' => 'FurusatoCatalog/' . NF_VERSION . ' ' . home_url('/'),
            ),
        ));

        if ( is_wp_error($response) ) {
            return $response;
        }

        $code = intval(wp_remote_retrieve_response_code($response));
        $body = wp_remote_retrieve_body($response);

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'nf_yahoo_http_error',
                'Yahoo! API HTTPエラー: ' . $code
            );
        }

        if ( trim($body) === '' ) {
            return new WP_Error(
                'nf_yahoo_empty',
                'Yahoo! APIから空のレスポンスが返されました。'
            );
        }

        return $body;
    }

    public static function search( $query, $start = 1, $results = 50, $affiliate = true ) {
        $client_id = self::client_id();

        if ( $client_id === '' ) {
            return new WP_Error(
                'nf_yahoo_no_client',
                'Yahoo! JAPAN Client IDを設定してください。'
            );
        }

        $query = trim((string)$query);

        $params = array(
            'appid' => $client_id,
            'query' => $query,
            'seller_id' => self::seller_id(),
            'results' => max(1, min(50, intval($results))),
            'start' => max(1, intval($start)),
            'image_size' => 600,
            'sort' => '-score',
            'condition' => 'new',
        );

        $body = self::remote_get(
            self::build_url(self::SEARCH_ENDPOINT, $params, $affiliate)
        );

        if ( is_wp_error($body) ) {
            return $body;
        }

        $json = json_decode($body, true);

        if ( ! is_array($json) ) {
            return new WP_Error(
                'nf_yahoo_json',
                'Yahoo!商品検索APIのJSON解析に失敗しました。'
            );
        }

        $hits = isset($json['hits']) && is_array($json['hits'])
            ? $json['hits']
            : array();

        return array(
            'total' => isset($json['totalResultsAvailable'])
                ? intval($json['totalResultsAvailable'])
                : count($hits),
            'returned' => count($hits),
            'start' => isset($json['firstResultsPosition'])
                ? intval($json['firstResultsPosition'])
                : max(1, intval($start)),
            'hits' => array_values(array_map(
                array(__CLASS__, 'normalize_search_hit'),
                $hits
            )),
        );
    }

    private static function normalize_search_hit( $hit ) {
        $seller = isset($hit['seller']) && is_array($hit['seller'])
            ? $hit['seller']
            : array();

        $review = isset($hit['review']) && is_array($hit['review'])
            ? $hit['review']
            : array();

        $image = '';

        if (
            isset($hit['exImage']) &&
            is_array($hit['exImage']) &&
            ! empty($hit['exImage']['url'])
        ) {
            $image = esc_url_raw($hit['exImage']['url']);
        } elseif (
            isset($hit['image']) &&
            is_array($hit['image']) &&
            ! empty($hit['image']['medium'])
        ) {
            $image = esc_url_raw($hit['image']['medium']);
        }

        return array(
            'code' => isset($hit['code'])
                ? sanitize_text_field($hit['code'])
                : '',
            'name' => isset($hit['name'])
                ? sanitize_text_field($hit['name'])
                : '',
            'headline' => isset($hit['headLine'])
                ? sanitize_text_field($hit['headLine'])
                : '',
            'description' => isset($hit['description'])
                ? NF_Variant_Spec::plain_text($hit['description'])
                : '',
            'url' => isset($hit['url'])
                ? esc_url_raw($hit['url'])
                : '',
            'price' => isset($hit['price'])
                ? absint($hit['price'])
                : 0,
            'inStock' => isset($hit['inStock'])
                ? (bool)$hit['inStock']
                : true,
            'image' => $image,
            'sellerId' => isset($seller['sellerId'])
                ? sanitize_key($seller['sellerId'])
                : '',
            'sellerName' => isset($seller['name'])
                ? sanitize_text_field($seller['name'])
                : '',
            'reviewRate' => isset($review['rate'])
                ? floatval($review['rate'])
                : 0,
            'reviewCount' => isset($review['count'])
                ? absint($review['count'])
                : 0,
        );
    }

    /**
     * 商品コード検索API。商品検索APIのCodeをそのままitemcodeへ渡す。
     */
    public static function lookup( $item_code, $affiliate = true ) {
        $client_id = self::client_id();
        $item_code = trim((string)$item_code);

        if ( $client_id === '' ) {
            return new WP_Error(
                'nf_yahoo_no_client',
                'Yahoo! JAPAN Client IDを設定してください。'
            );
        }

        if ( $item_code === '' ) {
            return new WP_Error(
                'nf_yahoo_no_code',
                'Yahoo!商品コードがありません。'
            );
        }

        $params = array(
            'appid' => $client_id,
            'itemcode' => $item_code,
            'responsegroup' => 'large',
            'image_size' => 600,
        );

        $body = self::remote_get(
            self::build_url(self::LOOKUP_ENDPOINT, $params, $affiliate)
        );

        if ( is_wp_error($body) ) {
            return $body;
        }

        if ( ! class_exists('DOMDocument') ) {
            return new WP_Error(
                'nf_yahoo_dom_missing',
                'PHP DOM拡張が利用できないためYahoo!商品詳細を解析できません。'
            );
        }

        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body, LIBXML_NOCDATA | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return new WP_Error(
                'nf_yahoo_xml',
                'Yahoo!商品コード検索APIのXML解析に失敗しました。'
            );
        }

        $xpath = new DOMXPath($dom);
        $hits = $xpath->query('//*[local-name()="Hit"]');

        if ( ! $hits || $hits->length < 1 ) {
            return new WP_Error(
                'nf_yahoo_not_found',
                'Yahoo!ショッピングで商品を確認できませんでした。'
            );
        }

        $hit = $hits->item(0);

        $text = function($expression) use ($xpath, $hit) {
            $nodes = $xpath->query($expression, $hit);
            if ( ! $nodes || $nodes->length < 1 ) return '';
            return trim((string)$nodes->item(0)->textContent);
        };

        $related_images = array();
        $nodes = $xpath->query(
            './/*[local-name()="RelatedImages"]/*[local-name()="Image"]/*[local-name()="Medium"]',
            $hit
        );

        if ( $nodes ) {
            foreach ( $nodes as $node ) {
                $url = esc_url_raw(trim((string)$node->textContent));
                if ( $url && ! in_array($url, $related_images, true) ) {
                    $related_images[] = $url;
                }
            }
        }

        $availability = strtolower(
            $text('.//*[local-name()="Availability"][1]')
        );

        $image = $text('.//*[local-name()="ExImage"]/*[local-name()="Url"][1]');
        if ( $image === '' ) {
            $image = $text('.//*[local-name()="Image"]/*[local-name()="Medium"][1]');
        }

        return array(
            'code' => sanitize_text_field(
                $text('./*[local-name()="Code"][1]') ?: $item_code
            ),
            'name' => sanitize_text_field(
                $text('./*[local-name()="Name"][1]')
            ),
            'headline' => sanitize_text_field(
                $text('./*[local-name()="Headline"][1]')
            ),
            'description' => NF_Variant_Spec::plain_text(
                $text('./*[local-name()="Description"][1]')
            ),
            'url' => esc_url_raw(
                $text('./*[local-name()="Url"][1]')
            ),
            'price' => absint(
                $text('./*[local-name()="Price"][1]')
            ),
            'inStock' => $availability !== 'outofstock',
            'image' => esc_url_raw($image),
            'relatedImages' => $related_images,
            'sellerId' => sanitize_key(
                $text('.//*[local-name()="Store"]/*[local-name()="Id"][1]')
            ),
            'sellerName' => sanitize_text_field(
                $text('.//*[local-name()="Store"]/*[local-name()="Name"][1]')
            ),
            'reviewRate' => floatval(
                $text('.//*[local-name()="Review"]/*[local-name()="Rate"][1]')
            ),
            'reviewCount' => absint(
                $text('.//*[local-name()="Review"]/*[local-name()="Count"][1]')
            ),
        );
    }

    /**
     * v0.8.5:
     * itemLookupで画像が空なら商品検索APIでも同一codeを探して補完。
     */
    private static function lookup_with_image_fallback(
        $item_code,
        $existing_variant = array(),
        $affiliate = true
    ) {
        $hit = self::lookup($item_code, $affiliate);

        if ( is_wp_error($hit) ) {
            return $hit;
        }

        if (
            ! empty($hit['image']) ||
            (
                ! empty($hit['relatedImages']) &&
                is_array($hit['relatedImages'])
            )
        ) {
            return $hit;
        }

        $queries = array();

        if (
            is_array($existing_variant) &&
            ! empty($existing_variant['name'])
        ) {
            $queries[] = sanitize_text_field(
                $existing_variant['name']
            );
        }

        if ( ! empty($hit['name']) ) {
            $queries[] = sanitize_text_field(
                $hit['name']
            );
        }

        $queries[] = sanitize_text_field($item_code);

        $queries = array_values(
            array_unique(
                array_filter(
                    array_map('trim', $queries)
                )
            )
        );

        foreach ( array_slice($queries, 0, 3) as $query ) {
            $search = self::search(
                $query,
                1,
                50,
                $affiliate
            );

            if ( is_wp_error($search) ) {
                continue;
            }

            foreach ( $search['hits'] as $candidate ) {
                if (
                    empty($candidate['code']) ||
                    (string)$candidate['code'] !==
                    (string)$item_code
                ) {
                    continue;
                }

                if ( ! empty($candidate['image']) ) {
                    $hit['image'] = $candidate['image'];
                }

                foreach (
                    array(
                        'url',
                        'sellerId',
                        'sellerName',
                        'name',
                        'headline',
                        'description'
                    ) as $key
                ) {
                    if (
                        empty($hit[$key]) &&
                        ! empty($candidate[$key])
                    ) {
                        $hit[$key] = $candidate[$key];
                    }
                }

                return $hit;
            }
        }

        return $hit;
    }

    private static function normalize_search_text( $text ) {
        $text = html_entity_decode(
            wp_strip_all_tags((string)$text),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace('/[\s\x{3000}]+/u', '', $text);

        if ( function_exists('mb_strtolower') ) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }

        return $text;
    }

    private static function extract_management_code( $text ) {
        $text = (string)$text;

        if (
            preg_match(
                '/[\[［【]\s*([A-Z]{2,10})[-_]?(\d{2,10})\s*[\]］】]/u',
                $text,
                $m
            )
        ) {
            return strtoupper($m[1] . $m[2]);
        }

        // 括弧が無い場合でも末尾付近の英字+数字を補完。
        if (
            preg_match(
                '/(?:^|[\s　])([A-Z]{2,10})[-_]?(\d{2,10})(?:$|[\s　])/u',
                strtoupper($text),
                $m
            )
        ) {
            return strtoupper($m[1] . $m[2]);
        }

        return '';
    }

    /**
     * Yahoo側の新規候補も楽天v0.6.1と同様、説明文の単純一致では採用しない。
     */
    /**
     * v0.7.3 revised:
     * 自治体ごとの提供事業者識別プレフィックス。
     *
     * 八代市のYahoo!「さとふる」掲載で、
     * `_232-4628` / `_232-6723` / `_232-6604` のように
     * 日本フルーツ商品の管理番号先頭が232であることを確認。
     *
     * 232単独では確定せず「八代市」と組み合わせて使う。
     */
    private static function provider_prefix_map() {
        $map = array();

        foreach ( self::search_routes(false) as $route ) {
            if (
                ! isset($route['type']) ||
                $route['type'] !== 'provider-prefix'
            ) {
                continue;
            }

            $municipality = isset($route['municipality'])
                ? sanitize_text_field($route['municipality'])
                : '';

            $prefix = isset($route['providerPrefix'])
                ? trim((string)$route['providerPrefix'])
                : '';

            if ( $municipality === '' || $prefix === '' ) {
                continue;
            }

            if ( ! isset($map[$municipality]) ) {
                $map[$municipality] = array();
            }

            if ( ! in_array($prefix, $map[$municipality], true) ) {
                $map[$municipality][] = $prefix;
            }
        }

        return $map;
    }

    /**
     * v0.7.4:
     * Yahoo!探索は1つの検索キーワードに依存しない。
     *
     * 例:
     * - 通常ルート: 日本フルーツ 熊本
     * - 事業者識別ルート: 八代市 232
     *
     * provider_prefix_map() に自治体別識別子を追加すれば、
     * 新しい検索ルートも自動生成される。
     */
    private static function discovery_routes() {
        $routes = array();

        foreach ( self::search_routes(false) as $saved_route ) {
            $type = isset($saved_route['type'])
                ? $saved_route['type']
                : 'keyword';

            $query = isset($saved_route['query'])
                ? trim((string)$saved_route['query'])
                : '';

            if ( $query === '' ) {
                continue;
            }

            $name = isset($saved_route['name'])
                ? sanitize_text_field($saved_route['name'])
                : '';

            $routes[] = array(
                'id' => isset($saved_route['id'])
                    ? sanitize_key($saved_route['id'])
                    : '',
                'type' => $type,
                'query' => $query,
                'municipality' => isset($saved_route['municipality'])
                    ? sanitize_text_field($saved_route['municipality'])
                    : '',
                'providerPrefix' => isset($saved_route['providerPrefix'])
                    ? trim((string)$saved_route['providerPrefix'])
                    : '',
                'label' => $name !== ''
                    ? $name
                    : (
                        $type === 'provider-prefix'
                            ? '事業者識別検索: ' . $query
                            : '通常検索: ' . $query
                    ),
            );
        }

        // 同じ条件のルートは1回だけ。
        $unique = array();

        foreach ( $routes as $route ) {
            $key =
                self::normalize_search_text($route['query']) . '|' .
                $route['type'] . '|' .
                $route['municipality'] . '|' .
                $route['providerPrefix'];

            if ( isset($unique[$key]) ) {
                continue;
            }

            $unique[$key] = $route;
        }

        return array_values($unique);
    }

    /**
     * 事業者識別ルートで取得した商品が、その自治体・prefixに本当に一致するか再確認。
     */
    private static function route_accepts_hit( $route, $hit ) {
        if ( ! is_array($route) || ! is_array($hit) ) {
            return false;
        }

        // seller_idはsearch()側でも固定しているが防御的に再確認。
        if (
            ! empty($hit['sellerId']) &&
            sanitize_key($hit['sellerId']) !== self::seller_id()
        ) {
            return false;
        }

        if (
            ! isset($route['type']) ||
            $route['type'] !== 'provider-prefix'
        ) {
            return true;
        }

        $text =
            (isset($hit['name']) ? $hit['name'] : '') . ' ' .
            (isset($hit['headline']) ? $hit['headline'] : '') . ' ' .
            (isset($hit['description']) ? $hit['description'] : '');

        $municipality = self::extract_municipality($text);
        $number = self::extract_provider_number($text);

        if (
            empty($route['municipality']) ||
            empty($route['providerPrefix'])
        ) {
            return false;
        }

        return (
            $municipality === $route['municipality'] &&
            ! empty($number['prefix']) &&
            $number['prefix'] === (string)$route['providerPrefix']
        );
    }

    /**
     * `_232-4628` / `232-4628` / `232_4628` から
     * prefix=232, suffix=4628 を抽出。
     */
    private static function extract_provider_number( $text ) {
        $text = (string)$text;

        if (
            preg_match(
                '/(?:^|[_\-\s　])(\d{2,4})[-_](\d{2,8})(?:$|[\s　\]］】])/u',
                $text,
                $m
            )
        ) {
            return array(
                'prefix' => $m[1],
                'suffix' => $m[2],
                'full' => $m[1] . '-' . $m[2],
            );
        }

        // Yahoo商品名末尾の `_232-4628` をより緩く補完。
        if (
            preg_match(
                '/_(\d{2,4})-(\d{2,8})/u',
                $text,
                $m
            )
        ) {
            return array(
                'prefix' => $m[1],
                'suffix' => $m[2],
                'full' => $m[1] . '-' . $m[2],
            );
        }

        return array(
            'prefix' => '',
            'suffix' => '',
            'full' => '',
        );
    }

    private static function provider_prefix_matches( $hit ) {
        if ( ! is_array($hit) ) {
            return false;
        }

        $text =
            (isset($hit['name']) ? $hit['name'] : '') . ' ' .
            (isset($hit['headline']) ? $hit['headline'] : '') . ' ' .
            (isset($hit['description']) ? $hit['description'] : '');

        $municipality = self::extract_municipality($text);

        if ( $municipality === '' ) {
            return false;
        }

        $number = self::extract_provider_number($text);

        if ( empty($number['prefix']) ) {
            return false;
        }

        $map = self::provider_prefix_map();

        return (
            isset($map[$municipality]) &&
            in_array($number['prefix'], $map[$municipality], true)
        );
    }

    private static function provider_matches( $hit ) {
        $configured_seller = self::seller_id();
        $hit_seller = isset($hit['sellerId'])
            ? sanitize_key($hit['sellerId'])
            : '';

        if (
            $configured_seller !== '' &&
            $hit_seller !== '' &&
            $hit_seller !== $configured_seller
        ) {
            return false;
        }

        $strong = self::normalize_search_text(
            (isset($hit['name']) ? $hit['name'] : '') . ' ' .
            (isset($hit['headline']) ? $hit['headline'] : '')
        );

        $provider = class_exists('NF_Settings') ? NF_Settings::provider_name() : '';
        $provider_norm = self::normalize_search_text($provider);
        $provider_short = str_replace('株式会社', '', $provider_norm);

        if ( $provider_norm !== '' && strpos($strong, $provider_norm) !== false ) {
            return true;
        }
        if ( $provider_short !== '' && strpos($strong, $provider_short) !== false ) {
            return true;
        }

        $description = isset($hit['description'])
            ? (string)$hit['description']
            : '';

        if ( $description !== '' && $provider_short !== '' ) {
            $description_norm = self::normalize_search_text($description);
            if (
                preg_match('/(?:提供者|提供元|提供事業者|事業者|販売者|発送元)\s*[:：]?\s*([^\n\r<]{1,120})/u', $description, $m) &&
                strpos(self::normalize_search_text($m[1]), $provider_short) !== false
            ) {
                return true;
            }
        }

        // 旧サイトで学習済みの管理番号prefixは後方互換として補助利用。
        if ( self::provider_prefix_matches($hit) ) {
            return true;
        }

        $management = self::extract_management_code(
            isset($hit['name']) ? $hit['name'] : ''
        );

        if ( $management !== '' ) {
            $prefix = preg_replace('/\d+$/', '', $management);
            $trusted = self::trusted_management_prefixes();

            if ( $prefix && in_array($prefix, $trusted, true) ) {
                return true;
            }
        }

        return false;
    }

    private static function trusted_management_prefixes() {
        static $cache = null;

        if ( is_array($cache) ) return $cache;

        $cache = array();

        $post_ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ( $post_ids as $post_id ) {
            $title = (string)get_post_meta(
                $post_id,
                '_nf_rakuten_item_name',
                true
            );

            if ( $title === '' ) {
                $title = get_the_title($post_id);
            }

            if (
                strpos(
                    self::normalize_search_text($title),
                    (class_exists('NF_Settings') ? NF_Settings::brand_name() : '')
                ) === false
            ) {
                continue;
            }

            $management = self::extract_management_code($title);

            if ( $management !== '' ) {
                $prefix = preg_replace('/\d+$/', '', $management);
                if ( $prefix ) $cache[] = $prefix;
            }
        }

        $cache = array_values(array_unique($cache));

        return $cache;
    }

    private static function kumamoto_municipalities() {
        return array(
            '熊本市','八代市','人吉市','荒尾市','水俣市','玉名市','山鹿市',
            '菊池市','宇土市','上天草市','宇城市','阿蘇市','天草市','合志市',
            '美里町','玉東町','南関町','長洲町','和水町','大津町','菊陽町',
            '南小国町','小国町','産山村','高森町','西原村','南阿蘇村',
            '御船町','嘉島町','益城町','甲佐町','山都町','氷川町',
            '芦北町','津奈木町','錦町','多良木町','湯前町','水上村',
            '相良村','五木村','山江村','球磨村','あさぎり町','苓北町'
        );
    }

    private static function municipality_slug_map() {
        return array(
            '熊本市' => 'kumamoto-city',
            '八代市' => 'yatsushiro',
            '人吉市' => 'hitoyoshi',
            '荒尾市' => 'arao',
            '水俣市' => 'minamata',
            '玉名市' => 'tamana',
            '山鹿市' => 'yamaga',
            '菊池市' => 'kikuchi',
            '宇土市' => 'uto',
            '上天草市' => 'kamiamakusa',
            '宇城市' => 'uki',
            '阿蘇市' => 'aso',
            '天草市' => 'amakusa',
            '合志市' => 'koshi',
            '美里町' => 'misato',
            '玉東町' => 'gyokuto',
            '南関町' => 'nankan',
            '長洲町' => 'nagasu',
            '和水町' => 'nagomi',
            '大津町' => 'ozu',
            '菊陽町' => 'kikuyo',
            '南小国町' => 'minamioguni',
            '小国町' => 'oguni',
            '産山村' => 'ubuyama',
            '高森町' => 'takamori',
            '西原村' => 'nishihara',
            '南阿蘇村' => 'minamiaso',
            '御船町' => 'mifune',
            '嘉島町' => 'kashima',
            '益城町' => 'mashiki',
            '甲佐町' => 'kosa',
            '山都町' => 'yamato',
            '氷川町' => 'hikawa',
            '芦北町' => 'ashikita',
            '津奈木町' => 'tsunagi',
            '錦町' => 'nishiki',
            '多良木町' => 'taragi',
            '湯前町' => 'yunomae',
            '水上村' => 'mizukami',
            '相良村' => 'sagara',
            '五木村' => 'itsuki',
            '山江村' => 'yamae',
            '球磨村' => 'kuma',
            'あさぎり町' => 'asagiri',
            '苓北町' => 'reihoku',
        );
    }

    private static function fruit_slug_map() {
        return array(
            'いちご' => 'strawberry',
            'さつまいも' => 'sweet-potato',
            'ぶどう' => 'grape',
            'みかん' => 'mikan',
            'アスパラガス' => 'asparagus',
            'シャインマスカット' => 'shine-muscat',
            'スイカ' => 'watermelon',
            'スイートスプリング' => 'sweet-spring',
            'パール柑・文旦' => 'pearlkan-buntan',
            'ピオーネ' => 'pione',
            'メロン' => 'melon',
            '不知火・デコポン' => 'shiranui-dekopon',
            '人参' => 'carrot',
            '巨峰' => 'kyoho',
            '晩白柚' => 'banpeiyu',
            '柑橘' => 'citrus',
            '柿' => 'persimmon',
            '栗' => 'chestnut',
            '梨' => 'pear',
            '河内晩柑' => 'kawachi-bankan',
        );
    }

    private static function extract_municipality( $text ) {
        $text = (string)$text;

        foreach ( self::kumamoto_municipalities() as $municipality ) {
            if ( strpos($text, $municipality) !== false ) {
                return $municipality;
            }
        }

        return '';
    }

    private static function extract_fruit( $text ) {
        $text = (string)$text;

        $rules = array(
            '晩白柚' => array('晩白柚','ばんぺいゆ'),
            '不知火・デコポン' => array('デコポン','不知火','デコみかん'),
            'シャインマスカット' => array('シャインマスカット'),
            'ピオーネ' => array('ピオーネ'),
            '巨峰' => array('巨峰'),
            '梨' => array('豊水','秋月','あきづき','新高','梨'),
            'スイカ' => array('金色羅皇','羅王','ムーンフラッシュ','すいか','スイカ','西瓜'),
            'メロン' => array('肥後グリーン','メロン'),
            'いちご' => array('いちご','苺'),
            '柿' => array('太秋柿','柿'),
            '栗' => array('生栗','栗'),
            'みかん' => array('温州みかん','極早生みかん','早生みかん','みかん'),
            'パール柑・文旦' => array('パール柑','文旦'),
            '河内晩柑' => array('河内晩柑','ジューシーオレンジ'),
            'スイートスプリング' => array('スイートスプリング'),
            'さつまいも' => array('紅はるか','シルクスイート','さつまいも','サツマイモ'),
            'アスパラガス' => array('アスパラガス','アスパラ'),
            '人参' => array('人参','にんじん'),
            'ぶどう' => array('ぶどう','葡萄'),
            '柑橘' => array('柑橘'),
        );

        foreach ( $rules as $fruit => $words ) {
            foreach ( $words as $word ) {
                if ( strpos($text, $word) !== false ) {
                    return $fruit;
                }
            }
        }

        return '';
    }

    private static function weight_range_grams( $text ) {
        $text = (string)$text;

        if (
            ! preg_match_all(
                '/(\d+(?:\.\d+)?)\s*(kg|g)/iu',
                $text,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            return array(0, 0);
        }

        $values = array();

        foreach ( $matches as $match ) {
            $value = floatval($match[1]);

            if ( strtolower($match[2]) === 'kg' ) {
                $value *= 1000;
            }

            if ( $value > 0 ) {
                $values[] = intval(round($value));
            }
        }

        if ( ! $values ) return array(0, 0);

        return array(min($values), max($values));
    }

    private static function capacity_text( $text ) {
        list($min, $max) = self::weight_range_grams($text);

        if ( ! $min ) return '';

        $format = function($grams) {
            if ( $grams >= 1000 ) {
                $kg = $grams / 1000;
                $value = (floor($kg) == $kg)
                    ? intval($kg)
                    : rtrim(rtrim(number_format($kg, 2, '.', ''), '0'), '.');

                return $value . 'kg';
            }

            return intval($grams) . 'g';
        };

        if ( $min === $max ) {
            return '約' . $format($min);
        }

        return '約' . $format($min) . '〜' . $format($max);
    }

    private static function title_similarity( $a, $b ) {
        $a = self::normalize_search_text($a);
        $b = self::normalize_search_text($b);

        $noise = array(
            'ふるさと納税','先行予約','予約','送料無料','産地直送',
            '農家直送','数量限定','期間限定','返礼品'
        );
        if ( class_exists('NF_Settings') ) {
            $noise[] = NF_Settings::company_name();
            $noise[] = NF_Settings::brand_name();
        }

        $a = str_replace($noise, '', $a);
        $b = str_replace($noise, '', $b);

        if ( $a === '' || $b === '' ) return 0.0;

        if ( function_exists('mb_strlen') ) {
            $ngrams = function($text) {
                $set = array();
                $length = mb_strlen($text, 'UTF-8');

                for ( $i = 0; $i < $length - 1; $i++ ) {
                    $gram = mb_substr($text, $i, 2, 'UTF-8');
                    if ( $gram !== '' ) $set[$gram] = true;
                }

                return array_keys($set);
            };
        } else {
            $ngrams = function($text) {
                $set = array();
                $length = strlen($text);

                for ( $i = 0; $i < $length - 1; $i++ ) {
                    $gram = substr($text, $i, 2);
                    if ( $gram !== '' ) $set[$gram] = true;
                }

                return array_keys($set);
            };
        }

        $ga = $ngrams($a);
        $gb = $ngrams($b);

        if ( ! $ga || ! $gb ) return 0.0;

        $intersection = count(array_intersect($ga, $gb));
        $union = count(array_unique(array_merge($ga, $gb)));

        return $union > 0
            ? $intersection / $union
            : 0.0;
    }

    private static function post_features( $post_id ) {
        $title = (string)get_post_meta(
            $post_id,
            '_nf_rakuten_item_name',
            true
        );

        if ( $title === '' ) {
            $title = get_the_title($post_id);
        }

        $municipality = '';
        $municipality_terms = wp_get_post_terms(
            $post_id,
            'nf_municipality'
        );

        if (
            ! is_wp_error($municipality_terms) &&
            ! empty($municipality_terms)
        ) {
            $municipality = $municipality_terms[0]->name;
        }

        if ( $municipality === '' ) {
            $municipality = self::extract_municipality($title);
        }

        $fruit = '';
        $fruit_terms = wp_get_post_terms(
            $post_id,
            'nf_fruit'
        );

        if (
            ! is_wp_error($fruit_terms) &&
            ! empty($fruit_terms)
        ) {
            $preferred = array(
                '晩白柚','不知火・デコポン','シャインマスカット',
                'ピオーネ','巨峰','梨','スイカ','メロン','いちご',
                '柿','栗','みかん','パール柑・文旦','河内晩柑',
                'スイートスプリング','さつまいも','アスパラガス','人参'
            );

            foreach ( $preferred as $name ) {
                foreach ( $fruit_terms as $term ) {
                    if ( $term->name === $name ) {
                        $fruit = $name;
                        break 2;
                    }
                }
            }

            if ( $fruit === '' ) {
                $fruit = $fruit_terms[0]->name;
            }
        }

        if ( $fruit === '' ) {
            $fruit = self::extract_fruit($title);
        }

        $capacity = (string)get_post_meta(
            $post_id,
            '_nf_capacity',
            true
        );

        list($weight_min, $weight_max) = self::weight_range_grams(
            $capacity ?: $title
        );

        $price_min = absint(get_post_meta(
            $post_id,
            '_nf_price_min',
            true
        ));
        $price_max = absint(get_post_meta(
            $post_id,
            '_nf_price_max',
            true
        ));

        $legacy = absint(get_post_meta(
            $post_id,
            '_nf_price',
            true
        ));

        if ( $price_min <= 100 ) $price_min = 0;
        if ( $price_max <= 100 ) $price_max = 0;
        if ( $legacy <= 100 ) $legacy = 0;

        if ( ! $price_min ) $price_min = $legacy ?: $price_max;
        if ( ! $price_max ) $price_max = $legacy ?: $price_min;

        return array(
            'post_id' => intval($post_id),
            'title' => $title,
            'management' => self::extract_management_code($title),
            'municipality' => $municipality,
            'fruit' => $fruit,
            'weight_min' => intval($weight_min),
            'weight_max' => intval($weight_max),
            'price_min' => intval($price_min),
            'price_max' => intval($price_max),
        );
    }

    private static function hit_features( $hit ) {
        $text =
            (isset($hit['name']) ? $hit['name'] : '') . ' ' .
            (isset($hit['headline']) ? $hit['headline'] : '') . ' ' .
            (isset($hit['description']) ? $hit['description'] : '') . ' ' .
            (isset($hit['sellerName']) ? $hit['sellerName'] : '');

        list($weight_min, $weight_max) = self::weight_range_grams($text);

        $provider_number = self::extract_provider_number($text);

        return array(
            'title' => isset($hit['name']) ? $hit['name'] : '',
            'management' => self::extract_management_code(
                isset($hit['name']) ? $hit['name'] : ''
            ),
            'provider_prefix' => isset($provider_number['prefix'])
                ? $provider_number['prefix']
                : '',
            'provider_number' => isset($provider_number['full'])
                ? $provider_number['full']
                : '',
            'municipality' => self::extract_municipality($text),
            'fruit' => self::extract_fruit($text),
            'weight_min' => intval($weight_min),
            'weight_max' => intval($weight_max),
            'price' => isset($hit['price']) ? absint($hit['price']) : 0,
        );
    }

    /**
     * 0〜100点。
     * 外部AI APIではなく、商品属性を複合比較する保守的な自動マッチング。
     */
    /**
     * v0.7.9:
     * 親分類と詳細分類だけを互換扱いする。
     * 柿↔栗、梨↔みかん等は互換にしない。
     */
    private static function fruit_compatible( $a, $b ) {
        $a = trim((string)$a);
        $b = trim((string)$b);

        if ( $a === '' || $b === '' ) {
            return null;
        }

        if ( $a === $b ) {
            return true;
        }

        $citrus = array(
            '不知火・デコポン',
            '晩白柚',
            'みかん',
            'パール柑・文旦',
            '河内晩柑',
            'スイートスプリング',
        );

        $grapes = array(
            'シャインマスカット',
            'ピオーネ',
            '巨峰',
        );

        if (
            ($a === '柑橘' && in_array($b, $citrus, true)) ||
            ($b === '柑橘' && in_array($a, $citrus, true))
        ) {
            return true;
        }

        if (
            ($a === 'ぶどう' && in_array($b, $grapes, true)) ||
            ($b === 'ぶどう' && in_array($a, $grapes, true))
        ) {
            return true;
        }

        return false;
    }

    /**
     * 商品名/見出しを優先してYahoo!側の同一性を判定。
     * 説明文全体に関連商品が混ざる場合の誤認を避ける。
     */
    private static function variant_identity_features( $hit ) {
        $name = isset($hit['name'])
            ? (string)$hit['name']
            : '';

        $headline = isset($hit['headline'])
            ? (string)$hit['headline']
            : '';

        $description = isset($hit['description'])
            ? (string)$hit['description']
            : '';

        $strong = trim($name . ' ' . $headline);

        $municipality = self::extract_municipality($strong);
        $fruit = self::extract_fruit($strong);

        // 強い領域に無い場合だけ、説明文の産地/原材料付近を補完的に見る。
        if ( $municipality === '' && $description !== '' ) {
            $municipality_pattern = implode(
                '|',
                array_map(
                    function($name) {
                        return preg_quote($name, '/');
                    },
                    self::kumamoto_municipalities()
                )
            );

            if (
                preg_match(
                    '/(?:産地|原産地|自治体|市町村)[^。\n]{0,60}(' .
                    $municipality_pattern .
                    ')/u',
                    $description,
                    $m
                )
            ) {
                $municipality = $m[1];
            }
        }

        if ( $fruit === '' && $description !== '' ) {
            $sample = function_exists('mb_substr')
                ? mb_substr($description, 0, 600, 'UTF-8')
                : substr($description, 0, 1200);

            $fruit = self::extract_fruit($sample);
        }

        return array(
            'management' => self::extract_management_code($name),
            'municipality' => $municipality,
            'fruit' => $fruit,
            'title' => $name,
        );
    }

    private static function variant_to_hit( $variant ) {
        return array(
            'code' => isset($variant['code']) ? $variant['code'] : '',
            'name' => isset($variant['name']) ? $variant['name'] : '',
            'headline' => isset($variant['headline']) ? $variant['headline'] : '',
            'description' => isset($variant['description']) ? $variant['description'] : '',
            'url' => isset($variant['url']) ? $variant['url'] : '',
            'price' => isset($variant['price']) ? $variant['price'] : 0,
            'inStock' => isset($variant['inStock']) ? $variant['inStock'] : true,
            'image' => isset($variant['image']) ? $variant['image'] : '',
            'relatedImages' => isset($variant['images']) ? $variant['images'] : array(),
            'sellerId' => isset($variant['sellerId']) ? $variant['sellerId'] : '',
            'sellerName' => isset($variant['sellerName']) ? $variant['sellerName'] : '',
        );
    }

    private static function audit_overrides( $post_id ) {
        $value = get_post_meta(
            $post_id,
            self::META_AUDIT_OVERRIDES,
            true
        );

        return is_array($value) ? $value : array();
    }

    /**
     * normal / review / quarantine
     * 管理番号完全一致だけは自治体・品目のhard conflictを上書き可能。
     */
    private static function variant_audit_guard( $post_id, $hit ) {
        $post_id = intval($post_id);

        $code = isset($hit['code'])
            ? sanitize_text_field($hit['code'])
            : '';

        $overrides = self::audit_overrides($post_id);

        if ( $code !== '' && ! empty($overrides[$code]) ) {
            return array(
                'status' => 'normal',
                'reason' => '管理者による復元・確認済み',
                'postFruit' => '',
                'variantFruit' => '',
                'postMunicipality' => '',
                'variantMunicipality' => '',
            );
        }

        $vf = self::variant_identity_features($hit);
        $pf = self::post_features($post_id);

        if (
            $vf['management'] !== '' &&
            $pf['management'] !== '' &&
            $vf['management'] === $pf['management']
        ) {
            return array(
                'status' => 'normal',
                'reason' => '管理番号完全一致',
                'postFruit' => $pf['fruit'],
                'variantFruit' => $vf['fruit'],
                'postMunicipality' => $pf['municipality'],
                'variantMunicipality' => $vf['municipality'],
            );
        }

        if (
            $vf['municipality'] !== '' &&
            $pf['municipality'] !== '' &&
            $vf['municipality'] !== $pf['municipality']
        ) {
            return array(
                'status' => 'quarantine',
                'reason' =>
                    '自治体不一致：' .
                    $pf['municipality'] .
                    ' ↔ ' .
                    $vf['municipality'],
                'postFruit' => $pf['fruit'],
                'variantFruit' => $vf['fruit'],
                'postMunicipality' => $pf['municipality'],
                'variantMunicipality' => $vf['municipality'],
            );
        }

        $fruit_compatible = self::fruit_compatible(
            $pf['fruit'],
            $vf['fruit']
        );

        if ( $fruit_compatible === false ) {
            return array(
                'status' => 'quarantine',
                'reason' =>
                    '品目不一致：' .
                    $pf['fruit'] .
                    ' ↔ ' .
                    $vf['fruit'],
                'postFruit' => $pf['fruit'],
                'variantFruit' => $vf['fruit'],
                'postMunicipality' => $pf['municipality'],
                'variantMunicipality' => $vf['municipality'],
            );
        }

        $missing = array();

        if ( $pf['municipality'] === '' || $vf['municipality'] === '' ) {
            $missing[] = '自治体';
        }

        if ( $pf['fruit'] === '' || $vf['fruit'] === '' ) {
            $missing[] = '品目';
        }

        if ( $missing ) {
            return array(
                'status' => 'review',
                'reason' =>
                    '判定情報不足：' .
                    implode('・', $missing),
                'postFruit' => $pf['fruit'],
                'variantFruit' => $vf['fruit'],
                'postMunicipality' => $pf['municipality'],
                'variantMunicipality' => $vf['municipality'],
            );
        }

        return array(
            'status' => 'normal',
            'reason' => '自治体・品目整合',
            'postFruit' => $pf['fruit'],
            'variantFruit' => $vf['fruit'],
            'postMunicipality' => $pf['municipality'],
            'variantMunicipality' => $vf['municipality'],
        );
    }

    private static function quarantined_variants( $post_id ) {
        $stored = get_post_meta(
            $post_id,
            self::META_QUARANTINED_VARIANTS,
            true
        );

        return is_array($stored) ? $stored : array();
    }

    private static function review_variants( $post_id ) {
        $stored = get_post_meta(
            $post_id,
            self::META_REVIEW_VARIANTS,
            true
        );

        return is_array($stored) ? $stored : array();
    }

    private static function quarantine_variant(
        $post_id,
        $variant,
        $reason,
        $guard = array()
    ) {
        $post_id = intval($post_id);

        if ( ! is_array($variant) || empty($variant['code']) ) {
            return false;
        }

        $code = sanitize_text_field($variant['code']);
        $quarantined = self::quarantined_variants($post_id);

        $variant['quarantineReason'] = sanitize_text_field($reason);
        $variant['quarantinedAt'] = current_time('mysql');
        $variant['auditGuard'] = is_array($guard) ? $guard : array();

        $quarantined[$code] = $variant;

        update_post_meta(
            $post_id,
            self::META_QUARANTINED_VARIANTS,
            $quarantined
        );

        $active = self::get_variants($post_id);

        if ( isset($active[$code]) ) {
            unset($active[$code]);
            self::save_variants($post_id, $active);
        }

        $review = self::review_variants($post_id);

        if ( isset($review[$code]) ) {
            unset($review[$code]);
            update_post_meta(
                $post_id,
                self::META_REVIEW_VARIANTS,
                $review
            );
        }

        $overrides = self::audit_overrides($post_id);

        if ( isset($overrides[$code]) ) {
            unset($overrides[$code]);
            update_post_meta(
                $post_id,
                self::META_AUDIT_OVERRIDES,
                $overrides
            );
        }

        return true;
    }

    private static function mark_review_variant(
        $post_id,
        $variant,
        $reason,
        $guard = array()
    ) {
        if ( ! is_array($variant) || empty($variant['code']) ) {
            return;
        }

        $review = self::review_variants($post_id);
        $code = sanitize_text_field($variant['code']);

        $review[$code] = array(
            'code' => $code,
            'name' => isset($variant['name']) ? $variant['name'] : '',
            'url' => isset($variant['url']) ? $variant['url'] : '',
            'reason' => sanitize_text_field($reason),
            'checkedAt' => current_time('mysql'),
            'auditGuard' => is_array($guard) ? $guard : array(),
        );

        update_post_meta(
            $post_id,
            self::META_REVIEW_VARIANTS,
            $review
        );
    }

    private static function clear_review_variant( $post_id, $code ) {
        $review = self::review_variants($post_id);

        if ( isset($review[$code]) ) {
            unset($review[$code]);
            update_post_meta(
                $post_id,
                self::META_REVIEW_VARIANTS,
                $review
            );
        }
    }

    public static function audit_all_variants( $mutate = true ) {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        $summary = array(
            'normal' => 0,
            'review' => 0,
            'quarantinedNew' => 0,
            'quarantinedTotal' => 0,
            'checked' => 0,
        );

        foreach ( $ids as $post_id ) {
            $variants = self::get_variants($post_id);

            foreach ( $variants as $code => $variant ) {
                $summary['checked']++;

                $guard = self::variant_audit_guard(
                    $post_id,
                    self::variant_to_hit($variant)
                );

                if ( $guard['status'] === 'quarantine' ) {
                    if ( $mutate ) {
                        self::quarantine_variant(
                            $post_id,
                            $variant,
                            $guard['reason'],
                            $guard
                        );
                    }

                    $summary['quarantinedNew']++;
                    continue;
                }

                if ( $guard['status'] === 'review' ) {
                    if ( $mutate ) {
                        self::mark_review_variant(
                            $post_id,
                            $variant,
                            $guard['reason'],
                            $guard
                        );
                    }

                    $summary['review']++;
                    continue;
                }

                if ( $mutate ) {
                    self::clear_review_variant(
                        $post_id,
                        $code
                    );
                }

                $summary['normal']++;
            }

            $summary['quarantinedTotal'] += count(
                self::quarantined_variants($post_id)
            );
        }

        if ( ! $mutate ) {
            // 管理画面プレビューでは、まだ移動前のhard conflictも「隔離」件数へ含める。
            $summary['quarantinedTotal'] +=
                intval($summary['quarantinedNew']);
        }

        if ( $mutate ) {
            update_option(
                self::OPTION_LAST_AUDIT,
                array(
                    'time' => current_time('mysql'),
                    'summary' => $summary,
                ),
                false
            );
        }

        return $summary;
    }

    private static function quarantine_rows( $limit = 150 ) {
        $rows = array();

        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ( $ids as $post_id ) {
            foreach (
                self::quarantined_variants($post_id)
                as $code => $variant
            ) {
                $rows[] = array(
                    'postId' => intval($post_id),
                    'postTitle' => get_the_title($post_id),
                    'editUrl' => get_edit_post_link($post_id, ''),
                    'code' => $code,
                    'name' => isset($variant['name']) ? $variant['name'] : '',
                    'url' => isset($variant['url']) ? $variant['url'] : '',
                    'reason' => isset($variant['quarantineReason'])
                        ? $variant['quarantineReason']
                        : '監査で隔離',
                    'quarantinedAt' => isset($variant['quarantinedAt'])
                        ? $variant['quarantinedAt']
                        : '',
                );

                if ( count($rows) >= intval($limit) ) {
                    break 2;
                }
            }
        }

        return $rows;
    }

    private static function content_match_score( $hit, $post_id ) {
        $hf = self::hit_features($hit);
        $pf = self::post_features($post_id);

        $score = 0.0;
        $reasons = array();
        $hard_conflict = false;

        if (
            $hf['management'] !== '' &&
            $pf['management'] !== '' &&
            $hf['management'] === $pf['management']
        ) {
            return array(
                'score' => 100,
                'reasons' => array('管理番号完全一致'),
                'hardConflict' => false,
            );
        }

        // 自治体固有の事業者識別番号
        if (
            ! empty($hf['municipality']) &&
            ! empty($hf['provider_prefix'])
        ) {
            $prefix_map = self::provider_prefix_map();

            if (
                isset($prefix_map[$hf['municipality']]) &&
                in_array(
                    $hf['provider_prefix'],
                    $prefix_map[$hf['municipality']],
                    true
                )
            ) {
                $score += 18;
                $reasons[] =
                    '事業者識別子' .
                    $hf['municipality'] .
                    ':' .
                    $hf['provider_prefix'];
            }
        }

        // 自治体
        if ( $hf['municipality'] && $pf['municipality'] ) {
            if ( $hf['municipality'] === $pf['municipality'] ) {
                $score += 25;
                $reasons[] = '自治体一致';
            } else {
                $score -= 35;
                $reasons[] = '自治体不一致';
                $hard_conflict = true;
            }
        }

        // 品目
        if ( $hf['fruit'] && $pf['fruit'] ) {
            if ( $hf['fruit'] === $pf['fruit'] ) {
                $score += 25;
                $reasons[] = '品目一致';
            } elseif (
                self::fruit_compatible(
                    $hf['fruit'],
                    $pf['fruit']
                )
            ) {
                $score += 12;
                $reasons[] = '品目系統一致';
            } else {
                $score -= 30;
                $reasons[] = '品目不一致';
                $hard_conflict = true;
            }
        }

        // 容量
        if (
            $hf['weight_min'] > 0 &&
            $pf['weight_min'] > 0
        ) {
            $hmin = $hf['weight_min'];
            $hmax = $hf['weight_max'] ?: $hmin;
            $pmin = $pf['weight_min'];
            $pmax = $pf['weight_max'] ?: $pmin;

            $overlap = max($hmin, $pmin) <= min($hmax, $pmax);

            if ( $overlap ) {
                $score += 20;
                $reasons[] = '容量一致';
            } else {
                $hmid = ($hmin + $hmax) / 2;
                $pmid = ($pmin + $pmax) / 2;
                $ratio = max($hmid, $pmid) / max(1, min($hmid, $pmid));

                if ( $ratio <= 1.12 ) {
                    $score += 14;
                    $reasons[] = '容量近似';
                } elseif ( $ratio <= 1.30 ) {
                    $score += 6;
                    $reasons[] = '容量やや近似';
                } else {
                    $score -= 15;
                    $reasons[] = '容量差大';
                }
            }
        }

        // 寄附額
        if (
            $hf['price'] > 100 &&
            ($pf['price_min'] > 100 || $pf['price_max'] > 100)
        ) {
            $price = $hf['price'];
            $pmin = $pf['price_min'] ?: $pf['price_max'];
            $pmax = $pf['price_max'] ?: $pf['price_min'];

            if ( $price >= $pmin && $price <= $pmax ) {
                $score += 15;
                $reasons[] = '寄附額範囲一致';
            } else {
                $nearest = min(
                    abs($price - $pmin),
                    abs($price - $pmax)
                );

                $ratio = $nearest / max(1, min($price, $pmin ?: $pmax));

                if ( $ratio <= 0.05 ) {
                    $score += 12;
                    $reasons[] = '寄附額ほぼ一致';
                } elseif ( $ratio <= 0.12 ) {
                    $score += 8;
                    $reasons[] = '寄附額近似';
                } elseif ( $ratio <= 0.25 ) {
                    $score += 3;
                } else {
                    $score -= 8;
                    $reasons[] = '寄附額差大';
                }
            }
        }

        // 商品名類似
        $similarity = self::title_similarity(
            $hf['title'],
            $pf['title']
        );

        if ( $similarity >= 0.60 ) {
            $score += 15;
            $reasons[] = '商品名高類似';
        } elseif ( $similarity >= 0.42 ) {
            $score += 11;
            $reasons[] = '商品名類似';
        } elseif ( $similarity >= 0.28 ) {
            $score += 6;
        } elseif ( $similarity >= 0.18 ) {
            $score += 2;
        }

        $score = max(0, min(100, intval(round($score))));

        return array(
            'score' => $score,
            'reasons' => $reasons,
            'hardConflict' => $hard_conflict,
        );
    }

    private static function match_candidate_posts( $exclude_post_id = 0 ) {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        return array_values(array_filter(
            array_map('intval', $ids),
            function($post_id) use ($exclude_post_id) {
                if ( $exclude_post_id && $post_id === intval($exclude_post_id) ) {
                    return false;
                }

                // Yahoo単独同士は統合先にしない。
                return get_post_meta(
                    $post_id,
                    '_nf_yahoo_only',
                    true
                ) !== '1';
            }
        ));
    }

    private static function best_content_match( $hit, $exclude_post_id = 0 ) {
        $ranked = array();

        foreach ( self::match_candidate_posts($exclude_post_id) as $post_id ) {
            $result = self::content_match_score($hit, $post_id);

            if ( ! empty($result['hardConflict']) ) {
                continue;
            }

            $ranked[] = array(
                'postId' => intval($post_id),
                'score' => intval($result['score']),
                'reasons' => $result['reasons'],
            );
        }

        usort($ranked, function($a, $b) {
            if ( $a['score'] === $b['score'] ) {
                return $a['postId'] <=> $b['postId'];
            }

            return $b['score'] <=> $a['score'];
        });

        $best = isset($ranked[0]) ? $ranked[0] : null;
        $second = isset($ranked[1]) ? $ranked[1] : null;

        if ( ! $best ) {
            return array(
                'matched' => false,
                'postId' => 0,
                'score' => 0,
                'secondScore' => 0,
                'reasons' => array(),
            );
        }

        $second_score = $second ? intval($second['score']) : 0;
        $margin = intval($best['score']) - $second_score;

        $matched =
            intval($best['score']) >= self::AUTO_MATCH_THRESHOLD &&
            (
                intval($best['score']) >= 95 ||
                $margin >= self::AUTO_MATCH_MARGIN
            );

        return array(
            'matched' => $matched,
            'postId' => intval($best['postId']),
            'score' => intval($best['score']),
            'secondScore' => $second_score,
            'reasons' => $best['reasons'],
        );
    }

    private static function ensure_term( $taxonomy, $name, $slug = '' ) {
        $term = get_term_by('name', $name, $taxonomy);

        if ( $term && ! is_wp_error($term) ) {
            return intval($term->term_id);
        }

        if (
            $taxonomy === 'nf_municipality' &&
            class_exists('NF_Settings') &&
            ! NF_Settings::municipality_assist_mode()
        ) {
            return 0;
        }

        $args = array();
        if ( $slug ) $args['slug'] = $slug;

        $created = wp_insert_term($name, $taxonomy, $args);

        if ( is_wp_error($created) ) {
            return 0;
        }

        return intval($created['term_id']);
    }

    private static function create_yahoo_only_post( $hit, $match = array() ) {
        if ( ! is_array($hit) || empty($hit['code']) ) {
            return new WP_Error(
                'nf_yahoo_standalone_invalid',
                'Yahoo!単独返礼品を作成できませんでした。'
            );
        }

        $existing_code_map = self::yahoo_code_map();

        if ( isset($existing_code_map[$hit['code']]) ) {
            return intval($existing_code_map[$hit['code']]);
        }

        $title = ! empty($hit['name'])
            ? sanitize_text_field($hit['name'])
            : 'Yahoo!ショッピング返礼品';

        $post_id = wp_insert_post(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
        ), true);

        if ( is_wp_error($post_id) ) {
            return $post_id;
        }

        update_post_meta($post_id, '_nf_yahoo_only', '1');
        update_post_meta(
            $post_id,
            '_nf_status',
            ! empty($hit['inStock']) ? '受付中' : '受付終了'
        );

        $text =
            $title . ' ' .
            (isset($hit['description']) ? $hit['description'] : '') . ' ' .
            (isset($hit['sellerName']) ? $hit['sellerName'] : '');

        $municipality = self::extract_municipality($text);
        $fruit = self::extract_fruit($text);
        $capacity = self::capacity_text($text);

        if ( $municipality ) {
            $slug_map = self::municipality_slug_map();
            $term_id = self::ensure_term(
                'nf_municipality',
                $municipality,
                isset($slug_map[$municipality])
                    ? $slug_map[$municipality]
                    : ''
            );

            if ( $term_id ) {
                wp_set_object_terms(
                    $post_id,
                    array($term_id),
                    'nf_municipality',
                    false
                );
            }

            update_post_meta(
                $post_id,
                '_nf_origin',
                '熊本県' . $municipality
            );
        }

        if ( $fruit ) {
            $slug_map = self::fruit_slug_map();
            $term_id = self::ensure_term(
                'nf_fruit',
                $fruit,
                isset($slug_map[$fruit])
                    ? $slug_map[$fruit]
                    : ''
            );

            if ( $term_id ) {
                wp_set_object_terms(
                    $post_id,
                    array($term_id),
                    'nf_fruit',
                    false
                );
            }
        }

        if ( $capacity ) {
            update_post_meta(
                $post_id,
                '_nf_capacity',
                $capacity
            );
        }

        update_post_meta(
            $post_id,
            '_nf_yahoo_match_score',
            isset($match['score']) ? intval($match['score']) : 0
        );

        update_post_meta(
            $post_id,
            '_nf_yahoo_match_reason',
            ! empty($match['reasons'])
                ? implode(' / ', $match['reasons'])
                : '既存返礼品との高信頼一致なし'
        );

        $provider_number = self::extract_provider_number($text);

        if ( ! empty($provider_number['prefix']) ) {
            update_post_meta(
                $post_id,
                '_nf_yahoo_provider_prefix',
                sanitize_text_field($provider_number['prefix'])
            );
        }

        if ( ! empty($provider_number['full']) ) {
            update_post_meta(
                $post_id,
                '_nf_yahoo_provider_number',
                sanitize_text_field($provider_number['full'])
            );
        }

        $saved = self::link_hit_to_post($post_id, $hit);

        if ( is_wp_error($saved) ) {
            wp_delete_post($post_id, true);
            return $saved;
        }

        return intval($post_id);
    }

    private static function merge_yahoo_only_into(
        $source_id,
        $target_id
    ) {
        $source_id = intval($source_id);
        $target_id = intval($target_id);

        if (
            ! $source_id ||
            ! $target_id ||
            $source_id === $target_id
        ) {
            return false;
        }

        $source_variants = self::get_variants($source_id);
        $target_variants = self::get_variants($target_id);

        foreach ( $source_variants as $code => $variant ) {
            $target_variants[$code] = $variant;
        }

        self::save_variants(
            $target_id,
            $target_variants
        );

        foreach ( array(
            '_nf_yahoo_match_score',
            '_nf_yahoo_match_reason',
        ) as $key ) {
            $value = get_post_meta($source_id, $key, true);

            if ( $value !== '' ) {
                update_post_meta(
                    $target_id,
                    $key,
                    $value
                );
            }
        }

        delete_post_meta(
            $target_id,
            '_nf_yahoo_only'
        );

        update_post_meta(
            $source_id,
            '_nf_merged_into',
            $target_id
        );

        wp_update_post(array(
            'ID' => $source_id,
            'post_status' => 'draft',
        ));

        return true;
    }

    private static function reconcile_yahoo_only_post( $post_id, $hit ) {
        $match = self::best_content_match($hit, $post_id);

        if ( empty($match['matched']) || empty($match['postId']) ) {
            return false;
        }

        update_post_meta(
            $match['postId'],
            '_nf_yahoo_match_score',
            intval($match['score'])
        );
        update_post_meta(
            $match['postId'],
            '_nf_yahoo_match_reason',
            implode(' / ', $match['reasons'])
        );

        return self::merge_yahoo_only_into(
            $post_id,
            $match['postId']
        );
    }

    private static function existing_management_map() {
        $map = array();

        $post_ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ( $post_ids as $post_id ) {
            $titles = array(
                get_post_meta($post_id, '_nf_rakuten_item_name', true),
                get_the_title($post_id),
                get_post_meta($post_id, '_nf_display_name', true),
            );

            foreach ( $titles as $title ) {
                $management = self::extract_management_code($title);

                if ( $management === '' ) continue;

                if ( ! isset($map[$management]) ) {
                    $map[$management] = array();
                }

                if ( ! in_array(intval($post_id), $map[$management], true) ) {
                    $map[$management][] = intval($post_id);
                }
            }
        }

        return $map;
    }

    private static function yahoo_code_map() {
        $map = array();

        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ( $ids as $post_id ) {
            foreach (
                array_keys(self::get_variants($post_id))
                as $code
            ) {
                if ( $code !== '' ) {
                    $map[$code] = intval($post_id);
                }
            }
        }

        return $map;
    }

    private static function find_auto_match(
        $hit,
        $management_map,
        $yahoo_code_map
    ) {
        $code = isset($hit['code']) ? trim($hit['code']) : '';
        $satofull_policy = self::is_satofull_hit($hit)
            ? self::satofull_merge_policy()
            : 'normal';

        if ( $code !== '' && isset($yahoo_code_map[$code]) ) {
            $existing_post = intval($yahoo_code_map[$code]);

            // 分離モードでは、既存のYahoo!専用カードを楽天側へ再統合しない。
            if (
                $satofull_policy !== 'normal' &&
                get_post_meta($existing_post, '_nf_yahoo_only', true) === '1'
            ) {
                return array(
                    'postId' => $existing_post,
                    'score' => 100,
                    'reasons' => array(
                        $satofull_policy === 'separate'
                            ? 'さとふるストア分離'
                            : 'さとふるストア管理番号一致待ち'
                    ),
                    'mergeSource' => 0,
                );
            }

            $existing_guard = self::variant_audit_guard(
                $existing_post,
                $hit
            );

            if ( $existing_guard['status'] === 'quarantine' ) {
                $existing_variants = self::get_variants(
                    $existing_post
                );

                if ( isset($existing_variants[$code]) ) {
                    self::quarantine_variant(
                        $existing_post,
                        $existing_variants[$code],
                        $existing_guard['reason'],
                        $existing_guard
                    );
                }

                // 誤った既存紐付けを優先せず、以下の通常マッチングへ進む。
                unset($yahoo_code_map[$code]);
            } else {
                // Yahoo単独商品は、後から楽天側に同商品が増えていないか再判定。
                if (
                    get_post_meta(
                        $existing_post,
                        '_nf_yahoo_only',
                        true
                    ) === '1'
                ) {
                    $content = self::best_content_match(
                        $hit,
                        $existing_post
                    );

                    if ( ! empty($content['matched']) ) {
                        return array(
                            'postId' => intval($content['postId']),
                            'score' => intval($content['score']),
                            'reasons' => $content['reasons'],
                            'mergeSource' => $existing_post,
                        );
                    }
                }

                return array(
                    'postId' => $existing_post,
                    'score' => 100,
                    'reasons' => array('Yahoo!商品コード既存一致'),
                    'mergeSource' => 0,
                );
            }
        }

        $management = self::extract_management_code(
            isset($hit['name']) ? $hit['name'] : ''
        );

        // Yahoo!内のさとふるストアは、初期設定では楽天商品と統合しない。
        if ( $satofull_policy === 'separate' ) {
            return array(
                'postId' => 0,
                'score' => 0,
                'reasons' => array('さとふるストアは別カード'),
                'mergeSource' => 0,
            );
        }

        if (
            $management !== '' &&
            isset($management_map[$management]) &&
            count($management_map[$management]) === 1
        ) {
            return array(
                'postId' => intval($management_map[$management][0]),
                'score' => 100,
                'reasons' => array('管理番号完全一致'),
                'mergeSource' => 0,
            );
        }

        // 「管理番号一致のみ」では、曖昧な商品名・容量の類似判定を行わない。
        if ( $satofull_policy === 'management' ) {
            return array(
                'postId' => 0,
                'score' => 0,
                'reasons' => array('さとふるストアは管理番号一致のみ統合'),
                'mergeSource' => 0,
            );
        }

        $content = self::best_content_match($hit);

        if ( ! empty($content['matched']) ) {
            return array(
                'postId' => intval($content['postId']),
                'score' => intval($content['score']),
                'reasons' => $content['reasons'],
                'mergeSource' => 0,
            );
        }

        return array(
            'postId' => 0,
            'score' => isset($content['score'])
                ? intval($content['score'])
                : 0,
            'reasons' => isset($content['reasons'])
                ? $content['reasons']
                : array(),
            'mergeSource' => 0,
        );
    }

    public static function maybe_upgrade_072() {
        if ( get_option('nf_yahoo_072_migrated', '') === '1' ) {
            return;
        }

        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ( $ids as $post_id ) {
            $variants = self::get_variants($post_id, false);

            if ( $variants ) {
                continue;
            }

            $legacy_code = trim((string)get_post_meta(
                $post_id,
                '_nf_yahoo_code',
                true
            ));

            if ( $legacy_code === '' ) {
                continue;
            }

            $legacy = array(
                'code' => $legacy_code,
                'name' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_item_name',
                    true
                ),
                'url' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_url',
                    true
                ),
                'affiliateUrl' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_affiliate_url',
                    true
                ),
                'price' => absint(get_post_meta(
                    $post_id,
                    '_nf_yahoo_price',
                    true
                )),
                'inStock' => get_post_meta(
                    $post_id,
                    '_nf_yahoo_in_stock',
                    true
                ) !== '0',
                'sellerId' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_seller_id',
                    true
                ),
                'sellerName' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_seller_name',
                    true
                ),
                'image' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_image_url',
                    true
                ),
                'images' => (array)get_post_meta(
                    $post_id,
                    '_nf_yahoo_image_urls',
                    true
                ),
                'lastSync' => (string)get_post_meta(
                    $post_id,
                    '_nf_yahoo_last_sync',
                    true
                ),
            );

            self::save_variants(
                $post_id,
                array($legacy_code => $legacy)
            );
        }

        update_option(
            'nf_yahoo_072_migrated',
            '1',
            false
        );
    }

    private static function variant_capacity_label( $hit ) {
        $variant = NF_Variant_Spec::enrich($hit);
        return $variant['capacityLabel'];
    }

    private static function normalize_variant( $hit ) {
        $code = isset($hit['code'])
            ? sanitize_text_field($hit['code'])
            : '';

        $image = isset($hit['image'])
            ? esc_url_raw($hit['image'])
            : '';

        $images = array();

        if ( $image ) {
            $images[] = $image;
        }

        if (
            isset($hit['relatedImages']) &&
            is_array($hit['relatedImages'])
        ) {
            foreach ( $hit['relatedImages'] as $url ) {
                $url = esc_url_raw($url);

                if ( $url && ! in_array($url, $images, true) ) {
                    $images[] = $url;
                }
            }
        }

        $url = isset($hit['url'])
            ? esc_url_raw($hit['url'])
            : '';

        $variant = array(
            'code' => $code,
            'name' => isset($hit['name'])
                ? sanitize_text_field($hit['name'])
                : '',
            'url' => $url,
            // Yahoo APIにaffiliate_idを付けた時点でレスポンスurl自体がAffiliate URL。
            'affiliateUrl' => $url,
            'price' => isset($hit['price'])
                ? absint($hit['price'])
                : 0,
            'headline' => isset($hit['headline']) ? $hit['headline'] : '',
            'description' => isset($hit['description']) ? $hit['description'] : '',
            'inStock' => ! empty($hit['inStock']),
            'sellerId' => isset($hit['sellerId'])
                ? sanitize_key($hit['sellerId'])
                : '',
            'sellerName' => isset($hit['sellerName'])
                ? sanitize_text_field($hit['sellerName'])
                : '',
            'reviewRate' => isset($hit['reviewRate'])
                ? floatval($hit['reviewRate'])
                : 0,
            'reviewCount' => isset($hit['reviewCount'])
                ? absint($hit['reviewCount'])
                : 0,
            'image' => $image,
            'images' => $images,
            'lastSync' => current_time('mysql'),
        );

        return NF_Variant_Spec::enrich($variant);
    }

    private static function get_variants( $post_id, $with_legacy = true ) {
        $stored = get_post_meta(
            $post_id,
            '_nf_yahoo_variants',
            true
        );

        $variants = is_array($stored)
            ? $stored
            : array();

        $normalized = array();

        foreach ( $variants as $key => $variant ) {
            if ( ! is_array($variant) ) {
                continue;
            }

            $code = ! empty($variant['code'])
                ? sanitize_text_field($variant['code'])
                : sanitize_text_field($key);

            if ( $code === '' ) {
                continue;
            }

            $variant['code'] = $code;
            $variant['price'] = isset($variant['price'])
                ? absint($variant['price'])
                : 0;

            $variant = NF_Variant_Spec::enrich($variant);

            $variant['inStock'] = isset($variant['inStock'])
                ? (bool)$variant['inStock']
                : true;
            $variant['images'] = isset($variant['images']) &&
                                 is_array($variant['images'])
                ? array_values(array_filter(array_unique(
                    array_map('esc_url_raw', $variant['images'])
                )))
                : array();

            $normalized[$code] = $variant;
        }

        if ( ! $normalized && $with_legacy ) {
            $legacy_code = trim((string)get_post_meta(
                $post_id,
                '_nf_yahoo_code',
                true
            ));

            if ( $legacy_code !== '' ) {
                $normalized[$legacy_code] = array(
                    'code' => $legacy_code,
                    'name' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_item_name',
                        true
                    ),
                    'url' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_url',
                        true
                    ),
                    'affiliateUrl' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_affiliate_url',
                        true
                    ),
                    'price' => absint(get_post_meta(
                        $post_id,
                        '_nf_yahoo_price',
                        true
                    )),
                    'capacityLabel' => self::variant_capacity_label(array(
                        'name' => (string)get_post_meta(
                            $post_id,
                            '_nf_yahoo_item_name',
                            true
                        ),
                        'headline' => '',
                        'description' => '',
                    )),
                    'inStock' => get_post_meta(
                        $post_id,
                        '_nf_yahoo_in_stock',
                        true
                    ) !== '0',
                    'sellerId' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_seller_id',
                        true
                    ),
                    'sellerName' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_seller_name',
                        true
                    ),
                    'image' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_image_url',
                        true
                    ),
                    'images' => (array)get_post_meta(
                        $post_id,
                        '_nf_yahoo_image_urls',
                        true
                    ),
                    'lastSync' => (string)get_post_meta(
                        $post_id,
                        '_nf_yahoo_last_sync',
                        true
                    ),
                );
            }
        }

        // Includes the pre-v0.7.2 single-meta fallback without writing during page views.
        foreach ( $normalized as $code => $variant ) {
            if ( ! isset($variant['specLabel']) ) {
                $normalized[$code] = NF_Variant_Spec::enrich($variant);
            }
        }

        return $normalized;
    }

    private static function choose_primary_variant( $variants ) {
        if ( ! $variants ) {
            return null;
        }

        $rows = array_values($variants);

        usort($rows, function($a, $b) {
            $a_stock = ! empty($a['inStock']) ? 1 : 0;
            $b_stock = ! empty($b['inStock']) ? 1 : 0;

            if ( $a_stock !== $b_stock ) {
                return $b_stock <=> $a_stock;
            }

            $ap = ! empty($a['price']) ? intval($a['price']) : PHP_INT_MAX;
            $bp = ! empty($b['price']) ? intval($b['price']) : PHP_INT_MAX;

            if ( $ap === $bp ) {
                return strcmp(
                    isset($a['code']) ? $a['code'] : '',
                    isset($b['code']) ? $b['code'] : ''
                );
            }

            return $ap <=> $bp;
        });

        return $rows[0];
    }

    private static function save_variants( $post_id, $variants ) {
        $clean = array();

        foreach ( (array)$variants as $key => $variant ) {
            if ( ! is_array($variant) ) {
                continue;
            }

            $code = ! empty($variant['code'])
                ? sanitize_text_field($variant['code'])
                : sanitize_text_field($key);

            if ( $code === '' ) {
                continue;
            }

            $variant['code'] = $code;
            $variant['name'] = isset($variant['name'])
                ? sanitize_text_field($variant['name'])
                : '';
            $variant['url'] = isset($variant['url'])
                ? esc_url_raw($variant['url'])
                : '';
            $variant['affiliateUrl'] = isset($variant['affiliateUrl'])
                ? esc_url_raw($variant['affiliateUrl'])
                : '';
            $variant['price'] = isset($variant['price'])
                ? absint($variant['price'])
                : 0;

            $variant = NF_Variant_Spec::enrich($variant);

            $variant['inStock'] = ! empty($variant['inStock']);
            $variant['sellerId'] = isset($variant['sellerId'])
                ? sanitize_key($variant['sellerId'])
                : '';
            $variant['sellerName'] = isset($variant['sellerName'])
                ? sanitize_text_field($variant['sellerName'])
                : '';
            $variant['image'] = isset($variant['image'])
                ? esc_url_raw($variant['image'])
                : '';
            $variant['images'] = isset($variant['images']) &&
                                 is_array($variant['images'])
                ? array_values(array_filter(array_unique(
                    array_map('esc_url_raw', $variant['images'])
                )))
                : array();
            $variant['lastSync'] = isset($variant['lastSync'])
                ? sanitize_text_field($variant['lastSync'])
                : current_time('mysql');

            $clean[$code] = $variant;
        }

        update_post_meta(
            $post_id,
            '_nf_yahoo_variants',
            $clean
        );

        // v0.9.2: Yahoo!内ストア検索候補を最新化。
        delete_transient('nf_catalog_yahoo_store_options_092');

        $primary = self::choose_primary_variant($clean);

        if ( ! $primary ) {
            foreach ( array(
                '_nf_yahoo_code',
                '_nf_yahoo_url',
                '_nf_yahoo_affiliate_url',
                '_nf_yahoo_item_name',
                '_nf_yahoo_price',
                '_nf_yahoo_in_stock',
                '_nf_yahoo_seller_id',
                '_nf_yahoo_seller_name',
                '_nf_yahoo_image_url',
                '_nf_yahoo_image_urls',
                '_nf_yahoo_last_sync',
            ) as $key ) {
                delete_post_meta($post_id, $key);
            }

            return;
        }

        // 旧コードとの互換用に「代表Yahoo掲載」を従来metaへ反映。
        update_post_meta(
            $post_id,
            '_nf_yahoo_code',
            $primary['code']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_url',
            $primary['url']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_affiliate_url',
            $primary['affiliateUrl']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_item_name',
            $primary['name']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_price',
            $primary['price']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_in_stock',
            ! empty($primary['inStock']) ? '1' : '0'
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_seller_id',
            $primary['sellerId']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_seller_name',
            $primary['sellerName']
        );
        update_post_meta(
            $post_id,
            '_nf_yahoo_image_url',
            $primary['image']
        );

        $all_images = array();

        foreach ( $clean as $variant ) {
            if ( ! empty($variant['image']) ) {
                $all_images[] = $variant['image'];
            }

            foreach ( (array)$variant['images'] as $url ) {
                $all_images[] = $url;
            }
        }

        $all_images = array_values(array_filter(array_unique(
            array_map('esc_url_raw', $all_images)
        )));

        update_post_meta(
            $post_id,
            '_nf_yahoo_image_urls',
            $all_images
        );

        update_post_meta(
            $post_id,
            '_nf_yahoo_last_sync',
            current_time('mysql')
        );
    }

    private static function remove_variant( $post_id, $code ) {
        $variants = self::get_variants($post_id);

        if ( isset($variants[$code]) ) {
            unset($variants[$code]);
        }

        self::save_variants($post_id, $variants);
    }

    /**
     * v0.8.0:
     * Yahoo!ショッピング内の実際のストア名を公開表示用に返す。
     */
    /**
     * v0.8.2:
     * Yahoo!掲載の保存済み画像から、公開表示に使える画像を1枚返す。
     * 旧metaが空でもvariant側の画像を直接確認する。
     */
    public static function public_image_url( $post_id ) {
        $post_id = intval($post_id);

        $urls = array();

        $legacy_all = get_post_meta(
            $post_id,
            '_nf_yahoo_image_urls',
            true
        );

        if ( is_array($legacy_all) ) {
            foreach ( $legacy_all as $url ) {
                if ( is_string($url) && trim($url) !== '' ) {
                    $urls[] = trim($url);
                }
            }
        }

        $legacy_main = trim((string)get_post_meta(
            $post_id,
            '_nf_yahoo_image_url',
            true
        ));

        if ( $legacy_main !== '' ) {
            $urls[] = $legacy_main;
        }

        foreach ( self::public_variants($post_id) as $variant ) {
            if ( ! empty($variant['image']) ) {
                $urls[] = $variant['image'];
            }

            if (
                ! empty($variant['images']) &&
                is_array($variant['images'])
            ) {
                foreach ( $variant['images'] as $url ) {
                    if ( is_string($url) && trim($url) !== '' ) {
                        $urls[] = trim($url);
                    }
                }
            }
        }

        foreach ( array_unique($urls) as $url ) {
            $url = esc_url_raw($url);

            if ( $url ) {
                return $url;
            }
        }

        return '';
    }

    private static function post_has_any_saved_image( $post_id ) {
        if ( get_the_post_thumbnail_url($post_id, 'thumbnail') ) {
            return true;
        }

        if (
            trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_image_url',
                true
            )) !== ''
        ) {
            return true;
        }

        $rakuten_all = get_post_meta(
            $post_id,
            '_nf_rakuten_image_urls',
            true
        );

        if ( is_array($rakuten_all) && array_filter($rakuten_all) ) {
            return true;
        }

        if ( self::public_image_url($post_id) !== '' ) {
            return true;
        }

        return false;
    }

    public static function public_store_name( $variant ) {
        if ( ! is_array($variant) ) {
            return '';
        }

        $seller_name = isset($variant['sellerName'])
            ? trim((string)$variant['sellerName'])
            : '';

        if ( $seller_name !== '' ) {
            return sanitize_text_field($seller_name);
        }

        $seller_id = isset($variant['sellerId'])
            ? sanitize_key($variant['sellerId'])
            : '';

        // 旧データでsellerNameが未保存だった場合のフォールバック。
        $known = array(
            'y-sf' => 'さとふる',
        );

        if ( $seller_id !== '' && isset($known[$seller_id]) ) {
            return $known[$seller_id];
        }

        if ( $seller_id !== '' ) {
            return 'ストア ' . $seller_id;
        }

        return '';
    }

    public static function public_variants( $post_id ) {
        $variants = self::get_variants($post_id);
        $quarantined = self::quarantined_variants($post_id);

        // 公開時の最終防御。DBに古い誤紐付けが残っていても明確な不一致は出さない。
        $safe = array();

        foreach ( $variants as $code => $variant ) {
            if ( isset($quarantined[$code]) ) {
                continue;
            }

            $guard = self::variant_audit_guard(
                $post_id,
                self::variant_to_hit($variant)
            );

            if ( $guard['status'] === 'quarantine' ) {
                continue;
            }

            $safe[$code] = $variant;
        }

        $rows = array_values($safe);

        usort($rows, function($a, $b) {
            $as = ! empty($a['inStock']) ? 1 : 0;
            $bs = ! empty($b['inStock']) ? 1 : 0;

            if ( $as !== $bs ) {
                return $bs <=> $as;
            }

            list($amin,) = self::weight_range_grams(
                ! empty($a['weightLabel'])
                    ? $a['weightLabel']
                    : (isset($a['name']) ? $a['name'] : '')
            );

            list($bmin,) = self::weight_range_grams(
                ! empty($b['weightLabel'])
                    ? $b['weightLabel']
                    : (isset($b['name']) ? $b['name'] : '')
            );

            if ( $amin && $bmin && $amin !== $bmin ) {
                return $amin <=> $bmin;
            }

            if ( $amin && ! $bmin ) {
                return -1;
            }

            if ( ! $amin && $bmin ) {
                return 1;
            }

            $ap = ! empty($a['price'])
                ? intval($a['price'])
                : PHP_INT_MAX;

            $bp = ! empty($b['price'])
                ? intval($b['price'])
                : PHP_INT_MAX;

            if ( $ap !== $bp ) {
                return $ap <=> $bp;
            }

            return strcmp(
                isset($a['code']) ? $a['code'] : '',
                isset($b['code']) ? $b['code'] : ''
            );
        });

        return $rows;
    }


    public static function public_price_text( $post_id ) {
        $variants = self::public_variants($post_id);
        $prices = array();

        foreach ( $variants as $variant ) {
            $price = ! empty($variant['price'])
                ? intval($variant['price'])
                : 0;

            if ( $price > 100 ) {
                $prices[] = $price;
            }
        }

        if ( ! $prices ) {
            return '寄附額はYahoo!で確認';
        }

        $min = min($prices);
        $max = max($prices);

        if ( $min === $max ) {
            return number_format_i18n($min) . '円';
        }

        return
            number_format_i18n($min) .
            '円〜' .
            number_format_i18n($max) .
            '円';
    }

    public static function link_hit_to_post( $post_id, $hit ) {
        $post_id = intval($post_id);

        if (
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE ||
            ! is_array($hit)
        ) {
            return new WP_Error(
                'nf_yahoo_bad_link',
                'Yahoo!商品を返礼品へ紐付けできませんでした。'
            );
        }

        $variant = self::normalize_variant($hit);
        $code = $variant['code'];

        if ( $code === '' ) {
            return new WP_Error(
                'nf_yahoo_missing_code',
                'Yahoo!商品コードがありません。'
            );
        }

        $guard = self::variant_audit_guard(
            $post_id,
            $hit
        );

        if ( $guard['status'] === 'quarantine' ) {
            self::quarantine_variant(
                $post_id,
                $variant,
                $guard['reason'],
                $guard
            );

            return new WP_Error(
                'nf_yahoo_hard_conflict',
                'Yahoo!誤紐付けを防止しました：' .
                $guard['reason']
            );
        }

        if ( $guard['status'] === 'review' ) {
            self::mark_review_variant(
                $post_id,
                $variant,
                $guard['reason'],
                $guard
            );
        } else {
            self::clear_review_variant(
                $post_id,
                $code
            );
        }

        $variants = self::get_variants($post_id);
        $variants[$code] = $variant;

        self::save_variants(
            $post_id,
            $variants
        );

        delete_post_meta(
            $post_id,
            '_nf_yahoo_last_error'
        );

        return true;
    }

    public static function sync_post( $post_id ) {
        $post_id = intval($post_id);
        $variants = self::get_variants($post_id);

        if ( ! $variants ) {
            return new WP_Error(
                'nf_yahoo_not_linked',
                'Yahoo!商品コードが未登録です。'
            );
        }

        $new_variants = $variants;
        $success = 0;
        $errors = array();

        foreach ( array_keys($variants) as $index => $code ) {
            if ( $index > 0 ) {
                usleep(250000);
            }

            $hit = self::lookup_with_image_fallback(
                $code,
                isset($variants[$code])
                    ? $variants[$code]
                    : array(),
                true
            );

            if ( is_wp_error($hit) ) {
                $errors[$code] = $hit->get_error_message();
                continue;
            }

            $new_variants[$code] = self::normalize_variant($hit);
            $success++;
        }

        self::save_variants(
            $post_id,
            $new_variants
        );

        if ( $errors ) {
            update_post_meta(
                $post_id,
                '_nf_yahoo_last_error',
                implode(
                    ' / ',
                    array_map(
                        function($code, $message) {
                            return $code . ': ' . $message;
                        },
                        array_keys($errors),
                        array_values($errors)
                    )
                )
            );
        } else {
            delete_post_meta(
                $post_id,
                '_nf_yahoo_last_error'
            );
        }

        if (
            get_post_meta(
                $post_id,
                '_nf_yahoo_only',
                true
            ) === '1'
        ) {
            $available = false;

            foreach ( $new_variants as $variant ) {
                if ( ! empty($variant['inStock']) ) {
                    $available = true;
                    break;
                }
            }

            update_post_meta(
                $post_id,
                '_nf_status',
                $available
                    ? '受付中'
                    : '受付終了'
            );

            // 代表variantを使って、後から楽天側に同商品が追加されたか再判定。
            $primary = self::choose_primary_variant($new_variants);

            if ( $primary ) {
                $match_hit = array(
                    'code' => $primary['code'],
                    'name' => $primary['name'],
                    'url' => $primary['url'],
                    'price' => $primary['price'],
                    'inStock' => $primary['inStock'],
                    'image' => $primary['image'],
                    'relatedImages' => $primary['images'],
                    'sellerId' => $primary['sellerId'],
                    'sellerName' => $primary['sellerName'],
                    'description' => '',
                    'headline' => '',
                );

                self::reconcile_yahoo_only_post(
                    $post_id,
                    $match_hit
                );
            }
        }

        if ( $success < 1 ) {
            return new WP_Error(
                'nf_yahoo_sync_failed',
                implode(' / ', array_values($errors))
            );
        }

        return true;
    }

    /**
     * Yahoo!商品を検索して、管理番号が完全一致する返礼品へ自動リンク。
     */
    /**
     * Yahoo!商品を検索。
     * 1) 管理番号
     * 2) 自治体・品目・容量・価格・商品名の内容マッチング
     * 3) 高信頼一致が無ければYahoo!単独返礼品として公開
     */
    /**
     * v0.7.4:
     * Yahoo!「さとふる」ストア内を複数検索ルートで走査。
     *
     * 通常検索だけでは拾えない商品を、自治体固有の事業者識別prefixでも補完する。
     */
    public static function discover( $max_pages_per_query = 20 ) {
        $routes = self::discovery_routes();

        $all = array();
        $errors = array();
        $route_stats = array();

        foreach ( $routes as $route ) {
            $query = isset($route['query'])
                ? trim((string)$route['query'])
                : '';

            if ( $query === '' ) {
                continue;
            }

            $start = 1;
            $route_found = 0;
            $route_accepted = 0;

            for (
                $page = 0;
                $page < max(1, intval($max_pages_per_query));
                $page++
            ) {
                $result = self::search(
                    $query,
                    $start,
                    50,
                    true
                );

                if ( is_wp_error($result) ) {
                    $errors[] =
                        (isset($route['label']) ? $route['label'] : $query) .
                        ': ' .
                        $result->get_error_message();
                    break;
                }

                $route_found = max(
                    $route_found,
                    intval($result['total'])
                );

                foreach ( $result['hits'] as $hit ) {
                    if ( empty($hit['code']) ) {
                        continue;
                    }

                    if ( ! self::route_accepts_hit($route, $hit) ) {
                        continue;
                    }

                    $route_accepted++;

                    // 複数ルートに同じ商品が出てもYahoo!商品コードで1件化。
                    $all[$hit['code']] = $hit;
                }

                if (
                    $result['returned'] < 50 ||
                    ($start + 50) >
                    min(1000, intval($result['total']))
                ) {
                    break;
                }

                $start += 50;

                // Yahoo APIへの連続アクセスを少し空ける。
                usleep(250000);
            }

            $route_stats[] = array(
                'id' => isset($route['id'])
                    ? sanitize_key($route['id'])
                    : '',
                'type' => isset($route['type'])
                    ? sanitize_key($route['type'])
                    : 'keyword',
                'label' => isset($route['label'])
                    ? sanitize_text_field($route['label'])
                    : sanitize_text_field($query),
                'query' => sanitize_text_field($query),
                'found' => intval($route_found),
                'accepted' => intval($route_accepted),
            );

            // 検索ルート間にも少し間隔を空ける。
            usleep(250000);
        }

        $management_map = self::existing_management_map();
        $yahoo_code_map = self::yahoo_code_map();

        $linked = 0;
        $standalone = 0;
        $merged = 0;
        $skipped = 0;
        $failed = 0;
        $unmatched = array();

        foreach ( $all as $hit ) {
            if ( ! self::provider_matches($hit) ) {
                $skipped++;
                continue;
            }

            $match = self::find_auto_match(
                $hit,
                $management_map,
                $yahoo_code_map
            );

            if ( ! empty($match['postId']) ) {
                $target_id = intval($match['postId']);

                $saved = self::link_hit_to_post(
                    $target_id,
                    $hit
                );

                if ( is_wp_error($saved) ) {
                    $failed++;
                    continue;
                }

                update_post_meta(
                    $target_id,
                    '_nf_yahoo_match_score',
                    intval($match['score'])
                );

                update_post_meta(
                    $target_id,
                    '_nf_yahoo_match_reason',
                    ! empty($match['reasons'])
                        ? implode(' / ', $match['reasons'])
                        : ''
                );

                if ( ! empty($match['mergeSource']) ) {
                    if (
                        self::merge_yahoo_only_into(
                            intval($match['mergeSource']),
                            $target_id
                        )
                    ) {
                        $merged++;
                    }
                }

                $linked++;
                $yahoo_code_map[$hit['code']] = $target_id;
                continue;
            }

            // 高信頼一致がない商品はYahoo!単独返礼品として公開。
            $created_id = self::create_yahoo_only_post(
                $hit,
                $match
            );

            if ( is_wp_error($created_id) ) {
                $failed++;

                $unmatched[] = array(
                    'code' => $hit['code'],
                    'name' => $hit['name'],
                    'url' => $hit['url'],
                    'price' => $hit['price'],
                    'inStock' => ! empty($hit['inStock']),
                    'image' => $hit['image'],
                    'sellerName' => $hit['sellerName'],
                    'managementCode' => self::extract_management_code(
                        $hit['name']
                    ),
                    'reason' => $created_id->get_error_message(),
                );

                continue;
            }

            $standalone++;
            $yahoo_code_map[$hit['code']] = intval($created_id);
        }

        update_option(
            self::OPTION_UNMATCHED,
            array_slice($unmatched, 0, 100),
            false
        );

        update_option(
            self::OPTION_LAST_DISCOVERY,
            current_time('mysql'),
            false
        );

        update_option(
            'nf_yahoo_last_route_stats',
            $route_stats,
            false
        );

        return array(
            'found' => count($all),
            'linked' => $linked,
            'standalone' => $standalone,
            'merged' => $merged,
            'unmatched' => count($unmatched),
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'routes' => $route_stats,
        );
    }


    public static function auto_sync_tick() {
        if ( class_exists('NF_Commercial_Config') && ! NF_Commercial_Config::feature('feature_yahoo') ) return;
        if ( self::client_id() === '' ) {
            return;
        }

        self::sync_linked_batch(10);

        if ( ! self::auto_discovery_enabled() ) {
            return;
        }

        $last = trim((string)get_option(
            self::OPTION_LAST_DISCOVERY,
            ''
        ));

        $last_ts = $last ? strtotime($last) : 0;

        if (
            ! $last_ts ||
            (current_time('timestamp') - $last_ts) >= HOUR_IN_SECONDS
        ) {
            // v0.9.27: 楽天と同じ1時間周期でYahoo!の新商品も走査する。
            // seller_idで対象を限定し、最大20ページ（1000件）を確認。
            // v0.7.3:
            // seller_id=y-sf に限定しているため、検索結果を最後まで走査。
            // Yahoo APIのstart上限を考慮し最大20ページ（1000件）。
            self::discover(20);
        }
    }

    private static function post_has_yahoo_image( $post_id ) {
        $post_id = intval($post_id);

        $legacy = trim((string)get_post_meta(
            $post_id,
            '_nf_yahoo_image_url',
            true
        ));

        if ( $legacy !== '' ) {
            return true;
        }

        $legacy_all = get_post_meta(
            $post_id,
            '_nf_yahoo_image_urls',
            true
        );

        if ( is_array($legacy_all) ) {
            foreach ( $legacy_all as $url ) {
                if ( trim((string)$url) !== '' ) {
                    return true;
                }
            }
        }

        foreach ( self::get_variants($post_id) as $variant ) {
            if (
                ! empty($variant['image']) &&
                trim((string)$variant['image']) !== ''
            ) {
                return true;
            }

            if (
                ! empty($variant['images']) &&
                is_array($variant['images'])
            ) {
                foreach ( $variant['images'] as $url ) {
                    if ( trim((string)$url) !== '' ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function admin_missing_image_count() {
        return count(
            self::missing_yahoo_image_post_ids()
        );
    }

    private static function missing_yahoo_image_post_ids() {
        $missing = array();

        foreach ( self::linked_post_ids() as $post_id ) {
            if ( ! self::post_has_yahoo_image($post_id) ) {
                $missing[] = intval($post_id);
            }
        }

        return array_values(
            array_unique($missing)
        );
    }

    private static function linked_post_ids() {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ));

        $ids = array_values(array_filter(
            array_map('intval', $ids),
            function($post_id) {
                return ! empty(
                    self::get_variants($post_id)
                );
            }
        ));

        // v0.8.2:
        // 画像が欠けているYahoo!連携商品を先に再同期し、
        // 数百件ある場合でも画像修復までの待ち時間を短縮する。
        usort($ids, function($a, $b) {
            $a_missing = self::post_has_any_saved_image($a) ? 0 : 1;
            $b_missing = self::post_has_any_saved_image($b) ? 0 : 1;

            if ( $a_missing !== $b_missing ) {
                return $b_missing <=> $a_missing;
            }

            return $a <=> $b;
        });

        return $ids;
    }

    public static function sync_linked_batch( $batch_size = 3 ) {
        $ids = self::linked_post_ids();
        $total = count($ids);

        if ( ! $total ) {
            update_option(self::OPTION_CURSOR, 0, false);
            return array('processed' => 0, 'success' => 0, 'errors' => 0);
        }

        $cursor = max(0, intval(get_option(self::OPTION_CURSOR, 0)));
        if ( $cursor >= $total ) $cursor = 0;

        $batch_size = max(1, min(20, intval($batch_size)));

        $processed = 0;
        $success = 0;
        $errors = 0;

        for ( $i = 0; $i < $batch_size; $i++ ) {
            $index = ($cursor + $i) % $total;
            $post_id = intval($ids[$index]);

            if ( $processed > 0 ) {
                usleep(250000);
            }

            $processed++;

            $sync = self::sync_post($post_id);

            if ( is_wp_error($sync) ) {
                $errors++;
            } else {
                $success++;
            }
        }

        update_option(
            self::OPTION_CURSOR,
            ($cursor + $batch_size) % max(1, $total),
            false
        );
        update_option(
            self::OPTION_LAST_SYNC,
            current_time('mysql'),
            false
        );

        return array(
            'processed' => $processed,
            'success' => $success,
            'errors' => $errors,
        );
    }

    public static function add_meta_box() {
        add_meta_box(
            'nf_yahoo_product',
            'Yahoo!ショッピング連携',
            array(__CLASS__, 'render_meta_box'),
            NF_Core::POST_TYPE,
            'normal',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field(
            'nf_yahoo_meta_save',
            'nf_yahoo_meta_nonce'
        );

        $code = get_post_meta($post->ID, '_nf_yahoo_code', true);
        $url = get_post_meta($post->ID, '_nf_yahoo_url', true);
        $name = get_post_meta($post->ID, '_nf_yahoo_item_name', true);
        $price = absint(get_post_meta($post->ID, '_nf_yahoo_price', true));
        $in_stock = get_post_meta($post->ID, '_nf_yahoo_in_stock', true);
        $seller = get_post_meta($post->ID, '_nf_yahoo_seller_name', true);
        $last = get_post_meta($post->ID, '_nf_yahoo_last_sync', true);
        $error = get_post_meta($post->ID, '_nf_yahoo_last_error', true);
        $variants = self::public_variants($post->ID);
        ?>
        <p>
            <label for="nf_yahoo_code"><strong>Yahoo!商品コード</strong></label>
        </p>
        <input
            type="text"
            id="nf_yahoo_code"
            name="nf_yahoo_code"
            value="<?php echo esc_attr($code); ?>"
            class="widefat"
            placeholder="例: storeid_itemcode"
        >

        <p class="description">
            Yahoo!商品検索APIの code（ストアID_商品コード）です。
            自動検索で紐付いた場合は自動入力されます。
        </p>

        <?php if ( $variants ) : ?>
            <h4 style="margin:16px 0 8px">Yahoo!掲載一覧（<?php echo intval(count($variants)); ?>件）</h4>

            <table class="widefat striped" style="margin-top:8px">
                <thead>
                    <tr>
                        <th>商品コード</th>
                        <th>Yahoo!掲載名</th>
                        <th style="width:130px">Yahoo!ストア</th>
                        <th style="width:110px">寄附額</th>
                        <th style="width:90px">在庫</th>
                        <th style="width:90px">リンク</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $variants as $variant ) : ?>
                        <tr>
                            <td><code><?php echo esc_html($variant['code']); ?></code></td>
                            <td><?php echo esc_html($variant['name'] ?: '—'); ?></td>
                            <td>
                                <?php
                                $variant_store_name = self::public_store_name($variant);
                                echo esc_html($variant_store_name ?: '—');
                                ?>
                            </td>
                            <td>
                                <?php echo ! empty($variant['price'])
                                    ? esc_html(number_format_i18n($variant['price']) . '円')
                                    : '—'; ?>
                            </td>
                            <td><?php echo ! empty($variant['inStock']) ? '在庫あり' : '在庫なし'; ?></td>
                            <td>
                                <?php if ( ! empty($variant['url']) ) : ?>
                                    <a
                                        href="<?php echo esc_url($variant['url']); ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >確認</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top:8px">
                同じ返礼品に3kg・5kg・10kgなど複数のYahoo!掲載がある場合も、すべて保持します。
            </p>

            <p style="margin:12px 0 8px">
                <?php
                $image_sync_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action' => 'nf_yahoo_sync_post_image',
                            'furusato_post_id' => intval($post->ID),
                        ),
                        admin_url('admin-post.php')
                    ),
                    'nf_yahoo_sync_post_image_' . intval($post->ID)
                );
                ?>
                <a
                    href="<?php echo esc_url($image_sync_url); ?>"
                    class="button button-secondary"
                >
                    Yahoo!画像を今すぐ再同期
                </a>

                <?php if ( ! self::post_has_yahoo_image($post->ID) ) : ?>
                    <span style="display:inline-block;margin-left:8px;color:#b32d2e;font-weight:600">
                        Yahoo!画像が未取得です
                    </span>
                <?php endif; ?>
            </p>

            <?php if ( $last ) : ?>
                <p class="description">最終同期: <?php echo esc_html($last); ?></p>
            <?php endif; ?>

            <?php if ( $error ) : ?>
                <p style="color:#b32d2e">
                    最終エラー: <?php echo esc_html($error); ?>
                </p>
            <?php endif; ?>

            <p style="margin-top:10px">
                <label>
                    <input type="checkbox" name="nf_yahoo_unlink_on_save" value="1">
                    この返礼品のYahoo!連携をすべて解除する
                </label>
            </p>
        <?php endif;
    }

    public static function save_post_meta( $post_id ) {
        if (
            ! isset($_POST['nf_yahoo_meta_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST['nf_yahoo_meta_nonce'])
                ),
                'nf_yahoo_meta_save'
            )
        ) {
            return;
        }

        if ( ! current_user_can('edit_post', $post_id) ) {
            return;
        }

        if ( ! empty($_POST['nf_yahoo_unlink_on_save']) ) {
            self::unlink_post($post_id);
            return;
        }

        if ( isset($_POST['nf_yahoo_code']) ) {
            $new_code = sanitize_text_field(
                wp_unslash($_POST['nf_yahoo_code'])
            );

            $old_code = (string)get_post_meta(
                $post_id,
                '_nf_yahoo_code',
                true
            );

            if ( $new_code !== $old_code ) {
                update_post_meta(
                    $post_id,
                    '_nf_yahoo_code',
                    $new_code
                );

                if ( $new_code === '' ) {
                    self::unlink_post($post_id);
                }
            }
        }
    }

    private static function unlink_post( $post_id ) {
        foreach ( array(
            '_nf_yahoo_code',
            '_nf_yahoo_url',
            '_nf_yahoo_affiliate_url',
            '_nf_yahoo_item_name',
            '_nf_yahoo_price',
            '_nf_yahoo_in_stock',
            '_nf_yahoo_seller_id',
            '_nf_yahoo_seller_name',
            '_nf_yahoo_image_url',
            '_nf_yahoo_last_sync',
            '_nf_yahoo_last_error',
            '_nf_yahoo_image_urls',
            '_nf_yahoo_match_score',
            '_nf_yahoo_match_reason',
            '_nf_yahoo_only',
            '_nf_yahoo_variants',
            self::META_QUARANTINED_VARIANTS,
            self::META_REVIEW_VARIANTS,
            self::META_AUDIT_OVERRIDES,
        ) as $key ) {
            delete_post_meta($post_id, $key);
        }
    }

    private static function admin_redirect( $message, $type = 'success' ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type' => NF_Core::POST_TYPE,
                    'page' => self::PAGE_SLUG,
                    'nf_yahoo_message' => rawurlencode($message),
                    'nf_yahoo_type' => sanitize_key($type),
                ),
                admin_url('edit.php')
            )
        );
        exit;
    }

    private static function require_admin_action( $nonce_action ) {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        check_admin_referer($nonce_action);
    }

    public static function admin_reset_routes() {
        self::require_admin_action('nf_yahoo_reset_routes');

        update_option(
            self::OPTION_SEARCH_ROUTES,
            self::default_search_routes(),
            false
        );

        delete_option('nf_yahoo_last_route_stats');

        self::admin_redirect(
            'Yahoo!検索ルートを初期設定へ戻しました。'
        );
    }

    public static function admin_audit_all() {
        self::require_admin_action('nf_yahoo_audit_all');

        $summary = self::audit_all_variants(true);

        self::admin_redirect(
            'Yahoo!全件監査完了：確認' .
            intval($summary['checked']) .
            '件 / 正常' .
            intval($summary['normal']) .
            '件 / 要確認' .
            intval($summary['review']) .
            '件 / 新規隔離' .
            intval($summary['quarantinedNew']) .
            '件 / 隔離合計' .
            intval($summary['quarantinedTotal']) .
            '件'
        );
    }

    public static function admin_restore_quarantine() {
        self::require_admin_action('nf_yahoo_restore_quarantine');

        $post_id = isset($_POST['furusato_post_id'])
            ? intval($_POST['furusato_post_id'])
            : 0;

        $code = isset($_POST['yahoo_code'])
            ? sanitize_text_field(
                wp_unslash($_POST['yahoo_code'])
            )
            : '';

        if (
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE ||
            $code === ''
        ) {
            self::admin_redirect(
                '隔離商品の復元指定が不正です。',
                'error'
            );
        }

        $quarantined = self::quarantined_variants($post_id);

        if ( empty($quarantined[$code]) ) {
            self::admin_redirect(
                '指定した隔離商品が見つかりません。',
                'error'
            );
        }

        $variant = $quarantined[$code];

        unset(
            $variant['quarantineReason'],
            $variant['quarantinedAt'],
            $variant['auditGuard']
        );

        unset($quarantined[$code]);

        update_post_meta(
            $post_id,
            self::META_QUARANTINED_VARIANTS,
            $quarantined
        );

        $overrides = self::audit_overrides($post_id);
        $overrides[$code] = array(
            'restoredAt' => current_time('mysql'),
            'userId' => get_current_user_id(),
        );

        update_post_meta(
            $post_id,
            self::META_AUDIT_OVERRIDES,
            $overrides
        );

        $variants = self::get_variants($post_id);
        $variants[$code] = $variant;

        self::save_variants(
            $post_id,
            $variants
        );

        self::admin_redirect(
            '隔離したYahoo!掲載を復元しました。以後この組み合わせは管理者確認済みとして扱います。'
        );
    }

    public static function admin_test() {
        self::require_admin_action('nf_yahoo_test');

        $routes = self::discovery_routes();

        $test_query = ! empty($routes[0]['query'])
            ? $routes[0]['query']
            : (class_exists('NF_Settings') ? NF_Settings::brand_name() : '');

        $result = self::search(
            $test_query,
            1,
            1,
            false
        );

        if ( is_wp_error($result) ) {
            self::admin_redirect(
                'Yahoo! API接続失敗: ' . $result->get_error_message(),
                'error'
            );
        }

        self::admin_redirect(
            'Yahoo! API接続成功。ストア ' .
            self::seller_id() .
            ' 内で通常検索「' .
            $test_query .
            '」の検索結果 ' .
            intval($result['total']) .
            '件を確認しました。追加の事業者識別検索も自動実行されます。'
        );
    }

    public static function admin_discover() {
        self::require_admin_action('nf_yahoo_discover');

        $result = self::discover(20);

        $message =
            'Yahoo!商品検索完了（ストア ' . self::seller_id() . '）：候補' .
            intval($result['found']) .
            '件 / 既存へ自動紐付け' . intval($result['linked']) .
            '件 / Yahoo!単独新規' . intval($result['standalone']) .
            '件 / 自動統合' . intval($result['merged']) .
            '件 / 除外' . intval($result['skipped']) .
            '件 / 失敗' . intval($result['failed']) . '件';

        if ( ! empty($result['routes']) ) {
            $route_parts = array();

            foreach ( $result['routes'] as $route ) {
                $route_parts[] =
                    $route['query'] .
                    '=' .
                    intval($route['accepted']) .
                    '件';
            }

            if ( $route_parts ) {
                $message .= ' / 検索別: ' . implode('、', $route_parts);
            }
        }

        if ( ! empty($result['errors']) ) {
            $message .= '（一部APIエラーあり）';
        }

        self::admin_redirect($message);
    }

    public static function admin_sync_all() {
        self::require_admin_action('nf_yahoo_sync_all');

        $ids = self::linked_post_ids();
        $success = 0;
        $errors = 0;

        foreach ( $ids as $index => $post_id ) {
            if ( $index > 0 ) usleep(250000);

            $sync = self::sync_post($post_id);

            if ( is_wp_error($sync) ) $errors++;
            else $success++;
        }

        update_option(
            self::OPTION_LAST_SYNC,
            current_time('mysql'),
            false
        );

        self::admin_redirect(
            'Yahoo!連携商品を同期しました：成功' .
            $success . '件 / エラー' . $errors . '件'
        );
    }

    public static function admin_sync_missing_images() {
        self::require_admin_action(
            'nf_yahoo_sync_missing_images'
        );

        $ids = self::missing_yahoo_image_post_ids();

        if ( ! $ids ) {
            self::admin_redirect(
                'Yahoo!画像が未取得の返礼品はありません。'
            );
        }

        $success = 0;
        $repaired = 0;
        $still_missing = 0;
        $errors = 0;

        foreach ( $ids as $index => $post_id ) {
            if ( $index > 0 ) {
                usleep(250000);
            }

            $sync = self::sync_post($post_id);

            if ( is_wp_error($sync) ) {
                $errors++;
                continue;
            }

            $success++;

            if ( self::post_has_yahoo_image($post_id) ) {
                $repaired++;
            } else {
                $still_missing++;
            }
        }

        update_option(
            self::OPTION_LAST_SYNC,
            current_time('mysql'),
            false
        );

        self::admin_redirect(
            'Yahoo!画像欠損を手動再同期しました：対象' .
            count($ids) .
            '件 / 同期成功' .
            $success .
            '件 / 画像復旧' .
            $repaired .
            '件 / 画像未取得のまま' .
            $still_missing .
            '件 / エラー' .
            $errors .
            '件'
        );
    }

    public static function admin_sync_post_image() {
        $post_id = isset($_REQUEST['furusato_post_id'])
            ? intval($_REQUEST['furusato_post_id'])
            : 0;

        if (
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE
        ) {
            wp_die('返礼品の指定が不正です。');
        }

        if ( ! current_user_can('edit_post', $post_id) ) {
            wp_die('権限がありません。');
        }

        check_admin_referer(
            'nf_yahoo_sync_post_image_' . $post_id
        );

        $sync = self::sync_post($post_id);

        if ( is_wp_error($sync) ) {
            $result = 'error';
        } elseif ( self::post_has_yahoo_image($post_id) ) {
            $result = 'repaired';
        } else {
            $result = 'missing';
        }

        $return_to = isset($_REQUEST['return_to'])
            ? sanitize_key(
                wp_unslash($_REQUEST['return_to'])
            )
            : '';

        if ( $return_to === 'yahoo' ) {
            if ( $result === 'error' ) {
                self::admin_redirect(
                    'Yahoo!画像の再同期に失敗しました：' .
                    $sync->get_error_message(),
                    'error'
                );
            }

            if ( $result === 'missing' ) {
                self::admin_redirect(
                    'Yahoo!再同期は完了しましたが、この商品はYahoo! APIから画像を取得できませんでした。',
                    'error'
                );
            }

            self::admin_redirect(
                'Yahoo!画像を再同期しました。画像URLを取得できました。'
            );
        }

        $edit_url = get_edit_post_link(
            $post_id,
            'raw'
        );

        if ( ! $edit_url ) {
            $edit_url = admin_url(
                'post.php?post=' .
                intval($post_id) .
                '&action=edit'
            );
        }

        wp_safe_redirect(
            add_query_arg(
                'nf_yahoo_image_sync',
                $result,
                $edit_url
            )
        );
        exit;
    }

    public static function admin_image_sync_notice() {
        if (
            ! isset($_GET['nf_yahoo_image_sync']) ||
            ! isset($_GET['post'])
        ) {
            return;
        }

        $post_id = intval($_GET['post']);

        if (
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE
        ) {
            return;
        }

        $result = sanitize_key(
            wp_unslash(
                $_GET['nf_yahoo_image_sync']
            )
        );

        if ( $result === 'repaired' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Yahoo!画像を再同期しました。画像URLを取得できました。</p></div>';
        } elseif ( $result === 'missing' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Yahoo!再同期は完了しましたが、この商品はYahoo! APIから画像を取得できませんでした。</p></div>';
        } elseif ( $result === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>Yahoo!画像の再同期に失敗しました。Yahoo!連携画面の最終エラーも確認してください。</p></div>';
        }
    }

    public static function admin_manual_link() {
        self::require_admin_action('nf_yahoo_manual_link');

        $code = isset($_POST['yahoo_code'])
            ? sanitize_text_field(wp_unslash($_POST['yahoo_code']))
            : '';

        $post_id = isset($_POST['furusato_post_id'])
            ? intval($_POST['furusato_post_id'])
            : 0;

        if (
            $code === '' ||
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE
        ) {
            self::admin_redirect(
                '手動紐付けの指定が不正です。',
                'error'
            );
        }

        $hit = self::lookup($code, true);

        if ( is_wp_error($hit) ) {
            self::admin_redirect(
                'Yahoo!商品取得失敗: ' .
                $hit->get_error_message(),
                'error'
            );
        }

        $saved = self::link_hit_to_post($post_id, $hit);

        if ( is_wp_error($saved) ) {
            self::admin_redirect(
                $saved->get_error_message(),
                'error'
            );
        }

        $unmatched = get_option(
            self::OPTION_UNMATCHED,
            array()
        );

        if ( is_array($unmatched) ) {
            $unmatched = array_values(array_filter(
                $unmatched,
                function($row) use ($code) {
                    return ! is_array($row) ||
                        ! isset($row['code']) ||
                        $row['code'] !== $code;
                }
            ));

            update_option(
                self::OPTION_UNMATCHED,
                $unmatched,
                false
            );
        }

        self::admin_redirect(
            'Yahoo!商品を返礼品へ紐付けました。'
        );
    }

    public static function admin_unlink() {
        self::require_admin_action('nf_yahoo_unlink');

        $post_id = isset($_POST['furusato_post_id'])
            ? intval($_POST['furusato_post_id'])
            : 0;

        if (
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE
        ) {
            self::admin_redirect('返礼品を確認できません。', 'error');
        }

        self::unlink_post($post_id);

        self::admin_redirect('Yahoo!連携を解除しました。');
    }

    private static function linked_count() {
        return count(self::linked_post_ids());
    }

    public static function settings_page() {
        if ( ! current_user_can('manage_options') ) return;

        $client_id = get_option(self::OPTION_CLIENT_ID, '');
        $affiliate = get_option(self::OPTION_VC_AFFILIATE_ID, '');
        $keyword = get_option(
            self::OPTION_SEARCH_KEYWORD,
            class_exists('NF_Settings') ? NF_Settings::brand_name() : ''
        );
        $seller_id = get_option(
            self::OPTION_SELLER_ID,
            'y-sf'
        );
        $satofull_merge_policy = self::satofull_merge_policy();

        $editable_routes = self::search_routes(true);

        $last_route_stats = get_option(
            'nf_yahoo_last_route_stats',
            array()
        );

        if ( ! is_array($last_route_stats) ) {
            $last_route_stats = array();
        }

        $auto = self::auto_discovery_enabled();

        $unmatched = get_option(
            self::OPTION_UNMATCHED,
            array()
        );

        if ( ! is_array($unmatched) ) $unmatched = array();

        $linked = self::linked_count();

        $missing_image_ids =
            self::missing_yahoo_image_post_ids();

        $missing_image_count =
            count($missing_image_ids);

        $listing_count = 0;
        foreach ( self::linked_post_ids() as $linked_post_id ) {
            $listing_count += count(
                self::get_variants($linked_post_id)
            );
        }

        $yahoo_only_count = count(get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array('publish','draft'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_nf_yahoo_only',
            'meta_value' => '1',
            'no_found_rows' => true,
        )));

        // v0.7.9: 現在の公開対象を軽量監査してサマリー表示。
        $audit_preview = self::audit_all_variants(false);
        $quarantine_rows = self::quarantine_rows(150);
        $last_audit = get_option(
            self::OPTION_LAST_AUDIT,
            array()
        );


        $last_discovery = get_option(
            self::OPTION_LAST_DISCOVERY,
            ''
        );
        $last_sync = get_option(
            self::OPTION_LAST_SYNC,
            ''
        );

        $message = isset($_GET['nf_yahoo_message'])
            ? sanitize_text_field(
                rawurldecode(
                    wp_unslash($_GET['nf_yahoo_message'])
                )
            )
            : '';

        $message_type = isset($_GET['nf_yahoo_type'])
            ? sanitize_key($_GET['nf_yahoo_type'])
            : 'success';

        $posts = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish','draft','pending','private','future'
            ),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
        ?>
        <div class="wrap">
            <h1>Yahoo!ショッピング連携</h1>

            <?php if ( $message ) : ?>
                <div class="notice <?php echo $message_type === 'error' ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <p>
                Yahoo!ショッピングのストア「さとふる」内だけを対象に、
                設定した条件に一致する返礼品を全ページ走査します。
                管理番号に加えて自治体・品目・容量・寄附額・商品名を複合比較します。\n                管理番号完全一致を除き、明確な自治体不一致・品目不一致はスコアに関係なく自動紐付けしません。
                一致しない商品はYahoo!単独返礼品として自動公開します。
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;max-width:900px;margin:16px 0">
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                    <small>Yahoo!連携済み</small>
                    <strong style="display:block;font-size:24px;margin-top:4px"><?php echo intval($linked); ?>件</strong>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                    <small>Yahoo!掲載総数</small>
                    <strong style="display:block;font-size:24px;margin-top:4px"><?php echo intval($listing_count); ?>件</strong>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                    <small>Yahoo!単独返礼品</small>
                    <strong style="display:block;font-size:24px;margin-top:4px"><?php echo intval($yahoo_only_count); ?>件</strong>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                    <small>Yahoo!画像未取得</small>
                    <strong style="display:block;font-size:24px;margin-top:4px;color:<?php echo $missing_image_count ? '#b32d2e' : '#276b2e'; ?>">
                        <?php echo intval($missing_image_count); ?>件
                    </strong>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                    <small>最終探索</small>
                    <strong style="display:block;font-size:15px;margin-top:8px"><?php echo esc_html($last_discovery ?: 'まだありません'); ?></strong>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                    <small>最終同期</small>
                    <strong style="display:block;font-size:15px;margin-top:8px"><?php echo esc_html($last_sync ?: 'まだありません'); ?></strong>
                </div>
            </div>


            <section style="max-width:1100px;margin:18px 0;padding:18px;background:#fff;border:1px solid #dcdcde;border-radius:10px">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap">
                    <div>
                        <h2 style="margin:0 0 5px">Yahoo!画像同期・修復</h2>
                        <p style="margin:0;color:#646970">
                            Yahoo!連携はあるのに画像URLが未取得の返礼品を手動で再取得します。
                            詳細APIで画像が空の場合は商品検索APIから同一商品コードも探します。
                        </p>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <form
                            method="post"
                            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            onsubmit="return window.confirm('Yahoo!画像が未取得の返礼品だけを再同期しますか？');"
                        >
                            <input type="hidden" name="action" value="nf_yahoo_sync_missing_images">
                            <?php wp_nonce_field('nf_yahoo_sync_missing_images'); ?>
                            <button
                                type="submit"
                                class="button button-primary"
                                <?php disabled($missing_image_count < 1); ?>
                            >
                                画像欠損だけ再同期（<?php echo intval($missing_image_count); ?>件）
                            </button>
                        </form>

                        <form
                            method="post"
                            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            onsubmit="return window.confirm('Yahoo!連携済みの商品をすべて再同期しますか？件数が多い場合は時間がかかります。');"
                        >
                            <input type="hidden" name="action" value="nf_yahoo_sync_all">
                            <?php wp_nonce_field('nf_yahoo_sync_all'); ?>
                            <button type="submit" class="button">
                                Yahoo!連携を全件再同期
                            </button>
                        </form>
                    </div>
                </div>

                <div style="margin-top:14px;padding:12px 14px;background:<?php echo $missing_image_count ? '#fff8e5' : '#edf7ee'; ?>;border:1px solid <?php echo $missing_image_count ? '#ead9a6' : '#cfe6d1'; ?>;border-radius:8px">
                    <strong>
                        Yahoo!画像未取得：
                        <?php echo intval($missing_image_count); ?>件
                    </strong>
                    <span style="margin-left:8px;color:#646970">
                        ※楽天画像やアイキャッチがあっても、Yahoo!画像自体が無ければ対象です。
                    </span>
                </div>

                <?php if ( $missing_image_ids ) : ?>
                    <table class="widefat striped" style="margin-top:14px;max-width:100%">
                        <thead>
                            <tr>
                                <th>返礼品</th>
                                <th style="width:150px">自治体</th>
                                <th style="width:190px">Yahoo!商品コード</th>
                                <th style="width:150px">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( array_slice($missing_image_ids, 0, 100) as $missing_post_id ) : ?>
                            <?php
                            $missing_terms = wp_get_post_terms(
                                $missing_post_id,
                                'nf_municipality',
                                array('fields' => 'names')
                            );

                            if ( is_wp_error($missing_terms) ) {
                                $missing_terms = array();
                            }

                            $missing_codes = array();

                            foreach ( self::get_variants($missing_post_id) as $variant ) {
                                if ( ! empty($variant['code']) ) {
                                    $missing_codes[] = $variant['code'];
                                }
                            }

                            $single_sync_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'action' => 'nf_yahoo_sync_post_image',
                                        'furusato_post_id' => intval($missing_post_id),
                                        'return_to' => 'yahoo',
                                    ),
                                    admin_url('admin-post.php')
                                ),
                                'nf_yahoo_sync_post_image_' . intval($missing_post_id)
                            );
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(get_edit_post_link($missing_post_id)); ?>">
                                            <?php echo esc_html(get_the_title($missing_post_id)); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo esc_html(
                                        $missing_terms
                                            ? implode(' / ', $missing_terms)
                                            : '—'
                                    ); ?>
                                </td>
                                <td>
                                    <code>
                                        <?php echo esc_html(
                                            $missing_codes
                                                ? implode(', ', $missing_codes)
                                                : '—'
                                        ); ?>
                                    </code>
                                </td>
                                <td>
                                    <a
                                        href="<?php echo esc_url($single_sync_url); ?>"
                                        class="button button-small"
                                    >
                                        画像を再同期
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ( count($missing_image_ids) > 100 ) : ?>
                        <p class="description">
                            先頭100件を表示しています。一括再同期では全件処理します。
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>


            <section style="max-width:1100px;margin:18px 0;padding:18px;background:#fff;border:1px solid #dcdcde;border-radius:10px">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap">
                    <div>
                        <h2 style="margin:0 0 5px">Yahoo!誤紐付け監査</h2>
                        <p style="margin:0;color:#646970">
                            自治体・品目の明確な不一致を検出し、誤ったYahoo!掲載を公開画面から隔離します。
                            隔離時もデータは削除しません。
                        </p>
                    </div>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="nf_yahoo_audit_all">
                        <?php wp_nonce_field('nf_yahoo_audit_all'); ?>
                        <button type="submit" class="button button-primary">
                            今すぐ全件監査
                        </button>
                    </form>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:9px;margin-top:15px">
                    <div style="padding:12px;background:#edf7ee;border:1px solid #cfe6d1;border-radius:8px">
                        <small>正常</small>
                        <strong style="display:block;font-size:22px;color:#276b2e"><?php echo intval($audit_preview['normal']); ?>件</strong>
                    </div>

                    <div style="padding:12px;background:#fff8e5;border:1px solid #ead9a6;border-radius:8px">
                        <small>要確認</small>
                        <strong style="display:block;font-size:22px;color:#8a6500"><?php echo intval($audit_preview['review']); ?>件</strong>
                    </div>

                    <div style="padding:12px;background:#fff0f0;border:1px solid #eccaca;border-radius:8px">
                        <small>隔離済み</small>
                        <strong style="display:block;font-size:22px;color:#a61b1b"><?php echo intval($audit_preview['quarantinedTotal']); ?>件</strong>
                    </div>

                    <div style="padding:12px;background:#f6f7f7;border:1px solid #ddd;border-radius:8px">
                        <small>今回監査対象</small>
                        <strong style="display:block;font-size:22px"><?php echo intval($audit_preview['checked']); ?>件</strong>
                    </div>
                </div>

                <?php if ( is_array($last_audit) && ! empty($last_audit['time']) ) : ?>
                    <p style="margin:10px 0 0;color:#777;font-size:12px">
                        最終全件監査：<?php echo esc_html($last_audit['time']); ?>
                    </p>
                <?php endif; ?>
            </section>

            <form method="post" action="options.php" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px;max-width:900px">
                <?php settings_fields('nf_yahoo_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_CLIENT_ID); ?>">
                                Yahoo! JAPAN Client ID
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                autocomplete="off"
                                id="<?php echo esc_attr(self::OPTION_CLIENT_ID); ?>"
                                name="<?php echo esc_attr(self::OPTION_CLIENT_ID); ?>"
                                value="<?php echo esc_attr($client_id); ?>"
                            >
                            <p class="description">
                                Yahoo!デベロッパーネットワークで発行されたアプリケーションIDです。
                                チャット等には貼らず、この画面だけに保存してください。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_VC_AFFILIATE_ID); ?>">
                                ValueCommerce Affiliate ID
                            </label>
                        </th>
                        <td>
                            <textarea
                                class="large-text"
                                rows="3"
                                id="<?php echo esc_attr(self::OPTION_VC_AFFILIATE_ID); ?>"
                                name="<?php echo esc_attr(self::OPTION_VC_AFFILIATE_ID); ?>"
                                placeholder="空欄でもYahoo!商品連携は利用できます"
                            ><?php echo esc_textarea($affiliate); ?></textarea>
                            <p class="description">
                                Yahoo!ショッピングのValueCommerce広告コードから、
                                referral URL（末尾が vc_url= のもの）または
                                Yahoo API用にURLエンコード済みのaffiliate_idを入力できます。
                                空欄の場合は通常のYahoo!商品URLを使います。
                            </p>
                        </td>
                    </tr>
<tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_SELLER_ID); ?>">
                                Yahoo!ストアID
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                id="<?php echo esc_attr(self::OPTION_SELLER_ID); ?>"
                                name="<?php echo esc_attr(self::OPTION_SELLER_ID); ?>"
                                value="<?php echo esc_attr($seller_id); ?>"
                            >
                            <p class="description">
                                Yahoo!ショッピングの検索対象ストアを限定します。
                                「さとふる」は <code>y-sf</code> です。
                                通常は変更しないでください。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            Yahoo!検索ルート
                        </th>
                        <td>
                            <p class="description" style="margin-bottom:10px">
                                検索ルートは自由に追加・編集・削除できます。
                                「事業者識別検索」は検索後に自治体と識別子を再確認するため、
                                通常検索より厳格に候補を絞り込みます。
                            </p>

                            <div
                                id="nf-yahoo-routes-editor"
                                class="nf-yahoo-routes-editor"
                            >
                                <div id="nf-yahoo-routes-body">
                                <?php foreach ( $editable_routes as $route_index => $route ) : ?>
                                    <?php
                                    $last_accepted = '—';

                                    foreach ( $last_route_stats as $stat ) {
                                        $id_match =
                                            ! empty($route['id']) &&
                                            ! empty($stat['id']) &&
                                            $route['id'] === $stat['id'];

                                        $query_match =
                                            isset($stat['query']) &&
                                            $stat['query'] === $route['query'];

                                        if ( $id_match || $query_match ) {
                                            $last_accepted =
                                                intval($stat['accepted']) . '件';
                                            break;
                                        }
                                    }

                                    $is_provider =
                                        $route['type'] === 'provider-prefix';
                                    ?>

                                    <section class="nf-yahoo-route-card">
                                        <input
                                            type="hidden"
                                            class="nf-route-id"
                                            name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][id]"
                                            value="<?php echo esc_attr($route['id']); ?>"
                                        >

                                        <div class="nf-yahoo-route-card__head">
                                            <div class="nf-yahoo-route-card__status">
                                                <label class="nf-route-enabled-label">
                                                    <input
                                                        type="checkbox"
                                                        class="nf-route-enabled"
                                                        name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][enabled]"
                                                        value="1"
                                                        <?php checked(! empty($route['enabled'])); ?>
                                                    >
                                                    <span>有効</span>
                                                </label>

                                                <span
                                                    class="nf-route-type-badge <?php echo $is_provider ? 'is-provider' : 'is-keyword'; ?>"
                                                >
                                                    <?php echo $is_provider ? '事業者識別検索' : '通常検索'; ?>
                                                </span>

                                                <span class="nf-route-last">
                                                    前回採用：
                                                    <strong><?php echo esc_html($last_accepted); ?></strong>
                                                </span>
                                            </div>

                                            <div class="nf-yahoo-route-card__actions">
                                                <button
                                                    type="button"
                                                    class="button nf-route-up"
                                                    title="上へ移動"
                                                >↑</button>

                                                <button
                                                    type="button"
                                                    class="button nf-route-down"
                                                    title="下へ移動"
                                                >↓</button>

                                                <button
                                                    type="button"
                                                    class="button-link-delete nf-route-remove"
                                                >削除</button>
                                            </div>
                                        </div>

                                        <div class="nf-yahoo-route-card__grid">
                                            <div class="nf-route-field">
                                                <label>ルート名</label>
                                                <input
                                                    type="text"
                                                    class="regular-text nf-route-name"
                                                    name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][name]"
                                                    value="<?php echo esc_attr($route['name']); ?>"
                                                >
                                            </div>

                                            <div class="nf-route-field">
                                                <label>検索方式</label>
                                                <select
                                                    class="nf-route-type"
                                                    name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][type]"
                                                >
                                                    <option
                                                        value="keyword"
                                                        <?php selected($route['type'], 'keyword'); ?>
                                                    >通常検索</option>

                                                    <option
                                                        value="provider-prefix"
                                                        <?php selected($route['type'], 'provider-prefix'); ?>
                                                    >事業者識別検索</option>
                                                </select>
                                            </div>

                                            <div class="nf-route-field nf-route-query-field">
                                                <label>検索キーワード</label>
                                                <input
                                                    type="text"
                                                    class="regular-text nf-route-query"
                                                    name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][query]"
                                                    value="<?php echo esc_attr($route['query']); ?>"
                                                    placeholder="例: 提供事業者名 地域名"
                                                >
                                            </div>

                                            <div
                                                class="nf-route-provider-fields"
                                                <?php echo $is_provider ? '' : 'hidden'; ?>
                                            >
                                                <div class="nf-route-field">
                                                    <label>自治体</label>
                                                    <select
                                                        class="nf-route-municipality"
                                                        name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][municipality]"
                                                    >
                                                        <option value="">指定なし</option>

                                                        <?php foreach ( self::kumamoto_municipalities() as $municipality_name ) : ?>
                                                            <option
                                                                value="<?php echo esc_attr($municipality_name); ?>"
                                                                <?php selected($route['municipality'], $municipality_name); ?>
                                                            >
                                                                <?php echo esc_html($municipality_name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="nf-route-field">
                                                    <label>事業者識別子</label>
                                                    <input
                                                        type="text"
                                                        class="regular-text nf-route-prefix"
                                                        name="<?php echo esc_attr(self::OPTION_SEARCH_ROUTES); ?>[<?php echo intval($route_index); ?>][providerPrefix]"
                                                        value="<?php echo esc_attr($route['providerPrefix']); ?>"
                                                        placeholder="例: 232"
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                                </div>
                            </div>

                            <p class="nf-yahoo-route-add">
                                <button
                                    type="button"
                                    class="button button-secondary"
                                    id="nf-yahoo-add-route"
                                >
                                    ＋ 検索ルートを追加
                                </button>
                            </p>

                            <p class="description">
                                例：八代市232なら
                                「方式=事業者識別検索 / 検索キーワード=八代市 232 /
                                自治体=八代市 / 識別子=232」。
                                識別子が不要な検索は「通常検索」にしてください。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_SATOFULL_MERGE_POLICY); ?>">
                                さとふるストアのカード統合
                            </label>
                        </th>
                        <td>
                            <select
                                id="<?php echo esc_attr(self::OPTION_SATOFULL_MERGE_POLICY); ?>"
                                name="<?php echo esc_attr(self::OPTION_SATOFULL_MERGE_POLICY); ?>"
                            >
                                <option value="separate" <?php selected($satofull_merge_policy, 'separate'); ?>>常に楽天とは別カード（推奨）</option>
                                <option value="management" <?php selected($satofull_merge_policy, 'management'); ?>>管理番号が完全一致した場合のみ統合</option>
                                <option value="normal" <?php selected($satofull_merge_policy, 'normal'); ?>>通常のYahoo!商品と同じ条件で統合</option>
                            </select>
                            <p class="description">
                                初期設定では、Yahoo!内の「さとふる」ストア商品を楽天商品へ統合しません。
                                「常に別カード」を保存すると、既に楽天カードへ統合済みのさとふる掲載も自動で分離します。
                                容量・玉数・サイズが異なるYahoo!商品コードは、それぞれ1枚のカードになります。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">自動探索</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_AUTO_DISCOVERY); ?>"
                                    value="1"
                                    <?php checked($auto); ?>
                                >
                                1日1回、さとふるストア内のYahoo!掲載を全走査する
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Yahoo!連携設定を保存'); ?>
            </form>

            <div style="display:flex;flex-wrap:wrap;gap:8px;margin:16px 0">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="nf_yahoo_test">
                    <?php wp_nonce_field('nf_yahoo_test'); ?>
                    <button type="submit" class="button">
                        API接続テスト
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="nf_yahoo_discover">
                    <?php wp_nonce_field('nf_yahoo_discover'); ?>
                    <button type="submit" class="button button-primary">
                        Yahoo!商品を検索・自動紐付け
                    </button>
                </form>

                <form
                    method="post"
                    action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    onsubmit="return window.confirm('検索ルートを初期設定へ戻しますか？');"
                >
                    <input type="hidden" name="action" value="nf_yahoo_reset_routes">
                    <?php wp_nonce_field('nf_yahoo_reset_routes'); ?>
                    <button type="submit" class="button">
                        検索ルートを初期値に戻す
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="nf_yahoo_sync_all">
                    <?php wp_nonce_field('nf_yahoo_sync_all'); ?>
                    <button type="submit" class="button">
                        連携済みを今すぐ同期
                    </button>
                </form>
            </div>

            <?php if ( $unmatched ) : ?>
                <h2>自動処理できなかったYahoo!商品</h2>
                <p>
                    通常は一致しない商品もYahoo!単独返礼品として自動作成します。
                    以下は作成・取得エラー等で自動処理できなかった候補です。
                </p>

                <table class="widefat striped" style="max-width:1200px">
                    <thead>
                        <tr>
                            <th style="width:72px">画像</th>
                            <th>Yahoo!商品</th>
                            <th style="width:120px">寄附額</th>
                            <th style="width:130px">管理番号</th>
                            <th style="width:360px">紐付け先</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( array_slice($unmatched, 0, 100) as $row ) : ?>
                        <tr>
                            <td>
                                <?php if ( ! empty($row['image']) ) : ?>
                                    <img
                                        src="<?php echo esc_url($row['image']); ?>"
                                        alt=""
                                        style="width:60px;height:60px;object-fit:cover;border-radius:5px"
                                    >
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($row['name']); ?></strong>
                                <br>
                                <small>
                                    <?php echo esc_html($row['sellerName']); ?>
                                    / <?php echo esc_html($row['code']); ?>
                                </small>
                                <?php if ( ! empty($row['url']) ) : ?>
                                    <br>
                                    <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener">
                                        Yahoo!商品ページ
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo ! empty($row['price'])
                                    ? esc_html(number_format_i18n($row['price']) . '円')
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php echo esc_html($row['managementCode'] ?: '—'); ?>
                            </td>
                            <td>
                                <form
                                    method="post"
                                    action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                    style="display:flex;gap:6px"
                                >
                                    <input type="hidden" name="action" value="nf_yahoo_manual_link">
                                    <input type="hidden" name="yahoo_code" value="<?php echo esc_attr($row['code']); ?>">
                                    <?php wp_nonce_field('nf_yahoo_manual_link'); ?>

                                    <select name="furusato_post_id" required style="max-width:270px">
                                        <option value="">返礼品を選択</option>
                                        <?php foreach ( $posts as $product_post ) : ?>
                                            <option value="<?php echo intval($product_post->ID); ?>">
                                                <?php echo esc_html(
                                                    wp_trim_words(
                                                        $product_post->post_title,
                                                        18,
                                                        '…'
                                                    )
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit" class="button">
                                        紐付け
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>


            <?php if ( $quarantine_rows ) : ?>
                <section style="max-width:1200px;margin-top:24px">
                    <h2>隔離されたYahoo!掲載</h2>
                    <p>
                        明確な自治体・品目不一致により公開対象から外した掲載です。
                        削除はしていません。誤判定だと確認できた場合のみ「復元」を使用してください。
                    </p>

                    <div style="overflow-x:auto">
                        <table class="widefat striped" style="min-width:900px">
                            <thead>
                                <tr>
                                    <th>返礼品</th>
                                    <th>Yahoo!商品</th>
                                    <th>隔離理由</th>
                                    <th>商品コード</th>
                                    <th style="width:90px">Yahoo!</th>
                                    <th style="width:90px">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $quarantine_rows as $row ) : ?>
                                    <tr>
                                        <td>
                                            <?php if ( ! empty($row['editUrl']) ) : ?>
                                                <a href="<?php echo esc_url($row['editUrl']); ?>">
                                                    <?php echo esc_html(
                                                        wp_trim_words(
                                                            $row['postTitle'],
                                                            18,
                                                            '…'
                                                        )
                                                    ); ?>
                                                </a>
                                            <?php else : ?>
                                                <?php echo esc_html($row['postTitle']); ?>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php echo esc_html(
                                                wp_trim_words(
                                                    $row['name'],
                                                    22,
                                                    '…'
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <strong style="color:#b32d2e">
                                                <?php echo esc_html($row['reason']); ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <code><?php echo esc_html($row['code']); ?></code>
                                        </td>

                                        <td>
                                            <?php if ( ! empty($row['url']) ) : ?>
                                                <a
                                                    href="<?php echo esc_url($row['url']); ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                >確認</a>
                                            <?php else : ?>
                                                —
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <form
                                                method="post"
                                                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                                onsubmit="return window.confirm('このYahoo!掲載を復元しますか？ 誤判定であることを確認した場合だけ実行してください。');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="nf_yahoo_restore_quarantine"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="furusato_post_id"
                                                    value="<?php echo intval($row['postId']); ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="yahoo_code"
                                                    value="<?php echo esc_attr($row['code']); ?>"
                                                >
                                                <?php wp_nonce_field('nf_yahoo_restore_quarantine'); ?>
                                                <button type="submit" class="button">
                                                    復元
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <div class="notice notice-success inline" style="max-width:900px;margin-top:20px">
                <p>
                    <strong>v0.7.4 複数検索ルート：</strong>
                    Yahoo!ショッピング全体ではなく、
                    <code>seller_id=<?php echo esc_html($seller_id); ?></code>
                    のストアだけを検索します。
                    初期値 <code>y-sf</code> は「さとふる」ストアです。
                    検索結果が300件を超えても、1000件以内なら50件ずつ最後まで取得します。
                </p>
            </div>

            <div class="notice notice-success inline" style="max-width:900px;margin-top:20px">
                <p>
                    <strong>v0.7.6 管理画面UI刷新：</strong>
                    事業者識別ルートに設定した「自治体 + 識別子」は、
                    検索後の提供事業者判定と商品マッチングの強い補助シグナルにも自動利用されます。
                    ルートを無効化・削除すれば、その識別子ルールも無効になります。
                </p>
            </div>

            <div class="notice notice-info inline" style="max-width:900px;margin-top:20px">
                <p>
                    <strong>自動マッチング：</strong>
                    管理番号完全一致を最優先し、管理番号が違う場合は
                    自治体・品目・容量・寄附額・商品名類似度を0〜100点で複合評価します。
                    82点以上かつ次点候補と十分な差がある場合だけ既存返礼品へ統合します。
                    それ以外は誤結合を避け、Yahoo!単独返礼品として公開します。
                    外部AI APIは使用しないため、API料金や商品データの外部送信はありません。
                </p>
            </div>
        <style>
        .nf-yahoo-routes-editor{
            max-width:920px;
            margin-top:12px;
        }

        #nf-yahoo-routes-body{
            display:grid;
            gap:14px;
        }

        .nf-yahoo-route-card{
            margin:0;
            padding:16px;
            background:#fff;
            border:1px solid #dcdcde;
            border-radius:10px;
            box-shadow:0 1px 2px rgba(0,0,0,.03);
        }

        .nf-yahoo-route-card__head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
            padding-bottom:12px;
            border-bottom:1px solid #ececec;
        }

        .nf-yahoo-route-card__status{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:8px;
            min-width:0;
        }

        .nf-route-enabled-label{
            display:inline-flex;
            align-items:center;
            gap:6px;
            font-weight:600;
        }

        .nf-route-type-badge{
            display:inline-block;
            padding:4px 8px;
            border-radius:999px;
            font-size:11px;
            font-weight:700;
            line-height:1.3;
        }

        .nf-route-type-badge.is-keyword{
            background:#eef5ff;
            color:#135e96;
        }

        .nf-route-type-badge.is-provider{
            background:#edf7ee;
            color:#277332;
        }

        .nf-route-last{
            color:#666;
            font-size:12px;
        }

        .nf-yahoo-route-card__actions{
            display:flex;
            align-items:center;
            gap:6px;
            flex:0 0 auto;
        }

        .nf-yahoo-route-card__grid{
            display:grid;
            grid-template-columns:minmax(0,1fr) minmax(180px,.55fr);
            gap:12px 14px;
        }

        .nf-route-query-field{
            grid-column:1 / -1;
        }

        .nf-route-provider-fields{
            grid-column:1 / -1;
            display:grid;
            grid-template-columns:minmax(0,1fr) minmax(160px,.55fr);
            gap:12px 14px;
            padding:12px;
            background:#f7faf6;
            border:1px solid #e1eadf;
            border-radius:8px;
        }

        .nf-route-provider-fields[hidden]{
            display:none !important;
        }

        .nf-route-field{
            min-width:0;
        }

        .nf-route-field label{
            display:block;
            margin-bottom:5px;
            color:#3c434a;
            font-size:12px;
            font-weight:600;
        }

        .nf-route-field input[type="text"],
        .nf-route-field select{
            width:100%;
            max-width:none;
            min-height:38px;
            box-sizing:border-box;
        }

        .nf-yahoo-route-add{
            margin:12px 0 0;
        }

        @media(max-width:782px){
            .nf-yahoo-route-card{
                padding:13px;
            }

            .nf-yahoo-route-card__head{
                align-items:flex-start;
                flex-direction:column;
            }

            .nf-yahoo-route-card__actions{
                width:100%;
                justify-content:flex-end;
            }

            .nf-yahoo-route-card__grid,
            .nf-route-provider-fields{
                grid-template-columns:1fr;
            }

            .nf-route-query-field{
                grid-column:auto;
            }
        }
        </style>

        <script>
        (function(){
            const container = document.getElementById('nf-yahoo-routes-body');
            const addButton = document.getElementById('nf-yahoo-add-route');

            if (!container || !addButton) return;

            const optionName = <?php echo wp_json_encode(self::OPTION_SEARCH_ROUTES); ?>;
            const municipalities = <?php echo wp_json_encode(
                self::kumamoto_municipalities(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ); ?>;

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function makeMunicipalityOptions() {
                let html = '<option value="">指定なし</option>';

                municipalities.forEach(function(name){
                    html +=
                        '<option value="' +
                        escapeHtml(name) +
                        '">' +
                        escapeHtml(name) +
                        '</option>';
                });

                return html;
            }

            function newRouteId() {
                return (
                    'route_' +
                    Date.now().toString(36) +
                    '_' +
                    Math.random().toString(36).slice(2, 8)
                );
            }

            function routeCards() {
                return Array.from(
                    container.querySelectorAll('.nf-yahoo-route-card')
                );
            }

            function reindexCards() {
                routeCards().forEach(function(card, index){
                    card.querySelectorAll('[name]').forEach(function(field){
                        field.name = field.name.replace(
                            new RegExp(
                                optionName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') +
                                '\\[\\d+\\]'
                            ),
                            optionName + '[' + index + ']'
                        );
                    });
                });
            }

            function updateBadge(card) {
                const type = card.querySelector('.nf-route-type');
                const badge = card.querySelector('.nf-route-type-badge');
                const providerFields = card.querySelector(
                    '.nf-route-provider-fields'
                );

                const isProvider =
                    type && type.value === 'provider-prefix';

                if (providerFields) {
                    providerFields.hidden = !isProvider;
                }

                if (badge) {
                    badge.textContent = isProvider
                        ? '事業者識別検索'
                        : '通常検索';

                    badge.classList.toggle(
                        'is-provider',
                        isProvider
                    );

                    badge.classList.toggle(
                        'is-keyword',
                        !isProvider
                    );
                }
            }

            function maybeBuildProviderQuery(card) {
                const type = card.querySelector('.nf-route-type');
                const municipality = card.querySelector(
                    '.nf-route-municipality'
                );
                const prefix = card.querySelector('.nf-route-prefix');
                const query = card.querySelector('.nf-route-query');

                if (
                    !type ||
                    type.value !== 'provider-prefix' ||
                    !municipality ||
                    !prefix ||
                    !query
                ) {
                    return;
                }

                if (
                    municipality.value &&
                    prefix.value.trim()
                ) {
                    query.value =
                        municipality.value +
                        ' ' +
                        prefix.value.trim();
                }
            }

            function bindCard(card) {
                const remove = card.querySelector('.nf-route-remove');
                const up = card.querySelector('.nf-route-up');
                const down = card.querySelector('.nf-route-down');
                const type = card.querySelector('.nf-route-type');
                const municipality = card.querySelector(
                    '.nf-route-municipality'
                );
                const prefix = card.querySelector('.nf-route-prefix');

                if (remove) {
                    remove.addEventListener('click', function(){
                        card.remove();
                        reindexCards();
                    });
                }

                if (up) {
                    up.addEventListener('click', function(){
                        const prev = card.previousElementSibling;

                        if (prev) {
                            container.insertBefore(card, prev);
                            reindexCards();
                        }
                    });
                }

                if (down) {
                    down.addEventListener('click', function(){
                        const next = card.nextElementSibling;

                        if (next) {
                            container.insertBefore(next, card);
                            reindexCards();
                        }
                    });
                }

                if (type) {
                    type.addEventListener('change', function(){
                        updateBadge(card);
                        maybeBuildProviderQuery(card);
                    });
                }

                if (municipality) {
                    municipality.addEventListener(
                        'change',
                        function(){
                            maybeBuildProviderQuery(card);
                        }
                    );
                }

                if (prefix) {
                    prefix.addEventListener(
                        'input',
                        function(){
                            maybeBuildProviderQuery(card);
                        }
                    );
                }

                updateBadge(card);
            }

            function cardTemplate(index) {
                return (
                    '<section class="nf-yahoo-route-card">' +
                        '<input type="hidden" class="nf-route-id" ' +
                            'name="' + optionName + '[' + index + '][id]" ' +
                            'value="' + newRouteId() + '">' +

                        '<div class="nf-yahoo-route-card__head">' +
                            '<div class="nf-yahoo-route-card__status">' +
                                '<label class="nf-route-enabled-label">' +
                                    '<input type="checkbox" class="nf-route-enabled" ' +
                                        'name="' + optionName + '[' + index + '][enabled]" ' +
                                        'value="1" checked>' +
                                    '<span>有効</span>' +
                                '</label>' +
                                '<span class="nf-route-type-badge is-keyword">通常検索</span>' +
                                '<span class="nf-route-last">前回採用：<strong>—</strong></span>' +
                            '</div>' +

                            '<div class="nf-yahoo-route-card__actions">' +
                                '<button type="button" class="button nf-route-up" title="上へ移動">↑</button>' +
                                '<button type="button" class="button nf-route-down" title="下へ移動">↓</button>' +
                                '<button type="button" class="button-link-delete nf-route-remove">削除</button>' +
                            '</div>' +
                        '</div>' +

                        '<div class="nf-yahoo-route-card__grid">' +
                            '<div class="nf-route-field">' +
                                '<label>ルート名</label>' +
                                '<input type="text" class="regular-text nf-route-name" ' +
                                    'name="' + optionName + '[' + index + '][name]" ' +
                                    'value="新しい検索ルート">' +
                            '</div>' +

                            '<div class="nf-route-field">' +
                                '<label>検索方式</label>' +
                                '<select class="nf-route-type" ' +
                                    'name="' + optionName + '[' + index + '][type]">' +
                                    '<option value="keyword">通常検索</option>' +
                                    '<option value="provider-prefix">事業者識別検索</option>' +
                                '</select>' +
                            '</div>' +

                            '<div class="nf-route-field nf-route-query-field">' +
                                '<label>検索キーワード</label>' +
                                '<input type="text" class="regular-text nf-route-query" ' +
                                    'name="' + optionName + '[' + index + '][query]" ' +
                                    'value="" placeholder="例: 提供事業者名 地域名">' +
                            '</div>' +

                            '<div class="nf-route-provider-fields" hidden>' +
                                '<div class="nf-route-field">' +
                                    '<label>自治体</label>' +
                                    '<select class="nf-route-municipality" ' +
                                        'name="' + optionName + '[' + index + '][municipality]">' +
                                        makeMunicipalityOptions() +
                                    '</select>' +
                                '</div>' +

                                '<div class="nf-route-field">' +
                                    '<label>事業者識別子</label>' +
                                    '<input type="text" class="regular-text nf-route-prefix" ' +
                                        'name="' + optionName + '[' + index + '][providerPrefix]" ' +
                                        'value="" placeholder="例: 232">' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</section>'
                );
            }

            addButton.addEventListener('click', function(){
                const index = routeCards().length;
                const wrapper = document.createElement('div');

                wrapper.innerHTML = cardTemplate(index);

                const card = wrapper.firstElementChild;

                container.appendChild(card);
                bindCard(card);
                reindexCards();

                const nameInput = card.querySelector('.nf-route-name');

                if (nameInput) {
                    nameInput.focus();
                    nameInput.select();
                }
            });

            routeCards().forEach(bindCard);
        })();
        </script>

        </div>
        <?php
    }
}
