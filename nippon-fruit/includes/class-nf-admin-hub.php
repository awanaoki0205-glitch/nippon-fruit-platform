<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Admin_Hub {

    const IMPORT_SLUG = 'nf-product-import';
    const MAINT_SLUG  = 'nf-sync-maintenance';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 50);
        add_action('admin_menu', array(__CLASS__, 'simplify_menu'), 999);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function admin_menu() {
        $parent = 'edit.php?post_type=' . NF_Core::POST_TYPE;

        add_submenu_page(
            $parent,
            '登録・取込',
            '登録・取込',
            'manage_options',
            self::IMPORT_SLUG,
            array(__CLASS__, 'import_page')
        );

        add_submenu_page(
            $parent,
            '同期・メンテナンス',
            '同期・メンテナンス',
            'manage_options',
            self::MAINT_SLUG,
            array(__CLASS__, 'maintenance_page')
        );
    }

    public static function simplify_menu() {
        $parent = 'edit.php?post_type=' . NF_Core::POST_TYPE;

        // 日常運用で直接触らないページは左メニューから隠し、
        // 統合画面の「詳細設定」リンクからアクセスできるようにする。
        foreach ( array(
            'nippon-fruit-settings',
            'nippon-fruit-csv',
            'nippon-fruit-rakuten-bulk',
            'nippon-fruit-discovery',
            'nippon-fruit-auto-sync',
            'nippon-fruit-yahoo',
        ) as $slug ) {
            remove_submenu_page($parent, $slug);
        }

        // 表示名を初心者向けに統一。
        global $submenu;

        if ( empty($submenu[$parent]) ) {
            return;
        }

        foreach ( $submenu[$parent] as &$item ) {
            if ( isset($item[2]) && $item[2] === 'edit.php?post_type=' . NF_Core::POST_TYPE ) {
                $item[0] = '返礼品一覧';
            }
            if ( isset($item[2]) && $item[2] === 'nf-display-settings' ) {
                $item[0] = 'サイト設定';
            }
        }
    }

    public static function assets() {
        if (
            empty($_GET['page']) ||
            ! in_array(
                sanitize_key($_GET['page']),
                array(self::IMPORT_SLUG, self::MAINT_SLUG, NF_Quality::PAGE_SLUG, NF_Classification_Admin::PAGE_SLUG),
                true
            )
        ) {
            return;
        }

        wp_enqueue_style(
            'nf-admin-hub',
            NF_PLUGIN_URL . 'assets/admin-hub.css',
            array(),
            NF_VERSION
        );
    }

    private static function page_url( $slug ) {
        return admin_url(
            'edit.php?post_type=' .
            NF_Core::POST_TYPE .
            '&page=' .
            $slug
        );
    }

    public static function import_page() {
        if ( ! current_user_can('manage_options') ) return;
        ?>
        <div class="wrap nf-admin-hub">
          <h1>返礼品・自治体の登録</h1>
          <p class="nf-hub-lead">自治体は手動で階層登録し、返礼品は自動同期・候補発見・自動分類で登録します。</p>

          <div class="nf-hub-cards">
            <a class="nf-hub-card is-primary" href="<?php echo esc_url(self::page_url(NF_Quality::PAGE_SLUG)); ?>">
              <span>品質管理</span>
              <h2>要確認商品をチェック</h2>
              <p>自動分類の確信度が低い商品を確認し、正しいカテゴリを手動確定します。</p>
              <b>品質チェック →</b>
            </a>
            <a class="nf-hub-card is-primary" href="<?php echo esc_url(self::page_url('nippon-fruit-auto-sync')); ?>">
              <span>返礼品</span>
              <h2>返礼品を自動登録</h2>
              <p>定期同期、候補発見、自動公開、自動分類の状態と実行結果を確認します。</p>
              <b>自動登録設定 →</b>
            </a>

            <a class="nf-hub-card is-primary" href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=nf_municipality&post_type=' . NF_Core::POST_TYPE)); ?>">
              <span>自治体</span>
              <h2>自治体を手動登録</h2>
              <p>都道府県を親、市区町村を子として登録し、公開用の階層を作ります。</p>
              <b>自治体一覧 →</b>
            </a>

            <a class="nf-hub-card" href="<?php echo esc_url(self::page_url('nippon-fruit-discovery')); ?>">
              <span>候補発見</span>
              <h2>返礼品候補を確認</h2>
              <p>楽天上の商品候補を検索し、既存商品との照合や自動分類を行います。</p>
              <b>開く →</b>
            </a>

            <a class="nf-hub-card" href="<?php echo esc_url(self::page_url('nippon-fruit-rakuten-bulk')); ?>">
              <span>楽天</span>
              <h2>楽天商品を一括取込</h2>
              <p>楽天の商品URLや商品データをまとめて取り込みます。</p>
              <b>開く →</b>
            </a>

            <a class="nf-hub-card" href="<?php echo esc_url(self::page_url('nippon-fruit-csv')); ?>">
              <span>CSV</span>
              <h2>CSV一括登録</h2>
              <p>CSVファイルから返礼品をまとめて登録・更新します。</p>
              <b>開く →</b>
            </a>
          </div>
        </div>
        <?php
    }

    public static function maintenance_page() {
        if ( ! current_user_can('manage_options') ) return;

        $next = wp_next_scheduled(NF_Auto_Sync::CRON_HOOK);
        $next_text = $next
            ? wp_date('Y年n月j日 H:i', $next)
            : '未予約';

        $rakuten_last = get_option(
            NF_Auto_Sync::OPTION_LAST_RUN,
            ''
        );

        $yahoo_last = get_option(
            NF_Yahoo::OPTION_LAST_SYNC,
            ''
        );

        $yahoo_missing = method_exists('NF_Yahoo', 'admin_missing_image_count')
            ? NF_Yahoo::admin_missing_image_count()
            : 0;

        $audit = get_option(
            NF_Yahoo::OPTION_LAST_AUDIT,
            array()
        );

        $review_count = is_array($audit) && isset($audit['review'])
            ? intval($audit['review'])
            : 0;
        ?>
        <div class="wrap nf-admin-hub">
          <h1>同期・メンテナンス</h1>
          <p class="nf-hub-lead">
            楽天・Yahoo!の商品情報は1時間ごとの分割バッチで更新します。
            画像、商品説明、寄附額、受付状況、レビュー、規格を順次同期します。
          </p>

          <div class="nf-status-grid">
            <div class="nf-status-card">
              <span>楽天 最終同期</span>
              <strong><?php echo esc_html($rakuten_last ?: 'まだありません'); ?></strong>
            </div>
            <div class="nf-status-card">
              <span>Yahoo! 最終同期</span>
              <strong><?php echo esc_html($yahoo_last ?: 'まだありません'); ?></strong>
            </div>
            <div class="nf-status-card <?php echo $yahoo_missing ? 'is-warning' : 'is-ok'; ?>">
              <span>Yahoo!画像未取得</span>
              <strong><?php echo intval($yahoo_missing); ?>件</strong>
            </div>
            <div class="nf-status-card <?php echo $review_count ? 'is-warning' : 'is-ok'; ?>">
              <span>要確認</span>
              <strong><?php echo intval($review_count); ?>件</strong>
            </div>
            <div class="nf-status-card">
              <span>次回自動同期</span>
              <strong><?php echo esc_html($next_text); ?></strong>
            </div>
          </div>

          <div class="nf-hub-cards">
            <a class="nf-hub-card is-primary" href="<?php echo esc_url(self::page_url('nippon-fruit-auto-sync')); ?>">
              <span>楽天 + 自動探索</span>
              <h2>自動同期の状態・手動実行</h2>
              <p>同期のON/OFF、バッチ数、手動同期、新商品探索の状態を確認します。</p>
              <b>開く →</b>
            </a>

            <a class="nf-hub-card" href="<?php echo esc_url(self::page_url('nippon-fruit-yahoo')); ?>">
              <span>Yahoo!</span>
              <h2>Yahoo!同期・画像修復・監査</h2>
              <p>Yahoo!画像欠損、誤紐付け監査、検索ルートなどの詳細メンテナンスです。</p>
              <b>開く →</b>
            </a>

            <a class="nf-hub-card" href="<?php echo esc_url(self::page_url('nf-display-settings')); ?>">
              <span>公開サイト</span>
              <h2>サイト設定</h2>
              <p>表示ポータル、価格、色、レイアウト、ロゴ、自社コンテンツを設定します。</p>
              <b>開く →</b>
            </a>
          </div>
        </div>
        <?php
    }
}
