<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Furusato_Header {

    public static function init() {
        add_action(
            'wp_enqueue_scripts',
            array(__CLASS__, 'enqueue_assets'),
            1
        );

        add_action(
            'wp_body_open',
            array(__CLASS__, 'render_header'),
            1
        );

        add_filter(
            'body_class',
            array(__CLASS__, 'body_class')
        );
    }

    public static function is_furusato_context() {
        $catalog_context =
            is_post_type_archive(NF_Core::POST_TYPE) ||
            (class_exists('NF_System_Page') && NF_System_Page::is_system_page()) ||
            is_singular(NF_Core::POST_TYPE) ||
            is_tax('nf_municipality') ||
            is_tax('nf_fruit') ||
            is_tax('nf_category');

        if ($catalog_context) return true;

        // /furusato/ 配下に作成した案内用固定ページでも、専用ヘッダーを継続する。
        // 管理画面・404・他の通常固定ページには影響させない。
        if ( ! is_page() || is_admin() ) return false;

        $request_path = isset($_SERVER['REQUEST_URI'])
            ? (string)wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
            : '';
        $archive_path = (string)wp_parse_url(self::archive_url(), PHP_URL_PATH);
        $request_path = '/' . trim(rawurldecode($request_path), '/');
        $archive_path = '/' . trim(rawurldecode($archive_path), '/');

        if ($archive_path === '/' || $request_path === $archive_path) return false;
        return strpos($request_path . '/', trailingslashit($archive_path)) === 0;
    }

    public static function body_class( $classes ) {
        if ( self::is_furusato_context() ) {
            $classes[] = 'nf-furusato-shell';
        }

        return $classes;
    }

    public static function enqueue_assets() {
        if ( ! self::is_furusato_context() ) {
            return;
        }

        wp_enqueue_style(
            'nippon-fruit-furusato-header',
            NF_PLUGIN_URL . 'assets/furusato-header.css',
            array(),
            NF_VERSION
        );

        wp_enqueue_script(
            'nippon-fruit-furusato-header',
            NF_PLUGIN_URL . 'assets/furusato-header.js',
            array(),
            NF_VERSION,
            true
        );
    }

    private static function archive_url() {
        return class_exists('NF_System_Page')
            ? NF_System_Page::url()
            : (get_post_type_archive_link(NF_Core::POST_TYPE) ?: home_url('/furusato/'));
    }

    private static function nav_target($url) {
        $fragment = (string)wp_parse_url($url, PHP_URL_FRAGMENT);
        return preg_match('/^nf_catalog_[a-z0-9_]+$/', $fragment) ? $fragment : '';
    }

    public static function render_header() {
        if ( ! self::is_furusato_context() ) {
            return;
        }

        $archive = self::archive_url();
        $nav_items = class_exists('NF_Settings')
            ? NF_Settings::header_custom_nav_items($archive)
            : array();
        $brand_name = class_exists('NF_Settings') ? NF_Settings::brand_name() : get_bloginfo('name');
        $company_name = class_exists('NF_Settings') ? NF_Settings::company_name() : get_bloginfo('name');
        $site_label = class_exists('NF_Settings') ? NF_Settings::site_label() : 'ふるさと納税';
        $search_keyword = isset($_GET['q'])
            ? sanitize_text_field(wp_unslash($_GET['q']))
            : '';

        $logo_url = '';

        $custom_logo_id = class_exists('NF_Settings')
            ? NF_Settings::header_logo_id()
            : 0;

        if ( ! $custom_logo_id ) {
            $custom_logo_id = get_theme_mod('custom_logo');
        }

        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url(
                $custom_logo_id,
                'medium'
            );
        }
        ?>
        <header class="nf-furusato-header" id="nf_furusato_header">
          <div class="nf-furusato-header__inner">
            <a
              class="nf-furusato-brand"
              href="<?php echo esc_url($archive); ?>"
              aria-label="<?php echo esc_attr($brand_name . ' ' . $site_label . 'トップ'); ?>"
            >
              <?php if ( $logo_url ) : ?>
                <img
                  src="<?php echo esc_url($logo_url); ?>"
                  alt="<?php echo esc_attr($brand_name); ?>"
                  class="nf-furusato-brand__logo"
                >
              <?php else : ?>
                <strong class="nf-furusato-brand__text">
                  <?php echo esc_html($brand_name); ?>
                </strong>
              <?php endif; ?>

              <span class="nf-furusato-brand__sub">
                <?php echo esc_html($site_label); ?>
              </span>
            </a>

            <form
              class="nf-furusato-header-search"
              action="<?php echo esc_url($archive); ?>"
              method="get"
              role="search"
            >
              <label class="screen-reader-text" for="nf_furusato_header_keyword">返礼品を検索</label>
              <input
                type="search"
                id="nf_furusato_header_keyword"
                name="q"
                placeholder="返礼品のキーワードから探す"
                autocomplete="off"
                value="<?php echo esc_attr($search_keyword); ?>"
              >
              <button type="submit" aria-label="返礼品を検索">検索</button>
              <button
                type="button"
                id="nf_furusato_refine_button"
                class="nf-furusato-refine-button"
                aria-controls="nf_catalog_filter_body"
                aria-expanded="false"
                hidden
              >絞り込み</button>
            </form>

            <nav
              class="nf-furusato-desktop-nav"
              aria-label="ふるさと納税メニュー"
            >
              <?php foreach ($nav_items as $item) : ?>
                <a href="<?php echo esc_url($item['url']); ?>"<?php $target = self::nav_target($item['url']); echo $target ? ' data-nf-target="' . esc_attr($target) . '"' : ''; ?>><?php echo esc_html($item['label']); ?></a>
              <?php endforeach; ?>
            </nav>

            <?php if ($nav_items) : ?>
              <button type="button" class="nf-furusato-menu-button" id="nf_furusato_menu_button" aria-controls="nf_furusato_mobile_menu" aria-expanded="false">
                <span></span><span></span><span></span><b>メニュー</b>
              </button>
            <?php endif; ?>

          </div>
          <?php if ($nav_items) : ?>
            <nav class="nf-furusato-mobile-menu" id="nf_furusato_mobile_menu" aria-label="スマホメニュー" hidden>
              <?php foreach ($nav_items as $item) : ?>
                <a href="<?php echo esc_url($item['url']); ?>"<?php $target = self::nav_target($item['url']); echo $target ? ' data-nf-target="' . esc_attr($target) . '"' : ''; ?>><?php echo esc_html($item['label']); ?></a>
              <?php endforeach; ?>
            </nav>
          <?php endif; ?>
        </header>

        <?php
    }
}
