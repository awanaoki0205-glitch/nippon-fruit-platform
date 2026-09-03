<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Single {

    const META_POPULARITY_DAILY = '_nf_popularity_daily';
    const POPULARITY_DAYS = 30;

    /**
     * v0.8.1:
     * 規格ラベルを重量・数量・サイズ等の独立チップとして表示する。
     * 区切りの「/」は画面には出さない。
     */
    public static function spec_label_html( $label ) {
        $parts = preg_split(
            '/\\s*\\/\\s*/u',
            trim((string)$label),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ( ! is_array($parts) || ! $parts ) {
            return '';
        }

        $html = array();

        foreach ( $parts as $part ) {
            $part = trim((string)$part);

            if ( $part === '' ) {
                continue;
            }

            $html[] =
                '<span class="nf-spec-part nf-spec-chip">' .
                esc_html($part) .
                '</span>';
        }

        return implode('', $html);
    }

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'render_single' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        add_action(
            'wp_ajax_nf_track_furusato_popularity',
            array( __CLASS__, 'track_popularity_event' )
        );
        add_action(
            'wp_ajax_nopriv_nf_track_furusato_popularity',
            array( __CLASS__, 'track_popularity_event' )
        );
    }

    public static function enqueue_assets() {
        if ( is_singular( NF_Core::POST_TYPE ) ) {
            wp_enqueue_style(
                'nippon-fruit-single',
                NF_PLUGIN_URL . 'assets/single.css',
                array(),
                NF_VERSION
            );

            wp_enqueue_script(
                'nippon-fruit-single',
                NF_PLUGIN_URL . 'assets/single.js',
                array(),
                NF_VERSION,
                true
            );

            wp_localize_script(
                'nippon-fruit-single',
                'NFPopularity',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'action' => 'nf_track_furusato_popularity',
                    'postId' => intval(get_queried_object_id()),
                    // 管理者自身の確認・開発閲覧は人気集計へ入れない。
                    'enabled' => current_user_can('manage_options') ? 0 : 1,
                )
            );
        }
    }

    public static function render_single() {
        if ( ! is_singular( NF_Core::POST_TYPE ) ) return;

        global $post;

        if ( ! $post || $post->post_type !== NF_Core::POST_TYPE ) return;

        status_header(200);
        nocache_headers();

        $post_id = intval($post->ID);

        $display_title = self::display_title($post_id);
        $rakuten_title = (string)get_post_meta($post_id, '_nf_rakuten_item_name', true);
        $yahoo_title = (string)get_post_meta($post_id, '_nf_yahoo_item_name', true);
        $source_title = $rakuten_title ?: ($yahoo_title ?: get_the_title($post_id));

        $gallery_images = self::gallery_images($post_id);
        $image = ! empty($gallery_images[0])
            ? $gallery_images[0]
            : '';

        $price_text = self::price_text($post_id);
        $capacity = (string)get_post_meta($post_id, '_nf_capacity', true);
        if ( $capacity === '' ) {
            $capacity = self::extract_capacity($source_title);
        }

        $shipping = (string)get_post_meta($post_id, '_nf_shipping', true);
        if ( $shipping === '' ) {
            $shipping = self::extract_shipping($source_title);
        }

        $origin = (string)get_post_meta($post_id, '_nf_origin', true);
        $status = self::effective_status($post_id);

        $affiliate_url = (string)get_post_meta($post_id, '_nf_rakuten_affiliate_url', true);
        $rakuten_url = (string)get_post_meta($post_id, '_nf_rakuten_url', true);
        $cta_url = $affiliate_url ?: $rakuten_url;

        $show_price = ! class_exists('NF_Settings') || NF_Settings::show_price();
        $allow_rakuten = ! class_exists('NF_Settings') || NF_Settings::allow_rakuten();
        $allow_yahoo = ! class_exists('NF_Settings') || NF_Settings::allow_yahoo();
        $show_reviews = ! class_exists('NF_Settings') || NF_Settings::show_reviews();

        if ( ! $allow_rakuten ) {
            $cta_url = '';
        }

        $yahoo_variants = (
            $allow_yahoo &&
            class_exists('NF_Yahoo')
        )
            ? NF_Yahoo::public_variants($post_id)
            : array();

        $yahoo_cta_url = ! empty($yahoo_variants[0])
            ? (
                ! empty($yahoo_variants[0]['affiliateUrl'])
                    ? $yahoo_variants[0]['affiliateUrl']
                    : $yahoo_variants[0]['url']
            )
            : '';

        $yahoo_price = ! empty($yahoo_variants[0]['price'])
            ? intval($yahoo_variants[0]['price'])
            : 0;

        $yahoo_in_stock = ! empty($yahoo_variants[0])
            ? ! empty($yahoo_variants[0]['inStock'])
            : false;

        $review_average = floatval(
            get_post_meta($post_id, '_nf_rakuten_review_average', true)
        );
        $review_count = absint(
            get_post_meta($post_id, '_nf_rakuten_review_count', true)
        );

        if (
            $review_average <= 0 &&
            ! empty($yahoo_variants[0]['reviewRate'])
        ) {
            $review_average = floatval(
                $yahoo_variants[0]['reviewRate']
            );
            $review_count = ! empty($yahoo_variants[0]['reviewCount'])
                ? absint($yahoo_variants[0]['reviewCount'])
                : 0;
        }

        $feature_source_text = self::feature_source_text(
            $post_id,
            $yahoo_variants
        );

        $feature_description = self::feature_description(
            $post_id,
            $yahoo_variants
        );

        $classified_features = class_exists('NF_Content_Classifier')
            ? NF_Content_Classifier::merged_for_post(
                $post_id,
                $feature_source_text
            )
            : array(
                'taste' => '',
                'serving' => '',
                'storage' => '',
                'delivery' => '',
            );

        $management_code = self::management_code(
            $post_id,
            $rakuten_title,
            $yahoo_variants
        );

        $municipalities = wp_get_post_terms($post_id, 'nf_municipality');
        $fruits = class_exists('NF_Category')
            ? NF_Category::public_terms_for_post($post_id)
            : wp_get_post_terms($post_id, 'nf_category');

        $municipality_name = '';
        if ( ! is_wp_error($municipalities) && ! empty($municipalities) ) {
            $municipality_name = $municipalities[0]->name;
        }

        if ( $origin === '' && $municipality_name !== '' ) {
            $origin = $municipality_name === '熊本県'
                ? '熊本県'
                : '熊本県' . $municipality_name;
        }

        get_header();
        ?>
        <main id="main" class="site-main nf-single-page">
          <div class="nf-single-container">

            <nav class="nf-single-breadcrumbs" aria-label="パンくず">
              <a href="<?php echo esc_url(get_post_type_archive_link(NF_Core::POST_TYPE)); ?>">返礼品トップ</a>
              <span>›</span>
              <a href="<?php echo esc_url(get_post_type_archive_link(NF_Core::POST_TYPE)); ?>">ふるさと納税返礼品</a>
              <?php if ( $municipality_name ) : ?>
                <span>›</span>
                <span><?php echo esc_html($municipality_name); ?></span>
              <?php endif; ?>
            </nav>

            <a class="nf-single-back" href="<?php echo esc_url(get_post_type_archive_link(NF_Core::POST_TYPE)); ?>">
              ← 返礼品一覧へ戻る
            </a>

            <section class="nf-single-hero">
              <div class="nf-single-media">
                <?php if ( $image ) : ?>
                  <button
                    type="button"
                    class="nf-single-visual nf-gallery-main"
                    aria-label="商品画像を拡大"
                  >
                    <img
                      id="nf_gallery_main_image"
                      src="<?php echo esc_url($image); ?>"
                      alt="<?php echo esc_attr($display_title); ?>"
                    >

                    <?php if ( count($gallery_images) > 1 ) : ?>
                      <span class="nf-gallery-counter">
                        <span id="nf_gallery_current">1</span>
                        /
                        <?php echo intval(count($gallery_images)); ?>
                      </span>
                    <?php endif; ?>

                    <span class="nf-gallery-zoom" aria-hidden="true">＋</span>
                  </button>
                <?php else : ?>
                  <div class="nf-single-visual">
                    <div class="nf-single-noimage">Nippon Fruit</div>
                  </div>
                <?php endif; ?>

                <?php if ( count($gallery_images) > 1 ) : ?>
                  <div
                    class="nf-gallery-thumbs"
                    id="nf_gallery_thumbs"
                    aria-label="商品画像一覧"
                  >
                    <?php foreach ( $gallery_images as $index => $gallery_image ) : ?>
                      <button
                        type="button"
                        class="nf-gallery-thumb<?php echo $index === 0 ? ' is-active' : ''; ?>"
                        data-index="<?php echo intval($index); ?>"
                        data-full="<?php echo esc_url($gallery_image); ?>"
                        aria-label="商品画像 <?php echo intval($index + 1); ?> を表示"
                        aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                      >
                        <img
                          src="<?php echo esc_url($gallery_image); ?>"
                          alt=""
                          loading="<?php echo $index < 4 ? 'eager' : 'lazy'; ?>"
                        >
                      </button>
                    <?php endforeach; ?>
                  </div>

                  <p class="nf-gallery-help">
                    画像を選択すると切り替わります
                    <span>・横にスクロールできます</span>
                  </p>
                <?php endif; ?>
              </div>

              <div class="nf-single-summary">
                <div class="nf-single-top-tags">
                  <?php if ( $municipality_name ) : ?>
                    <span class="nf-single-location"><?php echo esc_html($municipality_name); ?></span>
                  <?php endif; ?>

                  <?php if ( $status ) : ?>
                    <span class="nf-single-status <?php echo esc_attr(self::status_class($status)); ?>">
                      <?php echo esc_html($status); ?>
                    </span>
                  <?php endif; ?>
                </div>

                <h1><?php echo esc_html($display_title); ?></h1>

                <?php if ( ! is_wp_error($fruits) && ! empty($fruits) ) : ?>
                  <div class="nf-single-fruits">
                    <?php foreach ( array_slice($fruits, 0, 4) as $term ) : ?>
                      <span><?php echo esc_html($term->name); ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ( $show_price ) : ?>
                  <div class="nf-single-price-block">
                    <span>寄附額</span>
                    <strong><?php echo esc_html($price_text); ?></strong>
                  </div>
                <?php endif; ?>

                <?php if ( $show_reviews && $review_average > 0 ) : ?>
                  <div class="nf-single-review-summary" aria-label="クチコミ評価">
                    <span class="nf-single-review-stars" aria-hidden="true">
                      <?php echo esc_html(self::rating_stars($review_average)); ?>
                    </span>
                    <span class="nf-single-review-meta">
                      <?php echo esc_html(number_format($review_average, 1)); ?>
                      <?php if ( $review_count > 0 ) : ?>
                        （<?php echo intval($review_count); ?>件）
                      <?php endif; ?>
                    </span>
                  </div>
                <?php endif; ?>

                <dl class="nf-single-quick-info">
                  <?php if ( $capacity ) : ?>
                    <div>
                      <dt>容量</dt>
                      <dd><?php echo esc_html($capacity); ?></dd>
                    </div>
                  <?php endif; ?>

                  <?php if ( $shipping ) : ?>
                    <div>
                      <dt>発送時期</dt>
                      <dd><?php echo esc_html($shipping); ?></dd>
                    </div>
                  <?php endif; ?>

                  <?php if ( $origin ) : ?>
                    <div>
                      <dt>産地</dt>
                      <dd><?php echo esc_html($origin); ?></dd>
                    </div>
                  <?php endif; ?>
                </dl>

                <?php if ( $cta_url || $yahoo_cta_url ) : ?>
                  <div class="nf-single-portals">
                    <div class="nf-single-portals-title">お申し込み先</div>

                    <?php if ( $cta_url ) : ?>
                      <div class="nf-single-portal-row is-rakuten">
                        <div class="nf-single-portal-info">
                          <strong>楽天ふるさと納税</strong>
                          <?php if ( $show_price ) : ?>
                            <span><?php echo esc_html($price_text); ?></span>
                          <?php endif; ?>
                        </div>
                        <a
                          href="<?php echo esc_url($cta_url); ?>"
                          target="_blank"
                          rel="nofollow sponsored noopener"
                        >
                          楽天で寄付
                        </a>
                      </div>
                    <?php endif; ?>

                    <?php foreach ( $yahoo_variants as $index => $yahoo_variant ) : ?>
                      <?php
                      $variant_url = ! empty($yahoo_variant['affiliateUrl'])
                        ? $yahoo_variant['affiliateUrl']
                        : $yahoo_variant['url'];

                      if ( ! $variant_url ) continue;

                      $variant_spec = ! empty($yahoo_variant['specLabel'])
                        ? $yahoo_variant['specLabel']
                        : (! empty($yahoo_variant['capacityLabel']) ? $yahoo_variant['capacityLabel'] : '');

                      $variant_store_name = class_exists('NF_Yahoo')
                        ? NF_Yahoo::public_store_name($yahoo_variant)
                        : '';

                      $variant_price_text = ! empty($yahoo_variant['price'])
                        ? number_format_i18n($yahoo_variant['price']) . '円'
                        : '寄附額はYahoo!で確認';
                      ?>
                      <div class="nf-single-portal-row is-yahoo<?php echo ! empty($yahoo_variant['inStock']) ? '' : ' is-out'; ?>">
                        <div class="nf-single-portal-info">
                          <strong>Yahoo!ショッピング</strong>

                          <?php if ( $variant_store_name ) : ?>
                            <span class="nf-yahoo-store-line">
                              <span>ストア</span>
                              <b><?php echo esc_html($variant_store_name); ?></b>
                            </span>
                          <?php endif; ?>

                          <?php if ( $variant_spec ) : ?>
                            <small class="nf-variant-capacity nf-variant-spec">
                              <?php echo self::spec_label_html($variant_spec); ?>
                            </small>
                          <?php elseif ( count($yahoo_variants) > 1 ) : ?>
                            <small>掲載<?php echo intval($index + 1); ?></small>
                          <?php endif; ?>

                          <span class="nf-portal-meta">
                            <?php if ( $show_price ) : ?>
                              <b><?php echo esc_html($variant_price_text); ?></b>
                            <?php endif; ?>
                            <em class="<?php echo ! empty($yahoo_variant['inStock']) ? 'is-in-stock' : 'is-out-stock'; ?>">
                              <?php echo ! empty($yahoo_variant['inStock']) ? '受付中' : '在庫なし'; ?>
                            </em>
                          </span>
                        </div>

                        <a
                          href="<?php echo esc_url($variant_url); ?>"
                          target="_blank"
                          rel="nofollow sponsored noopener"
                        >
                          Yahoo!で寄付
                        </a>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <p class="nf-single-ad-note">
                    ※当ページにはアフィリエイト広告が含まれる場合があります。
                  </p>
                <?php endif; ?>

                <?php if ( $rakuten_title && $rakuten_title !== $display_title ) : ?>
                  <details class="nf-single-rakuten-title">
                    <summary>楽天掲載名を見る</summary>
                    <p><?php echo esc_html($rakuten_title); ?></p>
                  </details>
                <?php endif; ?>
              </div>
            </section>

            <section class="nf-single-section">
              <div class="nf-single-section-head">
                <p class="nf-single-section-label">PRODUCT FEATURES</p>
                <h2>返礼品の特徴</h2>
              </div>

              <div class="nf-single-info-table">
                <?php if ( $feature_description ) : ?>
                  <div class="nf-feature-description is-long">
                    <strong>説明</strong>
                    <span><?php echo esc_html($feature_description); ?></span>
                  </div>
                <?php endif; ?>
                <?php if ( ! empty($classified_features['taste']) ) : ?>
                  <div class="nf-feature-description is-long">
                    <strong>おいしさ・味わい</strong>
                    <span><?php echo esc_html($classified_features['taste']); ?></span>
                  </div>
                <?php endif; ?>
                <?php if ( ! empty($classified_features['serving']) ) : ?>
                  <div class="nf-feature-description is-long">
                    <strong>おすすめの食べ方</strong>
                    <span><?php echo esc_html($classified_features['serving']); ?></span>
                  </div>
                <?php endif; ?>
                <?php if ( ! empty($classified_features['storage']) ) : ?>
                  <div class="nf-feature-description is-long">
                    <strong>保存方法</strong>
                    <span><?php echo esc_html($classified_features['storage']); ?></span>
                  </div>
                <?php endif; ?>
                <?php if ( ! empty($classified_features['delivery']) ) : ?>
                  <div class="nf-feature-description is-long">
                    <strong>配送について</strong>
                    <span><?php echo esc_html($classified_features['delivery']); ?></span>
                  </div>
                <?php endif; ?>

                <?php if ( $shipping ) : ?>
                  <div><strong>配送</strong><span><?php echo esc_html($shipping); ?></span></div>
                <?php endif; ?>
                <?php if ( $capacity ) : ?>
                  <div><strong>容量</strong><span><?php echo esc_html($capacity); ?></span></div>
                <?php endif; ?>
                <?php if ( $status ) : ?>
                  <div><strong>申込</strong><span><?php echo esc_html($status); ?></span></div>
                <?php endif; ?>
                <?php if ( $origin ) : ?>
                  <div><strong>産地</strong><span><?php echo esc_html($origin); ?></span></div>
                <?php endif; ?>
                <?php if ( $management_code ) : ?>
                  <div><strong>自治体での管理番号</strong><span><?php echo esc_html($management_code); ?></span></div>
                <?php endif; ?>
                <?php if ( $show_price && $price_text ) : ?>
                  <div><strong>寄附額</strong><span><?php echo esc_html($price_text); ?></span></div>
                <?php endif; ?>
              </div>
            </section>

            <?php
            $raw_content = self::about_content(
                $post_id,
                (string)$post->post_content,
                $feature_description,
                $capacity
            );
            if ( $raw_content !== '' ) :
                $body = do_shortcode($raw_content);
                $body = wpautop($body);
            ?>
              <section class="nf-single-section nf-single-description">
                <div class="nf-single-section-head">
                  <p class="nf-single-section-label">ABOUT</p>
                  <h2>この返礼品について</h2>
                </div>
                <div class="nf-single-description-body">
                  <?php echo wp_kses_post($body); ?>
                </div>
              </section>
            <?php endif; ?>

            <?php
            /* v0.9.0:
             * 同一寄附リンクの重複表示を避けるため、
             * 申込先CTAは上部の商品サマリーに一本化。
             */
            ?>

            <?php
            $related = self::related_products($post_id, $municipalities, $fruits);
            $related_ids = array();

            if ( ! empty($related->posts) ) {
                $related_ids = array_map(
                    'intval',
                    wp_list_pluck($related->posts, 'ID')
                );
            }

            if ( $related->have_posts() ) :
            ?>
              <section class="nf-single-related">
                <div class="nf-single-section-head">
                  <p class="nf-single-section-label">RELATED</p>
                  <h2>おすすめ返礼品</h2>
                  <p class="nf-single-related-note">
                    品目・寄附額・容量・発送時期などが近い返礼品を優先して表示しています。
                  </p>
                </div>

                <div class="nf-single-related-grid">
                  <?php while ( $related->have_posts() ) : $related->the_post(); ?>
                    <?php
                    $rid = get_the_ID();
                    $rtitle = self::display_title($rid);
                    $rimage = self::best_product_image(
                        $rid,
                        'medium'
                    );
                    ?>
                    <article class="nf-single-related-card">
                      <a href="<?php the_permalink(); ?>" class="nf-single-related-image">
                        <?php if ( $rimage ) : ?>
                          <img src="<?php echo esc_url($rimage); ?>" alt="<?php echo esc_attr($rtitle); ?>" loading="lazy">
                        <?php else : ?>
                          <div class="nf-single-noimage">Nippon Fruit</div>
                        <?php endif; ?>
                      </a>
                      <div class="nf-single-related-body">
                        <h3><a href="<?php the_permalink(); ?>"><?php echo esc_html($rtitle); ?></a></h3>
                        <strong><?php echo esc_html(self::price_text($rid)); ?></strong>
                      </div>
                    </article>
                  <?php endwhile; ?>
                </div>
              </section>
            <?php
              wp_reset_postdata();
            endif;

            $popularity_section = self::popularity_section(
                $post_id,
                $related_ids
            );

            $popular = $popularity_section['query'];

            if ( $popular->have_posts() ) :
            ?>
              <section
                class="nf-single-related nf-single-popular nf-popularity-mode-<?php echo esc_attr($popularity_section['mode']); ?>"
              >
                <div class="nf-single-section-head">
                  <p class="nf-single-section-label">
                    <?php echo esc_html($popularity_section['eyebrow']); ?>
                  </p>

                  <h2>
                    <?php echo esc_html($popularity_section['title']); ?>
                  </h2>

                  <p class="nf-single-related-note">
                    <?php echo esc_html($popularity_section['note']); ?>
                  </p>
                </div>

                <div class="nf-single-related-grid">
                  <?php
                  $popularity_index = 0;

                  while ( $popular->have_posts() ) :
                    $popular->the_post();

                    $pid = get_the_ID();
                    $ptitle = self::display_title($pid);
                    $pimage = self::best_product_image(
                        $pid,
                        'medium'
                    );

                    $is_measured_popular =
                        $popularity_index <
                        intval(
                            $popularity_section['popularCount']
                        );
                  ?>
                    <article class="nf-single-related-card">
                      <a
                        href="<?php the_permalink(); ?>"
                        class="nf-single-related-image"
                      >
                        <?php if ( $pimage ) : ?>
                          <img
                            src="<?php echo esc_url($pimage); ?>"
                            alt="<?php echo esc_attr($ptitle); ?>"
                            loading="lazy"
                          >
                        <?php else : ?>
                          <div class="nf-single-noimage">
                            Nippon Fruit
                          </div>
                        <?php endif; ?>

                        <?php if ( $popularity_section['mode'] === 'mixed' ) : ?>
                          <span
                            class="nf-popularity-card-badge <?php echo $is_measured_popular ? 'is-popular' : 'is-featured'; ?>"
                          >
                            <?php echo $is_measured_popular ? '最近人気' : '注目'; ?>
                          </span>
                        <?php endif; ?>
                      </a>

                      <div class="nf-single-related-body">
                        <h3>
                          <a href="<?php the_permalink(); ?>">
                            <?php echo esc_html($ptitle); ?>
                          </a>
                        </h3>

                        <strong>
                          <?php echo esc_html(self::price_text($pid)); ?>
                        </strong>
                      </div>
                    </article>
                  <?php
                    $popularity_index++;
                  endwhile;
                  ?>
                </div>
              </section>
            <?php
              wp_reset_postdata();
            endif;
            ?>
          </div>

          <?php if ( count($gallery_images) > 0 ) : ?>
            <div
              class="nf-gallery-lightbox"
              id="nf_gallery_lightbox"
              hidden
              aria-hidden="true"
              role="dialog"
              aria-modal="true"
              aria-label="商品画像拡大表示"
            >
              <button
                type="button"
                class="nf-gallery-lightbox-close"
                id="nf_gallery_lightbox_close"
                aria-label="閉じる"
              >×</button>

              <?php if ( count($gallery_images) > 1 ) : ?>
                <button
                  type="button"
                  class="nf-gallery-lightbox-nav is-prev"
                  id="nf_gallery_lightbox_prev"
                  aria-label="前の画像"
                >‹</button>
              <?php endif; ?>

              <div class="nf-gallery-lightbox-stage">
                <img
                  id="nf_gallery_lightbox_image"
                  src="<?php echo esc_url($gallery_images[0]); ?>"
                  alt="<?php echo esc_attr($display_title); ?>"
                >
                <?php if ( count($gallery_images) > 1 ) : ?>
                  <div class="nf-gallery-lightbox-count">
                    <span id="nf_gallery_lightbox_current">1</span>
                    /
                    <?php echo intval(count($gallery_images)); ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ( count($gallery_images) > 1 ) : ?>
                <button
                  type="button"
                  class="nf-gallery-lightbox-nav is-next"
                  id="nf_gallery_lightbox_next"
                  aria-label="次の画像"
                >›</button>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php /* v0.9.0: CTAは商品サマリー内に一本化 */ ?>
        </main>
        <?php
        get_footer();
        exit;
    }

    private static function gallery_images( $post_id ) {
        $images = array();

        $append_unique = function($url) use (&$images) {
            $url = self::high_res_image_url($url);

            if ( $url && ! in_array($url, $images, true) ) {
                $images[] = $url;
            }
        };

        // アイキャッチがある場合は最優先。
        $featured = get_the_post_thumbnail_url($post_id, 'full');
        if ( $featured ) {
            $append_unique($featured);
        }

        // 楽天APIから保存済みの全画像。
        $stored = get_post_meta(
            $post_id,
            '_nf_rakuten_image_urls',
            true
        );

        if ( is_string($stored) && $stored !== '' ) {
            $decoded = json_decode($stored, true);
            if ( is_array($decoded) ) {
                $stored = $decoded;
            }
        }

        if ( is_array($stored) ) {
            foreach ( $stored as $url ) {
                $append_unique($url);
            }
        }

        // 旧バージョン互換。
        $legacy = get_post_meta(
            $post_id,
            '_nf_rakuten_image_url',
            true
        );
        $append_unique($legacy);

        // v0.7.1: Yahoo!単独商品/マルチポータルの商品画像。
        $yahoo_images = get_post_meta(
            $post_id,
            '_nf_yahoo_image_urls',
            true
        );

        if ( is_array($yahoo_images) ) {
            foreach ( $yahoo_images as $url ) {
                $append_unique($url);
            }
        }

        $append_unique(
            get_post_meta(
                $post_id,
                '_nf_yahoo_image_url',
                true
            )
        );

        // v0.8.2:
        // 集約metaが古い場合でも、現在のYahoo!variant画像を直接拾う。
        if ( class_exists('NF_Yahoo') ) {
            foreach ( NF_Yahoo::public_variants($post_id) as $variant ) {
                if ( ! empty($variant['image']) ) {
                    $append_unique($variant['image']);
                }

                if (
                    ! empty($variant['images']) &&
                    is_array($variant['images'])
                ) {
                    foreach ( $variant['images'] as $url ) {
                        $append_unique($url);
                    }
                }
            }
        }

        // v0.6.4:
        // 運営者がWordPress側で登録した追加画像を全て連結。
        // 自動同期ではこのmetaは一切触らない。
        $manual = get_post_meta(
            $post_id,
            '_nf_manual_image_urls',
            true
        );

        if ( is_string($manual) && $manual !== '' ) {
            $decoded = json_decode($manual, true);
            if ( is_array($decoded) ) {
                $manual = $decoded;
            } else {
                $manual = preg_split(
                    '/\r\n|\r|\n/',
                    $manual
                );
            }
        }

        if ( is_array($manual) ) {
            foreach ( $manual as $url ) {
                $append_unique($url);
            }
        }

        // 手動登録枚数に上限を設けず、保存されている有効URLを全て表示。
        return array_values(array_filter($images));
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

        // 「約1.5kg〜10kg」「約2kg〜約5kg」等
        if ( preg_match('/約?\s*(\d+(?:\.\d+)?)\s*(kg|g)\s*[〜～\-]\s*約?\s*(\d+(?:\.\d+)?)\s*(kg|g)/iu', $title, $m) ) {
            return '約' . $m[1] . strtolower($m[2]) . '〜' . $m[3] . strtolower($m[4]);
        }

        // 複数サイズがある場合、最初と最後のkg/gを範囲化。
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

    private static function extract_shipping( $title ) {
        $title = (string)$title;

        $patterns = array(
            '/【([^】]*(?:発送|出荷)[^】]*)】/u',
            '/《([^》]*(?:発送|出荷)[^》]*)》/u',
            '/(\d{4}年\d{1,2}月[^ ]{0,30}(?:発送|出荷)[^ ]{0,20})/u',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match($pattern, $title, $m) ) {
                $text = trim($m[1]);
                if ( function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 55 ) {
                    $text = mb_substr($text, 0, 55, 'UTF-8') . '…';
                }
                return $text;
            }
        }

        return '';
    }

    private static function high_res_image_url( $url ) {
        $url = trim((string)$url);
        if ( $url === '' ) return '';

        $url = preg_replace('/([?&])_ex=\d+x\d+(&?)/i', '$1', $url);
        $url = preg_replace('/[?&]$/', '', $url);
        $url = str_replace('?&', '?', $url);

        return esc_url_raw($url);
    }

    public static function track_popularity_event() {
        $post_id = isset($_POST['post_id'])
            ? intval($_POST['post_id'])
            : 0;

        $event = isset($_POST['event'])
            ? sanitize_key(wp_unslash($_POST['event']))
            : '';

        $portal = isset($_POST['portal'])
            ? sanitize_key(wp_unslash($_POST['portal']))
            : '';

        if (
            ! $post_id ||
            get_post_type($post_id) !== NF_Core::POST_TYPE ||
            get_post_status($post_id) !== 'publish' ||
            ! in_array($event, array('view','click'), true)
        ) {
            wp_send_json_error(
                array('message' => 'invalid_event'),
                400
            );
        }

        // 管理者のプレビューや作業確認は人気へ加算しない。
        if ( current_user_can('manage_options') ) {
            wp_send_json_success(
                array('ignored' => true)
            );
        }

        $daily = get_post_meta(
            $post_id,
            self::META_POPULARITY_DAILY,
            true
        );

        if ( ! is_array($daily) ) {
            $daily = array();
        }

        $today = wp_date('Y-m-d');
        $now = current_time('timestamp');
        $cutoff = strtotime('-40 days', $now);

        // 30日ランキング + 少し余裕を持って40日だけ保持。
        foreach ( array_keys($daily) as $day ) {
            $timestamp = strtotime($day . ' 00:00:00');

            if ( ! $timestamp || $timestamp < $cutoff ) {
                unset($daily[$day]);
            }
        }

        if (
            ! isset($daily[$today]) ||
            ! is_array($daily[$today])
        ) {
            $daily[$today] = array(
                'views' => 0,
                'clicks' => 0,
                'rakutenClicks' => 0,
                'yahooClicks' => 0,
            );
        }

        if ( $event === 'view' ) {
            $daily[$today]['views'] =
                intval($daily[$today]['views']) + 1;
        } else {
            $daily[$today]['clicks'] =
                intval($daily[$today]['clicks']) + 1;

            if ( $portal === 'rakuten' ) {
                $daily[$today]['rakutenClicks'] =
                    intval($daily[$today]['rakutenClicks']) + 1;
            } elseif ( $portal === 'yahoo' ) {
                $daily[$today]['yahooClicks'] =
                    intval($daily[$today]['yahooClicks']) + 1;
            }
        }

        update_post_meta(
            $post_id,
            self::META_POPULARITY_DAILY,
            $daily
        );

        wp_send_json_success(
            array('recorded' => true)
        );
    }

    private static function popularity_score( $post_id ) {
        $daily = get_post_meta(
            $post_id,
            self::META_POPULARITY_DAILY,
            true
        );

        if ( ! is_array($daily) || ! $daily ) {
            return 0.0;
        }

        $today = new DateTimeImmutable(
            'today',
            wp_timezone()
        );

        $score = 0.0;

        foreach ( $daily as $day => $counts ) {
            if ( ! is_array($counts) ) {
                continue;
            }

            try {
                $date = new DateTimeImmutable(
                    $day,
                    wp_timezone()
                );
            } catch ( Exception $e ) {
                continue;
            }

            $age = intval(
                $date->diff($today)->format('%r%a')
            );

            if ( $age < 0 || $age >= self::POPULARITY_DAYS ) {
                continue;
            }

            if ( $age <= 6 ) {
                $recency = 2.0;
            } elseif ( $age <= 13 ) {
                $recency = 1.5;
            } else {
                $recency = 1.0;
            }

            $views = isset($counts['views'])
                ? intval($counts['views'])
                : 0;

            $clicks = isset($counts['clicks'])
                ? intval($counts['clicks'])
                : 0;

            $score += (
                ($views * 1.0) +
                ($clicks * 5.0)
            ) * $recency;
        }

        return round($score, 2);
    }

    private static function popular_products(
        $post_id,
        $exclude_ids = array()
    ) {
        $exclude_ids = array_values(array_unique(array_filter(
            array_map(
                'intval',
                array_merge(
                    array($post_id),
                    (array)$exclude_ids
                )
            )
        )));

        $candidate_ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 250,
            'post__not_in' => $exclude_ids,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        $ranked = array();

        foreach ( $candidate_ids as $candidate_id ) {
            $candidate_id = intval($candidate_id);

            if (
                ! $candidate_id ||
                ! self::portal_available($candidate_id) ||
                self::effective_status($candidate_id) === '受付終了'
            ) {
                continue;
            }

            $score = self::popularity_score(
                $candidate_id
            );

            // 実測データの無い商品を「人気」とは表示しない。
            if ( $score <= 0 ) {
                continue;
            }

            $ranked[] = array(
                'id' => $candidate_id,
                'score' => $score,
            );
        }

        usort($ranked, function($a, $b) {
            if ( $a['score'] == $b['score'] ) {
                return $a['id'] <=> $b['id'];
            }

            return $b['score'] <=> $a['score'];
        });

        $selected = array_slice(
            array_column($ranked, 'id'),
            0,
            4
        );

        if ( ! $selected ) {
            return new WP_Query(array(
                'post_type' => NF_Core::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => 0,
                'post__in' => array(0),
                'no_found_rows' => true,
            ));
        }

        return new WP_Query(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => count($selected),
            'post__in' => $selected,
            'orderby' => 'post__in',
            'no_found_rows' => true,
        ));
    }


    /**
     * 人気データが足りないときの「注目」スコア。
     * 実測人気とは混ぜず、旬・おすすめ・新着・受付状況を根拠にする。
     */
    private static function featured_score( $post_id ) {
        $score = 0;

        if ( ! self::portal_available($post_id) ) {
            return -9999;
        }

        $status = self::effective_status($post_id);

        if ( $status === '受付終了' ) {
            return -9999;
        }

        if ( $status === '先行予約受付中' ) {
            $score += 22;
        } else {
            $score += 18;
        }

        if (
            get_post_meta(
                $post_id,
                '_nf_recommended',
                true
            ) === '1'
        ) {
            $score += 50;
        }

        $shipping_index = self::shipping_month_index($post_id);

        if ( $shipping_index ) {
            $current_index =
                intval(wp_date('Y')) * 12 +
                intval(wp_date('n'));

            $diff = abs(
                $shipping_index - $current_index
            );

            if ( $diff === 0 ) {
                $score += 34;
            } elseif ( $diff === 1 ) {
                $score += 28;
            } elseif ( $diff === 2 ) {
                $score += 20;
            } elseif ( $diff <= 4 ) {
                $score += 10;
            }
        }

        $published = intval(
            get_post_time(
                'U',
                true,
                $post_id
            )
        );

        if ( $published > 0 ) {
            $age_days = max(
                0,
                floor(
                    (
                        current_time('timestamp') -
                        $published
                    ) / DAY_IN_SECONDS
                )
            );

            if ( $age_days <= 30 ) {
                $score += 18;
            } elseif ( $age_days <= 60 ) {
                $score += 11;
            } elseif ( $age_days <= 120 ) {
                $score += 5;
            }
        }

        if (
            self::best_product_image(
                $post_id,
                'thumbnail'
            )
        ) {
            $score += 8;
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

        $has_yahoo =
            class_exists('NF_Yahoo') &&
            ! empty(
                NF_Yahoo::public_variants($post_id)
            );

        if ( $has_rakuten || $has_yahoo ) {
            $score += 5;
        }

        return intval($score);
    }

    private static function featured_product_ids(
        $exclude_ids = array(),
        $limit = 4
    ) {
        $limit = max(0, intval($limit));

        if ( $limit < 1 ) {
            return array();
        }

        $exclude_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        (array)$exclude_ids
                    )
                )
            )
        );

        $candidate_ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 250,
            'post__not_in' => $exclude_ids,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        $ranked = array();

        foreach ( $candidate_ids as $candidate_id ) {
            $candidate_id = intval($candidate_id);

            if ( ! $candidate_id ) {
                continue;
            }

            $score = self::featured_score(
                $candidate_id
            );

            if ( $score < 0 ) {
                continue;
            }

            $ranked[] = array(
                'id' => $candidate_id,
                'score' => $score,
                'published' => intval(
                    get_post_time(
                        'U',
                        true,
                        $candidate_id
                    )
                ),
            );
        }

        usort($ranked, function($a, $b) {
            if ( $a['score'] === $b['score'] ) {
                if (
                    $a['published'] ===
                    $b['published']
                ) {
                    return $a['id'] <=> $b['id'];
                }

                return
                    $b['published'] <=>
                    $a['published'];
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice(
            array_column(
                $ranked,
                'id'
            ),
            0,
            $limit
        );
    }

    private static function query_product_ids( $ids ) {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        (array)$ids
                    )
                )
            )
        );

        if ( ! $ids ) {
            return new WP_Query(array(
                'post_type' => NF_Core::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => 0,
                'post__in' => array(0),
                'no_found_rows' => true,
            ));
        }

        return new WP_Query(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => count($ids),
            'post__in' => $ids,
            'orderby' => 'post__in',
            'no_found_rows' => true,
        ));
    }

    /**
     * 人気4件に達していないときだけ、注目商品で不足分を補完。
     */
    private static function popularity_section(
        $post_id,
        $exclude_ids = array()
    ) {
        $popular_query = self::popular_products(
            $post_id,
            $exclude_ids
        );

        $popular_ids = ! empty($popular_query->posts)
            ? array_map(
                'intval',
                wp_list_pluck(
                    $popular_query->posts,
                    'ID'
                )
            )
            : array();

        $popular_ids = array_values(
            array_unique(
                array_filter($popular_ids)
            )
        );

        $popular_count = count($popular_ids);

        if ( $popular_count >= 4 ) {
            return array(
                'mode' => 'popular',
                'eyebrow' => 'POPULAR',
                'title' => '最近人気の返礼品',
                'note' =>
                    '直近30日間の閲覧と、楽天・Yahoo!など申込先へのクリックをもとに表示しています。',
                'popularCount' => 4,
                'query' => self::query_product_ids(
                    array_slice(
                        $popular_ids,
                        0,
                        4
                    )
                ),
            );
        }

        $needed = 4 - $popular_count;

        $featured_ids = self::featured_product_ids(
            array_merge(
                array($post_id),
                (array)$exclude_ids,
                $popular_ids
            ),
            $needed
        );

        $final_ids = array_merge(
            $popular_ids,
            $featured_ids
        );

        if ( $popular_count === 0 ) {
            return array(
                'mode' => 'featured',
                'eyebrow' => 'FEATURED',
                'title' => '注目の返礼品',
                'note' =>
                    '旬や発送時期、おすすめ指定、新しく掲載された返礼品からピックアップしています。',
                'popularCount' => 0,
                'query' => self::query_product_ids(
                    $final_ids
                ),
            );
        }

        return array(
            'mode' => 'mixed',
            'eyebrow' => 'POPULAR & FEATURED',
            'title' => '最近人気・注目の返礼品',
            'note' =>
                '最近よく見られている返礼品を優先し、不足分を旬やおすすめ指定から補っています。',
            'popularCount' => $popular_count,
            'query' => self::query_product_ids(
                $final_ids
            ),
        );
    }

    private static function rating_stars( $average ) {
        $average = max(0, min(5, floatval($average)));
        $rounded = intval(round($average));

        return str_repeat('★', $rounded) .
            str_repeat('☆', max(0, 5 - $rounded));
    }

    private static function feature_source_text(
        $post_id,
        $yahoo_variants = array()
    ) {
        $sources = array();

        $rakuten = trim((string)get_post_meta(
            $post_id,
            '_nf_rakuten_description',
            true
        ));

        if ( $rakuten !== '' ) {
            $sources[] = $rakuten;
        }

        foreach ( (array)$yahoo_variants as $variant ) {
            if ( ! empty($variant['headline']) ) {
                $sources[] = $variant['headline'];
            }

            if ( ! empty($variant['description']) ) {
                $sources[] = $variant['description'];
            }
        }

        if ( ! $sources ) {
            $excerpt = trim((string)get_post_field(
                'post_excerpt',
                $post_id
            ));

            if ( $excerpt !== '' ) {
                $sources[] = $excerpt;
            }
        }

        if ( ! $sources ) {
            return '';
        }

        $text = html_entity_decode(
            wp_strip_all_tags(
                implode(' ', array_unique($sources)),
                true
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace(
            '/[\s\x{3000}]+/u',
            ' ',
            $text
        );

        return trim($text);
    }

    private static function feature_description(
        $post_id,
        $yahoo_variants = array()
    ) {
        // v0.9.12: 元の商品説明全文をここへ流用しない。
        // 容量・配送・制度説明は下のスペック表へ任せ、説明欄は商品そのものの識別に絞る。
        $municipality = '';
        $municipalities = wp_get_post_terms($post_id, 'nf_municipality');
        if ( ! is_wp_error($municipalities) && ! empty($municipalities) ) {
            $municipality = trim((string)$municipalities[0]->name);
        }

        $fruit_names = array();
        $fruits = class_exists('NF_Category')
            ? NF_Category::public_terms_for_post($post_id)
            : wp_get_post_terms($post_id, 'nf_category');
        if ( ! is_wp_error($fruits) && ! empty($fruits) ) {
            foreach ( $fruits as $fruit ) {
                $name = trim((string)$fruit->name);
                if ( $name !== '' ) {
                    $fruit_names[] = $name;
                }
            }
        }

        $source = self::feature_source_text($post_id, $yahoo_variants);
        $title = self::display_title($post_id);
        $variety = self::feature_variety_name($title . ' ' . $source);

        // 商品名に明確な品種がある場合は、誤分類されたカテゴリより優先する。
        // 商品名で判別できない場合だけ正式カテゴリを説明へ利用する。
        $title_variety = self::feature_variety_name($title);
        $subject = $title_variety !== ''
            ? $title_variety
            : (
                ! empty($fruit_names)
                    ? implode('・', array_slice($fruit_names, 0, 2))
                    : ($variety !== '' ? $variety : '返礼品')
            );

        $where = '';
        if ( $municipality !== '' ) {
            $where = $municipality === '熊本県'
                ? '熊本県'
                : '熊本県' . $municipality;
        }

        if ( $where !== '' && $subject !== '返礼品' ) {
            return $where . 'からお届けする' . $subject . 'の返礼品です。';
        }

        if ( $subject !== '返礼品' ) {
            return $subject . 'の返礼品です。';
        }

        return '';
    }

    private static function about_content(
        $post_id,
        $raw_content,
        $feature_description,
        $capacity
    ) {
        $raw_content = trim((string)$raw_content);

        if ( $raw_content === '' ) {
            return '';
        }

        $plain = trim(wp_strip_all_tags($raw_content));
        $is_legacy_generated =
            strpos($plain, 'ふるさと納税返礼品をご紹介しています') !== false ||
            (
                preg_match('/で育てられた.+です。/u', $plain) &&
                strpos($plain, '日本フルーツ') !== false
            );

        // 過去の自動生成文は、統合・再分類後も古い品種名が残るため、
        // 現在の商品タイトルから生成した説明へ置き換えて表示する。
        if ( ! $is_legacy_generated ) {
            return $raw_content;
        }

        $summary = trim((string)$feature_description);
        $capacity = trim((string)$capacity);

        if ( $capacity !== '' ) {
            $summary .= ($summary !== '' ? "\n\n" : '') .
                '内容量は' . $capacity . 'です。';
        }

        if ( $summary !== '' ) {
            $summary .= "\n\n最新の受付内容は、お申し込み先でご確認ください。";
        }

        return $summary;
    }

    private static function feature_variety_name( $text ) {
        if ( class_exists('NF_Product_Title') ) {
            $primary = NF_Product_Title::primary_variety($text);
            if ( $primary !== '' ) return $primary;
        }

        $text = (string)$text;
        $varieties = array(
            '温州みかん','温州ミカン',
            '秋月梨','豊水梨','新高梨','あきづき','秋月','豊水','新高',
            'シャインマスカット','巨峰','ピオーネ','太秋柿','太秋',
            '不知火・デコポン','不知火','デコポン','晩白柚',
            '肥後グリーン','金色羅皇','羅王ザ・スウィート','羅皇ザ・スウィート',
            '紅はるか','シルクスイート'
        );

        foreach ( $varieties as $variety ) {
            if ( function_exists('mb_stripos') ) {
                if ( mb_stripos($text, $variety, 0, 'UTF-8') !== false ) {
                    return $variety;
                }
            } elseif ( stripos($text, $variety) !== false ) {
                return $variety;
            }
        }

        return '';
    }

    private static function management_code(
        $post_id,
        $rakuten_title = '',
        $yahoo_variants = array()
    ) {
        $manual = trim((string)get_post_meta(
            $post_id,
            '_nf_management_code',
            true
        ));

        if ( $manual !== '' ) {
            return $manual;
        }

        $sources = array(
            (string)$rakuten_title,
            (string)get_the_title($post_id),
        );

        foreach ( (array)$yahoo_variants as $variant ) {
            if ( ! empty($variant['name']) ) {
                $sources[] = $variant['name'];
            }
        }

        foreach ( $sources as $source ) {
            if (
                preg_match(
                    '/[\[［【]\s*([A-Z]{1,10}[-_]?\d{2,12}|\d{2,12}[-_]\d{1,12})\s*[\]］】]/iu',
                    $source,
                    $m
                )
            ) {
                return strtoupper($m[1]);
            }

            if (
                preg_match(
                    '/(?:管理番号|返礼品番号|商品番号|品番)\s*[:：]?\s*([A-Z0-9_-]{3,24})/iu',
                    $source,
                    $m
                )
            ) {
                return strtoupper($m[1]);
            }
        }

        foreach ( (array)$yahoo_variants as $variant ) {
            if ( empty($variant['code']) ) continue;

            $code = (string)$variant['code'];

            if ( strpos($code, '_') !== false ) {
                $parts = explode('_', $code, 2);
                if ( ! empty($parts[1]) ) {
                    return sanitize_text_field($parts[1]);
                }
            }
        }

        return '';
    }

    private static function best_product_image(
        $post_id,
        $thumbnail_size = 'medium'
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

        $yahoo = self::high_res_image_url(
            get_post_meta(
                $post_id,
                '_nf_yahoo_image_url',
                true
            )
        );

        if ( $yahoo ) {
            return $yahoo;
        }

        return '';
    }

    private static function numeric_price_midpoint( $post_id ) {
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

        if (
            ! $min &&
            ! $max &&
            class_exists('NF_Yahoo')
        ) {
            $prices = array();

            foreach ( NF_Yahoo::public_variants($post_id) as $variant ) {
                if ( ! empty($variant['price']) && intval($variant['price']) > 100 ) {
                    $prices[] = intval($variant['price']);
                }
            }

            if ( $prices ) {
                $min = min($prices);
                $max = max($prices);
            }
        }

        if ( ! $min && ! $max ) {
            return 0;
        }

        if ( ! $min ) $min = $max;
        if ( ! $max ) $max = $min;

        return ($min + $max) / 2;
    }

    private static function weight_midpoint_grams( $post_id ) {
        $capacity = trim((string)get_post_meta(
            $post_id,
            '_nf_capacity',
            true
        ));

        if ( $capacity === '' ) {
            $capacity = self::extract_capacity(
                self::display_title($post_id)
            );
        }

        if (
            ! preg_match_all(
                '/(\d+(?:\.\d+)?)\s*(kg|g)/iu',
                $capacity,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            return 0;
        }

        $values = array();

        foreach ( $matches as $match ) {
            $value = floatval($match[1]);

            if ( strtolower($match[2]) === 'kg' ) {
                $value *= 1000;
            }

            if ( $value > 0 ) {
                $values[] = $value;
            }
        }

        if ( ! $values ) {
            return 0;
        }

        return (min($values) + max($values)) / 2;
    }

    private static function shipping_month_index( $post_id ) {
        $shipping = trim((string)get_post_meta(
            $post_id,
            '_nf_shipping',
            true
        ));

        if ( $shipping === '' ) {
            $shipping = self::extract_shipping(
                (string)get_post_meta(
                    $post_id,
                    '_nf_rakuten_item_name',
                    true
                )
            );
        }

        if (
            preg_match(
                '/(\d{4})年\s*(\d{1,2})月/u',
                $shipping,
                $m
            )
        ) {
            return intval($m[1]) * 12 + intval($m[2]);
        }

        if (
            preg_match(
                '/(?:^|[^\d])(\d{1,2})月/u',
                $shipping,
                $m
            )
        ) {
            $month = intval($m[1]);
            $year = intval(wp_date('Y'));
            $current_month = intval(wp_date('n'));

            // 年表記がない場合、直近の未来月として扱う。
            if ( $month + 1 < $current_month ) {
                $year++;
            }

            return $year * 12 + $month;
        }

        return 0;
    }

    private static function portal_available( $post_id ) {
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
            return
                class_exists('NF_Yahoo') &&
                ! empty(NF_Yahoo::public_variants($post_id));
        }

        return true;
    }

    private static function taxonomy_names( $post_id, $taxonomy ) {
        $terms = wp_get_post_terms(
            $post_id,
            $taxonomy,
            array('fields' => 'names')
        );

        if ( is_wp_error($terms) ) {
            return array();
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $terms)
        )));
    }

    private static function recommendation_score(
        $source_id,
        $candidate_id
    ) {
        $score = 0;

        $source_fruits = self::taxonomy_names(
            $source_id,
            'nf_fruit'
        );
        $candidate_fruits = self::taxonomy_names(
            $candidate_id,
            'nf_fruit'
        );

        $common_fruits = array_intersect(
            $source_fruits,
            $candidate_fruits
        );

        if ( $common_fruits ) {
            $detailed = array_diff(
                $common_fruits,
                array('柑橘','ぶどう')
            );

            $score += $detailed ? 60 : 25;
        }

        $source_muni = self::taxonomy_names(
            $source_id,
            'nf_municipality'
        );
        $candidate_muni = self::taxonomy_names(
            $candidate_id,
            'nf_municipality'
        );

        if (
            array_intersect(
                $source_muni,
                $candidate_muni
            )
        ) {
            $score += 15;
        }

        $source_price = self::numeric_price_midpoint($source_id);
        $candidate_price = self::numeric_price_midpoint($candidate_id);

        if ( $source_price > 0 && $candidate_price > 0 ) {
            $ratio =
                max($source_price, $candidate_price) /
                max(1, min($source_price, $candidate_price));

            if ( $ratio <= 1.10 ) {
                $score += 14;
            } elseif ( $ratio <= 1.25 ) {
                $score += 9;
            } elseif ( $ratio <= 1.50 ) {
                $score += 4;
            }
        }

        $source_weight = self::weight_midpoint_grams($source_id);
        $candidate_weight = self::weight_midpoint_grams($candidate_id);

        if ( $source_weight > 0 && $candidate_weight > 0 ) {
            $ratio =
                max($source_weight, $candidate_weight) /
                max(1, min($source_weight, $candidate_weight));

            if ( $ratio <= 1.15 ) {
                $score += 12;
            } elseif ( $ratio <= 1.35 ) {
                $score += 7;
            } elseif ( $ratio <= 1.70 ) {
                $score += 3;
            }
        }

        $source_shipping = self::shipping_month_index($source_id);
        $candidate_shipping = self::shipping_month_index($candidate_id);

        if ( $source_shipping && $candidate_shipping ) {
            $diff = abs(
                $source_shipping - $candidate_shipping
            );

            if ( $diff <= 1 ) {
                $score += 8;
            } elseif ( $diff <= 3 ) {
                $score += 4;
            }
        }

        $status = self::effective_status($candidate_id);

        if ( $status === '受付終了' ) {
            $score -= 50;
        } elseif ( $status === '先行予約受付中' ) {
            $score += 8;
        } else {
            $score += 10;
        }

        if ( self::best_product_image($candidate_id, 'thumbnail') ) {
            $score += 5;
        }

        if (
            get_post_meta(
                $candidate_id,
                '_nf_recommended',
                true
            ) === '1'
        ) {
            $score += 4;
        }

        return intval($score);
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
                : 'Yahoo!で確認';
        }

        $min = absint(get_post_meta($post_id, '_nf_price_min', true));
        $max = absint(get_post_meta($post_id, '_nf_price_max', true));
        $legacy = absint(get_post_meta($post_id, '_nf_price', true));

        if ( ! $min && $legacy > 100 ) $min = $legacy;
        if ( ! $max && $legacy > 100 ) $max = $legacy;

        if ( $min > 0 && $min <= 100 ) $min = 0;
        if ( $max > 0 && $max <= 100 ) $max = 0;

        if ( ! $min && ! $max ) {
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
                ? '楽天で確認'
                : '各ポータルで確認';
        }

        if ( ! $min ) $min = $max;
        if ( ! $max ) $max = $min;

        if ( $min === $max ) return number_format_i18n($min) . '円';
        return number_format_i18n($min) . '円〜' . number_format_i18n($max) . '円';
    }

    private static function effective_status( $post_id ) {
        $raw = (string)get_post_meta($post_id, '_nf_status', true);

        if ( $raw === '受付終了' ) return '受付終了';

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

        if ( $sale_end !== '' ) {
            try {
                $dt = new DateTimeImmutable($sale_end, wp_timezone());
                if ( $dt->getTimestamp() < current_time('timestamp') ) {
                    return '受付終了';
                }
            } catch ( Exception $e ) {
                // 無視
            }
        }

        return $raw ?: '受付中';
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

    private static function related_products(
        $post_id,
        $municipalities,
        $fruits
    ) {
        $candidate_ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 250,
            'post__not_in' => array($post_id),
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        $ranked = array();

        foreach ( $candidate_ids as $candidate_id ) {
            $candidate_id = intval($candidate_id);

            if ( ! $candidate_id ) {
                continue;
            }

            if ( ! self::portal_available($candidate_id) ) {
                continue;
            }

            $ranked[] = array(
                'id' => $candidate_id,
                'score' => self::recommendation_score(
                    $post_id,
                    $candidate_id
                ),
            );
        }

        usort($ranked, function($a, $b) {
            if ( $a['score'] === $b['score'] ) {
                return $a['id'] <=> $b['id'];
            }

            return $b['score'] <=> $a['score'];
        });

        $selected = array();

        // まず受付中/予約受付中を優先。
        foreach ( $ranked as $row ) {
            if (
                self::effective_status($row['id']) === '受付終了'
            ) {
                continue;
            }

            $selected[] = $row['id'];

            if ( count($selected) >= 4 ) {
                break;
            }
        }

        // 足りない場合だけ受付終了品も補完。
        if ( count($selected) < 4 ) {
            foreach ( $ranked as $row ) {
                if ( in_array($row['id'], $selected, true) ) {
                    continue;
                }

                $selected[] = $row['id'];

                if ( count($selected) >= 4 ) {
                    break;
                }
            }
        }

        if ( ! $selected ) {
            return new WP_Query(array(
                'post_type' => NF_Core::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => 0,
                'post__in' => array(0),
                'no_found_rows' => true,
            ));
        }

        return new WP_Query(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => count($selected),
            'post__in' => $selected,
            'orderby' => 'post__in',
            'no_found_rows' => true,
        ));
    }
}
