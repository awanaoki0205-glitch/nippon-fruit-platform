<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Catalog {

    /** 一覧の並び替え中に同じ商品情報を何度も計算しないためのリクエスト内キャッシュ。 */
    private static $catalog_sort_cache = array();
    private static $catalog_status_cache = array();

    /** 品種検索は説明文ではなく、商品名で確定した主品目を照合する。 */
    private static function keyword_variety_aliases() {
        return array(
            '不知火・デコポン' => array('不知火', 'しらぬい', 'シラヌイ', 'デコポン', 'でこぽん', 'デコみかん'),
            '晩白柚' => array('晩白柚', 'ばんぺいゆ', 'バンペイユ'),
            '温州みかん' => array('温州みかん', '温州ミカン'),
            'シャインマスカット' => array('シャインマスカット'),
            '巨峰' => array('巨峰'),
            'ピオーネ' => array('ピオーネ'),
            'デラウェア' => array('デラウェア'),
            'ナガノパープル' => array('ナガノパープル'),
            '太秋柿' => array('太秋柿', 'たいしゅう', 'タイシュウ'),
            'ポンカン' => array('ポンカン'),
            'せとか' => array('せとか'),
            '文旦' => array('文旦'),
            '秋月梨' => array('秋月梨', 'あきづき'),
            '豊水梨' => array('豊水梨'),
            '新高梨' => array('新高梨'),
        );
    }

    private static function normalized_keyword($value) {
        $value = wp_strip_all_tags(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = mb_strtolower($value, 'UTF-8');
        return preg_replace('/[\s　・･\-ー_\/／（）()【】\[\]]+/u', '', $value);
    }

    private static function requested_variety($keyword) {
        $needle = self::normalized_keyword($keyword);
        if ($needle === '') return '';
        foreach (self::keyword_variety_aliases() as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $candidate = self::normalized_keyword($alias);
                if ($candidate !== '' && ($needle === $candidate || mb_strpos($needle, $candidate, 0, 'UTF-8') !== false)) {
                    return $canonical;
                }
            }
        }
        return '';
    }

    private static function product_matches_keyword($post_id, $keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return true;

        $display_title = class_exists('NF_Product_Title')
            ? NF_Product_Title::display_title($post_id)
            : get_the_title($post_id);
        $wanted_variety = self::requested_variety($keyword);

        if ($wanted_variety !== '' && class_exists('NF_Product_Title')) {
            $primary = NF_Product_Title::primary_variety($display_title);
            // 固有品種の検索では、説明文にその語があるだけの商品を除外する。
            if ($primary !== '') return $primary === $wanted_variety;
        }

        $searchable = array($display_title, get_the_title($post_id));
        foreach (array('nf_category', 'nf_municipality') as $taxonomy) {
            if (! taxonomy_exists($taxonomy)) continue;
            $names = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
            if (! is_wp_error($names)) $searchable = array_merge($searchable, (array)$names);
        }

        $haystack = self::normalized_keyword(implode(' ', array_filter($searchable)));
        $needle = self::normalized_keyword($keyword);
        return $needle !== '' && mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
    }

    /**
     * 誤ったカテゴリタグが残っていても、固有品種カテゴリには別品種を出さない。
     * 例: 「不知火・デコポン」タグ付きの温州みかんを一覧から除外する。
     */
    private static function product_matches_specific_category($post_id, $category_slug) {
        $category_slug = trim((string)$category_slug);
        if ($category_slug === '' || ! taxonomy_exists('nf_category')) return true;

        $term = get_term_by('slug', $category_slug, 'nf_category');
        if (! $term || is_wp_error($term)) return true;

        $wanted = self::requested_variety($term->name);
        if ($wanted === '' || ! class_exists('NF_Product_Title')) return true;

        $title = NF_Product_Title::display_title($post_id);
        $primary = NF_Product_Title::primary_variety($title);
        return $primary === '' || $primary === $wanted;
    }

    const PER_PAGE = 30;

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'render_archive' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_nf_catalog_filter', array( __CLASS__, 'ajax_filter' ) );
        add_action( 'wp_ajax_nopriv_nf_catalog_filter', array( __CLASS__, 'ajax_filter' ) );
    }

    public static function enqueue_assets() {
        if ( is_post_type_archive( NF_Core::POST_TYPE ) || (class_exists('NF_System_Page') && NF_System_Page::is_system_page()) ) {
            wp_enqueue_style(
                'nippon-fruit-catalog',
                NF_PLUGIN_URL . 'assets/catalog.css',
                array(),
                NF_VERSION
            );
            wp_enqueue_script(
                'nippon-fruit-catalog',
                NF_PLUGIN_URL . 'assets/catalog.js',
                array('jquery'),
                NF_VERSION,
                true
            );
            wp_localize_script(
                'nippon-fruit-catalog',
                'NF_CATALOG',
                array(
                    'ajaxUrl'    => admin_url('admin-ajax.php'),
                    'nonce'      => wp_create_nonce('nf_catalog_filter'),
                    'fruitLabel' => class_exists('NF_Settings') ? NF_Settings::fruit_label() : 'カテゴリ',
                    'categoryTree' => class_exists('NF_Category') ? NF_Category::tree_for_public() : array(),
                    'municipalityTree' => self::municipality_tree_for_public(),
                    'categoryLabel' => 'カテゴリ',
                    'categoryNavMode' => class_exists('NF_Settings') ? NF_Settings::category_nav_mode() : 'auto',
                )
            );
        }
    }

    public static function municipality_tree_for_public() {
        if ( ! taxonomy_exists('nf_municipality') ) return array();

        $terms = get_terms(array(
            'taxonomy' => 'nf_municipality',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        if ( is_wp_error($terms) || ! $terms ) return array();

        $allowed_ids = self::contract_municipality_ids($terms);
        if ( $allowed_ids ) {
            $terms = array_values(array_filter($terms, function($term) use ($allowed_ids) {
                return in_array((int)$term->term_id, $allowed_ids, true);
            }));
        }

        $by_id = array();
        foreach ( $terms as $term ) {
            $by_id[(int)$term->term_id] = $term;
        }
        if ( ! $by_id ) return array();

        $children = array();
        foreach ( $by_id as $term_id => $term ) {
            $parent = isset($by_id[(int)$term->parent]) ? (int)$term->parent : 0;
            if ( ! isset($children[$parent]) ) $children[$parent] = array();
            $children[$parent][] = $term_id;
        }

        if ( class_exists('NF_Category') ) {
            $rank = NF_Category::order_rank(NF_Category::MUNICIPALITY_ORDER_OPTION);
            foreach ($children as &$term_ids) {
                usort($term_ids, function($a, $b) use ($rank, $by_id) {
                    $ar = isset($rank[$a]) ? $rank[$a] : PHP_INT_MAX;
                    $br = isset($rank[$b]) ? $rank[$b] : PHP_INT_MAX;
                    if ($ar === $br) return strnatcasecmp($by_id[$a]->name, $by_id[$b]->name);
                    return $ar <=> $br;
                });
            }
            unset($term_ids);
        }

        $build = function($parent_id) use (&$build, &$children, &$by_id) {
            $rows = array();
            foreach ( isset($children[$parent_id]) ? $children[$parent_id] : array() as $term_id ) {
                $term = $by_id[$term_id];
                $child_rows = $build($term_id);
                $count = max(0, (int)$term->count);
                foreach ( $child_rows as $child_row ) $count += (int)$child_row['count'];
                if ( $count <= 0 ) continue;
                $rows[] = array(
                    'slug' => $term->slug,
                    'name' => $term->name,
                    'count' => $count,
                    'children' => $child_rows,
                );
            }
            return $rows;
        };

        $tree = $build(0);
        $mode = class_exists('NF_Settings') ? NF_Settings::municipality_nav_mode() : 'grouped';
        if ( $mode !== 'flat' ) return $tree;

        // Single-prefecture operators can present donation destinations in one
        // simple list. Grouping nodes are omitted, while their leaf destinations
        // retain the same slugs and filtering behavior.
        $flatten = function($nodes) use (&$flatten) {
            $rows = array();
            foreach ( $nodes as $node ) {
                if ( ! empty($node['children']) ) {
                    $rows = array_merge($rows, $flatten($node['children']));
                    continue;
                }
                $node['children'] = array();
                $rows[] = $node;
            }
            return $rows;
        };
        return $flatten($tree);
    }

    public static function render_archive() {
        if ( ! is_post_type_archive( NF_Core::POST_TYPE ) && (!class_exists('NF_System_Page') || !NF_System_Page::is_system_page()) ) return;

        status_header(200);
        nocache_headers();
        get_header();

        $municipalities = get_terms(array(
            'taxonomy' => 'nf_municipality',
            'hide_empty' => false,
            'orderby' => 'count',
            'order' => 'DESC',
        ));
        if ( is_wp_error($municipalities) ) $municipalities = array();

        $fruits = get_terms(array(
            'taxonomy' => 'nf_fruit',
            'hide_empty' => true,
            'orderby' => 'count',
            'order' => 'DESC',
        ));

        $category_tree = class_exists('NF_Category') ? NF_Category::tree_for_public() : array();

        $yahoo_stores = self::yahoo_store_options();

        $seasonal_ids = self::seasonal_recommendation_ids(5);

        $initial = self::query_products(array(
            'paged' => 1,
            'order' => 'season',
            'exclude_ids' => $seasonal_ids,
        ));

        $recommended_ids = (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_advanced_ranking'))
            ? self::manual_recommendation_ids(5)
            : array();

        $show_price = ! class_exists('NF_Settings') ||
            NF_Settings::show_price();

        $site_portal_mode = class_exists('NF_Settings')
            ? NF_Settings::portal_mode()
            : 'both';

        $fruit_label = class_exists('NF_Settings')
            ? NF_Settings::fruit_label()
            : 'カテゴリ';

        $season_title = class_exists('NF_Settings')
            ? NF_Settings::season_title()
            : '今が旬・まもなく発送';

        $price_filter_options = $show_price
            ? self::price_filter_options()
            : array();
        ?>
        <main id="main" class="site-main nf-catalog-page">
          <div class="nf-catalog-container">


            <div class="nf-catalog-main-layout">
              <aside class="nf-catalog-category-sidebar" aria-label="お礼品カテゴリ">
                <button type="button" class="nf-mobile-filter-section-toggle" aria-expanded="false" aria-controls="nf_catalog_category_filter_card">
                  <span>お礼品カテゴリ</span><span aria-hidden="true">▼</span>
                </button>
                <fieldset id="nf_catalog_category_filter_card" class="nf-catalog-category-filter-card">
                  <legend>お礼品カテゴリ</legend>
                  <p class="nf-category-tree-help">矢印で分類を開き、絞り込みたいカテゴリを1つ選択してください。</p>
                  <div id="nf_catalog_category_tree" class="nf-catalog-category-tree" aria-label="お礼品カテゴリ"></div>
                  <p id="nf_catalog_category_tree_summary" class="nf-category-tree-summary" hidden></p>

                  <div class="nf-category-select-fallback" hidden aria-hidden="true">
                    <label><span>大カテゴリ</span>
                      <select id="nf_catalog_category">
                        <option value="">すべて</option>
                      </select>
                    </label>

                    <label><span>小カテゴリ</span>
                      <select id="nf_catalog_subcategory" disabled>
                        <option value="">大カテゴリを選択してください</option>
                      </select>
                    </label>

                    <label><span>詳細分類</span>
                      <select id="nf_catalog_type" disabled>
                        <option value="">小カテゴリを選択してください</option>
                      </select>
                    </label>
                  </div>
                </fieldset>
                <button type="button" class="nf-mobile-filter-section-toggle" aria-expanded="false" aria-controls="nf_catalog_municipality_filter_card">
                  <span>自治体から探す</span><span aria-hidden="true">▼</span>
                </button>
                <fieldset id="nf_catalog_municipality_filter_card" class="nf-catalog-category-filter-card nf-catalog-municipality-filter-card">
                  <legend>自治体から探す</legend>
                  <p class="nf-category-tree-help">矢印で地域を開き、絞り込みたい自治体を1つ選択してください。</p>
                  <div id="nf_catalog_municipality_tree" class="nf-catalog-category-tree nf-catalog-municipality-tree" aria-label="自治体から探す"></div>
                  <p id="nf_catalog_municipality_tree_summary" class="nf-category-tree-summary" hidden></p>
                </fieldset>
                <?php if ( class_exists('NF_Settings') && NF_Settings::sidebar_custom_enabled() && trim(NF_Settings::sidebar_custom_html()) !== '' ) : ?>
                  <section class="nf-category-sidebar-custom nf-category-sidebar-custom--desktop" aria-label="ご案内">
                    <?php echo NF_Settings::sidebar_custom_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saved through wp_kses_post. ?>
                  </section>
                  <?php if ( trim(NF_Settings::sidebar_custom_css()) !== '' ) : ?>
                    <style id="nf-category-sidebar-custom-css"><?php echo NF_Settings::sidebar_custom_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized CSS setting. ?></style>
                  <?php endif; ?>
                <?php endif; ?>
              </aside>

              <div class="nf-catalog-main-column">
            <section id="nf_catalog_search_panel" class="nf-catalog-search-panel">
              <div class="nf-catalog-compact-search">
                <div class="nf-catalog-search-box">
                  <input
                    type="search"
                    id="nf_catalog_keyword"
                    placeholder="デコポン、梨、八代市など"
                    autocomplete="off"
                    aria-label="返礼品を検索"
                  >
                  <button type="button" id="nf_catalog_search_button">検索</button>
                </div>

              </div>

              <div id="nf_catalog_filter_drawer" class="nf-catalog-filter-drawer nf-catalog-filter-drawer--always-visible">
                <button type="button" id="nf_catalog_filter_toggle" class="nf-filter-drawer-head nf-filter-drawer-toggle" aria-expanded="false" aria-controls="nf_catalog_filter_body">
                  <div>
                    <span>FILTER</span>
                    <strong>条件で絞り込む</strong>
                  </div>
                  <p>条件を選び、下のボタンを押すと返礼品一覧へ移動します。</p>
                  <span class="nf-filter-drawer-toggle-icon" aria-hidden="true">＋</span>
                </button>

                <div id="nf_catalog_filter_body" class="nf-catalog-filter-body" hidden>

                <div class="nf-catalog-filters">
                  <select id="nf_catalog_municipality" hidden aria-hidden="true" tabindex="-1">
                      <option value="">すべて</option>
                      <?php foreach ( (array)$municipalities as $term ) : ?>
                        <option value="<?php echo esc_attr($term->slug); ?>">
                          <?php echo esc_html($term->name); ?>（<?php echo intval($term->count); ?>）
                        </option>
                      <?php endforeach; ?>
                  </select>

                  <select id="nf_catalog_fruit" hidden aria-hidden="true" tabindex="-1">
                    <option value="">すべて</option>
                    <?php foreach ( (array)$fruits as $term ) : ?>
                      <option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
                    <?php endforeach; ?>
                  </select>

                  <?php if ( $show_price ) : ?>
                    <select id="nf_catalog_price_range" hidden aria-hidden="true" tabindex="-1">
                      <option value="">すべて</option>
                      <?php foreach ( $price_filter_options as $price_option ) : ?>
                        <option value="<?php echo esc_attr($price_option['value']); ?>"><?php echo esc_html($price_option['label']); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label><span>寄附額</span>
                      <span class="nf-custom-price-range">
                        <span class="nf-custom-price-row">
                          <input type="number" id="nf_catalog_price_min" min="0" step="1000" inputmode="numeric" placeholder="下限なし" aria-label="寄附額の下限">
                          <b aria-hidden="true">円〜</b>
                        </span>
                        <span class="nf-custom-price-row">
                          <input type="number" id="nf_catalog_price_max" min="0" step="1000" inputmode="numeric" placeholder="上限なし" aria-label="寄附額の上限">
                          <b aria-hidden="true">円</b>
                        </span>
                      </span>
                    </label>
                  <?php endif; ?>

                  <label><span>掲載ポータル</span>
                    <select id="nf_catalog_portal">
                      <option value="">すべて</option>
                      <?php if ( $site_portal_mode !== 'yahoo' ) : ?>
                        <option value="rakuten">楽天ふるさと納税</option>
                      <?php endif; ?>
                      <?php if ( $site_portal_mode !== 'rakuten' ) : ?>
                        <option value="yahoo">Yahoo!ショッピング</option>
                      <?php endif; ?>
                    </select>
                  </label>

                  <?php if ( $site_portal_mode !== 'rakuten' ) : ?>
                  <label><span>Yahoo!内ストア</span>
                    <select id="nf_catalog_yahoo_store">
                      <option value="">すべて</option>
                      <?php foreach ( $yahoo_stores as $store_key => $store_name ) : ?>
                        <option value="<?php echo esc_attr($store_key); ?>">
                          <?php echo esc_html($store_name); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="nf-filter-help">Yahoo!を選んだ場合に利用できます</small>
                  </label>
                  <?php endif; ?>

                  <label><span>受付状況</span>
                    <select id="nf_catalog_status">
                      <option value="">すべて</option>
                      <option value="受付中">受付中</option>
                      <option value="先行予約受付中">先行予約受付中</option>
                      <option value="受付終了">受付終了</option>
                    </select>
                  </label>

                </div>

                <div class="nf-catalog-filter-actions">
                  <button type="button" class="nf-catalog-reset" id="nf_catalog_reset">
                    条件をリセット
                  </button>

                  <button type="button" class="nf-catalog-apply" id="nf_catalog_apply_filters">
                    この条件で返礼品を見る
                    <span aria-hidden="true">→</span>
                  </button>
                </div>
                </div>
              </div>
            </section>

            <?php
            if ( class_exists('NF_Settings') && NF_Settings::should_render_promo_at('before_season') ) {
                NF_Settings::render_promo_block('before_season');
            }
            ?>

            <?php if ( $seasonal_ids ) : ?>
              <section id="nf_catalog_season_section" class="nf-feature-section nf-feature-season">
                <div class="nf-feature-head">
                  <div>
                    <p class="nf-catalog-small-label">IN SEASON</p>
                    <h2><?php echo esc_html($season_title); ?></h2>
                  </div>
                  <span class="nf-feature-caption">発送時期から自動で選定</span>
                </div>
                <?php echo self::render_feature_cards($seasonal_ids, 'season'); ?>
              </section>
            <?php endif; ?>

            <?php
            if ( class_exists('NF_Settings') && NF_Settings::should_render_promo_at('after_season') ) {
                NF_Settings::render_promo_block('after_season');
            }
            ?>

            <?php if ( $recommended_ids ) : ?>
              <section class="nf-feature-section nf-feature-recommended">
                <div class="nf-feature-head">
                  <div>
                    <p class="nf-catalog-small-label">PICKS</p>
                    <h2>おすすめ返礼品</h2>
                  </div>
                  <span class="nf-feature-caption">スタッフおすすめ</span>
                </div>
                <?php echo self::render_feature_cards($recommended_ids, 'recommended'); ?>
              </section>
            <?php endif; ?>

            <section id="nf_catalog_products_section" class="nf-catalog-products-section">
            <div
              id="nf_catalog_active_filters"
              class="nf-catalog-active-filters"
              hidden
            >
              <div class="nf-catalog-active-filters__top">
                <div>
                  <span>現在の検索条件</span>
                  <strong id="nf_catalog_active_filter_count"></strong>
                </div>
                <button
                  type="button"
                  id="nf_catalog_change_filters"
                  class="nf-catalog-change-filters"
                >
                  条件を変更
                  <span aria-hidden="true">＋</span>
                </button>
              </div>
              <div
                id="nf_catalog_active_filter_chips"
                class="nf-catalog-active-filter-chips"
              ></div>
            </div>

              <div class="nf-catalog-products-head">
                <div>
                  <p class="nf-catalog-small-label">PRODUCTS</p>
                  <h2 id="nf_catalog_list_title">返礼品一覧</h2>
                </div>
                <div class="nf-catalog-list-controls">
                  <p class="nf-catalog-count">
                    <span class="nf-catalog-count-main">
                      <span class="nf-catalog-total">
                        <strong id="nf_catalog_count"><?php echo intval($initial['display_found']); ?></strong>件
                      </span>
                      <span id="nf_catalog_range"><?php echo esc_html(self::range_text($initial['query'])); ?></span>
                    </span>
                  </p>
                  <div class="nf-catalog-sort-controls">
                    <label><span class="screen-reader-text">並び順</span>
                      <select id="nf_catalog_order" aria-label="並び順">
                        <option value="season">おすすめ順</option>
                        <option value="new">新着順</option>
                        <?php if ( ! class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_review_sort') ) : ?>
                          <option value="review_count">レビュー件数順</option>
                          <option value="review_score">レビュー評価順</option>
                        <?php endif; ?>
                        <?php if ( $show_price ) : ?>
                          <option value="price_desc">寄附金額が高い順</option>
                          <option value="price_asc">寄附金額が低い順</option>
                        <?php endif; ?>
                      </select>
                    </label>
                    <label><span class="screen-reader-text">1ページの表示件数</span>
                      <select id="nf_catalog_per_page" aria-label="1ページの表示件数">
                        <option value="30">30件</option><option value="60">60件</option>
                        <option value="90">90件</option><option value="120">120件</option>
                      </select>
                    </label>
                  </div>
                </div>
              </div>

              <div id="nf_catalog_loading" class="nf-catalog-loading" style="display:none">
                返礼品を読み込んでいます…
              </div>

              <div id="nf_catalog_results">
                <?php echo self::render_product_grid($initial['query']); ?>
              </div>

              <div id="nf_catalog_pagination">
                <?php echo self::render_pagination($initial['query']); ?>
              </div>
            </section>

              </div>
            </div>

            <?php if ( class_exists('NF_Settings') && NF_Settings::sidebar_custom_enabled() && trim(NF_Settings::sidebar_custom_mobile_html()) !== '' ) : ?>
              <section class="nf-category-sidebar-custom nf-category-sidebar-custom--mobile" aria-label="ご案内">
                <?php echo NF_Settings::sidebar_custom_mobile_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saved through wp_kses_post. ?>
              </section>
              <?php if ( trim(NF_Settings::sidebar_custom_mobile_css()) !== '' ) : ?>
                <style id="nf-category-sidebar-custom-mobile-css"><?php echo NF_Settings::sidebar_custom_mobile_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized CSS setting. ?></style>
              <?php endif; ?>
            <?php endif; ?>

            <section class="nf-catalog-notice">
              <p>
                ※当サイトにはアフィリエイトリンクが含まれる場合があります。
                寄附額、受付状況、発送時期等は変更される場合があります。
                最新情報は各ふるさと納税ポータルの掲載ページでご確認ください。
              </p>
            </section>

          </div>
        </main>
        <?php

        get_footer();
        exit;
    }

    public static function ajax_filter() {
        check_ajax_referer('nf_catalog_filter', 'nonce');

        $show_feature = isset($_POST['show_feature'])
            ? intval($_POST['show_feature']) === 1
            : false;

        $args = array(
            'keyword' => isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '',
            'municipality' => isset($_POST['municipality']) ? sanitize_title(wp_unslash($_POST['municipality'])) : '',
            'fruit' => isset($_POST['fruit']) ? sanitize_title(wp_unslash($_POST['fruit'])) : '',
            'category' => isset($_POST['category']) ? sanitize_title(wp_unslash($_POST['category'])) : '',
            'subcategory' => isset($_POST['subcategory']) ? sanitize_title(wp_unslash($_POST['subcategory'])) : '',
            'type' => isset($_POST['type']) ? sanitize_title(wp_unslash($_POST['type'])) : '',
            'status' => isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '',
            'price_range' => isset($_POST['price_range']) ? sanitize_text_field(wp_unslash($_POST['price_range'])) : '',
            'price_min' => isset($_POST['price_min']) ? absint($_POST['price_min']) : 0,
            'price_max' => isset($_POST['price_max']) ? absint($_POST['price_max']) : 0,
            'portal' => isset($_POST['portal']) ? sanitize_key(wp_unslash($_POST['portal'])) : '',
            'yahoo_store' => isset($_POST['yahoo_store']) ? sanitize_text_field(wp_unslash($_POST['yahoo_store'])) : '',
            'order' => isset($_POST['order']) ? sanitize_key(wp_unslash($_POST['order'])) : 'season',
            'per_page' => isset($_POST['per_page']) ? self::sanitize_per_page($_POST['per_page']) : self::PER_PAGE,
            'paged' => isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1,
        );

        $args = self::enforce_contract_args($args);

        $seasonal_ids = $show_feature
            ? self::seasonal_recommendation_ids(5)
            : array();

        if ( $seasonal_ids ) {
            $args['exclude_ids'] = $seasonal_ids;
        }

        $result = self::query_products($args);

        wp_send_json_success(array(
            'html' => self::render_product_grid($result['query']),
            'pagination' => self::render_pagination($result['query']),
            'found' => intval($result['display_found']),
            'listTitle' => self::result_title($args),
            'rangeText' => self::range_text($result['query']),
        ));
    }

    private static function portal_available( $post_id ) {
        $allowed_municipalities = self::contract_municipality_ids();
        if ( $allowed_municipalities ) {
            $assigned = wp_get_post_terms($post_id, 'nf_municipality', array('fields'=>'ids'));
            if ( is_wp_error($assigned) || ! array_intersect(array_map('intval', (array)$assigned), $allowed_municipalities) ) {
                return false;
            }
        }

        if ( ! class_exists('NF_Settings') ) {
            return true;
        }

        $mode = NF_Settings::portal_mode();

        if ( $mode === 'rakuten' ) {
            return
                trim((string)get_post_meta($post_id, '_nf_rakuten_item_code', true)) !== '' ||
                trim((string)get_post_meta($post_id, '_nf_rakuten_url', true)) !== '';
        }

        if ( $mode === 'yahoo' ) {
            if ( class_exists('NF_Yahoo') ) {
                return ! empty(NF_Yahoo::public_variants($post_id));
            }

            return trim((string)get_post_meta($post_id, '_nf_yahoo_code', true)) !== '';
        }

        return true;
    }

    private static function catalog_max_price() {
        $cached = get_transient('nf_catalog_max_price_094');

        if ( $cached !== false ) {
            return absint($cached);
        }

        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        $site_mode = class_exists('NF_Settings')
            ? NF_Settings::portal_mode()
            : 'both';

        $max_price = 0;

        foreach ( $ids as $post_id ) {
            $post_id = intval($post_id);

            if ( ! $post_id || ! self::portal_available($post_id) ) {
                continue;
            }

            if ( $site_mode !== 'yahoo' ) {
                $rakuten_max = absint(get_post_meta(
                    $post_id,
                    '_nf_price_max',
                    true
                ));

                $rakuten_min = absint(get_post_meta(
                    $post_id,
                    '_nf_price_min',
                    true
                ));

                $legacy = absint(get_post_meta(
                    $post_id,
                    '_nf_price',
                    true
                ));

                foreach ( array($rakuten_max, $rakuten_min, $legacy) as $price ) {
                    if ( $price > 100 ) {
                        $max_price = max($max_price, $price);
                    }
                }
            }

            if (
                $site_mode !== 'rakuten' &&
                class_exists('NF_Yahoo')
            ) {
                foreach ( NF_Yahoo::public_variants($post_id) as $variant ) {
                    $price = ! empty($variant['price'])
                        ? absint($variant['price'])
                        : 0;

                    if ( $price > 100 ) {
                        $max_price = max($max_price, $price);
                    }
                }
            }
        }

        set_transient(
            'nf_catalog_max_price_094',
            $max_price,
            HOUR_IN_SECONDS
        );

        return $max_price;
    }

    private static function price_filter_options() {
        $max_price = self::catalog_max_price();

        if ( $max_price <= 0 ) {
            return array();
        }

        /*
         * 最終レンジは必ず「〜円以上」形式にする。
         *
         * 例:
         * 最高額 90,000円 -> 最後は 50,001円〜
         * 最高額 45,000円 -> 最後は 30,001円〜
         * 最高額 28,000円 -> 最後は 20,001円〜
         */
        $bands = array(
            array(2000, 5000),
            array(5001, 10000),
            array(10001, 20000),
            array(20001, 30000),
            array(30001, 50000),
            array(50001, 100000),
            array(100001, 200000),
            array(200001, 500000),
            array(500001, 1000000),
            array(1000001, 0),
        );

        $final_index = count($bands) - 1;

        foreach ( $bands as $index => $band ) {
            $upper = intval($band[1]);

            if ( $upper <= 0 || $max_price <= $upper ) {
                $final_index = $index;
                break;
            }
        }

        $options = array();

        foreach ( $bands as $index => $band ) {
            if ( $index > $final_index ) {
                break;
            }

            $lower = intval($band[0]);
            $upper = intval($band[1]);

            if ( $index === $final_index ) {
                $options[] = array(
                    'value' => $lower . '-',
                    'label' => number_format_i18n($lower) . '円〜',
                );
                break;
            }

            $options[] = array(
                'value' => $lower . '-' . $upper,
                'label' =>
                    number_format_i18n($lower) .
                    '円〜' .
                    number_format_i18n($upper) .
                    '円',
            );
        }

        return $options;
    }

    private static function yahoo_store_options() {
        $cached = get_transient('nf_catalog_yahoo_store_options_092');

        if ( is_array($cached) ) {
            return $cached;
        }

        $stores = array();

        if ( class_exists('NF_Yahoo') ) {
            $ids = get_posts(array(
                'post_type' => NF_Core::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ));

            foreach ( $ids as $post_id ) {
                foreach ( NF_Yahoo::public_variants($post_id) as $variant ) {
                    $seller_id = ! empty($variant['sellerId'])
                        ? sanitize_key($variant['sellerId'])
                        : '';

                    $seller_name = class_exists('NF_Yahoo')
                        ? NF_Yahoo::public_store_name($variant)
                        : '';

                    if ( $seller_name === '' ) {
                        continue;
                    }

                    $key = $seller_id !== ''
                        ? 'id:' . $seller_id
                        : 'name:' . md5($seller_name);

                    if ( ! isset($stores[$key]) ) {
                        $stores[$key] = $seller_name;
                    }
                }
            }
        }

        asort($stores, SORT_NATURAL | SORT_FLAG_CASE);

        set_transient(
            'nf_catalog_yahoo_store_options_092',
            $stores,
            HOUR_IN_SECONDS
        );

        return $stores;
    }

    private static function yahoo_variant_matches_store(
        $variant,
        $store_key
    ) {
        $store_key = trim((string)$store_key);

        if ( $store_key === '' ) {
            return true;
        }

        if ( strpos($store_key, 'id:') === 0 ) {
            $wanted = sanitize_key(substr($store_key, 3));
            $actual = ! empty($variant['sellerId'])
                ? sanitize_key($variant['sellerId'])
                : '';

            return $wanted !== '' && $wanted === $actual;
        }

        if ( strpos($store_key, 'name:') === 0 ) {
            $wanted_hash = substr($store_key, 5);
            $name = class_exists('NF_Yahoo')
                ? NF_Yahoo::public_store_name($variant)
                : '';

            return $name !== '' && md5($name) === $wanted_hash;
        }

        return false;
    }

    private static function parse_price_range( $range ) {
        $range = trim((string)$range);

        if (
            $range === '' ||
            ! preg_match('/^(\d+)-(\d*)$/', $range, $m)
        ) {
            return array(0, 0);
        }

        return array(
            absint($m[1]),
            isset($m[2]) && $m[2] !== ''
                ? absint($m[2])
                : 0
        );
    }

    private static function rakuten_price_intersects(
        $post_id,
        $range_min,
        $range_max
    ) {
        $min = absint(get_post_meta(
            $post_id,
            '_nf_price_min',
            true
        ));
        $max = absint(get_post_meta(
            $post_id,
            '_nf_price_max',
            true
        ));
        $legacy = absint(get_post_meta(
            $post_id,
            '_nf_price',
            true
        ));

        if ( $min <= 100 ) $min = 0;
        if ( $max <= 100 ) $max = 0;
        if ( $legacy <= 100 ) $legacy = 0;

        if ( ! $min ) $min = $legacy ?: $max;
        if ( ! $max ) $max = $legacy ?: $min;

        if ( ! $min && ! $max ) {
            return false;
        }

        if ( ! $min ) $min = $max;
        if ( ! $max ) $max = $min;

        if ( $range_max > 0 ) {
            return $max >= $range_min && $min <= $range_max;
        }

        return $max >= $range_min;
    }

    private static function yahoo_matches(
        $post_id,
        $store_key = '',
        $range_min = 0,
        $range_max = 0
    ) {
        if ( ! class_exists('NF_Yahoo') ) {
            return false;
        }

        $variants = NF_Yahoo::public_variants($post_id);

        if ( ! $variants ) {
            return false;
        }

        foreach ( $variants as $variant ) {
            if (
                $store_key !== '' &&
                ! self::yahoo_variant_matches_store(
                    $variant,
                    $store_key
                )
            ) {
                continue;
            }

            if ( $range_min <= 0 && $range_max <= 0 ) {
                return true;
            }

            $price = ! empty($variant['price'])
                ? absint($variant['price'])
                : 0;

            if ( $price <= 100 ) {
                continue;
            }

            if (
                $range_max > 0 &&
                $price >= $range_min &&
                $price <= $range_max
            ) {
                return true;
            }

            if (
                $range_max <= 0 &&
                $price >= $range_min
            ) {
                return true;
            }
        }

        return false;
    }

    private static function matches_extended_filters(
        $post_id,
        $portal = '',
        $yahoo_store = '',
        $price_range = '',
        $price_min = 0,
        $price_max = 0
    ) {
        $portal = sanitize_key($portal);

        if ( ! in_array($portal, array('', 'rakuten', 'yahoo'), true) ) {
            $portal = '';
        }

        $site_mode = class_exists('NF_Settings')
            ? NF_Settings::portal_mode()
            : 'both';

        if (
            ($site_mode === 'rakuten' && $portal === 'yahoo') ||
            ($site_mode === 'yahoo' && $portal === 'rakuten')
        ) {
            return false;
        }

        $yahoo_store = sanitize_text_field($yahoo_store);

        // ストア指定がある場合はYahoo!検索として扱う。
        if ( $yahoo_store !== '' ) {
            $portal = 'yahoo';
        }

        $price_min = absint($price_min);
        $price_max = absint($price_max);

        if ( $price_min > 0 || $price_max > 0 ) {
            $range_min = $price_min;
            $range_max = $price_max;
            if ( $range_min > 0 && $range_max > 0 && $range_min > $range_max ) {
                $swap = $range_min;
                $range_min = $range_max;
                $range_max = $swap;
            }
        } else {
            list($range_min, $range_max) =
                self::parse_price_range($price_range);
        }

        $has_rakuten =
            trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_item_code',
                true
            )) !== '' ||
            trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_url',
                true
            )) !== '';

        if ( $portal === 'rakuten' ) {
            if ( ! $has_rakuten ) {
                return false;
            }

            return ($range_min > 0 || $range_max > 0)
                ? self::rakuten_price_intersects(
                    $post_id,
                    $range_min,
                    $range_max
                )
                : true;
        }

        if ( $portal === 'yahoo' ) {
            return self::yahoo_matches(
                $post_id,
                $yahoo_store,
                $range_min,
                $range_max
            );
        }

        // 「すべて」の場合は、楽天またはYahoo!のどちらかに価格帯一致すれば採用。
        if ( $range_min > 0 || $range_max > 0 ) {
            if (
                $has_rakuten &&
                self::rakuten_price_intersects(
                    $post_id,
                    $range_min,
                    $range_max
                )
            ) {
                return true;
            }

            return self::yahoo_matches(
                $post_id,
                '',
                $range_min,
                $range_max
            );
        }

        return true;
    }

    private static function query_products( $args ) {
        $args = wp_parse_args($args, array(
            'keyword' => '',
            'municipality' => '',
            'fruit' => '',
            'category' => '',
            'subcategory' => '',
            'type' => '',
            'status' => '',
            'price_range' => '',
            'price_min' => 0,
            'price_max' => 0,
            'portal' => '',
            'yahoo_store' => '',
            'order' => 'season',
            'paged' => 1,
            'per_page' => self::PER_PAGE,
            'exclude_ids' => array(),
        ));
        $args = self::enforce_contract_args($args);

        $tax_query = array();

        if ( $args['municipality'] ) {
            $tax_query[] = array(
                'taxonomy' => 'nf_municipality',
                'field' => 'slug',
                'terms' => $args['municipality'],
            );
        }

        if ( $args['fruit'] ) {
            $tax_query[] = array(
                'taxonomy' => 'nf_fruit',
                'field' => 'slug',
                'terms' => $args['fruit'],
            );
        }

        $category_slug = $args['type'] ?: ($args['subcategory'] ?: $args['category']);
        if ( $category_slug && taxonomy_exists('nf_category') ) {
            $tax_query[] = array(
                'taxonomy' => 'nf_category',
                'field' => 'slug',
                'terms' => $category_slug,
                'include_children' => true,
            );
        }

        if ( count($tax_query) > 1 ) {
            $tax_query['relation'] = 'AND';
        }

        $meta_query = array();

        if ( $args['status'] ) {
            // 「受付終了」は楽天販売終了日時も含めたeffective statusで判定するため、
            // 後段のPHPフィルタで処理する。
            if ( $args['status'] !== '受付終了' ) {
                $meta_query[] = array(
                    'key' => '_nf_status',
                    'value' => $args['status'],
                    'compare' => '=',
                );
            }
        }

        /*
         * 商品数は現在100〜数百件規模なので、まず候補IDを取得し、
         * PHP側で「受付終了は必ず最後尾」を保証してからページングする。
         * これにより旬順だけでなく、新着/価格/商品名順でも受付終了が途中に混ざらない。
         */
        $base = array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        );

        if ( $tax_query ) $base['tax_query'] = $tax_query;
        if ( $meta_query ) $base['meta_query'] = $meta_query;
        $ids = get_posts($base);

        // 価格・レビュー順では全候補商品のメタ情報を参照するため、
        // 商品ごとの個別取得になる前にWordPressのキャッシュへ一括投入する。
        if ( $ids ) {
            update_meta_cache('post', array_map('intval', $ids));
        }

        // タクソノミーは過去の誤分類や手動タグが残ることがあるため、
        // 固有品種カテゴリでは商品名の主品種でも二重確認する。
        if ($category_slug) {
            $ids = array_values(array_filter($ids, function($id) use ($category_slug) {
                return self::product_matches_specific_category($id, $category_slug);
            }));
        }

        // WordPress標準検索は本文・説明文も対象にするため、別商品のSEO語句まで
        // ヒットしていた。商品名・確定カテゴリ・自治体だけで再判定する。
        if ( $args['keyword'] ) {
            $keyword = $args['keyword'];
            $ids = array_values(array_filter($ids, function($id) use ($keyword) {
                return self::product_matches_keyword($id, $keyword);
            }));
        }

        // v0.9.0: 管理画面の「楽天だけ / Yahoo!だけ」に合わせて
        // カタログの商品自体も絞り込む。
        $ids = array_values(array_filter($ids, function($id){
            return self::portal_available($id);
        }));

        // v0.9.2: 検索フォームのポータル / Yahoo!ストア / 寄附額帯を適用。
        if (
            ! empty($args['portal']) ||
            ! empty($args['yahoo_store']) ||
            ! empty($args['price_range']) ||
            ! empty($args['price_min']) ||
            ! empty($args['price_max'])
        ) {
            $ids = array_values(array_filter(
                $ids,
                function($id) use ($args) {
                    return self::matches_extended_filters(
                        $id,
                        $args['portal'],
                        $args['yahoo_store'],
                        $args['price_range'],
                        $args['price_min'],
                        $args['price_max']
                    );
                }
            ));
        }

        // effective statusで受付終了を正確に絞り込む。
        if ( $args['status'] === '受付終了' ) {
            $ids = array_values(array_filter($ids, function($id){
                return self::effective_status($id) === '受付終了';
            }));
        } elseif ( $args['status'] ) {
            $wanted = $args['status'];
            $ids = array_values(array_filter($ids, function($id) use ($wanted){
                return self::effective_status($id) === $wanted;
            }));
        }

        // 表示件数は「旬枠を含む全件数」を維持する。
        $display_found = count($ids);

        $exclude_ids = array_values(array_filter(array_map(
            'intval',
            (array)$args['exclude_ids']
        )));

        if ( $exclude_ids ) {
            $ids = array_values(array_diff($ids, $exclude_ids));
        }

        $order = $args['order'];

        // usort() の比較処理から初回計算を追い出し、各商品の値を1回だけ作る。
        // 特にYahoo!の価格・レビュー情報の復元を比較回数分繰り返さない。
        foreach ( $ids as $id ) {
            self::catalog_closed_value($id);
            self::catalog_sort_value($id, $order);
        }

        usort($ids, function($a, $b) use ($order){
            return self::compare_catalog_ids($a, $b, $order);
        });

        $found = count($ids);
        $paged = max(1, intval($args['paged']));
        $per_page = self::sanitize_per_page($args['per_page']);
        $offset = ($paged - 1) * $per_page;
        $page_ids = array_slice($ids, $offset, $per_page);

        if ( ! $page_ids ) {
            $page_ids = array(0);
        }

        $query = new WP_Query(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'post__in' => $page_ids,
            'orderby' => 'post__in',
            'paged' => 1,
        ));

        $query->found_posts = $found;
        $query->max_num_pages = max(1, (int)ceil($found / $per_page));
        $query->set('paged', $paged);

        return array(
            'query' => $query,
            'found' => $found,
            'display_found' => $display_found,
        );
    }

    /**
     * 絞り込み内容に応じて一覧見出しを生成。
     */
    private static function result_title( $args ) {
        $municipality_name = '';
        $fruit_name = '';
        $category_name = '';
        $keyword = isset($args['keyword'])
            ? trim((string)$args['keyword'])
            : '';

        if ( ! empty($args['municipality']) ) {
            $term = get_term_by(
                'slug',
                $args['municipality'],
                'nf_municipality'
            );

            if ( $term && ! is_wp_error($term) ) {
                $municipality_name = $term->name;
            }
        }

        if ( ! empty($args['fruit']) ) {
            $term = get_term_by(
                'slug',
                $args['fruit'],
                'nf_fruit'
            );

            if ( $term && ! is_wp_error($term) ) {
                $fruit_name = $term->name;
            }
        }

        $category_slug = ! empty($args['type']) ? $args['type'] : (! empty($args['subcategory']) ? $args['subcategory'] : (! empty($args['category']) ? $args['category'] : ''));
        if ( $category_slug && taxonomy_exists('nf_category') ) {
            $term = get_term_by('slug', $category_slug, 'nf_category');
            if ( $term && ! is_wp_error($term) ) $category_name = $term->name;
        }

        if ( $category_name ) {
            if ( $municipality_name ) return $municipality_name . 'の' . $category_name . '一覧';
            return $category_name . '一覧';
        }

        if ( $keyword !== '' ) {
            if ( $municipality_name && $fruit_name ) {
                return $municipality_name . 'の' . $fruit_name .
                    '「' . $keyword . '」検索結果';
            }

            if ( $municipality_name ) {
                return $municipality_name . 'の「' .
                    $keyword . '」検索結果';
            }

            if ( $fruit_name ) {
                return $fruit_name . 'の「' .
                    $keyword . '」検索結果';
            }

            return '「' . $keyword . '」の検索結果';
        }

        if ( $municipality_name && $fruit_name ) {
            return $municipality_name . 'の' . $fruit_name . '一覧';
        }

        if ( $municipality_name ) {
            return $municipality_name . '一覧';
        }

        if ( $fruit_name ) {
            return $fruit_name . '一覧';
        }

        return '返礼品一覧';
    }

    /**
     * どの並び順でも「受付終了」は必ず最後尾。
     * 受付中グループ内、および受付終了グループ内で指定された並び順を適用する。
     */
    private static function compare_catalog_ids( $a, $b, $order ) {
        $a_closed = self::catalog_closed_value($a);
        $b_closed = self::catalog_closed_value($b);

        if ( $a_closed !== $b_closed ) {
            return $a_closed <=> $b_closed;
        }

        switch ( $order ) {
            case 'review_count':
            case 'review_score':
                $ra = self::catalog_sort_value($a, $order);
                $rb = self::catalog_sort_value($b, $order);
                $primary = $order === 'review_count' ? 'count' : 'average';
                $secondary = $order === 'review_count' ? 'average' : 'count';
                if ( $ra[$primary] != $rb[$primary] ) return $rb[$primary] <=> $ra[$primary];
                if ( $ra[$secondary] != $rb[$secondary] ) return $rb[$secondary] <=> $ra[$secondary];
                return intval($b) <=> intval($a);

            case 'price_asc':
                $pa = self::catalog_sort_value($a, $order);
                $pb = self::catalog_sort_value($b, $order);

                if ( $pa === $pb ) {
                    return intval($b) <=> intval($a);
                }

                return $pa <=> $pb;

            case 'price_desc':
                $pa = self::catalog_sort_value($a, $order);
                $pb = self::catalog_sort_value($b, $order);

                if ( $pa === $pb ) {
                    return intval($b) <=> intval($a);
                }

                return $pb <=> $pa;

            case 'title':
                $ta = self::catalog_sort_value($a, $order);
                $tb = self::catalog_sort_value($b, $order);

                $cmp = function_exists('mb_strcoll')
                    ? mb_strcoll($ta, $tb)
                    : strnatcasecmp($ta, $tb);

                if ( $cmp === 0 ) {
                    return intval($b) <=> intval($a);
                }

                return $cmp;

            case 'new':
                $da = self::catalog_sort_value($a, $order);
                $db = self::catalog_sort_value($b, $order);

                if ( $da === $db ) {
                    return intval($b) <=> intval($a);
                }

                return $db <=> $da;

            case 'season':
            default:
                $ka = self::catalog_sort_value($a, 'season');
                $kb = self::catalog_sort_value($b, 'season');

                for ( $i = 0; $i < count($ka); $i++ ) {
                    if ( $ka[$i] == $kb[$i] ) continue;
                    return ($ka[$i] < $kb[$i]) ? -1 : 1;
                }

                return intval($b) <=> intval($a);
        }
    }

    private static function catalog_closed_value( $post_id ) {
        $post_id = intval($post_id);

        if ( ! array_key_exists($post_id, self::$catalog_status_cache) ) {
            self::$catalog_status_cache[$post_id] =
                self::effective_status($post_id) === '受付終了' ? 1 : 0;
        }

        return self::$catalog_status_cache[$post_id];
    }

    private static function catalog_sort_value( $post_id, $order ) {
        $post_id = intval($post_id);
        $order = in_array($order, array(
            'review_count',
            'review_score',
            'price_asc',
            'price_desc',
            'title',
            'new',
            'season',
        ), true) ? $order : 'season';
        $cache_key = $order . ':' . $post_id;

        if ( array_key_exists($cache_key, self::$catalog_sort_cache) ) {
            return self::$catalog_sort_cache[$cache_key];
        }

        switch ( $order ) {
            case 'review_count':
            case 'review_score':
                $value = self::review_data($post_id);
                break;
            case 'price_asc':
                $value = self::sort_price($post_id, 'asc');
                break;
            case 'price_desc':
                $value = self::sort_price($post_id, 'desc');
                break;
            case 'title':
                $value = self::display_title($post_id);
                break;
            case 'new':
                $value = intval(get_post_time('U', false, $post_id));
                break;
            case 'season':
            default:
                $value = self::season_sort_key($post_id);
                break;
        }

        self::$catalog_sort_cache[$cache_key] = $value;
        return $value;
    }

    private static function sanitize_per_page( $value ) {
        $value = absint($value);
        return in_array($value, array(30, 60, 90, 120), true) ? $value : self::PER_PAGE;
    }

    private static function enforce_contract_args( $args ) {
        if ( ! class_exists('NF_Commercial_Config') ) return $args;

        $allowed_orders = array('season', 'new');
        if ( NF_Commercial_Config::feature('feature_price') ) {
            $allowed_orders[] = 'price_asc';
            $allowed_orders[] = 'price_desc';
        }
        if ( NF_Commercial_Config::feature('feature_review_sort') ) {
            $allowed_orders[] = 'review_count';
            $allowed_orders[] = 'review_score';
        }
        if ( ! in_array($args['order'], $allowed_orders, true) ) $args['order'] = 'season';

        if ( ! NF_Commercial_Config::feature('feature_price') ) {
            $args['price_range'] = '';
            $args['price_min'] = 0;
            $args['price_max'] = 0;
        }
        if ( ! NF_Commercial_Config::feature('feature_yahoo') ) {
            $args['yahoo_store'] = '';
            if ( $args['portal'] === 'yahoo' ) $args['portal'] = 'rakuten';
        }
        if ( ! NF_Commercial_Config::feature('feature_rakuten') && $args['portal'] === 'rakuten' ) {
            $args['portal'] = NF_Commercial_Config::feature('feature_yahoo') ? 'yahoo' : '';
        }

        return $args;
    }

    /**
     * Starterは並び順の先頭自治体だけを公開。親タームがある場合は
     * 選択自治体の祖先も含め、ツリー表示を壊さない。
     */
    private static function contract_municipality_ids( $terms = null ) {
        if ( ! class_exists('NF_Commercial_Config') ) return array();
        $limit = absint(NF_Commercial_Config::get('municipality_limit', 0));
        if ( ! $limit || ! taxonomy_exists('nf_municipality') ) return array();

        if ( $terms === null ) {
            $terms = get_terms(array('taxonomy'=>'nf_municipality','hide_empty'=>false));
        }
        if ( is_wp_error($terms) || ! $terms ) return array();

        $rank = class_exists('NF_Category')
            ? NF_Category::order_rank(NF_Category::MUNICIPALITY_ORDER_OPTION)
            : array();
        usort($terms, function($a, $b) use ($rank) {
            $ar = isset($rank[$a->term_id]) ? $rank[$a->term_id] : PHP_INT_MAX;
            $br = isset($rank[$b->term_id]) ? $rank[$b->term_id] : PHP_INT_MAX;
            if ($ar === $br) return strnatcasecmp($a->name, $b->name);
            return $ar <=> $br;
        });

        $parent_ids = array_values(array_unique(array_filter(array_map(function($term){
            return (int)$term->parent;
        }, $terms))));
        $selected = array();
        foreach ($terms as $term) {
            if ( in_array((int)$term->term_id, $parent_ids, true) ) continue;
            $selected[] = (int)$term->term_id;
            if ( count($selected) >= $limit ) break;
        }

        $all = $selected;
        foreach ($selected as $term_id) {
            foreach ((array)get_ancestors($term_id, 'nf_municipality', 'taxonomy') as $ancestor) {
                $all[] = (int)$ancestor;
            }
        }
        return array_values(array_unique($all));
    }

    private static function range_text( $query ) {
        $found = intval($query->found_posts);
        if ( $found < 1 ) return '（0件）';
        $page = max(1, intval($query->get('paged')));
        $per_page = max(1, intval($query->get('posts_per_page')));
        $start = (($page - 1) * $per_page) + 1;
        $end = min($found, $start + max(0, intval($query->post_count) - 1));
        return sprintf('（%s〜%s件目）', number_format_i18n($start), number_format_i18n($end));
    }

    private static function sort_price( $post_id, $direction = 'asc' ) {
        $portal_mode = class_exists('NF_Settings')
            ? NF_Settings::portal_mode()
            : 'both';

        $min = absint(get_post_meta($post_id, '_nf_price_min', true));
        $max = absint(get_post_meta($post_id, '_nf_price_max', true));
        $legacy = absint(get_post_meta($post_id, '_nf_price', true));

        if ( $min <= 100 ) $min = 0;
        if ( $max <= 100 ) $max = 0;
        if ( $legacy <= 100 ) $legacy = 0;

        $yahoo_prices = array();

        if ( class_exists('NF_Yahoo') ) {
            foreach ( NF_Yahoo::public_variants($post_id) as $variant ) {
                if ( ! empty($variant['price']) && intval($variant['price']) > 100 ) {
                    $yahoo_prices[] = intval($variant['price']);
                }
            }
        }

        $yahoo_min = $yahoo_prices ? min($yahoo_prices) : 0;
        $yahoo_max = $yahoo_prices ? max($yahoo_prices) : 0;

        if ( ! $yahoo_min ) {
            $yahoo_min = absint(
                get_post_meta(
                    $post_id,
                    '_nf_yahoo_price',
                    true
                )
            );
            if ( $yahoo_min <= 100 ) $yahoo_min = 0;
            $yahoo_max = $yahoo_min;
        }

        if ( $portal_mode === 'yahoo' ) {
            $value = $direction === 'desc'
                ? ($yahoo_max ?: $yahoo_min)
                : ($yahoo_min ?: $yahoo_max);

            return $value
                ? $value
                : ($direction === 'desc' ? 0 : PHP_INT_MAX);
        }

        if ( $direction === 'desc' ) {
            $value = $max ?: $min ?: $legacy;

            if ( ! $value && $portal_mode === 'both' ) {
                $value = $yahoo_max ?: $yahoo_min;
            }

            return $value ?: 0;
        }

        $value = $min ?: $max ?: $legacy;

        if ( ! $value && $portal_mode === 'both' ) {
            $value = $yahoo_min ?: $yahoo_max;
        }

        return $value ?: PHP_INT_MAX;
    }

    public static function compare_season_ids( $a, $b ) {
        return self::compare_catalog_ids($a, $b, 'season');
    }

    private static function season_sort_key( $post_id ) {
        $status = self::effective_status($post_id);

        if ( $status === '受付終了' ) {
            return array(9, PHP_INT_MAX, 0);
        }

        $info = self::shipping_info($post_id);

        if ( ! $info['known'] ) {
            return array(5, PHP_INT_MAX, -intval(get_post_time('U', false, $post_id)));
        }

        $now = current_time('timestamp');

        if ( $now >= $info['start'] && $now <= $info['end'] ) {
            // 現在発送中。終了が近いものを少し前へ。
            return array(0, $info['end'], $info['start']);
        }

        if ( $info['start'] > $now ) {
            // これから発送。開始が近い順。
            return array(1, $info['start'], $info['end']);
        }

        // 明確に終了済み。
        return array(8, PHP_INT_MAX - $info['end'], 0);
    }

    private static function seasonal_recommendation_ids( $limit = 5 ) {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_nf_status',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key' => '_nf_status',
                    'value' => '受付終了',
                    'compare' => '!=',
                ),
            ),
        ));

        $ids = array_values(array_filter($ids, function($id){
            if ( ! self::portal_available($id) ) {
                return false;
            }

            if ( self::effective_status($id) === '受付終了' ) {
                return false;
            }

            $info = self::shipping_info($id);

            if ( ! $info['known'] ) {
                return false;
            }

            $now = current_time('timestamp');

            // 現在発送中、または180日以内に発送開始する商品。
            if ( $now >= $info['start'] && $now <= $info['end'] ) {
                return true;
            }

            if (
                $info['start'] > $now &&
                ($info['start'] - $now) <= 180 * DAY_IN_SECONDS
            ) {
                return true;
            }

            return false;
        }));

        usort($ids, array(__CLASS__, 'compare_season_ids'));

        $limit = max(1, intval($limit));

        /*
         * 単純な上位5件では梨だけ・同自治体だけに偏りやすいため、
         * 季節順位を維持しつつ、同一品目は原則最大2件、
         * 可能なら自治体も分散する。
         */
        $selected = array();
        $fruit_counts = array();
        $municipality_counts = array();

        $passes = array(
            // まず「未登場品目 + 未登場自治体」。
            array('new_fruit' => true,  'new_municipality' => true,  'fruit_max' => 1),
            // 次に「同品目2件まで + 未登場自治体」。
            array('new_fruit' => false, 'new_municipality' => true,  'fruit_max' => 2),
            // 自治体重複を許可。ただし同品目2件まで。
            array('new_fruit' => false, 'new_municipality' => false, 'fruit_max' => 2),
            // 候補不足時のみ制限を緩める。
            array('new_fruit' => false, 'new_municipality' => false, 'fruit_max' => 999),
        );

        foreach ( $passes as $pass ) {
            foreach ( $ids as $id ) {
                if ( count($selected) >= $limit ) {
                    break 2;
                }

                if ( in_array($id, $selected, true) ) {
                    continue;
                }

                $fruit = self::primary_fruit_name($id);
                $municipality = self::primary_municipality_name($id);

                $fruit_key = $fruit ?: '__unknown_fruit';
                $municipality_key = $municipality ?: '__unknown_municipality';

                $fruit_count = isset($fruit_counts[$fruit_key])
                    ? intval($fruit_counts[$fruit_key])
                    : 0;

                $municipality_count = isset(
                    $municipality_counts[$municipality_key]
                )
                    ? intval($municipality_counts[$municipality_key])
                    : 0;

                if (
                    $pass['new_fruit'] &&
                    $fruit_count > 0
                ) {
                    continue;
                }

                if (
                    $pass['new_municipality'] &&
                    $municipality_count > 0
                ) {
                    continue;
                }

                if ( $fruit_count >= intval($pass['fruit_max']) ) {
                    continue;
                }

                $selected[] = intval($id);
                $fruit_counts[$fruit_key] = $fruit_count + 1;
                $municipality_counts[$municipality_key] =
                    $municipality_count + 1;
            }
        }

        return array_slice($selected, 0, $limit);
    }

    private static function primary_fruit_name( $post_id ) {
        $terms = class_exists('NF_Category')
            ? NF_Category::public_terms_for_post($post_id)
            : wp_get_post_terms($post_id, 'nf_category');

        if ( is_wp_error($terms) || ! $terms ) {
            return '';
        }

        $preferred = array(
            '晩白柚','不知火・デコポン','シャインマスカット',
            '巨峰','ピオーネ','梨','スイカ','いちご','メロン',
            '柿','栗','みかん','パール柑・文旦','河内晩柑',
            'スイートスプリング','さつまいも','アスパラガス',
            '人参'
        );

        foreach ( $preferred as $name ) {
            foreach ( $terms as $term ) {
                if ( $term->name === $name ) {
                    return $name;
                }
            }
        }

        // 柑橘・ぶどうなど親分類しかない場合のフォールバック。
        return $terms[0]->name;
    }

    private static function primary_municipality_name( $post_id ) {
        $terms = wp_get_post_terms($post_id, 'nf_municipality');

        if ( is_wp_error($terms) || ! $terms ) {
            return '';
        }

        return $terms[0]->name;
    }

    private static function manual_recommendation_ids( $limit = 5 ) {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 30,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_nf_recommended',
                    'value' => '1',
                    'compare' => '=',
                ),
            ),
            'meta_key' => '_nf_recommend_priority',
            'orderby' => array(
                'meta_value_num' => 'DESC',
                'date' => 'DESC',
            ),
            'no_found_rows' => true,
        ));

        // 受付終了と、現在のポータル表示設定に合わない商品を除外。
        $ids = array_values(array_filter($ids, function($id){
            return
                self::portal_available($id) &&
                self::effective_status($id) !== '受付終了';
        }));

        return array_slice($ids, 0, max(1, intval($limit)));
    }

    private static function shipping_info( $post_id ) {
        static $cache = array();

        if ( isset($cache[$post_id]) ) {
            return $cache[$post_id];
        }

        $source = trim((string)get_post_meta($post_id, '_nf_shipping', true));

        if ( $source === '' ) {
            $source = trim((string)get_post_meta($post_id, '_nf_rakuten_item_name', true));
        }

        if ( $source === '' ) {
            $source = get_the_title($post_id);
        }

        $result = self::parse_shipping_text($source);
        $cache[$post_id] = $result;

        return $result;
    }

    private static function parse_shipping_text( $source ) {
        $unknown = array(
            'known' => false,
            'start' => 0,
            'end' => 0,
            'label' => '',
            'state' => 'unknown',
        );

        $source = html_entity_decode(wp_strip_all_tags((string)$source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = str_replace(array('～','−','–','—'), '〜', $source);

        $pattern = '/(?:(\d{4})年)?\s*(\d{1,2})月(?:\s*(上旬|中旬|下旬|月末|末)|\s*(\d{1,2})日)?/u';

        if ( ! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) || empty($matches) ) {
            return $unknown;
        }

        $first = $matches[0];
        $second = isset($matches[1]) ? $matches[1] : null;

        $now_dt = current_datetime();
        $current_year = intval($now_dt->format('Y'));

        $start_year_explicit = ! empty($first[1]);
        $start_year = $start_year_explicit ? intval($first[1]) : $current_year;
        $start_month = intval($first[2]);

        $start_period = isset($first[3]) ? $first[3] : '';
        $start_day_explicit = isset($first[4]) && $first[4] !== '' ? intval($first[4]) : 0;

        $start_day = self::period_start_day($start_year, $start_month, $start_period, $start_day_explicit);

        $end_year_explicit = false;

        if ( $second ) {
            $end_year_explicit = ! empty($second[1]);
            $end_year = $end_year_explicit ? intval($second[1]) : $start_year;
            $end_month = intval($second[2]);

            if ( ! $end_year_explicit && $end_month < $start_month ) {
                $end_year++;
            }

            $end_period = isset($second[3]) ? $second[3] : '';
            $end_day_explicit = isset($second[4]) && $second[4] !== '' ? intval($second[4]) : 0;
            $end_day = self::period_end_day($end_year, $end_month, $end_period, $end_day_explicit);

        } else {
            // 「12月上旬より順次発送」など終了日が無い場合は、約90日間を暫定発送期間とする。
            $end_year = $start_year;
            $end_month = $start_month;
            $end_period = $start_period;
            $end_day = self::period_end_day($end_year, $end_month, $end_period, $start_day_explicit);
        }

        $tz = wp_timezone();

        try {
            $start_dt = new DateTimeImmutable(
                sprintf('%04d-%02d-%02d 00:00:00', $start_year, $start_month, $start_day),
                $tz
            );

            if ( $second ) {
                $end_dt = new DateTimeImmutable(
                    sprintf('%04d-%02d-%02d 23:59:59', $end_year, $end_month, $end_day),
                    $tz
                );
            } else {
                $end_dt = $start_dt->modify('+90 days')->setTime(23,59,59);
            }

        } catch ( Exception $e ) {
            return $unknown;
        }

        // 年の記載がない季節商品は、今年分が終わっていれば次シーズンへ繰り越す。
        if ( ! $start_year_explicit && ! $end_year_explicit ) {
            $now_ts = current_time('timestamp');

            while ( $end_dt->getTimestamp() < $now_ts ) {
                $start_dt = $start_dt->modify('+1 year');
                $end_dt = $end_dt->modify('+1 year');
            }
        }

        $now = current_time('timestamp');
        $start_ts = $start_dt->getTimestamp();
        $end_ts = $end_dt->getTimestamp();

        if ( $now >= $start_ts && $now <= $end_ts ) {
            $state = 'shipping';
            $label = '発送中';
        } elseif ( $start_ts > $now ) {
            $days = (int)floor(($start_ts - $now) / DAY_IN_SECONDS);

            if ( $days <= 14 ) {
                $state = 'soon';
                $label = 'まもなく発送';
            } else {
                $state = 'upcoming';
                $label = self::format_period_label(
                    intval($start_dt->format('Y')),
                    intval($start_dt->format('n')),
                    $start_period,
                    intval($start_dt->format('j'))
                ) . '〜発送';
            }
        } else {
            $state = 'ended';
            $label = '発送期間終了';
        }

        return array(
            'known' => true,
            'start' => $start_ts,
            'end' => $end_ts,
            'label' => $label,
            'state' => $state,
        );
    }

    private static function period_start_day( $year, $month, $period, $explicit_day = 0 ) {
        if ( $explicit_day > 0 ) return $explicit_day;

        switch ( $period ) {
            case '中旬': return 11;
            case '下旬':
            case '月末':
            case '末': return 21;
            case '上旬':
            default: return 1;
        }
    }

    private static function period_end_day( $year, $month, $period, $explicit_day = 0 ) {
        if ( $explicit_day > 0 ) return $explicit_day;

        switch ( $period ) {
            case '上旬': return 10;
            case '中旬': return 20;
            case '下旬':
            case '月末':
            case '末':
            default:
                return intval(date('t', strtotime(sprintf('%04d-%02d-01', $year, $month))));
        }
    }

    private static function format_period_label( $year, $month, $period, $day ) {
        $current_year = intval(current_datetime()->format('Y'));
        $prefix = $year !== $current_year ? $year . '年' : '';

        if ( $period ) {
            $period = $period === '月末' ? '末' : $period;
            return $prefix . $month . '月' . $period;
        }

        return $prefix . $month . '月' . $day . '日';
    }

    private static function render_feature_cards( $ids, $type ) {
        ob_start();

        echo '<div class="nf-feature-grid">';

        foreach ( (array)$ids as $post_id ) {
            $title = self::display_title($post_id);
            $image = self::best_product_image(
                $post_id,
                'medium'
            );

            $municipality_terms = wp_get_post_terms($post_id, 'nf_municipality');
            $municipality = '';

            if ( ! is_wp_error($municipality_terms) && $municipality_terms ) {
                $municipality = $municipality_terms[0]->name;
            }

            $season = self::shipping_info($post_id);
            $badge = $type === 'recommended'
                ? 'おすすめ'
                : ($season['known'] ? $season['label'] : '旬の返礼品');

            ?>
            <article class="nf-feature-card">
              <a class="nf-feature-image" href="<?php echo esc_url(get_permalink($post_id)); ?>">
                <?php if ( $image ) : ?>
                  <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else : ?>
                  <div class="nf-catalog-noimage">Nippon Fruit</div>
                <?php endif; ?>

                <?php
                $feature_badge_class = 'is-pick';

                if ( $type !== 'recommended' ) {
                    if ( $season['state'] === 'shipping' ) {
                        $feature_badge_class = 'is-shipping';
                    } elseif ( $season['state'] === 'soon' || $season['state'] === 'upcoming' ) {
                        $feature_badge_class = 'is-soon';
                    } else {
                        $feature_badge_class = 'is-season';
                    }
                }
                ?>
                <span class="nf-feature-badge <?php echo esc_attr($feature_badge_class); ?>">
                  <?php echo esc_html($badge); ?>
                </span>
              </a>

              <div class="nf-feature-body">
                <?php if ( $municipality ) : ?>
                  <div class="nf-feature-municipality"><?php echo esc_html($municipality); ?></div>
                <?php endif; ?>

                <h3>
                  <a href="<?php echo esc_url(get_permalink($post_id)); ?>">
                    <?php echo esc_html($title); ?>
                  </a>
                </h3>

                <strong><?php echo esc_html(self::price_text($post_id)); ?></strong>
              </div>
            </article>
            <?php
        }

        echo '</div>';

        return ob_get_clean();
    }

    private static function render_product_grid( $query ) {
        ob_start();

        if ( ! $query->have_posts() ) {
            echo '<div class="nf-catalog-empty"><p>条件に一致する返礼品は見つかりませんでした。</p></div>';
            wp_reset_postdata();
            return ob_get_clean();
        }

        echo '<div class="nf-catalog-grid">';

        while ( $query->have_posts() ) {
            $query->the_post();

            $post_id = get_the_ID();
            $title = self::display_title($post_id);

            $image = self::best_product_image(
                $post_id,
                'medium_large'
            );

            $status = self::effective_status($post_id);
            $recommended = get_post_meta($post_id, '_nf_recommended', true) === '1';
            $season = self::shipping_info($post_id);
            $has_rakuten =
                trim((string)get_post_meta($post_id, '_nf_rakuten_item_code', true)) !== '' ||
                trim((string)get_post_meta($post_id, '_nf_rakuten_url', true)) !== '';

            $yahoo_variants = class_exists('NF_Yahoo')
                ? NF_Yahoo::public_variants($post_id)
                : array();

            $has_yahoo = ! empty($yahoo_variants) ||
                trim((string)get_post_meta($post_id, '_nf_yahoo_code', true)) !== '';

            $yahoo_in_stock = false;

            foreach ( $yahoo_variants as $variant ) {
                if ( ! empty($variant['inStock']) ) {
                    $yahoo_in_stock = true;
                    break;
                }
            }

            if ( ! $yahoo_variants ) {
                $yahoo_in_stock =
                    get_post_meta($post_id, '_nf_yahoo_in_stock', true) !== '0';
            }

            $yahoo_only = get_post_meta($post_id, '_nf_yahoo_only', true) === '1';

            $review = self::review_data(
                $post_id,
                $yahoo_variants
            );

            $municipality_terms = wp_get_post_terms($post_id, 'nf_municipality');
            $fruit_terms = class_exists('NF_Category')
                ? NF_Category::public_terms_for_post($post_id)
                : wp_get_post_terms($post_id, 'nf_category');
            ?>
            <article class="nf-catalog-card" data-nf-product-id="<?php echo intval(get_the_ID()); ?>">
              <a class="nf-catalog-card-image" href="<?php the_permalink(); ?>">
                <?php if ( $image ) : ?>
                  <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else : ?>
                  <div class="nf-catalog-noimage">Nippon Fruit</div>
                <?php endif; ?>

                <div class="nf-card-badges">
                  <?php if ( $recommended ) : ?>
                    <span class="nf-card-special-badge is-pick">おすすめ</span>
                  <?php endif; ?>

                  <?php if ( $season['known'] && in_array($season['state'], array('shipping','soon'), true) ) : ?>
                    <span class="nf-card-special-badge <?php echo $season['state'] === 'shipping' ? 'is-shipping' : 'is-soon'; ?>">
                      <?php echo esc_html($season['label']); ?>
                    </span>
                  <?php endif; ?>
                </div>

                <?php if ( $status ) : ?>
                  <span class="nf-catalog-status <?php echo esc_attr(self::status_class($status)); ?>">
                    <?php echo esc_html($status); ?>
                  </span>
                <?php endif; ?>
              </a>

              <div class="nf-catalog-card-body">
                <?php if ( ! is_wp_error($municipality_terms) && $municipality_terms ) : ?>
                  <div class="nf-catalog-municipality"><?php echo esc_html($municipality_terms[0]->name); ?></div>
                <?php endif; ?>

                <h3><a href="<?php the_permalink(); ?>"><?php echo esc_html($title); ?></a></h3>

                <?php if (
                    (! class_exists('NF_Settings') || NF_Settings::show_reviews()) &&
                    $review['average'] > 0
                ) : ?>
                  <div class="nf-catalog-review">
                    <span class="nf-catalog-review-stars"><?php echo esc_html(self::rating_stars($review['average'])); ?></span>
                    <small>
                      <?php echo esc_html(number_format($review['average'], 1)); ?>
                      <?php if ( $review['count'] > 0 ) : ?>
                        （<?php echo intval($review['count']); ?>件）
                      <?php endif; ?>
                    </small>
                  </div>
                <?php endif; ?>

                <?php if ( ! is_wp_error($fruit_terms) && $fruit_terms ) : ?>
                  <div class="nf-catalog-fruits">
                    <?php foreach ( array_slice($fruit_terms, 0, 3) as $term ) : ?>
                      <span><?php echo esc_html($term->name); ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <div class="nf-catalog-portal-badges">
                  <?php if ( $has_rakuten ) : ?>
                    <span class="is-rakuten">楽天</span>
                  <?php endif; ?>

                  <?php if ( $has_yahoo ) : ?>
                    <span class="is-yahoo<?php echo $yahoo_in_stock ? '' : ' is-out'; ?>">
                      Yahoo!
                    </span>
                  <?php endif; ?>

                  <?php if ( $yahoo_only ) : ?>
                    <span class="is-yahoo-only">Yahoo!掲載</span>
                  <?php endif; ?>
                </div>

                <?php if ( $season['known'] && $season['state'] === 'upcoming' ) : ?>
                  <div class="nf-catalog-shipping-hint">
                    <?php echo esc_html($season['label']); ?>
                  </div>
                <?php endif; ?>

                <div class="nf-catalog-price"><?php echo esc_html(self::price_text($post_id)); ?></div>
                <a class="nf-catalog-detail-button" href="<?php the_permalink(); ?>">詳しく見る</a>
              </div>
            </article>
            <?php
        }

        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    private static function rating_stars( $average ) {
        $average = max(0, min(5, floatval($average)));
        $rounded = intval(round($average));

        return str_repeat('★', $rounded) .
            str_repeat('☆', max(0, 5 - $rounded));
    }

    private static function review_data(
        $post_id,
        $yahoo_variants = array()
    ) {
        if ( ! $yahoo_variants && class_exists('NF_Yahoo') ) {
            $yahoo_variants = NF_Yahoo::public_variants($post_id);
        }

        $average = floatval(
            get_post_meta(
                $post_id,
                '_nf_rakuten_review_average',
                true
            )
        );

        $count = absint(
            get_post_meta(
                $post_id,
                '_nf_rakuten_review_count',
                true
            )
        );

        if ( $average <= 0 ) {
            foreach ( (array)$yahoo_variants as $variant ) {
                if ( ! empty($variant['reviewRate']) ) {
                    $average = floatval(
                        $variant['reviewRate']
                    );
                    $count = ! empty($variant['reviewCount'])
                        ? absint($variant['reviewCount'])
                        : 0;
                    break;
                }
            }
        }

        return array(
            'average' => $average,
            'count' => $count,
        );
    }

    private static function display_title( $post_id ) {
        if ( class_exists('NF_Product_Title') ) {
            return NF_Product_Title::display_title($post_id);
        }

        $custom = trim((string)get_post_meta($post_id, '_nf_display_name', true));
        if ( $custom !== '' ) return $custom;

        $title = trim((string)get_post_meta($post_id, '_nf_rakuten_item_name', true));
        if ( $title === '' ) {
            $title = trim((string)get_post_meta($post_id, '_nf_yahoo_item_name', true));
        }
        if ( $title === '' ) {
            $title = get_the_title($post_id);
        }

        return sanitize_text_field($title);
    }

    private static function extract_capacity( $title ) {
        $title = (string)$title;

        if ( preg_match('/約?\s*(\d+(?:\.\d+)?)\s*(kg|g)\s*[〜～\-]\s*約?\s*(\d+(?:\.\d+)?)\s*(kg|g)/iu', $title, $m) ) {
            return '約' . $m[1] . strtolower($m[2]) . '〜' . $m[3] . strtolower($m[4]);
        }

        if ( preg_match_all('/約?\s*(\d+(?:\.\d+)?)\s*(kg|g)/iu', $title, $matches, PREG_SET_ORDER) ) {
            if ( count($matches) >= 2 ) {
                $first = $matches[0];
                $last = $matches[count($matches)-1];

                if ( strtolower($first[2]) === strtolower($last[2]) ) {
                    return '約' . $first[1] . strtolower($first[2]) . '〜' . $last[1] . strtolower($last[2]);
                }
            }

            $first = $matches[0];
            return '約' . $first[1] . strtolower($first[2]);
        }

        return '';
    }

    private static function best_product_image(
        $post_id,
        $thumbnail_size = 'medium_large'
    ) {
        $featured = get_the_post_thumbnail_url(
            $post_id,
            $thumbnail_size
        );

        if ( $featured ) {
            return self::high_res_image_url($featured);
        }

        $rakuten_all = get_post_meta(
            $post_id,
            '_nf_rakuten_image_urls',
            true
        );

        if ( is_string($rakuten_all) && $rakuten_all !== '' ) {
            $decoded = json_decode($rakuten_all, true);

            if ( is_array($decoded) ) {
                $rakuten_all = $decoded;
            }
        }

        if ( is_array($rakuten_all) ) {
            foreach ( $rakuten_all as $url ) {
                $url = self::high_res_image_url($url);
                if ( $url ) return $url;
            }
        }

        $rakuten = self::high_res_image_url(
            get_post_meta(
                $post_id,
                '_nf_rakuten_image_url',
                true
            )
        );

        if ( $rakuten ) {
            return $rakuten;
        }

        if ( class_exists('NF_Yahoo') ) {
            $yahoo = self::high_res_image_url(
                NF_Yahoo::public_image_url($post_id)
            );

            if ( $yahoo ) {
                return $yahoo;
            }
        }

        $yahoo_all = get_post_meta(
            $post_id,
            '_nf_yahoo_image_urls',
            true
        );

        if ( is_array($yahoo_all) ) {
            foreach ( $yahoo_all as $url ) {
                $url = self::high_res_image_url($url);
                if ( $url ) return $url;
            }
        }

        return self::high_res_image_url(
            get_post_meta(
                $post_id,
                '_nf_yahoo_image_url',
                true
            )
        );
    }

    private static function high_res_image_url( $url ) {
        $url = trim((string)$url);
        if ( $url === '' ) return '';

        $url = preg_replace('/([?&])_ex=\d+x\d+(&?)/i', '$1', $url);
        $url = preg_replace('/[?&]$/', '', $url);
        $url = str_replace('?&', '?', $url);

        return esc_url_raw($url);
    }

    private static function price_text( $post_id ) {
        $portal_mode = class_exists('NF_Settings')
            ? NF_Settings::portal_mode()
            : 'both';

        if ( $portal_mode === 'yahoo' ) {
            if ( class_exists('NF_Yahoo') ) {
                $variants = NF_Yahoo::public_variants($post_id);

                if ( $variants ) {
                    return NF_Yahoo::public_price_text($post_id);
                }
            }

            $yahoo_price = absint(
                get_post_meta(
                    $post_id,
                    '_nf_yahoo_price',
                    true
                )
            );

            return $yahoo_price > 100
                ? number_format_i18n($yahoo_price) . '円'
                : '寄附額はYahoo!で確認';
        }

        $min = absint(get_post_meta($post_id, '_nf_price_min', true));
        $max = absint(get_post_meta($post_id, '_nf_price_max', true));
        $legacy = absint(get_post_meta($post_id, '_nf_price', true));

        if ( ! $min && $legacy > 100 ) $min = $legacy;
        if ( ! $max && $legacy > 100 ) $max = $legacy;

        if ( $min > 0 && $min <= 100 ) $min = 0;
        if ( $max > 0 && $max <= 100 ) $max = 0;

        if ( ! $min && ! $max ) {
            if (
                $portal_mode !== 'rakuten' &&
                class_exists('NF_Yahoo')
            ) {
                $yahoo_variants = NF_Yahoo::public_variants($post_id);

                if ( $yahoo_variants ) {
                    return NF_Yahoo::public_price_text($post_id);
                }
            }

            if ( $portal_mode !== 'rakuten' ) {
                $yahoo_price = absint(
                    get_post_meta(
                        $post_id,
                        '_nf_yahoo_price',
                        true
                    )
                );

                if ( $yahoo_price > 100 ) {
                    return number_format_i18n($yahoo_price) . '円';
                }
            }

            return $portal_mode === 'rakuten'
                ? '寄附額は楽天で確認'
                : '寄附額は各ポータルで確認';
        }

        if ( ! $min ) $min = $max;
        if ( ! $max ) $max = $min;

        if ( $min === $max ) return number_format_i18n($min) . '円';
        return number_format_i18n($min) . '円〜' . number_format_i18n($max) . '円';
    }

    private static function effective_status( $post_id ) {
        $raw = (string)get_post_meta($post_id, '_nf_status', true);

        if ( $raw === '受付終了' ) {
            return '受付終了';
        }

        if (
            get_post_meta($post_id, '_nf_yahoo_only', true) === '1' &&
            get_post_meta($post_id, '_nf_yahoo_in_stock', true) === '0'
        ) {
            return '受付終了';
        }

        $sale_end = trim((string)get_post_meta(
            $post_id,
            '_nf_rakuten_sale_end',
            true
        ));

        if ( $sale_end !== '' && self::datetime_is_past($sale_end) ) {
            return '受付終了';
        }

        return $raw ?: '受付中';
    }

    private static function datetime_is_past( $value ) {
        $value = trim((string)$value);
        if ( $value === '' ) return false;

        try {
            $dt = new DateTimeImmutable($value, wp_timezone());
            return $dt->getTimestamp() < current_time('timestamp');
        } catch ( Exception $e ) {
            return false;
        }
    }

    private static function status_class( $status ) {
        if ( $status === '受付終了' ) return 'is-closed';

        if (
            $status === '受付期間外' ||
            $status === '受付期間外・準備中'
        ) {
            return 'is-outside';
        }

        if ( $status === '先行予約受付中' ) return 'is-reserve';

        return 'is-open';
    }

    private static function render_pagination( $query ) {
        if ( $query->max_num_pages <= 1 ) return '';

        ob_start();
        echo '<div class="nf-catalog-pagination-inner">';

        $current = max(1, intval($query->get('paged')));
        $max = intval($query->max_num_pages);
        $start = max(1, $current - 3);
        $end = min($max, $current + 3);

        if ( $start > 1 ) {
            echo '<button type="button" class="nf-catalog-page-button" data-page="1">1</button>';
            if ( $start > 2 ) echo '<span class="nf-catalog-page-dots">…</span>';
        }

        for ( $i = $start; $i <= $end; $i++ ) {
            $class = $i === $current ? ' is-current' : '';
            echo '<button type="button" class="nf-catalog-page-button' . esc_attr($class) . '" data-page="' . intval($i) . '">' . intval($i) . '</button>';
        }

        if ( $end < $max ) {
            if ( $end < $max - 1 ) echo '<span class="nf-catalog-page-dots">…</span>';
            echo '<button type="button" class="nf-catalog-page-button" data-page="' . intval($max) . '">' . intval($max) . '</button>';
        }

        echo '</div>';
        return ob_get_clean();
    }
}
