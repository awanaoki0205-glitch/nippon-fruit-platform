<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Discovery {

    const PAGE_SLUG = 'nippon-fruit-discovery';
    const OPTION_KNOWN_SHOPS = 'nf_known_rakuten_shops';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        add_action( 'wp_ajax_nf_discovery_search_page', array( __CLASS__, 'ajax_search_page' ) );
        add_action( 'wp_ajax_nf_discovery_import_selected', array( __CLASS__, 'ajax_import_selected' ) );
        add_action( 'wp_ajax_nf_discovery_clear_session', array( __CLASS__, 'ajax_clear_session' ) );
        add_action( 'wp_ajax_nf_discovery_get_known_shops', array( __CLASS__, 'ajax_get_known_shops' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            '商品検索・自動発見',
            '商品検索・自動発見',
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
            'nippon-fruit-discovery',
            NF_PLUGIN_URL . 'assets/discovery.js',
            array('jquery'),
            NF_VERSION,
            true
        );

        wp_localize_script(
            'nippon-fruit-discovery',
            'NF_DISCOVERY',
            array(
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('nf_discovery'),
                'sessionId' => wp_generate_uuid4(),
                'delayMs'   => 1200,
            )
        );

        wp_enqueue_style(
            'nippon-fruit-discovery',
            NF_PLUGIN_URL . 'assets/discovery.css',
            array(),
            NF_VERSION
        );
    }

    public static function render_page() {
        if ( ! current_user_can('manage_options') ) return;

        $known_shops = self::get_all_known_shops();
        ?>
        <div class="wrap nf-discovery-wrap">
            <h1>商品検索・自動発見</h1>

            <p class="description nf-discovery-lead">
                楽天市場全体検索に加え、未確認自治体の探索と確認済み自治体ショップの全走査も行います。
                対象ショップは<strong>熊本県および熊本県内45市町村の公式ふるさと納税ショップのみ</strong>です。
                一般の楽天店舗は検索結果から除外します。
            </p>

            <div class="nf-discovery-box">
                <div class="nf-discovery-field">
                    <label for="nf_discovery_provider">提供事業者名</label>
                    <input
                        type="text"
                        id="nf_discovery_provider"
                        class="regular-text"
                        value="<?php echo esc_attr(class_exists('NF_Settings') ? NF_Settings::provider_name() : ''); ?>"
                    >
                </div>

                <div class="nf-discovery-field">
                    <label for="nf_discovery_keyword">楽天検索キーワード</label>
                    <input
                        type="text"
                        id="nf_discovery_keyword"
                        class="regular-text"
                        value="<?php echo esc_attr(class_exists('NF_Settings') ? NF_Settings::brand_name() : ''); ?>"
                    >
                    <p class="description">
                        楽天全体検索 → 未確認自治体の探索 → 確認済みショップ全商品走査の順で検索します。
                    </p>
                </div>

                <div class="notice notice-info inline nf-discovery-scope-note">
                    <p>
                        <strong>検索対象：</strong>
                        「熊本県」または「熊本県＋市町村名」というショップ名の
                        熊本県内自治体公式ショップのみ。一般店舗は除外します。
                    </p>
                </div>

                <div class="nf-discovery-options">
                    <label>
                        <input type="checkbox" id="nf_discovery_scan_known_shops" checked>
                        未確認自治体の探索＋確認済みショップ全走査
                    </label>

                    <label>
                        <input type="checkbox" id="nf_discovery_auto_municipality" checked>
                        ショップ名から自治体を自動設定
                    </label>

                    <label>
                        <input type="checkbox" id="nf_discovery_auto_fruit" checked>
                        商品名からカテゴリを自動設定
                    </label>

                    <label>
                        新規商品の状態：
                        <select id="nf_discovery_post_status">
                            <option value="draft" selected>下書き</option>
                            <option value="publish">公開</option>
                        </select>
                    </label>
                </div>

                <details class="nf-discovery-details">
                    <summary>詳細設定</summary>
                    <div class="nf-discovery-field">
                        <label for="nf_discovery_shop_filter">ショップコードで限定検索（任意）</label>
                        <input
                            type="text"
                            id="nf_discovery_shop_filter"
                            class="regular-text"
                            placeholder="例：f432024-yatsushiro"
                        >
                        <p class="description">
                            入力した場合はこのショップだけを全走査します。
                        </p>
                    </div>
                </details>

                <div class="nf-discovery-actions">
                    <button type="button" class="button button-primary" id="nf_discovery_search">
                        提供事業者の商品を完全検索
                    </button>
                    <button type="button" class="button" id="nf_discovery_stop" disabled>
                        検索を停止
                    </button>
                </div>

                <div id="nf_discovery_progress" class="nf-discovery-progress" aria-live="polite">
                    商品検索機能を読み込みました。
                </div>
            </div>

            <div id="nf_discovery_summary" class="nf-discovery-summary" style="display:none">
                <div class="nf-discovery-stat">
                    <span>対象商品</span>
                    <strong id="nf_discovery_match_count">0</strong>
                </div>
                <div class="nf-discovery-stat">
                    <span>新規</span>
                    <strong id="nf_discovery_new_count">0</strong>
                </div>
                <div class="nf-discovery-stat">
                    <span>既存</span>
                    <strong id="nf_discovery_existing_count">0</strong>
                </div>
                <div class="nf-discovery-stat">
                    <span>WordPress実在</span>
                    <strong id="nf_discovery_wp_actual_count">0</strong>
                </div>
                <div class="nf-discovery-stat">
                    <span>価格変更</span>
                    <strong id="nf_discovery_price_change_count">0</strong>
                </div>
                <div class="nf-discovery-stat">
                    <span>受付状態変更</span>
                    <strong id="nf_discovery_status_change_count">0</strong>
                </div>
                <div class="nf-discovery-stat">
                    <span>発見自治体</span>
                    <strong id="nf_discovery_municipality_count">0</strong>
                </div>
            </div>

            <div id="nf_discovery_groups" class="nf-discovery-groups" style="display:none"></div>

            <div id="nf_discovery_results_area" style="display:none">
                <div class="nf-discovery-toolbar">
                    <label>
                        <input type="checkbox" id="nf_discovery_select_all" checked>
                        すべて選択
                    </label>

                    <button type="button" class="button button-primary" id="nf_discovery_import">
                        選択した商品を一括登録
                    </button>
                </div>

                <div class="nf-discovery-table-scroll">
                    <table class="widefat striped" id="nf_discovery_results">
                        <thead>
                            <tr>
                                <th class="check-column"></th>
                                <th>画像</th>
                                <th>自治体</th>
                                <th>商品</th>
                                <th>寄附額</th>
                                <th>受付</th>
                                <th>カテゴリ</th>
                                <th>itemCode</th>
                                <th>判定</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div id="nf_discovery_import_result" aria-live="polite"></div>

            <?php if ( $known_shops ) : ?>
                <hr>
                <h2>確認済み楽天ショップ</h2>
                <div class="nf-known-shops">
                    <?php foreach ( $known_shops as $shop_code => $shop ) : ?>
                        <span>
                            <code><?php echo esc_html($shop_code); ?></code>
                            <?php if ( ! empty($shop['shopName']) ) : ?>
                                — <?php echo esc_html($shop['shopName']); ?>
                            <?php endif; ?>
                            <?php if ( ! empty($shop['municipality']) ) : ?>
                                （<?php echo esc_html($shop['municipality']); ?>）
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <hr>

            <h2>v0.4.5の安全策</h2>
            <ul style="list-style:disc;padding-left:22px">
                <li>楽天全体検索 + 発見済みショップ全走査で検索漏れを補完</li>
                <li>既存判定は楽天itemCodeの完全一致だけ。URLでは判定しません</li>
                <li>1円など100円以下のAPI価格は「価格取得不可」として扱う</li>
                <li>商品名の主商品を優先してカテゴリ分類し、SEOキーワードの誤分類を軽減</li>
                <li>新規 / 既存 / 価格変更 / 受付状態変更を集計</li>
                <li>熊本県・熊本県内45市町村以外の楽天ショップを完全除外</li>
            </ul>
        </div>
        <?php
    }

    public static function ajax_clear_session() {
        check_ajax_referer('nf_discovery', 'nonce');

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

    public static function ajax_get_known_shops() {
        check_ajax_referer('nf_discovery', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        wp_send_json_success(array(
            'shops' => self::get_all_known_shops(),
            'unconfirmedMunicipalities' => self::auto_sync_unconfirmed_municipalities(),
            'municipalityStatus' => self::auto_sync_municipality_status(),
        ));
    }

    public static function ajax_search_page() {
        check_ajax_referer('nf_discovery', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        $provider = isset($_POST['provider'])
            ? sanitize_text_field(wp_unslash($_POST['provider']))
            : (class_exists('NF_Settings') ? NF_Settings::provider_name() : '');

        $keyword = isset($_POST['keyword'])
            ? sanitize_text_field(wp_unslash($_POST['keyword']))
            : '';

        $shop_filter = isset($_POST['shopFilter'])
            ? sanitize_key(wp_unslash($_POST['shopFilter']))
            : '';

        $page = isset($_POST['page'])
            ? max(1, min(100, intval($_POST['page'])))
            : 1;

        $session_id = self::sanitize_session_id(
            isset($_POST['sessionId']) ? wp_unslash($_POST['sessionId']) : ''
        );

        if ( ! $provider ) {
            wp_send_json_error(array('message' => '提供事業者名を入力してください。'), 400);
        }

        if ( ! $keyword && ! $shop_filter ) {
            wp_send_json_error(array('message' => '検索キーワードまたはショップコードが必要です。'), 400);
        }

        if ( ! $session_id ) {
            wp_send_json_error(array('message' => '検索セッションを作成できませんでした。'), 400);
        }

        $params = array(
            'page' => $page,
            'hits' => 30,
        );

        if ( $keyword ) {
            $params['keyword'] = $keyword;
        }

        if ( $shop_filter ) {
            $params['shopCode'] = $shop_filter;
        }

        $api = NF_Rakuten::bulk_search_page($params);

        if ( is_wp_error($api) ) {
            wp_send_json_error(array('message' => $api->get_error_message()), 400);
        }

        $provider_normalized = self::normalize_search_text($provider);

        $stored = get_transient(self::transient_key($session_id));
        if ( ! is_array($stored) ) {
            $stored = array(
                'items' => array(),
                'claimedPosts' => array(),
            );
        }
        if ( empty($stored['items']) || ! is_array($stored['items']) ) {
            $stored['items'] = array();
        }
        if ( empty($stored['claimedPosts']) || ! is_array($stored['claimedPosts']) ) {
            $stored['claimedPosts'] = array();
        }

        $matches = array();

        // v0.4.3:
        // WordPressに「実在する返礼品投稿」だけを1回読み込み、
        // itemCode / 楽天URLの完全一致マップを作る。
        // これにより検索結果123件すべてが既存扱いになる誤判定を防ぐ。
        $existing_map = self::load_existing_map();

        foreach ( $api['items'] as $item ) {
            $shop_name_for_filter = isset($item['shopName'])
                ? sanitize_text_field($item['shopName'])
                : '';

            // v0.4.4:
            // 熊本県・熊本県内45市町村の自治体公式ショップ以外は完全除外。
            // 「日本フルーツ協会」等の語を含む一般店舗を拾わない。
            if ( ! self::is_allowed_kumamoto_public_shop($shop_name_for_filter) ) {
                continue;
            }

            $raw_item_code = isset($item['itemCode'])
                ? sanitize_text_field($item['itemCode'])
                : '';

            $raw_item_url = isset($item['itemUrl'])
                ? self::clean_rakuten_url($item['itemUrl'])
                : '';

            $provider_existing = self::lookup_existing_state(
                $existing_map,
                $raw_item_code,
                $raw_item_url
            );

            // v0.6.1:
            // 既存itemCode完全一致は継続同期。
            // 新規候補は「商品説明のどこかに日本フルーツがある」だけでは認定しない。
            if (
                empty($provider_existing['postId']) &&
                ! self::provider_matches_new_item($item, $provider)
            ) {
                continue;
            }

            $normalized = self::normalize_item($item);

            if ( empty($normalized['itemCode']) ) {
                continue;
            }

            $normalized['municipality'] = self::guess_municipality(
                $normalized['shopName'],
                $normalized['itemName'],
                $normalized['itemCaption']
            );

            $normalized['fruitTerms'] = self::guess_fruit_terms(
                $normalized['itemName']
            );

            $existing = self::lookup_existing_state(
                $existing_map,
                $normalized['itemCode'],
                $normalized['itemUrl']
            );

            $matched_post_id = intval($existing['postId']);

            // 1つのWordPress投稿を複数の楽天商品が既存扱いすることを禁止。
            if (
                $matched_post_id > 0 &&
                ! empty($stored['claimedPosts'][$matched_post_id]) &&
                $stored['claimedPosts'][$matched_post_id] !== $normalized['itemCode']
            ) {
                $existing = array(
                    'postId'   => 0,
                    'priceMin' => 0,
                    'priceMax' => 0,
                    'status'   => '',
                );
                $matched_post_id = 0;
            }

            if ( $matched_post_id > 0 ) {
                $stored['claimedPosts'][$matched_post_id] = $normalized['itemCode'];
            }

            $normalized['existing'] = $existing['postId'];
            $normalized['existingPriceMin'] = $existing['priceMin'];
            $normalized['existingPriceMax'] = $existing['priceMax'];
            $normalized['existingStatus'] = $existing['status'];

            $normalized['priceChanged'] = (
                $normalized['existing'] > 0 &&
                self::valid_price($normalized['priceMin']) &&
                (
                    intval($normalized['priceMin']) !== intval($existing['priceMin']) ||
                    intval($normalized['priceMax']) !== intval($existing['priceMax'])
                )
            );

            $api_status = self::status_from_item($normalized);
            $normalized['statusText'] = $api_status;
            $normalized['statusChanged'] = (
                $normalized['existing'] > 0 &&
                $existing['status'] !== '' &&
                $api_status !== $existing['status']
            );

            $stored['items'][$normalized['itemCode']] = $normalized;

            self::remember_shop(
                $normalized['shopCode'],
                $normalized['shopName'],
                $normalized['municipality']
            );

            $matches[] = $normalized;
        }

        set_transient(
            self::transient_key($session_id),
            $stored,
            45 * MINUTE_IN_SECONDS
        );

        $summary = self::summarize_items($stored['items']);

        wp_send_json_success(array(
            'matches'       => $matches,
            'page'          => $api['page'],
            'pageCount'     => min(100, max(1, $api['pageCount'])),
            'count'         => $api['count'],
            'pageItems'     => count($api['items']),
            'stored'        => count($stored['items']),
            'summary'       => $summary,
            'wpActualCount' => intval($existing_map['count']),
            'wpCodedCount'  => intval($existing_map['codedCount']),
            'knownShops'    => self::get_all_known_shops(),
        ));
    }

    public static function ajax_import_selected() {
        check_ajax_referer('nf_discovery', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }

        $session_id = self::sanitize_session_id(
            isset($_POST['sessionId']) ? wp_unslash($_POST['sessionId']) : ''
        );

        $selected = isset($_POST['itemCodes']) && is_array($_POST['itemCodes'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['itemCodes']))
            : array();

        // v0.4.2: 1リクエストで大量投稿を作らず、小分けバッチのみ受け付ける。
        // JS側は5件ずつ送信する。直接呼ばれた場合も最大10件に制限。
        $selected = array_slice(array_values(array_unique($selected)), 0, 10);

        $auto_municipality = ! empty($_POST['autoMunicipality']);
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

        if (
            ! is_array($stored) ||
            empty($stored['items']) ||
            ! is_array($stored['items'])
        ) {
            wp_send_json_error(array(
                'message' => '検索結果の有効期限が切れました。もう一度検索してください。'
            ), 400);
        }

        $created = 0;
        $updated = 0;
        $errors = array();
        $rows = array();

        // 各5件バッチの開始時点で、実在する返礼品だけを再読込。
        // 直前バッチで新規作成された投稿も次バッチでは既存として認識できる。
        $existing_map = self::load_existing_map();

        foreach ( array_values(array_unique($selected)) as $item_code ) {
            if ( empty($stored['items'][$item_code]) ) {
                $errors[] = $item_code . ': 検索結果にありません。';
                continue;
            }

            $item = $stored['items'][$item_code];

            $existing = self::lookup_existing_state(
                $existing_map,
                $item['itemCode'],
                $item['itemUrl']
            );

            $was_existing = ! empty($existing['postId']);

            if ( $was_existing ) {
                $saved_existing_code = trim((string)get_post_meta(
                    intval($existing['postId']),
                    '_nf_rakuten_item_code',
                    true
                ));

                if ( $saved_existing_code !== trim((string)$item['itemCode']) ) {
                    $was_existing = false;
                    $existing = array(
                        'postId'   => 0,
                        'priceMin' => 0,
                        'priceMax' => 0,
                        'status'   => '',
                    );
                }
            }

            if ( $was_existing ) {
                $post_id = intval($existing['postId']);
            } else {
                $post_id = wp_insert_post(array(
                    'post_type'   => NF_Core::POST_TYPE,
                    'post_title'  => $item['itemName'],
                    'post_status' => $post_status,
                ), true);

                if ( is_wp_error($post_id) ) {
                    $errors[] = $item_code . ': WordPress投稿作成エラー - ' . $post_id->get_error_message();
                    continue;
                }

                if ( ! $post_id || get_post_type($post_id) !== NF_Core::POST_TYPE ) {
                    $errors[] = $item_code . ': 投稿作成後の確認に失敗しました。';
                    continue;
                }
            }

            self::save_item_meta($post_id, $item);

            // itemCodeが実際に保存されたか確認。
            $saved_code = (string)get_post_meta($post_id, '_nf_rakuten_item_code', true);
            if ( $saved_code !== (string)$item['itemCode'] ) {
                $errors[] = $item_code . ': itemCode保存確認に失敗しました（投稿ID ' . intval($post_id) . '）。';
                continue;
            }

            if ( $was_existing ) {
                $updated++;
            } else {
                $created++;

                // 同一バッチ内でも以後は既存として扱えるようマップに追加。
                self::add_post_to_existing_map(
                    $existing_map,
                    $post_id,
                    $item['itemCode'],
                    $item['itemUrl']
                );
            }

            if ( $auto_municipality && ! empty($item['municipality']) ) {
                self::set_terms_by_names(
                    $post_id,
                    'nf_municipality',
                    array($item['municipality']),
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
                'status'   => $existing['postId'] ? '更新' : '新規登録',
            );
        }

        $counts = wp_count_posts(NF_Core::POST_TYPE);

        wp_send_json_success(array(
            'created'    => $created,
            'updated'    => $updated,
            'errors'     => $errors,
            'rows'       => $rows,
            'processed'  => count($selected),
            'postCounts' => array(
                'publish' => isset($counts->publish) ? intval($counts->publish) : 0,
                'draft'   => isset($counts->draft) ? intval($counts->draft) : 0,
                'total'   => (isset($counts->publish) ? intval($counts->publish) : 0)
                           + (isset($counts->draft) ? intval($counts->draft) : 0)
                           + (isset($counts->pending) ? intval($counts->pending) : 0)
                           + (isset($counts->private) ? intval($counts->private) : 0),
            ),
        ));
    }


    /**
     * v0.6.0: 自動同期用。既知の熊本県自治体公式ショップ一覧を返す。
     */
    public static function auto_sync_known_shops() {
        return self::get_all_known_shops();
    }

    public static function auto_sync_unconfirmed_municipalities() {
        $known = self::get_all_known_shops();
        $confirmed = array();

        foreach ( (array)$known as $shop ) {
            if ( is_array($shop) && ! empty($shop['municipality']) ) {
                $confirmed[] = sanitize_text_field(
                    $shop['municipality']
                );
            }
        }

        $confirmed = array_values(array_unique($confirmed));

        return array_values(array_filter(
            self::kumamoto_municipality_names(),
            function($municipality) use ($confirmed) {
                return ! in_array($municipality, $confirmed, true);
            }
        ));
    }

    public static function auto_sync_municipality_status() {
        $known = self::get_all_known_shops();
        $by_municipality = array();

        foreach ( (array)$known as $shop_code => $shop ) {
            if (
                ! is_array($shop) ||
                empty($shop['municipality'])
            ) {
                continue;
            }

            $by_municipality[
                sanitize_text_field($shop['municipality'])
            ] = sanitize_key($shop_code);
        }

        $status = array();

        foreach ( self::kumamoto_municipality_names() as $municipality ) {
            $status[$municipality] = isset(
                $by_municipality[$municipality]
            )
                ? $by_municipality[$municipality]
                : '';
        }

        return $status;
    }


    /**
     * v0.6.0: 既存商品1件を楽天APIレスポンスから同期。
     * 既存投稿の公開/下書き状態、サイト表示名、おすすめ指定等は変更しない。
     */
    public static function auto_sync_existing_post( $post_id, $raw_item ) {
        $post_id = intval($post_id);

        if ( ! $post_id || get_post_type($post_id) !== NF_Core::POST_TYPE ) {
            return new WP_Error('nf_sync_invalid_post', '返礼品投稿を確認できません。');
        }

        if ( ! is_array($raw_item) ) {
            return new WP_Error('nf_sync_invalid_item', '楽天商品データが不正です。');
        }

        $item = self::normalize_item($raw_item);

        if ( empty($item['itemCode']) ) {
            return new WP_Error('nf_sync_missing_code', '楽天itemCodeを取得できませんでした。');
        }

        $saved_code = trim((string)get_post_meta(
            $post_id,
            '_nf_rakuten_item_code',
            true
        ));

        if ( $saved_code !== '' && $saved_code !== $item['itemCode'] ) {
            return new WP_Error(
                'nf_sync_code_mismatch',
                '保存済みitemCodeと楽天APIのitemCodeが一致しません。'
            );
        }

        $item['municipality'] = self::guess_municipality(
            $item['shopName'],
            $item['itemName'],
            $item['itemCaption']
        );

        $item['fruitTerms'] = self::guess_fruit_terms($item['itemName']);

        self::save_item_meta($post_id, $item);

        // 楽天掲載名は最新化するが、サイト表示名は変更しない。
        if ( ! empty($item['itemName']) ) {
            wp_update_post(array(
                'ID'         => $post_id,
                'post_title' => $item['itemName'],
            ));
        }

        // 自治体・品目が未設定のときのみ補完。
        $municipality_terms = wp_get_post_terms(
            $post_id,
            'nf_municipality',
            array('fields' => 'ids')
        );

        if (
            ! is_wp_error($municipality_terms) &&
            empty($municipality_terms) &&
            ! empty($item['municipality'])
        ) {
            self::set_terms_by_names(
                $post_id,
                'nf_municipality',
                array($item['municipality']),
                false
            );
        }

        $fruit_terms = wp_get_post_terms(
            $post_id,
            'nf_fruit',
            array('fields' => 'ids')
        );

        if (
            ! is_wp_error($fruit_terms) &&
            empty($fruit_terms) &&
            ! empty($item['fruitTerms'])
        ) {
            self::set_terms_by_names(
                $post_id,
                'nf_fruit',
                $item['fruitTerms'],
                false
            );
        }

        self::remember_shop(
            $item['shopCode'],
            $item['shopName'],
            $item['municipality']
        );

        update_post_meta($post_id, '_nf_sync_last_success', current_time('mysql'));
        update_post_meta($post_id, '_nf_sync_fail_count', 0);
        delete_post_meta($post_id, '_nf_sync_needs_review');
        delete_post_meta($post_id, '_nf_sync_last_error');

        return array(
            'postId'   => $post_id,
            'itemCode' => $item['itemCode'],
            'status'   => self::status_from_item($item),
            'action'   => 'updated',
        );
    }

    /**
     * v0.6.0: 新商品探索用。
     * 熊本県・県内自治体公式ショップ + 日本フルーツの商品だけを登録/更新する。
     */
    public static function auto_sync_discovered_item(
        $raw_item,
        $post_status = 'publish',
        $provider = ''
    ) {
        if ( ! is_array($raw_item) ) {
            return array('action' => 'skipped', 'reason' => 'invalid_item');
        }

        if ( $provider === '' && class_exists('NF_Settings') ) {
            $provider = NF_Settings::provider_name();
        }

        $shop_name = isset($raw_item['shopName'])
            ? sanitize_text_field($raw_item['shopName'])
            : '';

        if ( ! self::is_allowed_kumamoto_public_shop($shop_name) ) {
            return array('action' => 'skipped', 'reason' => 'shop_scope');
        }

        $item = self::normalize_item($raw_item);

        if ( empty($item['itemCode']) ) {
            return array('action' => 'skipped', 'reason' => 'missing_code');
        }

        $existing_map = self::load_existing_map();
        $existing = self::lookup_existing_state(
            $existing_map,
            $item['itemCode'],
            $item['itemUrl']
        );

        if (
            empty($existing['postId']) &&
            ! self::provider_matches_new_item($raw_item, $provider)
        ) {
            return array('action' => 'skipped', 'reason' => 'provider');
        }

        $item['municipality'] = self::guess_municipality(
            $item['shopName'],
            $item['itemName'],
            $item['itemCaption']
        );

        $item['fruitTerms'] = self::guess_fruit_terms($item['itemName']);

        $post_id = intval($existing['postId']);
        $created = false;

        if ( $post_id > 0 ) {
            $saved_code = trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_item_code',
                true
            ));

            if ( $saved_code !== $item['itemCode'] ) {
                $post_id = 0;
            }
        }

        if ( ! $post_id ) {
            if ( ! in_array($post_status, array('draft','publish'), true) ) {
                $post_status = 'publish';
            }

            $post_id = wp_insert_post(array(
                'post_type'   => NF_Core::POST_TYPE,
                'post_title'  => $item['itemName'],
                'post_status' => $post_status,
            ), true);

            if ( is_wp_error($post_id) ) {
                return $post_id;
            }

            if ( ! $post_id || get_post_type($post_id) !== NF_Core::POST_TYPE ) {
                return new WP_Error(
                    'nf_sync_create_failed',
                    '新規返礼品投稿の作成確認に失敗しました。'
                );
            }

            $created = true;
        }

        self::save_item_meta($post_id, $item);

        if ( ! empty($item['itemName']) ) {
            wp_update_post(array(
                'ID'         => $post_id,
                'post_title' => $item['itemName'],
            ));
        }

        if ( ! empty($item['municipality']) ) {
            if ( $created ) {
                self::set_terms_by_names(
                    $post_id,
                    'nf_municipality',
                    array($item['municipality']),
                    false
                );
            } else {
                $terms = wp_get_post_terms(
                    $post_id,
                    'nf_municipality',
                    array('fields' => 'ids')
                );

                if ( ! is_wp_error($terms) && empty($terms) ) {
                    self::set_terms_by_names(
                        $post_id,
                        'nf_municipality',
                        array($item['municipality']),
                        false
                    );
                }
            }
        }

        if ( ! empty($item['fruitTerms']) ) {
            if ( $created ) {
                self::set_terms_by_names(
                    $post_id,
                    'nf_fruit',
                    $item['fruitTerms'],
                    false
                );
            } else {
                $terms = wp_get_post_terms(
                    $post_id,
                    'nf_fruit',
                    array('fields' => 'ids')
                );

                if ( ! is_wp_error($terms) && empty($terms) ) {
                    self::set_terms_by_names(
                        $post_id,
                        'nf_fruit',
                        $item['fruitTerms'],
                        false
                    );
                }
            }
        }

        self::remember_shop(
            $item['shopCode'],
            $item['shopName'],
            $item['municipality']
        );

        update_post_meta($post_id, '_nf_sync_last_success', current_time('mysql'));
        update_post_meta($post_id, '_nf_sync_fail_count', 0);
        delete_post_meta($post_id, '_nf_sync_needs_review');
        delete_post_meta($post_id, '_nf_sync_last_error');

        return array(
            'postId'   => intval($post_id),
            'itemCode' => $item['itemCode'],
            'status'   => self::status_from_item($item),
            'action'   => $created ? 'created' : 'updated',
        );
    }

    private static function summarize_items( $items ) {
        $summary = array(
            'total'         => 0,
            'new'           => 0,
            'existing'      => 0,
            'priceChanged'  => 0,
            'statusChanged' => 0,
            'municipalities'=> array(),
        );

        $claimed_existing_posts = array();

        foreach ( (array)$items as $item ) {
            $summary['total']++;

            $post_id = ! empty($item['existing'])
                ? intval($item['existing'])
                : 0;

            if ( $post_id > 0 && ! isset($claimed_existing_posts[$post_id]) ) {
                $claimed_existing_posts[$post_id] = true;
                $summary['existing']++;
            } else {
                $summary['new']++;
            }

            if ( ! empty($item['priceChanged']) && $post_id > 0 ) {
                $summary['priceChanged']++;
            }

            if ( ! empty($item['statusChanged']) && $post_id > 0 ) {
                $summary['statusChanged']++;
            }

            if ( ! empty($item['municipality']) ) {
                $m = $item['municipality'];
                if ( ! isset($summary['municipalities'][$m]) ) {
                    $summary['municipalities'][$m] = 0;
                }
                $summary['municipalities'][$m]++;
            }
        }

        arsort($summary['municipalities']);

        return $summary;
    }

    /**
     * v0.6.1 新規商品の提供事業者判定。
     * itemCaption全文の単純キーワード一致は使わない。
     */
    private static function provider_matches_new_item(
        $raw_item,
        $provider = ''
    ) {
        if ( ! is_array($raw_item) ) return false;
        if ( $provider === '' && class_exists('NF_Settings') ) {
            $provider = NF_Settings::provider_name();
        }

        $provider_normalized = self::normalize_search_text($provider);
        $provider_short = str_replace(
            '株式会社',
            '',
            $provider_normalized
        );

        if ( $provider_short === '' ) {
            return false;
        }

        $title = isset($raw_item['itemName'])
            ? (string)$raw_item['itemName']
            : '';

        $catchcopy = isset($raw_item['catchcopy'])
            ? (string)$raw_item['catchcopy']
            : '';

        $strong_text = self::normalize_search_text(
            $title . ' ' . $catchcopy
        );

        if (
            ($provider_normalized !== '' &&
             strpos($strong_text, $provider_normalized) !== false) ||
            ($provider_short !== '' &&
             strpos($strong_text, $provider_short) !== false)
        ) {
            return true;
        }

        $caption = isset($raw_item['itemCaption'])
            ? html_entity_decode(
                wp_strip_all_tags((string)$raw_item['itemCaption']),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
            : '';

        // 商品説明は「提供者 / 提供元」などの明示欄だけを根拠にする。
        if ( $caption !== '' && $provider_short !== '' ) {
            $caption_normalized = self::normalize_search_text($caption);
            if (
                preg_match('/(?:提供者|提供元|提供事業者|事業者|販売者|発送元)\s*[:：]?\s*([^\n\r<]{1,120})/u', $caption, $m) &&
                strpos(self::normalize_search_text($m[1]), $provider_short) !== false
            ) {
                return true;
            }
        }

        // 既存の「日本フルーツ明記商品」から学習した管理番号prefixも利用。
        $management = self::extract_management_code($title);

        if ( ! empty($management['prefix']) ) {
            $trusted = self::trusted_management_prefixes();

            if ( in_array($management['prefix'], $trusted, true) ) {
                return true;
            }
        }

        return false;
    }

    private static function extract_management_code( $text ) {
        $text = (string)$text;

        if (
            preg_match(
                '/[\[［【]\s*([A-Z]{2,8})[-_]?(\d{2,8})\s*[\]］】]/u',
                $text,
                $m
            )
        ) {
            return array(
                'code' => strtoupper($m[1] . $m[2]),
                'prefix' => strtoupper($m[1]),
            );
        }

        return array(
            'code' => '',
            'prefix' => '',
        );
    }

    private static function trusted_management_prefixes() {
        static $cache = null;

        if ( is_array($cache) ) {
            return $cache;
        }

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
            $title = trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_item_name',
                true
            ));

            if ( $title === '' ) {
                $title = get_the_title($post_id);
            }

            $normalized = self::normalize_search_text($title);
            $brand = class_exists('NF_Settings') ? self::normalize_search_text(NF_Settings::brand_name()) : '';
            $provider = class_exists('NF_Settings') ? self::normalize_search_text(NF_Settings::provider_name()) : '';

            if (
                ($brand === '' || strpos($normalized, $brand) === false) &&
                ($provider === '' || strpos($normalized, $provider) === false)
            ) {
                continue;
            }

            $management = self::extract_management_code($title);

            if ( ! empty($management['prefix']) ) {
                $cache[] = $management['prefix'];
            }
        }

        $cache = array_values(array_unique($cache));

        return $cache;
    }

    private static function normalize_search_text( $text ) {
        $text = html_entity_decode(
            wp_strip_all_tags((string)$text),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace('/[\s\x{3000}]+/u', '', $text);
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        if ( function_exists('mb_strtolower') ) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }

        return trim($text);
    }

    private static function normalize_item( $item ) {
        $price = isset($item['itemPrice']) ? absint($item['itemPrice']) : 0;
        $price_min = isset($item['itemPriceMin3']) ? absint($item['itemPriceMin3']) : $price;
        $price_max = isset($item['itemPriceMax3']) ? absint($item['itemPriceMax3']) : $price;

        if ( ! $price_min ) $price_min = $price;
        if ( ! $price_max ) $price_max = $price_min ?: $price;

        // 1円等は実寄附額ではないため無効化。
        if ( ! self::valid_price($price_min) ) $price_min = 0;
        if ( ! self::valid_price($price_max) ) $price_max = 0;

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
            'imageUrls'    => array_values(array_filter(array_map(
                'esc_url_raw',
                NF_Rakuten::bulk_image_urls($item)
            ))),
            'availability' => isset($item['availability']) ? intval($item['availability']) : null,
            'affiliateUrl' => isset($item['affiliateUrl']) ? esc_url_raw($item['affiliateUrl']) : '',
            'shopName'     => isset($item['shopName']) ? sanitize_text_field($item['shopName']) : '',
            'shopCode'     => isset($item['shopCode']) ? sanitize_key($item['shopCode']) : '',
            'saleStart'    => isset($item['startTime']) ? sanitize_text_field($item['startTime']) : '',
            'saleEnd'      => isset($item['endTime']) ? sanitize_text_field($item['endTime']) : '',
            'reviewAverage'=> isset($item['reviewAverage']) ? floatval($item['reviewAverage']) : 0,
            'reviewCount'  => isset($item['reviewCount']) ? absint($item['reviewCount']) : 0,
        );
    }

    private static function valid_price( $price ) {
        return intval($price) > 100;
    }

    private static function status_from_item( $item ) {
        if (
            isset($item['availability']) &&
            intval($item['availability']) === 0
        ) {
            return '受付終了';
        }

        if (
            ! empty($item['saleEnd']) &&
            self::rakuten_datetime_is_past($item['saleEnd'])
        ) {
            return '受付終了';
        }

        if (
            ! empty($item['itemName']) &&
            strpos($item['itemName'], '先行予約') !== false
        ) {
            return '先行予約受付中';
        }

        return '受付中';
    }

    private static function rakuten_datetime_is_past( $value ) {
        $value = trim((string)$value);
        if ( $value === '' ) return false;

        try {
            $dt = new DateTimeImmutable($value, wp_timezone());
            return $dt->getTimestamp() < current_time('timestamp');
        } catch ( Exception $e ) {
            return false;
        }
    }

    private static function kumamoto_municipality_names() {
        return array(
            '熊本市','八代市','人吉市','荒尾市','水俣市','玉名市','山鹿市',
            '菊池市','宇土市','上天草市','宇城市','阿蘇市','天草市','合志市',
            '美里町',
            '玉東町','南関町','長洲町','和水町',
            '大津町','菊陽町',
            '南小国町','小国町','産山村','高森町','西原村','南阿蘇村',
            '御船町','嘉島町','益城町','甲佐町','山都町',
            '氷川町',
            '芦北町','津奈木町',
            '錦町','多良木町','湯前町','水上村','相良村','五木村',
            '山江村','球磨村','あさぎり町',
            '苓北町'
        );
    }

    private static function verified_shop_seeds() {
        return array(
            'f430005-kumamoto' => array(
                'shopName' => '熊本県',
                'municipality' => '熊本県',
            ),
            'f431001-kumamoto' => array(
                'shopName' => '熊本県熊本市',
                'municipality' => '熊本市',
            ),
            'f432024-yatsushiro' => array(
                'shopName' => '熊本県八代市',
                'municipality' => '八代市',
            ),
            'f432041-arao' => array(
                'shopName' => '熊本県荒尾市',
                'municipality' => '荒尾市',
            ),
            'f432059-minamata' => array(
                'shopName' => '熊本県水俣市',
                'municipality' => '水俣市',
            ),
            'f432083-yamaga' => array(
                'shopName' => '熊本県山鹿市',
                'municipality' => '山鹿市',
            ),
            'f432105-kikuchi' => array(
                'shopName' => '熊本県菊池市',
                'municipality' => '菊池市',
            ),
            'f432130-uki' => array(
                'shopName' => '熊本県宇城市',
                'municipality' => '宇城市',
            ),
            'f432164-koshi' => array(
                'shopName' => '熊本県合志市',
                'municipality' => '合志市',
            ),
            'f433683-nagasu' => array(
                'shopName' => '熊本県長洲町',
                'municipality' => '長洲町',
            ),
            'f434434-mashiki' => array(
                'shopName' => '熊本県益城町',
                'municipality' => '益城町',
            ),
            'f435015-nishiki' => array(
                'shopName' => '熊本県錦町',
                'municipality' => '錦町',
            ),
            'f435139-kuma' => array(
                'shopName' => '熊本県球磨村',
                'municipality' => '球磨村',
            ),
        );
    }

    /**
     * 熊本県および熊本県内45市町村の公式ショップ名一覧。
     */
    private static function kumamoto_public_shop_map() {
        $municipalities = self::kumamoto_municipality_names();

        $map = array(
            '熊本県' => '熊本県',
        );

        foreach ( $municipalities as $municipality ) {
            $map['熊本県' . $municipality] = $municipality;
        }

        return $map;
    }

    /**
     * 楽天ショップ名が熊本県または熊本県内自治体の公式ショップか。
     */
    private static function is_allowed_kumamoto_public_shop( $shop_name ) {
        $shop_name = self::normalize_shop_name($shop_name);

        if ( $shop_name === '' ) return false;

        $map = self::kumamoto_public_shop_map();

        return array_key_exists($shop_name, $map);
    }

    private static function normalize_shop_name( $shop_name ) {
        $shop_name = html_entity_decode(
            wp_strip_all_tags((string)$shop_name),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 空白や改行のみ除去。名称そのものは変更しない。
        $shop_name = preg_replace('/[\s\x{3000}]+/u', '', $shop_name);

        return trim($shop_name);
    }

    private static function guess_municipality( $shop_name, $item_name = '', $caption = '' ) {
        $shop_name = self::normalize_shop_name($shop_name);
        $map = self::kumamoto_public_shop_map();

        if ( isset($map[$shop_name]) ) {
            return sanitize_text_field($map[$shop_name]);
        }

        // v0.4.4では一般店舗の商品本文から自治体を推測して採用しない。
        // ショップ自体が熊本県・熊本県内自治体であることを必須とする。
        return '';
    }

    private static function guess_fruit_terms( $title ) {
        $title = (string)$title;

        // 定期便・食べ比べは本当に複数品目を含むため複数判定。
        $multi = (
            strpos($title, '定期便') !== false ||
            strpos($title, '食べ比べ') !== false ||
            strpos($title, '詰め合わせ') !== false
        );

        $rules = array(
            array('needles'=>array('デコポン','不知火','デコみかん'), 'terms'=>array('不知火・デコポン','柑橘')),
            array('needles'=>array('晩白柚'), 'terms'=>array('晩白柚','柑橘')),
            array('needles'=>array('パール柑','文旦'), 'terms'=>array('パール柑・文旦','柑橘')),
            array('needles'=>array('河内晩柑','ジューシーオレンジ'), 'terms'=>array('河内晩柑','柑橘')),
            array('needles'=>array('スイートスプリング'), 'terms'=>array('スイートスプリング','柑橘')),
            array('needles'=>array('温州みかん','極早生みかん','早生みかん','みかん','ミカン'), 'terms'=>array('みかん','柑橘')),
            array('needles'=>array('シャインマスカット'), 'terms'=>array('シャインマスカット','ぶどう')),
            array('needles'=>array('ピオーネ'), 'terms'=>array('ピオーネ','ぶどう')),
            array('needles'=>array('巨峰'), 'terms'=>array('巨峰','ぶどう')),
            array('needles'=>array('梨','豊水','秋月','新高','あきづき'), 'terms'=>array('梨')),
            array('needles'=>array('金色羅皇','金色羅王','スイカ','すいか','西瓜'), 'terms'=>array('スイカ')),
            array('needles'=>array('メロン'), 'terms'=>array('メロン')),
            array('needles'=>array('いちご','イチゴ','苺'), 'terms'=>array('いちご')),
            array('needles'=>array('太秋柿','柿'), 'terms'=>array('柿')),
            array('needles'=>array('栗'), 'terms'=>array('栗')),
            array('needles'=>array('さつまいも','サツマイモ','紅はるか','シルクスイート'), 'terms'=>array('さつまいも')),
            array('needles'=>array('アスパラ'), 'terms'=>array('アスパラガス')),
            array('needles'=>array('人参','にんじん','ニンジン'), 'terms'=>array('人参')),
        );

        $terms = array();

        if ( ! $multi ) {
            // 通常商品はタイトル前半の主商品を優先し、SEO後半語で誤分類しない。
            $prefix = function_exists('mb_substr')
                ? mb_substr($title, 0, 90, 'UTF-8')
                : substr($title, 0, 240);

            foreach ( $rules as $rule ) {
                foreach ( $rule['needles'] as $needle ) {
                    if ( strpos($prefix, $needle) !== false ) {
                        return array_values(array_unique($rule['terms']));
                    }
                }
            }
        }

        // 定期便等、または前半で特定できなかった場合は複数判定。
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

    private static function save_item_meta( $post_id, $item ) {
        update_post_meta($post_id, '_nf_rakuten_url', $item['itemUrl']);
        update_post_meta($post_id, '_nf_rakuten_item_code', $item['itemCode']);
        update_post_meta($post_id, '_nf_rakuten_item_name', $item['itemName']);
        update_post_meta($post_id, '_nf_rakuten_image_url', $item['imageUrl']);

        $image_urls = isset($item['imageUrls']) && is_array($item['imageUrls'])
            ? array_values(array_filter(array_unique($item['imageUrls'])))
            : array();

        if ( ! $image_urls && ! empty($item['imageUrl']) ) {
            $image_urls = array($item['imageUrl']);
        }

        update_post_meta(
            $post_id,
            '_nf_rakuten_image_urls',
            array_slice($image_urls, 0, 12)
        );

        update_post_meta($post_id, '_nf_rakuten_shop_name', $item['shopName']);
        update_post_meta($post_id, '_nf_rakuten_affiliate_url', $item['affiliateUrl']);
        update_post_meta($post_id, '_nf_rakuten_sale_start', isset($item['saleStart']) ? $item['saleStart'] : '');
        update_post_meta($post_id, '_nf_rakuten_sale_end', isset($item['saleEnd']) ? $item['saleEnd'] : '');
        update_post_meta($post_id, '_nf_rakuten_description', isset($item['itemCaption']) ? sanitize_text_field($item['itemCaption']) : '');
        update_post_meta($post_id, '_nf_rakuten_review_average', isset($item['reviewAverage']) ? floatval($item['reviewAverage']) : 0);
        update_post_meta($post_id, '_nf_rakuten_review_count', isset($item['reviewCount']) ? absint($item['reviewCount']) : 0);

        // 100円以下の無効価格では既存価格を上書きしない。
        if ( self::valid_price($item['priceMin']) ) {
            update_post_meta($post_id, '_nf_price', $item['priceMin']);
            update_post_meta($post_id, '_nf_price_min', $item['priceMin']);
        }

        if ( self::valid_price($item['priceMax']) ) {
            update_post_meta($post_id, '_nf_price_max', $item['priceMax']);
        }

        update_post_meta(
            $post_id,
            '_nf_status',
            self::status_from_item($item)
        );
    }

    /**
     * WordPressに実在する返礼品投稿だけから既存商品マップを作成。
     *
     * byCode[itemCode] => postId
     * byUrl[cleanUrl]  => postId
     *
     * 投稿実数が9件なら、このマップに入るpostIdも最大9投稿分。
     */
    private static function load_existing_map() {
        $map = array(
            'byCode'     => array(),
            'states'     => array(),
            'count'      => 0,
            'codedCount' => 0,
        );

        $post_ids = get_posts(array(
            'post_type'      => NF_Core::POST_TYPE,
            'post_status'    => array('publish','draft','pending','private','future'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ));

        foreach ( (array)$post_ids as $post_id ) {
            $post_id = intval($post_id);
            if ( ! $post_id ) continue;

            $item_code = trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_item_code',
                true
            ));

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

            if ( ! $price_min ) {
                $price_min = absint(get_post_meta(
                    $post_id,
                    '_nf_price',
                    true
                ));
            }

            if ( ! $price_max ) {
                $price_max = $price_min;
            }

            $map['states'][$post_id] = array(
                'postId'   => $post_id,
                'priceMin' => $price_min,
                'priceMax' => $price_max,
                'status'   => (string)get_post_meta(
                    $post_id,
                    '_nf_status',
                    true
                ),
            );

            if ( $item_code !== '' && ! isset($map['byCode'][$item_code]) ) {
                $map['byCode'][$item_code] = $post_id;
                $map['codedCount']++;
            }
        }

        $map['count'] = count($post_ids);

        return $map;
    }

    /**
     * 実在投稿マップから完全一致で既存状態を返す。
     */
    private static function lookup_existing_state( $map, $item_code, $item_url = '' ) {
        $empty = array(
            'postId'   => 0,
            'priceMin' => 0,
            'priceMax' => 0,
            'status'   => '',
        );

        if ( ! is_array($map) ) return $empty;

        $item_code = trim((string)$item_code);

        // v0.4.5: URLでは一切既存判定しない。
        if ( $item_code === '' || empty($map['byCode'][$item_code]) ) {
            return $empty;
        }

        $post_id = intval($map['byCode'][$item_code]);

        if (
            $post_id > 0 &&
            ! empty($map['states'][$post_id]) &&
            is_array($map['states'][$post_id])
        ) {
            return $map['states'][$post_id];
        }

        return $empty;
    }

    /**
     * 新規投稿作成後、同じAJAXバッチ内の既存マップへ即時追加。
     */
    private static function add_post_to_existing_map(
        &$map,
        $post_id,
        $item_code,
        $item_url
    ) {
        $post_id = intval($post_id);
        if ( ! $post_id ) return;

        $item_code = trim((string)$item_code);

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

        if ( ! $price_min ) {
            $price_min = absint(get_post_meta(
                $post_id,
                '_nf_price',
                true
            ));
        }

        if ( ! $price_max ) $price_max = $price_min;

        $map['states'][$post_id] = array(
            'postId'   => $post_id,
            'priceMin' => $price_min,
            'priceMax' => $price_max,
            'status'   => (string)get_post_meta(
                $post_id,
                '_nf_status',
                true
            ),
        );

        if ( $item_code !== '' ) {
            if ( empty($map['byCode'][$item_code]) ) {
                $map['codedCount'] = isset($map['codedCount'])
                    ? intval($map['codedCount']) + 1
                    : 1;
            }

            $map['byCode'][$item_code] = $post_id;
        }

        $map['count'] = isset($map['count'])
            ? intval($map['count']) + 1
            : 1;
    }

    private static function set_terms_by_names( $post_id, $taxonomy, $names, $append = false ) {
        $term_ids = array();
        $allow_create = $taxonomy !== 'nf_municipality' ||
            ! class_exists('NF_Settings') ||
            NF_Settings::municipality_assist_mode();

        foreach ( (array)$names as $name ) {
            $name = sanitize_text_field(trim((string)$name));
            if ( $name === '' ) continue;

            $term = term_exists($name, $taxonomy);

            if ( ! $term && $allow_create ) {
                $term = wp_insert_term($name, $taxonomy);
            }

            if ( ! $term ) continue;

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

    private static function remember_shop( $shop_code, $shop_name, $municipality ) {
        if ( ! $shop_code ) return;

        // 熊本県・熊本県内自治体ショップだけを学習対象とする。
        if ( ! self::is_allowed_kumamoto_public_shop($shop_name) ) {
            return;
        }

        $shops = get_option(self::OPTION_KNOWN_SHOPS, array());
        if ( ! is_array($shops) ) $shops = array();

        $shops[$shop_code] = array(
            'shopName'     => sanitize_text_field($shop_name),
            'municipality' => sanitize_text_field($municipality),
            'lastSeen'     => current_time('mysql'),
        );

        update_option(self::OPTION_KNOWN_SHOPS, $shops, false);
    }

    /**
     * option + 既存返礼品のitemCode/shopNameからショップ台帳を復元。
     */
    private static function get_all_known_shops() {
        global $wpdb;

        $shops = get_option(self::OPTION_KNOWN_SHOPS, array());
        if ( ! is_array($shops) ) $shops = array();

        // v0.6.1: 確認済み公式ショップを初期台帳へ投入。
        foreach ( self::verified_shop_seeds() as $seed_code => $seed_shop ) {
            if ( ! isset($shops[$seed_code]) ) {
                $shops[$seed_code] = array(
                    'shopName' => $seed_shop['shopName'],
                    'municipality' => $seed_shop['municipality'],
                    'lastSeen' => '',
                    'source' => 'verified-seed',
                );
            }
        }

        // v0.4.4:
        // 過去バージョンで誤学習した一般店舗を既知ショップ台帳から除去。
        foreach ( $shops as $existing_shop_code => $existing_shop ) {
            $existing_shop_name = is_array($existing_shop) && isset($existing_shop['shopName'])
                ? $existing_shop['shopName']
                : '';

            if ( ! self::is_allowed_kumamoto_public_shop($existing_shop_name) ) {
                unset($shops[$existing_shop_code]);
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID,
                        code.meta_value AS item_code,
                        shop.meta_value AS shop_name
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} code
                    ON code.post_id = p.ID
                   AND code.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} shop
                    ON shop.post_id = p.ID
                   AND shop.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash','auto-draft')
                   AND code.meta_value <> ''",
                '_nf_rakuten_item_code',
                '_nf_rakuten_shop_name',
                NF_Core::POST_TYPE
            ),
            ARRAY_A
        );

        foreach ( (array)$rows as $row ) {
            $item_code = isset($row['item_code']) ? (string)$row['item_code'] : '';
            if ( strpos($item_code, ':') === false ) continue;

            list($shop_code) = explode(':', $item_code, 2);
            $shop_code = sanitize_key($shop_code);
            if ( ! $shop_code ) continue;

            $shop_name = isset($row['shop_name'])
                ? sanitize_text_field($row['shop_name'])
                : '';

            // 既存投稿に一般楽天店舗のデータが混ざっていても既知ショップへ追加しない。
            if ( ! self::is_allowed_kumamoto_public_shop($shop_name) ) {
                continue;
            }

            $municipality = self::guess_municipality($shop_name);

            if ( ! isset($shops[$shop_code]) ) {
                $shops[$shop_code] = array(
                    'shopName'     => $shop_name,
                    'municipality' => $municipality,
                    'lastSeen'     => '',
                );
            } else {
                if ( empty($shops[$shop_code]['shopName']) && $shop_name ) {
                    $shops[$shop_code]['shopName'] = $shop_name;
                }
                if ( empty($shops[$shop_code]['municipality']) && $municipality ) {
                    $shops[$shop_code]['municipality'] = $municipality;
                }
            }
        }

        ksort($shops);

        update_option(self::OPTION_KNOWN_SHOPS, $shops, false);

        return $shops;
    }

    private static function transient_key( $session_id ) {
        return 'nf_discovery_' . get_current_user_id() . '_' . md5($session_id);
    }

    private static function sanitize_session_id( $session_id ) {
        $session_id = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$session_id);
        return substr($session_id, 0, 80);
    }
}
