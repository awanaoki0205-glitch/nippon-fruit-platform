<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Rakuten {

    const OPTION_APP_ID = 'nf_rakuten_application_id';
    const OPTION_ACCESS_KEY = 'nf_rakuten_access_key';
    const OPTION_AFFILIATE_ID = 'nf_rakuten_affiliate_id';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_nf_fetch_rakuten', array( __CLASS__, 'ajax_fetch_rakuten' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            '楽天API・アフィリエイト設定',
            '楽天連携',
            'manage_options',
            'nippon-fruit-settings',
            array( __CLASS__, 'settings_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'nf_rakuten_settings', self::OPTION_APP_ID, array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        register_setting( 'nf_rakuten_settings', self::OPTION_ACCESS_KEY, array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        register_setting( 'nf_rakuten_settings', self::OPTION_AFFILIATE_ID, array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
    }

    public static function settings_page() {
        if ( ! current_user_can('manage_options') ) return;
        ?>
        <div class="wrap">
            <h1>楽天API・アフィリエイト設定</h1>
            <form method="post" action="options.php">
                <?php settings_fields('nf_rakuten_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_APP_ID); ?>">楽天 Application ID</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION_APP_ID); ?>" id="<?php echo esc_attr(self::OPTION_APP_ID); ?>" type="text" class="regular-text" value="<?php echo esc_attr(get_option(self::OPTION_APP_ID, '')); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ACCESS_KEY); ?>">楽天 Access Key</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_ACCESS_KEY); ?>" id="<?php echo esc_attr(self::OPTION_ACCESS_KEY); ?>" type="password" class="regular-text" value="<?php echo esc_attr(get_option(self::OPTION_ACCESS_KEY, '')); ?>" autocomplete="new-password">
                            <p class="description">楽天Web Service 2026-07-01版ではApplication IDとAccess Keyが必要です。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_AFFILIATE_ID); ?>">楽天 Affiliate ID</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_AFFILIATE_ID); ?>" id="<?php echo esc_attr(self::OPTION_AFFILIATE_ID); ?>" type="text" class="regular-text" value="<?php echo esc_attr(get_option(self::OPTION_AFFILIATE_ID, '')); ?>" autocomplete="off">
                            <p class="description">自動アフィリエイトカードを使う場合は設定してください。楽天APIへaffiliateIdを送信し、affiliateUrlを取得します。</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('保存'); ?>
            </form>
        </div>
        <?php
    }

    public static function enqueue_admin_assets() {
        global $post_type;
        if ( $post_type !== NF_Core::POST_TYPE ) return;

        // v0.6.4: 追加商品画像をWordPressメディアライブラリから複数選択。
        wp_enqueue_media();

        wp_enqueue_script(
            'nippon-fruit-admin',
            NF_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            NF_VERSION,
            true
        );
        wp_localize_script( 'nippon-fruit-admin', 'NF_ADMIN', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nf_fetch_rakuten'),
        ));
    }

    private static function parse_shop_code_from_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty($parts['host']) || empty($parts['path']) ) {
            return new WP_Error('nf_invalid_url', '楽天商品URLを確認してください。');
        }

        $host = strtolower($parts['host']);
        if ( strpos($host, 'item.rakuten.co.jp') === false ) {
            return new WP_Error('nf_invalid_host', 'item.rakuten.co.jp の商品URLを入力してください。');
        }

        $segments = array_values(array_filter(explode('/', trim($parts['path'], '/'))));
        if ( empty($segments[0]) ) {
            return new WP_Error('nf_invalid_path', '楽天商品URLからショップコードを取得できませんでした。');
        }

        return sanitize_key($segments[0]);
    }

    private static function extract_item_id_from_affiliate_html( $html ) {
        if ( ! $html ) return '';

        $patterns = array(
            '/[?&]item_id=(\d+)/i',
            '/[?&]itemId=(\d+)/i',
            '/["\']item_id["\']\s*[:=]\s*["\']?(\d+)/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match($pattern, $html, $m) ) {
                return sanitize_text_field($m[1]);
            }
        }

        return '';
    }

    private static function normalize_items( $data ) {
        if ( ! is_array($data) ) return array();

        $items = array();
        if ( isset($data['Items']) && is_array($data['Items']) ) $items = $data['Items'];
        elseif ( isset($data['items']) && is_array($data['items']) ) $items = $data['items'];

        $normalized = array();
        foreach ( $items as $row ) {
            if ( isset($row['Item']) && is_array($row['Item']) ) $normalized[] = $row['Item'];
            elseif ( isset($row['item']) && is_array($row['item']) ) $normalized[] = $row['item'];
            elseif ( is_array($row) ) $normalized[] = $row;
        }
        return $normalized;
    }

    private static function normalize_image_url( $item ) {
        $urls = self::normalize_image_urls($item);

        return ! empty($urls[0]) ? $urls[0] : '';
    }

    private static function normalize_image_urls( $item ) {
        $urls = array();

        // mediumを優先。smallはmediumに無い画像の補完として使う。
        foreach ( array('mediumImageUrls','smallImageUrls') as $key ) {
            if ( empty($item[$key]) || ! is_array($item[$key]) ) {
                continue;
            }

            foreach ( $item[$key] as $entry ) {
                $url = '';

                if ( is_string($entry) ) {
                    $url = $entry;
                } elseif (
                    is_array($entry) &&
                    ! empty($entry['imageUrl'])
                ) {
                    $url = $entry['imageUrl'];
                }

                $url = self::strip_thumbnail_size($url);

                if ( ! $url ) {
                    continue;
                }

                if ( ! in_array($url, $urls, true) ) {
                    $urls[] = $url;
                }

                // 表示・転送量を考慮し最大12枚。
                if ( count($urls) >= 12 ) {
                    break 2;
                }
            }
        }

        return $urls;
    }

    private static function strip_thumbnail_size( $url ) {
        $url = trim((string)$url);
        if ( $url === '' ) return '';

        $url = preg_replace('/([?&])_ex=\d+x\d+(&?)/i', '$1', $url);
        $url = preg_replace('/[?&]$/', '', $url);
        $url = str_replace('?&', '?', $url);

        return $url;
    }

    private static function request_api( $params ) {
        $app_id = trim((string)get_option(self::OPTION_APP_ID, ''));
        $access_key = trim((string)get_option(self::OPTION_ACCESS_KEY, ''));
        $affiliate_id = trim((string)get_option(self::OPTION_AFFILIATE_ID, ''));

        if ( ! $app_id || ! $access_key ) {
            return new WP_Error('nf_missing_keys', '「返礼品 → Nippon Fruit設定」で楽天Application IDとAccess Keyを設定してください。');
        }

        $endpoint = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';

        $params = array_merge(array(
            'applicationId' => $app_id,
            'format' => 'json',
            'formatVersion' => 2,
            'availability' => 0,
            'hits' => 30,
        ), $params);

        if ( $affiliate_id ) $params['affiliateId'] = $affiliate_id;

        /*
         * Rakuten Web Service 2026-07-01:
         * - Access Key can be sent as an HTTP header.
         * - Apps using Allowed websites may require an HTTP Referer.
         *
         * Server-side WordPress requests do not send Referer automatically,
         * so explicitly identify this WordPress site.
         */
        $site_url = home_url( '/' );
        $origin   = untrailingslashit( home_url( '/' ) );

        $response = wp_remote_get(add_query_arg($params, $endpoint), array(
            'timeout' => 20,
            'headers' => array(
                'accessKey' => $access_key,
                'Referer'   => $site_url,
                'Origin'    => $origin,
                'User-Agent'=> 'Nippon-Fruit-WordPress/' . NF_VERSION . '; ' . $site_url,
            ),
        ));

        if ( is_wp_error($response) ) return $response;

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ( $status !== 200 ) {
            $msg = '楽天APIエラー (HTTP '.$status.')';
            if ( is_array($data) && !empty($data['error_description']) ) {
                $msg .= ': '.$data['error_description'];
            } elseif ( is_array($data) && !empty($data['errors']['errorMessage']) ) {
                $msg .= ': '.$data['errors']['errorMessage'];
            }

            if ( $status === 403 && strpos($msg, 'HTTP_REFERRER') !== false ) {
                $msg .= ' / 送信Referer: ' . $site_url . ' / 楽天Web ServiceのAllowed websitesにこのドメインが登録されているか確認してください。';
            }

            return new WP_Error('nf_rakuten_api', $msg);
        }

        return $data;
    }

    private static function canonical_path_from_url( $url ) {
        $parts = wp_parse_url($url);
        if ( empty($parts['path']) ) return '';
        return trailingslashit(strtolower($parts['path']));
    }

    private static function discover_item_by_url( $url, $shop_code, $keyword = '' ) {
        // フォールバック。affiliate HTMLにitem_idが無い場合のみ利用。
        $target_path = self::canonical_path_from_url($url);

        $params = array(
            'shopCode' => $shop_code,
            'hits' => 30,
        );
        if ( $keyword ) $params['keyword'] = $keyword;

        for ( $page = 1; $page <= 10; $page++ ) {
            $params['page'] = $page;
            $data = self::request_api($params);
            if ( is_wp_error($data) ) return $data;

            $items = self::normalize_items($data);
            foreach ( $items as $item ) {
                if ( empty($item['itemUrl']) ) continue;
                if ( self::canonical_path_from_url($item['itemUrl']) === $target_path ) {
                    return $item;
                }
            }

            $page_count = isset($data['pageCount']) ? intval($data['pageCount']) : 1;
            if ( $page >= $page_count ) break;
        }

        return new WP_Error('nf_item_not_found', '楽天商品URLに一致する商品を特定できませんでした。楽天アフィリエイトHTMLを貼ってから再実行してください。');
    }


    /**
     * v0.3.1: 楽天ショップ一括取込用。
     * 1ページ分の商品を取得し、APIレスポンス形式を正規化して返す。
     */
    public static function bulk_search_page( $params ) {
        $data = self::request_api( $params );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return array(
            'items'     => self::normalize_items( $data ),
            'count'     => isset($data['count']) ? intval($data['count']) : 0,
            'page'      => isset($data['page']) ? intval($data['page']) : 1,
            'pageCount' => isset($data['pageCount']) ? intval($data['pageCount']) : 1,
        );
    }

    /**
     * v0.3.1: 一括取込用の商品画像URL正規化。
     */
    public static function bulk_image_url( $item ) {
        return self::normalize_image_url( $item );
    }

    /**
     * v0.6.3: 楽天APIが返した商品画像を複数取得。
     */
    public static function bulk_image_urls( $item ) {
        return self::normalize_image_urls( $item );
    }

    public static function ajax_fetch_rakuten() {
        check_ajax_referer( 'nf_fetch_rakuten', 'nonce' );

        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $affiliate_html = isset($_POST['affiliateHtml']) ? wp_unslash($_POST['affiliateHtml']) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $saved_item_code = isset($_POST['savedItemCode']) ? sanitize_text_field(wp_unslash($_POST['savedItemCode'])) : '';

        if ( ! $url ) {
            wp_send_json_error(array('message' => '楽天商品URLを入力してください。'), 400);
        }

        $shop_code = self::parse_shop_code_from_url($url);
        if ( is_wp_error($shop_code) ) {
            wp_send_json_error(array('message' => $shop_code->get_error_message()), 400);
        }

        $item_id = self::extract_item_id_from_affiliate_html($affiliate_html);
        $item = null;
        $detected_by = '';

        // 1. 既に保存済みの正しいitemCodeがあれば最優先。
        if ( $saved_item_code && strpos($saved_item_code, $shop_code . ':') === 0 ) {
            $data = self::request_api(array(
                'itemCode' => $saved_item_code,
                'hits' => 1,
                'elements' => 'itemName,itemPrice,itemPriceMin3,itemPriceMax3,itemUrl,itemCode,shopName,shopCode,mediumImageUrls,smallImageUrls,availability,affiliateUrl,startTime,endTime,itemCaption,reviewAverage,reviewCount',
            ));

            if ( ! is_wp_error($data) ) {
                $items = self::normalize_items($data);
                if ( ! empty($items[0]) ) {
                    $item = $items[0];
                    $detected_by = 'saved_item_code';
                }
            }
        }

        // 2. 楽天アフィリエイトHTMLのitem_idから特定。
        if ( ! $item && $item_id ) {
            $item_code = $shop_code . ':' . $item_id;

            $data = self::request_api(array(
                'itemCode' => $item_code,
                'hits' => 1,
                'elements' => 'itemName,itemPrice,itemPriceMin3,itemPriceMax3,itemUrl,itemCode,shopName,shopCode,mediumImageUrls,smallImageUrls,availability,affiliateUrl,startTime,endTime,itemCaption,reviewAverage,reviewCount',
            ));

            if ( is_wp_error($data) ) {
                wp_send_json_error(array('message' => $data->get_error_message()), 400);
            }

            $items = self::normalize_items($data);
            if ( empty($items[0]) ) {
                wp_send_json_error(array('message' => 'itemCodeで商品を取得できませんでした: '.$item_code), 404);
            }

            $item = $items[0];
            $detected_by = 'affiliate_item_id';
        }

        // 3. HTMLが無い新規商品は、ショップ内を検索してitemUrl完全一致で特定。
        if ( ! $item ) {
            $item = self::discover_item_by_url($url, $shop_code, $title);
            if ( is_wp_error($item) ) {
                wp_send_json_error(array(
                    'message' => $item->get_error_message() . ' 新規商品で自動特定できない場合は、初回のみ楽天アフィリエイトHTMLを貼り付けてください。'
                ), 404);
            }
            $detected_by = 'url_search';
        }

        $price = isset($item['itemPrice']) ? intval($item['itemPrice']) : 0;
        $price_min = isset($item['itemPriceMin3']) ? intval($item['itemPriceMin3']) : $price;
        $price_max = isset($item['itemPriceMax3']) ? intval($item['itemPriceMax3']) : $price;

        if ( ! $price_min ) $price_min = $price;
        if ( ! $price_max ) $price_max = $price_min ?: $price;

        wp_send_json_success(array(
            'itemName' => isset($item['itemName']) ? $item['itemName'] : '',
            'itemPrice' => $price,
            'itemPriceMin' => $price_min,
            'itemPriceMax' => $price_max,
            'itemUrl' => isset($item['itemUrl']) ? $item['itemUrl'] : $url,
            'itemCode' => isset($item['itemCode']) ? $item['itemCode'] : '',
            'shopName' => isset($item['shopName']) ? $item['shopName'] : '',
            'shopCode' => isset($item['shopCode']) ? $item['shopCode'] : $shop_code,
            'imageUrl' => self::normalize_image_url($item),
            'imageUrls' => self::normalize_image_urls($item),
            'availability' => isset($item['availability']) ? intval($item['availability']) : null,
            'affiliateUrl' => isset($item['affiliateUrl']) ? $item['affiliateUrl'] : '',
            'saleStart' => isset($item['startTime']) ? $item['startTime'] : '',
            'saleEnd' => isset($item['endTime']) ? $item['endTime'] : '',
            'description' => isset($item['itemCaption']) ? wp_strip_all_tags($item['itemCaption']) : '',
            'reviewAverage' => isset($item['reviewAverage']) ? floatval($item['reviewAverage']) : 0,
            'reviewCount' => isset($item['reviewCount']) ? absint($item['reviewCount']) : 0,
            'detectedBy' => $detected_by,
        ));
    }
}
