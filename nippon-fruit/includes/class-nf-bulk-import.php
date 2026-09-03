<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Bulk_Import {

    const PAGE_SLUG = 'nippon-fruit-rakuten-bulk';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        add_action( 'wp_ajax_nf_bulk_search_page', array( __CLASS__, 'ajax_search_page' ) );
        add_action( 'wp_ajax_nf_bulk_import_selected', array( __CLASS__, 'ajax_import_selected' ) );
        add_action( 'wp_ajax_nf_bulk_clear_session', array( __CLASS__, 'ajax_clear_session' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            '楽天商品一括取込',
            '楽天商品一括取込',
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue_assets() {
        if (
            empty($_GET['post_type']) ||
            $_GET['post_type'] !== NF_Core::POST_TYPE ||
            empty($_GET['page']) ||
            $_GET['page'] !== self::PAGE_SLUG
        ) {
            return;
        }

        wp_enqueue_script(
            'nippon-fruit-bulk-import',
            NF_PLUGIN_URL . 'assets/bulk-import.js',
            array('jquery'),
            NF_VERSION,
            true
        );

        wp_localize_script(
            'nippon-fruit-bulk-import',
            'NF_BULK',
            array(
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('nf_bulk_import'),
                'sessionId' => wp_generate_uuid4(),
                'delayMs'   => 1200,
            )
        );

        wp_enqueue_style(
            'nippon-fruit-bulk-import',
            NF_PLUGIN_URL . 'assets/bulk-import.css',
            array(),
            NF_VERSION
        );
    }

    public static function render_page() {
        if ( ! current_user_can('manage_options') ) return;
        ?>
        <div class="wrap nf-bulk-wrap">
            <h1>楽天商品一括取込</h1>

            <p>
                楽天ふるさと納税の自治体ショップを検索し、
                商品名・キャッチコピー・商品説明を正規化して検索し、設定した<strong>提供事業者名</strong>に一致する商品を抽出して一括登録できます。
                CSV作成は不要です。
            </p>

            <div class="nf-bulk-settings">
                <div class="nf-bulk-field">
                    <label for="nf_bulk_shop_code">楽天ショップコード</label>
                    <input
                        type="text"
                        id="nf_bulk_shop_code"
                        class="regular-text"
                        placeholder="f432024-yatsushiro"
                    >
                    <p class="description">
                        例：楽天URLが https://www.rakuten.co.jp/f432024-yatsushiro/ なら
                        <code>f432024-yatsushiro</code>
                    </p>
                </div>

                <div class="nf-bulk-field">
                    <label for="nf_bulk_provider">提供事業者名</label>
                    <input
                        type="text"
                        id="nf_bulk_provider"
                        class="regular-text"
                        value="<?php echo esc_attr(class_exists('NF_Settings') ? NF_Settings::provider_name() : ''); ?>"
                    >
                </div>

                <div class="nf-bulk-field">
                    <label for="nf_bulk_municipality">登録する自治体</label>
                    <input
                        type="text"
                        id="nf_bulk_municipality"
                        class="regular-text"
                        placeholder="八代市"
                    >
                    <p class="description">
                        選択商品すべてに同じ自治体を設定します。未登録の自治体名なら自動作成します。
                    </p>
                </div>

                <div class="nf-bulk-field nf-bulk-inline">
                    <label>
                        <input type="checkbox" id="nf_bulk_auto_fruit" checked>
                        商品名からカテゴリを自動判定
                    </label>

                    <label>
                        新規商品の状態：
                        <select id="nf_bulk_post_status">
                            <option value="draft" selected>下書き</option>
                            <option value="publish">公開</option>
                        </select>
                    </label>
                </div>

                <div class="nf-bulk-actions">
                    <button type="button" class="button button-primary" id="nf_bulk_search">
                        提供事業者の商品を検索
                    </button>
                    <button type="button" class="button" id="nf_bulk_stop" disabled>
                        検索を停止
                    </button>
                </div>

                <div id="nf_bulk_progress" class="nf-bulk-progress" aria-live="polite"></div>
            </div>

            <div id="nf_bulk_results_area" style="display:none">
                <div class="nf-bulk-toolbar">
                    <label>
                        <input type="checkbox" id="nf_bulk_select_all" checked>
                        すべて選択
                    </label>

                    <strong>
                        対象商品：
                        <span id="nf_bulk_match_count">0</span>件
                    </strong>

                    <button
                        type="button"
                        class="button button-primary"
                        id="nf_bulk_import"
                    >
                        選択した商品を一括登録
                    </button>
                </div>

                <div class="nf-bulk-table-scroll">
                    <table class="widefat striped" id="nf_bulk_results">
                        <thead>
                            <tr>
                                <th class="check-column"></th>
                                <th>画像</th>
                                <th>商品</th>
                                <th>寄附額</th>
                                <th>受付</th>
                                <th>itemCode</th>
                                <th>自動分類</th>
                                <th>登録状況</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div id="nf_bulk_import_result" aria-live="polite"></div>

            <hr>

            <h2>使い方</h2>
            <ol>
                <li>自治体の楽天ショップコードを入力します。</li>
                <li>提供事業者名は「サイト設定」で登録した名称を確認してください。</li>
                <li>「提供事業者の商品を検索」を押します。</li>
                <li>APIを約1.2秒間隔で順番に読み込み、空白・改行・HTMLを除去して提供事業者名を照合し、指定した提供事業者の商品だけ表示します。</li>
                <li>登録したい商品にチェックして「選択した商品を一括登録」を押します。</li>
            </ol>

            <p>
                同じ楽天 <code>itemCode</code> の商品が既にある場合は重複作成せず、
                API情報だけ更新します。既存の商品タイトルや本文は原則維持します。
            </p>
        </div>
        <?php
    }

    public static function ajax_clear_session() {
        check_ajax_referer('nf_bulk_import', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        $session_id = self::sanitize_session_id(
            isset($_POST['sessionId']) ? wp_unslash($_POST['sessionId']) : ''
        );

        if ( $session_id ) {
            delete_transient(self::transient_key($session_id));
        }

        wp_send_json_success();
    }

    public static function ajax_search_page() {
        check_ajax_referer('nf_bulk_import', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        $shop_code = isset($_POST['shopCode'])
            ? sanitize_key(wp_unslash($_POST['shopCode']))
            : '';

        $provider = isset($_POST['provider'])
            ? sanitize_text_field(wp_unslash($_POST['provider']))
            : '';

        $page = isset($_POST['page'])
            ? max(1, min(100, intval($_POST['page'])))
            : 1;

        $session_id = self::sanitize_session_id(
            isset($_POST['sessionId']) ? wp_unslash($_POST['sessionId']) : ''
        );

        if ( ! $shop_code ) {
            wp_send_json_error(array('message' => '楽天ショップコードを入力してください。'), 400);
        }

        if ( ! $provider ) {
            wp_send_json_error(array('message' => '提供事業者名を入力してください。'), 400);
        }

        if ( ! $session_id ) {
            wp_send_json_error(array('message' => '検索セッションを作成できませんでした。'), 400);
        }

        // v0.3.2:
        // 一括検索では elements 制限を使わず、楽天APIの標準レスポンスを取得します。
        // itemCaption 等の欠落による判定漏れを避けます。
        $api = NF_Rakuten::bulk_search_page(array(
            'shopCode' => $shop_code,
            'page'     => $page,
            'hits'     => 30,
        ));

        if ( is_wp_error($api) ) {
            wp_send_json_error(
                array('message' => $api->get_error_message()),
                400
            );
        }

        $matches = array();
        $stored = get_transient(self::transient_key($session_id));
        if ( ! is_array($stored) ) $stored = array();

        $provider_normalized = self::normalize_search_text($provider);

        foreach ( $api['items'] as $item ) {
            $haystack = implode(' ', array(
                isset($item['itemName']) ? (string)$item['itemName'] : '',
                isset($item['catchcopy']) ? (string)$item['catchcopy'] : '',
                isset($item['itemCaption']) ? (string)$item['itemCaption'] : '',
            ));

            $haystack_normalized = self::normalize_search_text($haystack);

            if (
                $provider_normalized === '' ||
                strpos($haystack_normalized, $provider_normalized) === false
            ) {
                continue;
            }

            $normalized = self::normalize_item($item);

            if ( empty($normalized['itemCode']) ) {
                continue;
            }

            $normalized['fruitTerms'] = self::guess_fruit_terms(
                $normalized['itemName']
            );

            $normalized['existing'] = self::find_existing_post_id(
                $normalized['itemCode'],
                $normalized['itemUrl']
            );

            $stored[$normalized['itemCode']] = $normalized;
            $matches[] = $normalized;
        }

        set_transient(
            self::transient_key($session_id),
            $stored,
            30 * MINUTE_IN_SECONDS
        );

        wp_send_json_success(array(
            'matches'      => $matches,
            'page'         => $api['page'],
            'pageCount'    => min(100, max(1, $api['pageCount'])),
            'count'        => $api['count'],
            'stored'       => count($stored),
            'pageItems'    => count($api['items']),
            'pageMatches'  => count($matches),
            'providerNorm' => $provider_normalized,
        ));
    }

    public static function ajax_import_selected() {
        check_ajax_referer('nf_bulk_import', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        $session_id = self::sanitize_session_id(
            isset($_POST['sessionId']) ? wp_unslash($_POST['sessionId']) : ''
        );

        $selected = isset($_POST['itemCodes']) && is_array($_POST['itemCodes'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['itemCodes']))
            : array();

        $municipality = isset($_POST['municipality'])
            ? sanitize_text_field(wp_unslash($_POST['municipality']))
            : '';

        $auto_fruit = ! empty($_POST['autoFruit']);

        $post_status = isset($_POST['postStatus'])
            ? sanitize_key(wp_unslash($_POST['postStatus']))
            : 'draft';

        if ( ! in_array($post_status, array('draft','publish'), true) ) {
            $post_status = 'draft';
        }

        if ( ! $session_id || ! $selected ) {
            wp_send_json_error(array('message' => '登録する商品を選択してください。'), 400);
        }

        $stored = get_transient(self::transient_key($session_id));
        if ( ! is_array($stored) ) {
            wp_send_json_error(array(
                'message' => '検索結果の有効期限が切れました。もう一度検索してください。'
            ), 400);
        }

        $created = 0;
        $updated = 0;
        $errors = array();
        $rows = array();

        foreach ( array_values(array_unique($selected)) as $item_code ) {
            if ( empty($stored[$item_code]) ) {
                $errors[] = $item_code . ': 検索結果にありません。';
                continue;
            }

            $item = $stored[$item_code];

            $existing_id = self::find_existing_post_id(
                $item['itemCode'],
                $item['itemUrl']
            );

            if ( $existing_id ) {
                $post_id = $existing_id;
                $updated++;
            } else {
                $post_id = wp_insert_post(array(
                    'post_type'   => NF_Core::POST_TYPE,
                    'post_title'  => $item['itemName'],
                    'post_status' => $post_status,
                ), true);

                if ( is_wp_error($post_id) ) {
                    $errors[] = $item_code . ': ' . $post_id->get_error_message();
                    continue;
                }

                $created++;
            }

            self::save_item_meta($post_id, $item);

            if ( $municipality !== '' ) {
                self::set_terms_by_names(
                    $post_id,
                    'nf_municipality',
                    array($municipality),
                    false
                );
            }

            if ( $auto_fruit && ! empty($item['fruitTerms']) ) {
                self::set_terms_by_names(
                    $post_id,
                    'nf_fruit',
                    $item['fruitTerms'],
                    false
                );
            }

            $rows[] = array(
                'itemCode' => $item_code,
                'postId'   => intval($post_id),
                'editUrl'  => get_edit_post_link($post_id, 'raw'),
                'status'   => $existing_id ? '更新' : '新規登録',
            );
        }

        wp_send_json_success(array(
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
            'rows'    => $rows,
        ));
    }

    private static function normalize_search_text( $text ) {
        $text = html_entity_decode(
            wp_strip_all_tags((string)$text),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 半角/全角スペース、改行、タブ等をすべて除去。
        $text = preg_replace('/[\s\x{3000}]+/u', '', $text);

        // Unicodeの不可視文字を除去。
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        if ( function_exists('mb_strtolower') ) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }

        return trim($text);
    }

    private static function normalize_item( $item ) {
        $price = isset($item['itemPrice'])
            ? absint($item['itemPrice'])
            : 0;

        $price_min = isset($item['itemPriceMin3'])
            ? absint($item['itemPriceMin3'])
            : $price;

        $price_max = isset($item['itemPriceMax3'])
            ? absint($item['itemPriceMax3'])
            : $price;

        if ( ! $price_min ) $price_min = $price;
        if ( ! $price_max ) $price_max = $price_min ?: $price;

        $item_url = isset($item['itemUrl'])
            ? self::clean_rakuten_url($item['itemUrl'])
            : '';

        return array(
            'itemName'     => isset($item['itemName']) ? sanitize_text_field($item['itemName']) : '',
            'catchcopy'    => isset($item['catchcopy']) ? sanitize_text_field($item['catchcopy']) : '',
            'itemCaption'  => isset($item['itemCaption']) ? wp_strip_all_tags($item['itemCaption']) : '',
            'itemCode'     => isset($item['itemCode']) ? sanitize_text_field($item['itemCode']) : '',
            'itemPrice'    => $price,
            'priceMin'     => $price_min,
            'priceMax'     => $price_max,
            'itemUrl'      => esc_url_raw($item_url),
            'imageUrl'     => esc_url_raw(NF_Rakuten::bulk_image_url($item)),
            'availability' => isset($item['availability']) ? intval($item['availability']) : null,
            'affiliateUrl' => isset($item['affiliateUrl']) ? esc_url_raw($item['affiliateUrl']) : '',
            'shopName'     => isset($item['shopName']) ? sanitize_text_field($item['shopName']) : '',
            'shopCode'     => isset($item['shopCode']) ? sanitize_key($item['shopCode']) : '',
            'description'  => isset($item['itemCaption']) ? sanitize_text_field(wp_strip_all_tags($item['itemCaption'])) : '',
            'reviewAverage'=> isset($item['reviewAverage']) ? floatval($item['reviewAverage']) : 0,
            'reviewCount'  => isset($item['reviewCount']) ? absint($item['reviewCount']) : 0,
        );
    }

    private static function save_item_meta( $post_id, $item ) {
        update_post_meta($post_id, '_nf_rakuten_url', $item['itemUrl']);
        update_post_meta($post_id, '_nf_rakuten_item_code', $item['itemCode']);
        update_post_meta($post_id, '_nf_rakuten_item_name', $item['itemName']);
        update_post_meta($post_id, '_nf_rakuten_image_url', $item['imageUrl']);
        update_post_meta($post_id, '_nf_rakuten_shop_name', $item['shopName']);
        update_post_meta($post_id, '_nf_rakuten_affiliate_url', $item['affiliateUrl']);
        update_post_meta($post_id, '_nf_rakuten_description', isset($item['description']) ? $item['description'] : '');
        update_post_meta($post_id, '_nf_rakuten_review_average', isset($item['reviewAverage']) ? floatval($item['reviewAverage']) : 0);
        update_post_meta($post_id, '_nf_rakuten_review_count', isset($item['reviewCount']) ? absint($item['reviewCount']) : 0);

        update_post_meta($post_id, '_nf_price', $item['priceMin']);
        update_post_meta($post_id, '_nf_price_min', $item['priceMin']);
        update_post_meta($post_id, '_nf_price_max', $item['priceMax']);

        $status = '受付中';

        if ( isset($item['availability']) && intval($item['availability']) === 0 ) {
            $status = '受付終了';
        } elseif (
            ! empty($item['itemName']) &&
            strpos($item['itemName'], '先行予約') !== false
        ) {
            $status = '先行予約受付中';
        }

        update_post_meta($post_id, '_nf_status', $status);
    }

    private static function find_existing_post_id( $item_code, $item_url = '' ) {
        if ( $item_code ) {
            $q = new WP_Query(array(
                'post_type'      => NF_Core::POST_TYPE,
                'post_status'    => array('publish','draft','pending','private','future'),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_nf_rakuten_item_code',
                'meta_value'     => $item_code,
                'no_found_rows'  => true,
            ));

            if ( ! empty($q->posts[0]) ) {
                return intval($q->posts[0]);
            }
        }

        if ( $item_url ) {
            $q = new WP_Query(array(
                'post_type'      => NF_Core::POST_TYPE,
                'post_status'    => array('publish','draft','pending','private','future'),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_nf_rakuten_url',
                'meta_value'     => $item_url,
                'no_found_rows'  => true,
            ));

            if ( ! empty($q->posts[0]) ) {
                return intval($q->posts[0]);
            }
        }

        return 0;
    }

    private static function guess_fruit_terms( $title ) {
        $terms = array();

        $rules = array(
            array(
                'needles' => array('不知火','デコポン','デコみかん'),
                'terms'   => array('不知火・デコポン','柑橘'),
            ),
            array(
                'needles' => array('晩白柚'),
                'terms'   => array('晩白柚','柑橘'),
            ),
            array(
                'needles' => array('温州みかん','温州ミカン','みかん','ミカン'),
                'terms'   => array('みかん','柑橘'),
            ),
            array(
                'needles' => array('梨','豊水','秋月','新高','あきづき'),
                'terms'   => array('梨'),
            ),
            array(
                'needles' => array('シャインマスカット'),
                'terms'   => array('シャインマスカット','ぶどう'),
            ),
            array(
                'needles' => array('巨峰'),
                'terms'   => array('巨峰','ぶどう'),
            ),
            array(
                'needles' => array('ピオーネ'),
                'terms'   => array('ピオーネ','ぶどう'),
            ),
            array(
                'needles' => array('ぶどう','葡萄'),
                'terms'   => array('ぶどう'),
            ),
            array(
                'needles' => array('すいか','スイカ','羅王','羅皇','金色羅皇'),
                'terms'   => array('スイカ'),
            ),
            array(
                'needles' => array('いちご','イチゴ','苺'),
                'terms'   => array('いちご'),
            ),
            array(
                'needles' => array('太秋柿','柿'),
                'terms'   => array('柿'),
            ),
            array(
                'needles' => array('栗'),
                'terms'   => array('栗'),
            ),
            array(
                'needles' => array('メロン'),
                'terms'   => array('メロン'),
            ),
        );

        foreach ( $rules as $rule ) {
            foreach ( $rule['needles'] as $needle ) {
                if ( strpos($title, $needle) !== false ) {
                    $terms = array_merge($terms, $rule['terms']);
                    break;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    private static function set_terms_by_names( $post_id, $taxonomy, $names, $append = false ) {
        $term_ids = array();

        foreach ( (array)$names as $name ) {
            $name = sanitize_text_field(trim((string)$name));
            if ( $name === '' ) continue;

            $term = term_exists($name, $taxonomy);

            if ( ! $term ) {
                $term = wp_insert_term($name, $taxonomy);
            }

            if ( is_wp_error($term) ) continue;

            if ( is_array($term) && isset($term['term_id']) ) {
                $term_ids[] = intval($term['term_id']);
            } elseif ( is_int($term) ) {
                $term_ids[] = $term;
            }
        }

        if ( $term_ids ) {
            wp_set_object_terms(
                $post_id,
                array_values(array_unique($term_ids)),
                $taxonomy,
                $append
            );
        }
    }

    private static function clean_rakuten_url( $url ) {
        $parts = wp_parse_url($url);

        if ( empty($parts['scheme']) || empty($parts['host']) || empty($parts['path']) ) {
            return $url;
        }

        return $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
    }

    private static function transient_key( $session_id ) {
        return 'nf_bulk_' . get_current_user_id() . '_' . md5($session_id);
    }

    private static function sanitize_session_id( $session_id ) {
        $session_id = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$session_id);
        return substr($session_id, 0, 80);
    }
}
