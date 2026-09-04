<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Core {

    const POST_TYPE = 'nf_furusato';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_content' ) );
        add_action( 'init', array( __CLASS__, 'maybe_split_prefecture_office_terms' ), 30 );
        add_action( 'created_nf_municipality', array( __CLASS__, 'invalidate_prefecture_office_migration' ) );
        add_action( 'edited_nf_municipality', array( __CLASS__, 'invalidate_prefecture_office_migration' ) );
        add_action( 'set_object_terms', array( __CLASS__, 'maybe_invalidate_prefecture_office_migration' ), 10, 6 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ) );
        add_filter( 'the_content', array( __CLASS__, 'append_product_box' ) );
        add_shortcode( 'nippon_fruit_products', array( __CLASS__, 'shortcode_products' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public_assets' ) );
    }

    public static function activate() {
        self::register_content();
        self::maybe_split_prefecture_office_terms();
        flush_rewrite_rules();
    }

    /**
     * Separate a prefectural-government donation destination from its grouping
     * term. The rule applies equally to every 都/道/府/県 and is safe for sites
     * serving one or several prefectures.
     */
    public static function maybe_split_prefecture_office_terms() {
        $migration_version = '1';
        if ( get_option('nf_prefecture_office_migration_version', '') === $migration_version ) return;
        if ( ! taxonomy_exists('nf_municipality') || ! post_type_exists(self::POST_TYPE) ) return;

        $roots = get_terms(array(
            'taxonomy' => 'nf_municipality',
            'hide_empty' => false,
            'parent' => 0,
        ));
        if ( is_wp_error($roots) ) return;

        $summary = array('groups' => 0, 'destinations' => 0, 'products' => 0);
        foreach ( $roots as $root ) {
            if ( ! preg_match('/(都|道|府|県)$/u', (string)$root->name, $suffix) ) continue;

            $children = get_terms(array(
                'taxonomy' => 'nf_municipality',
                'hide_empty' => false,
                'parent' => (int)$root->term_id,
                'number' => 1,
            ));
            if ( is_wp_error($children) || ! $children ) continue;

            $office_labels = array('都' => '都庁', '道' => '道庁', '府' => '府庁', '県' => '県庁');
            $child_name = $root->name . '（' . $office_labels[$suffix[1]] . '）';
            $existing = get_terms(array(
                'taxonomy' => 'nf_municipality',
                'hide_empty' => false,
                'parent' => (int)$root->term_id,
                'name' => $child_name,
                'number' => 1,
            ));
            if ( ! is_wp_error($existing) && $existing ) {
                $office = reset($existing);
            } else {
                $inserted = wp_insert_term($child_name, 'nf_municipality', array(
                    'parent' => (int)$root->term_id,
                    'slug' => sanitize_title($root->slug . '-government-office'),
                ));
                if ( is_wp_error($inserted) ) continue;
                $office = get_term((int)$inserted['term_id'], 'nf_municipality');
                if ( ! $office || is_wp_error($office) ) continue;
                $summary['destinations']++;
            }

            $direct_ids = get_objects_in_term((int)$root->term_id, 'nf_municipality');
            if ( is_wp_error($direct_ids) || ! $direct_ids ) {
                $summary['groups']++;
                continue;
            }

            foreach ( array_map('absint', $direct_ids) as $post_id ) {
                if ( get_post_type($post_id) !== self::POST_TYPE ) continue;
                $assigned = wp_get_object_terms($post_id, 'nf_municipality', array('fields' => 'ids'));
                if ( is_wp_error($assigned) ) continue;

                // Do not reinterpret a product already assigned to a city or
                // another actual destination under this prefecture.
                $has_child_destination = false;
                foreach ( $assigned as $assigned_id ) {
                    $assigned_id = (int)$assigned_id;
                    if ( $assigned_id === (int)$root->term_id ) continue;
                    $ancestors = get_ancestors($assigned_id, 'nf_municipality', 'taxonomy');
                    if ( in_array((int)$root->term_id, array_map('intval', $ancestors), true) ) {
                        $has_child_destination = true;
                        break;
                    }
                }
                if ( $has_child_destination ) continue;

                $set = wp_set_object_terms($post_id, array((int)$office->term_id), 'nf_municipality', true);
                if ( is_wp_error($set) ) continue;
                $removed = wp_remove_object_terms($post_id, array((int)$root->term_id), 'nf_municipality');
                if ( ! is_wp_error($removed) ) $summary['products']++;
            }
            $summary['groups']++;
        }

        update_option('nf_prefecture_office_migration_summary', $summary, false);
        update_option('nf_prefecture_office_migration_version', $migration_version, false);
    }

    public static function invalidate_prefecture_office_migration() {
        delete_option('nf_prefecture_office_migration_version');
    }

    public static function maybe_invalidate_prefecture_office_migration($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        unset($object_id, $terms, $tt_ids, $append, $old_tt_ids);
        if ( $taxonomy === 'nf_municipality' ) self::invalidate_prefecture_office_migration();
    }

    public static function register_content() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name' => '返礼品',
                'singular_name' => '返礼品',
                'menu_name' => '返礼品',
                'add_new' => '返礼品を追加',
                'add_new_item' => '返礼品を追加',
                'edit_item' => '返礼品を編集',
                'new_item' => '新しい返礼品',
                'view_item' => '返礼品を見る',
                'search_items' => '返礼品を検索',
            ),
            'public' => true,
            'show_in_rest' => true,
            'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
            'has_archive' => class_exists('NF_System_Page') ? NF_System_Page::base() : 'furusato',
            'rewrite' => array( 'slug' => class_exists('NF_System_Page') ? NF_System_Page::item_rewrite() : 'furusato/item', 'with_front' => false ),
            'menu_icon' => 'dashicons-carrot',
        ) );

        register_taxonomy( 'nf_municipality', self::POST_TYPE, array(
            'labels' => array(
                'name' => '自治体',
                'singular_name' => '自治体',
                'menu_name' => '自治体',
                'all_items' => '自治体一覧',
                'edit_item' => '自治体を編集',
                'add_new_item' => '自治体を追加',
                'parent_item' => '親の都道府県・地域',
                'parent_item_colon' => '親の都道府県・地域：',
                'search_items' => '自治体を検索',
            ),
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'capabilities' => array(
                'manage_terms' => 'nf_manage_categories',
                'edit_terms' => 'nf_manage_categories',
                'delete_terms' => 'nf_manage_categories',
                'assign_terms' => 'nf_manage_categories',
            ),
            'rewrite' => array( 'slug' => class_exists('NF_System_Page') ? NF_System_Page::taxonomy_rewrite('municipality') : 'furusato/municipality', 'with_front' => false ),
        ) );

        // 旧分類。既存データと ?fruit=... URL の互換性のため内部維持する。
        // 新規運用では nf_category を正式なカテゴリ分類として使用する。
        register_taxonomy( 'nf_fruit', self::POST_TYPE, array(
            'labels' => array(
                'name' => '旧分類（互換用）',
                'singular_name' => '旧分類（互換用）',
                'menu_name' => '旧分類（互換用）',
            ),
            'public' => true,
            'hierarchical' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'show_admin_column' => false,
            'rewrite' => array( 'slug' => class_exists('NF_System_Page') ? NF_System_Page::taxonomy_rewrite('fruit') : 'furusato/fruit', 'with_front' => false ),
        ) );

        if ( class_exists('NF_Category') ) {
            NF_Category::register_taxonomies();
        }
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'nf_furusato_details',
            '返礼品情報',
            array( __CLASS__, 'render_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'nf_save_furusato_meta', 'nf_furusato_nonce' );

        $fields = array(
            'display_name' => get_post_meta( $post->ID, '_nf_display_name', true ),
            'recommended' => get_post_meta( $post->ID, '_nf_recommended', true ),
            'recommend_priority' => get_post_meta( $post->ID, '_nf_recommend_priority', true ),
            'rakuten_url' => get_post_meta( $post->ID, '_nf_rakuten_url', true ),
            'affiliate_html' => get_post_meta( $post->ID, '_nf_rakuten_affiliate_html', true ),
            'price' => get_post_meta( $post->ID, '_nf_price', true ),
            'price_min' => get_post_meta( $post->ID, '_nf_price_min', true ),
            'price_max' => get_post_meta( $post->ID, '_nf_price_max', true ),
            'capacity' => get_post_meta( $post->ID, '_nf_capacity', true ),
            'shipping' => get_post_meta( $post->ID, '_nf_shipping', true ),
            'origin' => get_post_meta( $post->ID, '_nf_origin', true ),
            'status' => get_post_meta( $post->ID, '_nf_status', true ),
            'rakuten_item_code' => get_post_meta( $post->ID, '_nf_rakuten_item_code', true ),
            'rakuten_item_name' => get_post_meta( $post->ID, '_nf_rakuten_item_name', true ),
            'rakuten_image_url' => get_post_meta( $post->ID, '_nf_rakuten_image_url', true ),
            'rakuten_image_urls' => get_post_meta( $post->ID, '_nf_rakuten_image_urls', true ),
            'manual_image_urls' => get_post_meta( $post->ID, '_nf_manual_image_urls', true ),
            'rakuten_shop_name' => get_post_meta( $post->ID, '_nf_rakuten_shop_name', true ),
            'rakuten_affiliate_url' => get_post_meta( $post->ID, '_nf_rakuten_affiliate_url', true ),
            'rakuten_sale_start' => get_post_meta( $post->ID, '_nf_rakuten_sale_start', true ),
            'rakuten_sale_end' => get_post_meta( $post->ID, '_nf_rakuten_sale_end', true ),
            'rakuten_description' => get_post_meta( $post->ID, '_nf_rakuten_description', true ),
            'rakuten_review_average' => get_post_meta( $post->ID, '_nf_rakuten_review_average', true ),
            'rakuten_review_count' => get_post_meta( $post->ID, '_nf_rakuten_review_count', true ),
            'management_code' => get_post_meta( $post->ID, '_nf_management_code', true ),
            'feature_taste' => get_post_meta( $post->ID, '_nf_feature_taste', true ),
            'feature_serving' => get_post_meta( $post->ID, '_nf_feature_serving', true ),
            'feature_storage' => get_post_meta( $post->ID, '_nf_feature_storage', true ),
            'feature_delivery' => get_post_meta( $post->ID, '_nf_feature_delivery', true ),
        );

        if ( ! is_array($fields['rakuten_image_urls']) ) {
            $fields['rakuten_image_urls'] = array();
        }
        if ( ! $fields['rakuten_image_urls'] && $fields['rakuten_image_url'] ) {
            $fields['rakuten_image_urls'] = array($fields['rakuten_image_url']);
        }

        if ( ! is_array($fields['manual_image_urls']) ) {
            $fields['manual_image_urls'] = array();
        }

        $fields['manual_image_urls'] = array_values(array_filter(array_unique(
            array_map('esc_url_raw', $fields['manual_image_urls'])
        )));

        if ( ! $fields['price_min'] && $fields['price'] ) $fields['price_min'] = $fields['price'];
        if ( ! $fields['price_max'] && $fields['price'] ) $fields['price_max'] = $fields['price'];
        if ( ! $fields['status'] ) $fields['status'] = '受付中';
        ?>
        <style>
            .nf-admin-row{margin:0 0 18px}
            .nf-admin-row label{display:block;font-weight:600;margin-bottom:6px}
            .nf-admin-row input[type=text],.nf-admin-row input[type=url],.nf-admin-row input[type=number],.nf-admin-row textarea,.nf-admin-row select{width:100%}
            .nf-api-actions{display:flex;align-items:center;gap:10px;margin-top:8px}
            .nf-api-result{margin-top:8px;font-size:13px}
            .nf-rakuten-preview{display:grid;grid-template-columns:120px 1fr;gap:16px;align-items:start;background:#f7f7f7;padding:12px;border-radius:8px;margin:14px 0 18px}
            .nf-rakuten-preview img{max-width:120px;height:auto}
            .nf-muted{color:#666;font-size:12px}
            .nf-price-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
            .nf-manual-images-box{padding:14px;background:#fbfcfa;border:1px solid #dfe5dc;border-radius:8px}
            .nf-manual-image-actions{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}
            .nf-manual-image-preview{display:grid;grid-template-columns:repeat(auto-fill,minmax(82px,1fr));gap:8px;margin-top:10px}
            .nf-manual-image-item{position:relative;aspect-ratio:1/1;background:#f0f0f0;border:1px solid #ddd;border-radius:7px;overflow:hidden}
            .nf-manual-image-item img{display:block;width:100%;height:100%;object-fit:cover}
            .nf-manual-image-remove{position:absolute;top:3px;right:3px;width:25px;height:25px;padding:0;border:0;border-radius:50%;background:rgba(0,0,0,.7);color:#fff;cursor:pointer;font-size:17px;line-height:25px}
            @media(max-width:782px){.nf-price-grid{grid-template-columns:1fr}}
        </style>

        <div class="nf-admin-row">
            <label for="nf_display_name">サイト表示名</label>
            <input
                type="text"
                id="nf_display_name"
                name="nf_display_name"
                value="<?php echo esc_attr( $fields['display_name'] ); ?>"
                placeholder="例：八代市産 晩白柚 約1.5kg〜10kg"
            >
            <p class="nf-muted">
                公開ページの商品名を手動で上書きできます。空欄の場合は楽天／Yahoo!の商品名を優先し、重量・玉数・サイズ・品種などの商品情報を残したまま、明らかなSEO・モール装飾語だけを最小限に整理して表示します。
            </p>
        </div>

        <div class="nf-admin-row nf-recommend-admin-box" style="padding:14px;background:#f6f8f5;border:1px solid #dfe5dc;border-radius:8px">
            <label style="display:flex;align-items:center;gap:8px">
                <input
                    type="checkbox"
                    id="nf_recommended"
                    name="nf_recommended"
                    value="1"
                    <?php checked( $fields['recommended'], '1' ); ?>
                    style="width:auto"
                >
                おすすめに設定
            </label>

            <div style="margin-top:10px;max-width:220px">
                <label for="nf_recommend_priority">おすすめ優先度（1〜10）</label>
                <input
                    type="number"
                    min="1"
                    max="10"
                    id="nf_recommend_priority"
                    name="nf_recommend_priority"
                    value="<?php echo esc_attr( $fields['recommend_priority'] ?: 5 ); ?>"
                >
            </div>

            <p class="nf-muted">
                おすすめに設定した返礼品は、一般公開カタログの「おすすめ」に表示されます。
                数字が大きいほど前に表示します。
            </p>
        </div>

        <div class="nf-admin-row">
            <label for="nf_rakuten_url">楽天商品URL</label>
            <input type="url" id="nf_rakuten_url" name="nf_rakuten_url" value="<?php echo esc_attr( $fields['rakuten_url'] ); ?>" placeholder="https://item.rakuten.co.jp/shop-code/item-slug/">
            <p class="nf-muted">URL末尾の文字列は楽天APIのitemCodeとは限りません。v0.2.2では楽天アフィリエイトHTML内のitem_idを優先して正しいitemCodeを組み立てます。</p>
        </div>

        <div class="nf-admin-row">
            <label for="nf_rakuten_affiliate_html">楽天アフィリエイトHTML（任意・旧方式）</label>
            <textarea id="nf_rakuten_affiliate_html" name="nf_rakuten_affiliate_html" rows="12" placeholder="楽天アフィリエイトで作成したHTMLをそのまま貼り付けてください。"><?php echo esc_textarea( $fields['affiliate_html'] ); ?></textarea>
            <p class="nf-muted">任意です。新方式では楽天APIのaffiliateUrlから商品カードを自動生成します。新規商品でitemCodeを自動特定できない場合のみ、楽天アフィリエイトHTMLを貼ると item_id を利用して特定できます。</p>

            <div class="nf-api-actions">
                <button type="button" class="button button-secondary" id="nf-fetch-rakuten">楽天から商品情報を取得</button>
                <span class="spinner" id="nf-rakuten-spinner" style="float:none;margin:0"></span>
            </div>
            <div id="nf-api-result" class="nf-api-result"></div>
        </div>

        <div class="nf-rakuten-preview" id="nf-rakuten-preview" <?php echo $fields['rakuten_item_name'] ? '' : 'style="display:none"'; ?>>
            <div><img id="nf-rakuten-image-preview" src="<?php echo esc_url( $fields['rakuten_image_url'] ); ?>" alt=""></div>
            <div>
                <strong id="nf-rakuten-name-preview"><?php echo esc_html( $fields['rakuten_item_name'] ); ?></strong>
                <p><span id="nf-rakuten-shop-preview"><?php echo esc_html( $fields['rakuten_shop_name'] ); ?></span></p>
                <p class="nf-muted">itemCode: <code id="nf-rakuten-code-preview"><?php echo esc_html( $fields['rakuten_item_code'] ); ?></code></p>
                <p class="nf-muted" id="nf-rakuten-images-preview">
                    商品画像: <?php echo intval(count($fields['rakuten_image_urls'])); ?>枚
                </p>
            </div>
        </div>

        <input
            type="hidden"
            id="nf_rakuten_image_urls"
            name="nf_rakuten_image_urls"
            value="<?php echo esc_attr( wp_json_encode($fields['rakuten_image_urls'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ); ?>"
        >
        <input type="hidden" id="nf_rakuten_item_code" name="nf_rakuten_item_code" value="<?php echo esc_attr( $fields['rakuten_item_code'] ); ?>">
        <input type="hidden" id="nf_rakuten_item_name" name="nf_rakuten_item_name" value="<?php echo esc_attr( $fields['rakuten_item_name'] ); ?>">
        <input type="hidden" id="nf_rakuten_image_url" name="nf_rakuten_image_url" value="<?php echo esc_attr( $fields['rakuten_image_url'] ); ?>">
        <input type="hidden" id="nf_rakuten_shop_name" name="nf_rakuten_shop_name" value="<?php echo esc_attr( $fields['rakuten_shop_name'] ); ?>">
        <input type="hidden" id="nf_rakuten_affiliate_url" name="nf_rakuten_affiliate_url" value="<?php echo esc_attr( $fields['rakuten_affiliate_url'] ); ?>">
        <input type="hidden" id="nf_rakuten_sale_start" name="nf_rakuten_sale_start" value="<?php echo esc_attr( $fields['rakuten_sale_start'] ); ?>">
        <input type="hidden" id="nf_rakuten_sale_end" name="nf_rakuten_sale_end" value="<?php echo esc_attr( $fields['rakuten_sale_end'] ); ?>">
        <input type="hidden" id="nf_rakuten_description" name="nf_rakuten_description" value="<?php echo esc_attr( $fields['rakuten_description'] ); ?>">
        <input type="hidden" id="nf_rakuten_review_average" name="nf_rakuten_review_average" value="<?php echo esc_attr( $fields['rakuten_review_average'] ); ?>">
        <input type="hidden" id="nf_rakuten_review_count" name="nf_rakuten_review_count" value="<?php echo esc_attr( $fields['rakuten_review_count'] ); ?>">


        <div class="nf-admin-row nf-manual-images-box">
            <label for="nf_manual_image_urls">追加商品画像（自社画像）</label>

            <p class="nf-muted">
                楽天API画像とは別に、運営者が利用できる商品画像を追加できます。
                自動同期ではこの画像を削除・上書きしません。個別ページでは楽天画像の後ろに続けて表示します。
            </p>

            <div class="nf-manual-image-actions">
                <button type="button" class="button button-secondary" id="nf-add-manual-images">
                    メディアから画像を追加
                </button>
                <button type="button" class="button" id="nf-clear-manual-images">
                    追加画像をすべてクリア
                </button>
            </div>

            <textarea
                id="nf_manual_image_urls"
                name="nf_manual_image_urls"
                rows="5"
                placeholder="画像URLを1行に1つ入力できます。メディアから追加した場合は自動入力されます。"
            ><?php echo esc_textarea( implode("\n", $fields['manual_image_urls']) ); ?></textarea>

            <p class="nf-muted">
                楽天API画像: <?php echo intval(count($fields['rakuten_image_urls'])); ?>枚 /
                追加画像: <span id="nf-manual-image-count"><?php echo intval(count($fields['manual_image_urls'])); ?></span>枚
            </p>

            <div class="nf-manual-image-preview" id="nf-manual-image-preview"></div>
        </div>

        <div class="nf-admin-row">
            <label>寄附額</label>
            <div class="nf-price-grid">
                <div>
                    <label for="nf_price_min">最低寄附額</label>
                    <input type="number" min="0" step="1" id="nf_price_min" name="nf_price_min" value="<?php echo esc_attr( $fields['price_min'] ); ?>"> 円
                </div>
                <div>
                    <label for="nf_price_max">最高寄附額</label>
                    <input type="number" min="0" step="1" id="nf_price_max" name="nf_price_max" value="<?php echo esc_attr( $fields['price_max'] ); ?>"> 円
                </div>
            </div>
            <input type="hidden" id="nf_price" name="nf_price" value="<?php echo esc_attr( $fields['price'] ); ?>">
            <p class="nf-muted">APIの itemPriceMin3 / itemPriceMax3 を優先します。単一価格なら同じ金額になります。</p>
        </div>

        <div class="nf-admin-row" style="padding:16px;background:#f8fbf8;border:1px solid #dbe7dd;border-radius:10px">
            <label style="font-size:15px">商品説明（自動分類・必要なときだけ修正）</label>
            <p class="nf-muted">
                楽天・Yahoo!の商品説明から「おいしさ・味わい」「おすすめの食べ方」「保存方法」「配送について」を自動抽出します。
                下記に文章を入力した項目だけ、自動抽出より優先して公開します。
            </p>

            <div class="nf-price-grid">
                <div>
                    <label for="nf_feature_taste">おいしさ・味わい</label>
                    <textarea id="nf_feature_taste" name="nf_feature_taste" rows="4" placeholder="空欄なら商品説明から自動抽出"><?php echo esc_textarea($fields['feature_taste']); ?></textarea>
                </div>
                <div>
                    <label for="nf_feature_serving">おすすめの食べ方</label>
                    <textarea id="nf_feature_serving" name="nf_feature_serving" rows="4" placeholder="空欄なら商品説明から自動抽出"><?php echo esc_textarea($fields['feature_serving']); ?></textarea>
                </div>
                <div>
                    <label for="nf_feature_storage">保存方法</label>
                    <textarea id="nf_feature_storage" name="nf_feature_storage" rows="4" placeholder="空欄なら商品説明から自動抽出"><?php echo esc_textarea($fields['feature_storage']); ?></textarea>
                </div>
                <div>
                    <label for="nf_feature_delivery">配送について</label>
                    <textarea id="nf_feature_delivery" name="nf_feature_delivery" rows="4" placeholder="空欄なら商品説明から自動抽出"><?php echo esc_textarea($fields['feature_delivery']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="nf-admin-row">
            <label for="nf_management_code">自治体での管理番号（任意）</label>
            <input type="text" id="nf_management_code" name="nf_management_code" value="<?php echo esc_attr( $fields['management_code'] ); ?>" placeholder="自動抽出できない場合のみ入力">
            <p class="nf-muted">空欄なら楽天/Yahoo!の商品名・商品コードから自動抽出を試みます。</p>
        </div>

        <div class="nf-admin-row">
            <label for="nf_capacity">容量</label>
            <input type="text" id="nf_capacity" name="nf_capacity" value="<?php echo esc_attr( $fields['capacity'] ); ?>">
        </div>
        <div class="nf-admin-row">
            <label for="nf_shipping">発送時期</label>
            <input type="text" id="nf_shipping" name="nf_shipping" value="<?php echo esc_attr( $fields['shipping'] ); ?>">
        </div>
        <div class="nf-admin-row">
            <label for="nf_origin">産地</label>
            <input type="text" id="nf_origin" name="nf_origin" value="<?php echo esc_attr( $fields['origin'] ); ?>">
        </div>
        <div class="nf-admin-row">
            <label for="nf_status">受付状況</label>
            <select id="nf_status" name="nf_status">
                <?php foreach ( array('受付中','先行予約受付中','受付終了') as $status ) : ?>
                    <option value="<?php echo esc_attr($status); ?>" <?php selected($fields['status'], $status); ?>><?php echo esc_html($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    public static function save_meta( $post_id ) {
        if ( ! isset( $_POST['nf_furusato_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['nf_furusato_nonce']) ), 'nf_save_furusato_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $simple = array(
            '_nf_display_name' => array( 'nf_display_name', 'sanitize_text_field' ),
            '_nf_recommend_priority' => array( 'nf_recommend_priority', 'absint' ),
            '_nf_rakuten_url' => array( 'nf_rakuten_url', 'esc_url_raw' ),
            '_nf_price' => array( 'nf_price', 'absint' ),
            '_nf_price_min' => array( 'nf_price_min', 'absint' ),
            '_nf_price_max' => array( 'nf_price_max', 'absint' ),
            '_nf_capacity' => array( 'nf_capacity', 'sanitize_text_field' ),
            '_nf_shipping' => array( 'nf_shipping', 'sanitize_text_field' ),
            '_nf_origin' => array( 'nf_origin', 'sanitize_text_field' ),
            '_nf_status' => array( 'nf_status', 'sanitize_text_field' ),
            '_nf_rakuten_item_code' => array( 'nf_rakuten_item_code', 'sanitize_text_field' ),
            '_nf_rakuten_item_name' => array( 'nf_rakuten_item_name', 'sanitize_text_field' ),
            '_nf_rakuten_image_url' => array( 'nf_rakuten_image_url', 'esc_url_raw' ),
            '_nf_rakuten_shop_name' => array( 'nf_rakuten_shop_name', 'sanitize_text_field' ),
            '_nf_rakuten_affiliate_url' => array( 'nf_rakuten_affiliate_url', 'esc_url_raw' ),
            '_nf_rakuten_sale_start' => array( 'nf_rakuten_sale_start', 'sanitize_text_field' ),
            '_nf_rakuten_sale_end' => array( 'nf_rakuten_sale_end', 'sanitize_text_field' ),
            '_nf_rakuten_description' => array( 'nf_rakuten_description', 'sanitize_text_field' ),
            '_nf_rakuten_review_average' => array( 'nf_rakuten_review_average', 'floatval' ),
            '_nf_rakuten_review_count' => array( 'nf_rakuten_review_count', 'absint' ),
            '_nf_management_code' => array( 'nf_management_code', 'sanitize_text_field' ),
            '_nf_feature_taste' => array( 'nf_feature_taste', 'sanitize_textarea_field' ),
            '_nf_feature_serving' => array( 'nf_feature_serving', 'sanitize_textarea_field' ),
            '_nf_feature_storage' => array( 'nf_feature_storage', 'sanitize_textarea_field' ),
            '_nf_feature_delivery' => array( 'nf_feature_delivery', 'sanitize_textarea_field' ),
        );

        foreach ( $simple as $meta_key => $cfg ) {
            list( $field, $sanitize ) = $cfg;
            if ( isset( $_POST[$field] ) ) {
                $raw = wp_unslash( $_POST[$field] );
                $value = is_callable($sanitize) ? call_user_func($sanitize, $raw) : $raw;
                update_post_meta( $post_id, $meta_key, $value );
            }
        }

        if ( isset($_POST['nf_rakuten_image_urls']) ) {
            $json = wp_unslash($_POST['nf_rakuten_image_urls']);
            $decoded = json_decode($json, true);
            $image_urls = array();

            if ( is_array($decoded) ) {
                foreach ( $decoded as $url ) {
                    $url = esc_url_raw($url);
                    if ( $url && ! in_array($url, $image_urls, true) ) {
                        $image_urls[] = $url;
                    }

                    if ( count($image_urls) >= 12 ) {
                        break;
                    }
                }
            }

            if ( ! $image_urls && isset($_POST['nf_rakuten_image_url']) ) {
                $legacy_image = esc_url_raw(
                    wp_unslash($_POST['nf_rakuten_image_url'])
                );
                if ( $legacy_image ) {
                    $image_urls[] = $legacy_image;
                }
            }

            update_post_meta(
                $post_id,
                '_nf_rakuten_image_urls',
                $image_urls
            );
        }

        if ( isset($_POST['nf_manual_image_urls']) ) {
            $raw = wp_unslash($_POST['nf_manual_image_urls']);
            $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
            $manual_urls = array();

            foreach ( (array)$lines as $line ) {
                $url = esc_url_raw(trim((string)$line));

                if ( ! $url ) {
                    continue;
                }

                if ( ! in_array($url, $manual_urls, true) ) {
                    $manual_urls[] = $url;
                }
            }

            update_post_meta(
                $post_id,
                '_nf_manual_image_urls',
                $manual_urls
            );
        }

        // 後方互換: _nf_price は最低寄附額を保存。
        if ( isset($_POST['nf_price_min']) ) {
            update_post_meta( $post_id, '_nf_price', absint( wp_unslash($_POST['nf_price_min']) ) );
        }

        update_post_meta(
            $post_id,
            '_nf_recommended',
            ! empty($_POST['nf_recommended']) ? '1' : '0'
        );

        $priority = isset($_POST['nf_recommend_priority'])
            ? absint(wp_unslash($_POST['nf_recommend_priority']))
            : 5;
        $priority = max(1, min(10, $priority));
        update_post_meta($post_id, '_nf_recommend_priority', $priority);

        if ( isset( $_POST['nf_rakuten_affiliate_html'] ) && current_user_can( 'unfiltered_html' ) ) {
            update_post_meta( $post_id, '_nf_rakuten_affiliate_html', wp_unslash( $_POST['nf_rakuten_affiliate_html'] ) );
        }
    }

    public static function enqueue_public_assets() {
        wp_enqueue_style( 'nippon-fruit', NF_PLUGIN_URL . 'assets/style.css', array(), NF_VERSION );
    }

    private static function format_price_range( $post_id ) {
        $min = absint( get_post_meta($post_id, '_nf_price_min', true) );
        $max = absint( get_post_meta($post_id, '_nf_price_max', true) );
        $legacy = absint( get_post_meta($post_id, '_nf_price', true) );

        if ( ! $min && $legacy ) $min = $legacy;
        if ( ! $max && $legacy ) $max = $legacy;

        // 楽天APIが返す1円等は実寄附額ではないため表示しない。
        if ( $min > 0 && $min <= 100 ) $min = 0;
        if ( $max > 0 && $max <= 100 ) $max = 0;

        if ( ! $min && ! $max ) return '';
        if ( ! $max ) $max = $min;
        if ( ! $min ) $min = $max;

        if ( $min === $max ) return number_format_i18n($min) . '円';
        return number_format_i18n($min) . '円〜' . number_format_i18n($max) . '円';
    }

    public static function append_product_box( $content ) {
        if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) return $content;

        $id = get_the_ID();
        $origin = get_post_meta($id, '_nf_origin', true);
        $capacity = get_post_meta($id, '_nf_capacity', true);
        $shipping = get_post_meta($id, '_nf_shipping', true);
        $price_text = self::format_price_range($id);
        $status = get_post_meta($id, '_nf_status', true);
        $affiliate_html = get_post_meta($id, '_nf_rakuten_affiliate_html', true);
        $affiliate_url = get_post_meta($id, '_nf_rakuten_affiliate_url', true);
        $rakuten_url = get_post_meta($id, '_nf_rakuten_url', true);

        ob_start(); ?>
        <section class="nf-product-info">
            <h2>返礼品情報</h2>
            <div class="nf-product-table">
                <?php if ($origin): ?><div><strong>産地</strong><span><?php echo esc_html($origin); ?></span></div><?php endif; ?>
                <?php if ($capacity): ?><div><strong>容量</strong><span><?php echo esc_html($capacity); ?></span></div><?php endif; ?>
                <?php if ($shipping): ?><div><strong>発送時期</strong><span><?php echo esc_html($shipping); ?></span></div><?php endif; ?>
                <?php if ($price_text): ?><div><strong>寄附額</strong><span><?php echo esc_html($price_text); ?></span></div><?php endif; ?>
                <?php if ($status): ?><div><strong>受付状況</strong><span><em class="nf-status"><?php echo esc_html($status); ?></em></span></div><?php endif; ?>
            </div>

            <div class="nf-application">
                <h3>楽天ふるさと納税でお申し込み</h3>
                <p class="nf-ad-note">※当ページにはアフィリエイト広告が含まれています。</p>
                <?php
                $rakuten_item_name  = get_post_meta($id, '_nf_rakuten_item_name', true);
                $rakuten_image_url  = get_post_meta($id, '_nf_rakuten_image_url', true);
                $rakuten_shop_name  = get_post_meta($id, '_nf_rakuten_shop_name', true);
                $auto_link          = $affiliate_url ? $affiliate_url : '';

                // v0.2.4: APIのaffiliateUrlが取れていれば、自動アフィリエイトカードを最優先表示。
                if ( $auto_link && ( $rakuten_item_name || $rakuten_image_url ) ) {
                    echo '<div class="nf-auto-affiliate-card">';

                    if ( $rakuten_image_url ) {
                        echo '<a class="nf-auto-affiliate-image" href="' . esc_url($auto_link) . '" target="_blank" rel="nofollow sponsored noopener">';
                        echo '<img src="' . esc_url($rakuten_image_url) . '" alt="' . esc_attr($rakuten_item_name ?: get_the_title($id)) . '" loading="lazy">';
                        echo '</a>';
                    }

                    echo '<div class="nf-auto-affiliate-body">';

                    if ( $rakuten_item_name ) {
                        echo '<a class="nf-auto-affiliate-title" href="' . esc_url($auto_link) . '" target="_blank" rel="nofollow sponsored noopener">';
                        echo esc_html($rakuten_item_name);
                        echo '</a>';
                    }

                    if ( $rakuten_shop_name ) {
                        echo '<div class="nf-auto-affiliate-shop">' . esc_html($rakuten_shop_name) . '</div>';
                    }

                    if ( $price_text ) {
                        echo '<div class="nf-auto-affiliate-price">' . esc_html($price_text) . '</div>';
                    }

                    echo '<a class="nf-auto-affiliate-button" href="' . esc_url($auto_link) . '" target="_blank" rel="nofollow sponsored noopener">楽天ふるさと納税で見る</a>';
                    echo '</div>';
                    echo '</div>';

                // 旧方式: 手貼りの楽天アフィリエイトHTMLがある場合。
                } elseif ( $affiliate_html ) {
                    echo '<div class="nf-rakuten-affiliate-card">';
                    echo $affiliate_html;
                    echo '</div>';

                // 最終フォールバック: 通常の商品URL。
                } elseif ( $rakuten_url ) {
                    echo '<p><a class="nf-rakuten-button" href="' . esc_url($rakuten_url) . '" target="_blank" rel="nofollow sponsored noopener">楽天ふるさと納税で見る</a></p>';
                }
                ?>
            </div>
        </section>
        <?php
        return $content . ob_get_clean();
    }

    public static function shortcode_products( $atts ) {
        $atts = shortcode_atts( array(
            'municipality' => '',
            'fruit' => '',
            'limit' => 12,
        ), $atts, 'nippon_fruit_products' );

        $tax_query = array();
        if ( $atts['municipality'] ) {
            $tax_query[] = array(
                'taxonomy' => 'nf_municipality',
                'field' => 'name',
                'terms' => sanitize_text_field($atts['municipality']),
            );
        }
        if ( $atts['fruit'] ) {
            $tax_query[] = array(
                'taxonomy' => 'nf_fruit',
                'field' => 'name',
                'terms' => sanitize_text_field($atts['fruit']),
            );
        }
        if ( count($tax_query) > 1 ) $tax_query['relation'] = 'AND';

        $args = array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(100, intval($atts['limit']))),
        );
        if ( $tax_query ) $args['tax_query'] = $tax_query;

        $q = new WP_Query($args);

        ob_start();
        echo '<div class="nf-product-grid">';
        while ($q->have_posts()) {
            $q->the_post();
            $image = get_the_post_thumbnail_url(get_the_ID(), 'medium');
            if ( ! $image ) $image = get_post_meta(get_the_ID(), '_nf_rakuten_image_url', true);
            echo '<article class="nf-product-card">';
            if ($image) echo '<a href="'.esc_url(get_permalink()).'"><img src="'.esc_url($image).'" alt=""></a>';
            echo '<h3><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></h3>';
            $price_text = self::format_price_range(get_the_ID());
            if ($price_text) echo '<p class="nf-card-price">'.esc_html($price_text).'</p>';
            echo '</article>';
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }
}
