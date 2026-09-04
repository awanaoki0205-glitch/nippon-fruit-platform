<?php
if ( ! defined('ABSPATH') ) exit;

/** Privacy-conscious first-party traffic and outbound referral analytics. */
class NF_Analytics {
    const DB_VERSION = '1.1';
    const DB_OPTION = 'nf_analytics_db_version';
    const CLEANUP_HOOK = 'nf_analytics_cleanup';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 40);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_tracking'), 40);
        add_action('wp_ajax_nf_track_analytics', array(__CLASS__, 'track'));
        add_action('wp_ajax_nopriv_nf_track_analytics', array(__CLASS__, 'track'));
        add_action(self::CLEANUP_HOOK, array(__CLASS__, 'cleanup'));
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'nf_analytics_events';
    }

    public static function activate() {
        self::create_table();
        if ( ! wp_next_scheduled(self::CLEANUP_HOOK) ) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    public static function maybe_upgrade() {
        if ( get_option(self::DB_OPTION, '') !== self::DB_VERSION ) self::create_table();
        if ( ! wp_next_scheduled(self::CLEANUP_HOOK) ) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
    }

    private static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            occurred_at datetime NOT NULL,
            event_date date NOT NULL,
            event_type varchar(32) NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            portal varchar(24) NOT NULL DEFAULT '',
            referrer_host varchar(190) NOT NULL DEFAULT '',
            device varchar(16) NOT NULL DEFAULT '',
            keyword varchar(190) NOT NULL DEFAULT '',
            category_slug varchar(190) NOT NULL DEFAULT '',
            municipality_slug varchar(190) NOT NULL DEFAULT '',
            visitor_hash char(64) NOT NULL DEFAULT '',
            session_hash char(64) NOT NULL DEFAULT '',
            event_count bigint(20) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY event_date (event_date),
            KEY event_type (event_type),
            KEY product_id (product_id),
            KEY visitor_hash (visitor_hash),
            KEY session_hash (session_hash)
        ) {$charset};";
        dbDelta($sql);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
        self::backfill_popularity();
    }

    private static function backfill_popularity() {
        if ( get_option('nf_analytics_popularity_backfilled', '') === '1' ) return;
        global $wpdb;
        $ids = get_posts(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true));
        foreach ( $ids as $post_id ) {
            $daily = get_post_meta($post_id, NF_Single::META_POPULARITY_DAILY, true);
            if ( ! is_array($daily) ) continue;
            foreach ( $daily as $day => $counts ) {
                if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day) || ! is_array($counts) ) continue;
                $views = absint($counts['views'] ?? 0);
                $clicks = absint($counts['clicks'] ?? 0);
                $rakuten = min($clicks, absint($counts['rakutenClicks'] ?? 0));
                $yahoo = min(max(0,$clicks-$rakuten), absint($counts['yahooClicks'] ?? 0));
                $other = max(0, $clicks - $rakuten - $yahoo);
                if ( $views ) self::insert_legacy($day, 'detail_view', $post_id, '', $views);
                if ( $rakuten ) self::insert_legacy($day, 'outbound_click', $post_id, 'rakuten', $rakuten);
                if ( $yahoo ) self::insert_legacy($day, 'outbound_click', $post_id, 'yahoo', $yahoo);
                if ( $other ) self::insert_legacy($day, 'outbound_click', $post_id, 'other', $other);
            }
        }
        update_option('nf_analytics_popularity_backfilled', '1', false);
    }

    private static function insert_legacy($day, $event, $post_id, $portal, $count) {
        global $wpdb;
        $wpdb->insert(self::table(), array(
            'occurred_at'=>$day.' 12:00:00','event_date'=>$day,'event_type'=>$event,'product_id'=>absint($post_id),
            'portal'=>sanitize_key($portal),'referrer_host'=>'','device'=>'legacy','keyword'=>'','category_slug'=>'','municipality_slug'=>'',
            'visitor_hash'=>'','session_hash'=>'','event_count'=>absint($count),
        ), array('%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d'));
    }

    public static function enqueue_tracking() {
        $is_catalog = is_post_type_archive(NF_Core::POST_TYPE) || (class_exists('NF_System_Page') && NF_System_Page::is_system_page());
        $is_product = is_singular(NF_Core::POST_TYPE);
        if ( ! $is_catalog && ! $is_product ) return;

        wp_enqueue_script('nf-analytics', NF_PLUGIN_URL . 'assets/analytics.js', array(), NF_VERSION, true);
        wp_localize_script('nf-analytics', 'NF_ANALYTICS', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => 'nf_track_analytics',
            'pageType' => $is_product ? 'product' : 'catalog',
            'postId' => $is_product ? absint(get_queried_object_id()) : 0,
            'enabled' => current_user_can('manage_options') ? 0 : 1,
        ));
    }

    private static function client_hash($value) {
        $value = substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)$value), 0, 100);
        return $value === '' ? '' : hash_hmac('sha256', $value, wp_salt('auth'));
    }

    private static function referrer_host($value) {
        $host = wp_parse_url(esc_url_raw((string)$value), PHP_URL_HOST);
        $host = strtolower((string)$host);
        $own = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        return $host === $own ? 'internal' : sanitize_text_field(substr($host, 0, 190));
    }

    public static function track() {
        if ( current_user_can('manage_options') ) wp_send_json_success(array('ignored'=>true));
        $event = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
        $allowed = array('catalog_view','product_impression','detail_view','outbound_click','filter_use');
        if ( ! in_array($event, $allowed, true) ) wp_send_json_error(array('message'=>'invalid_event'), 400);

        $visitor = self::client_hash(isset($_POST['visitor_id']) ? wp_unslash($_POST['visitor_id']) : '');
        $session = self::client_hash(isset($_POST['session_id']) ? wp_unslash($_POST['session_id']) : '');
        if ( $visitor === '' || $session === '' ) wp_send_json_error(array('message'=>'missing_client'), 400);

        $ids = array(0);
        if ( $event === 'product_impression' ) {
            $raw_ids = isset($_POST['product_ids']) ? explode(',', sanitize_text_field(wp_unslash($_POST['product_ids']))) : array();
            $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $raw_ids)))), 0, 30);
        } elseif ( in_array($event, array('detail_view','outbound_click'), true) ) {
            $ids = array(absint(isset($_POST['post_id']) ? $_POST['post_id'] : 0));
        }
        if ( ! $ids ) wp_send_json_error(array('message'=>'missing_product'), 400);

        global $wpdb;
        $table = self::table();
        $keyword = sanitize_text_field(isset($_POST['keyword']) ? wp_unslash($_POST['keyword']) : '');
        if ( function_exists('mb_substr') ) $keyword = mb_substr($keyword, 0, 100, 'UTF-8'); else $keyword = substr($keyword, 0, 190);
        $portal = substr(sanitize_key(isset($_POST['portal']) ? wp_unslash($_POST['portal']) : ''), 0, 24);
        $category = substr(sanitize_title(isset($_POST['category']) ? wp_unslash($_POST['category']) : ''), 0, 190);
        $municipality = substr(sanitize_title(isset($_POST['municipality']) ? wp_unslash($_POST['municipality']) : ''), 0, 190);
        $common = array(
            'occurred_at' => current_time('mysql'),
            'event_date' => wp_date('Y-m-d'),
            'event_type' => $event,
            'portal' => $portal,
            'referrer_host' => self::referrer_host(isset($_POST['referrer']) ? wp_unslash($_POST['referrer']) : ''),
            'device' => in_array((isset($_POST['device']) ? sanitize_key(wp_unslash($_POST['device'])) : ''), array('mobile','tablet','desktop'), true) ? sanitize_key(wp_unslash($_POST['device'])) : 'other',
            'keyword' => $keyword,
            'category_slug' => $category,
            'municipality_slug' => $municipality,
            'visitor_hash' => $visitor,
            'session_hash' => $session,
        );

        $recorded = 0;
        foreach ( $ids as $post_id ) {
            if ( $post_id && (get_post_type($post_id) !== NF_Core::POST_TYPE || get_post_status($post_id) !== 'publish') ) continue;
            $dedupe = 'nf_an_' . md5($session . '|' . $event . '|' . $post_id . '|' . $common['portal'] . '|' . $common['keyword'] . '|' . $common['category_slug'] . '|' . $common['municipality_slug']);
            if ( get_transient($dedupe) ) continue;
            set_transient($dedupe, 1, 6 * HOUR_IN_SECONDS);
            $row = array_merge($common, array('product_id'=>$post_id,'event_count'=>1));
            if ( $wpdb->insert($table, $row, array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d')) ) $recorded++;
        }
        wp_send_json_success(array('recorded'=>$recorded));
    }

    public static function cleanup() {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE event_date < %s', wp_date('Y-m-d', strtotime('-400 days'))));
    }

    private static function scalar($sql, $args = array()) {
        global $wpdb;
        return (int)$wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    private static function percent($a, $b) {
        return $b > 0 ? round(($a / $b) * 100, 1) : 0;
    }

    public static function render_dashboard() {
        if ( ! current_user_can('nf_view_analytics') ) wp_die('権限がありません。');
        if ( class_exists('NF_Commercial_Config') && ! NF_Commercial_Config::feature('feature_basic_analytics') ) wp_die('現在の契約プランでは分析機能を利用できません。');
        self::maybe_upgrade();
        global $wpdb;
        $table = self::table();
        $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
        if ( ! in_array($days, array(7,30,90), true) ) $days = 30;
        $start = wp_date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $where = $wpdb->prepare('event_date >= %s', $start);

        $catalog_views = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='catalog_view'");
        $impressions = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='product_impression'");
        $details = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='detail_view'");
        $clicks = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='outbound_click'");
        $visitors = self::scalar("SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE {$where} AND visitor_hash<>''");
        $sessions = self::scalar("SELECT COUNT(DISTINCT session_hash) FROM {$table} WHERE {$where} AND session_hash<>''");
        $advanced = ! class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_advanced_analytics');
        $product_detail = ! class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_product_analytics');

        $trend = $wpdb->get_results("SELECT event_date,
            SUM((event_type='product_impression')*event_count) impressions,
            SUM((event_type='detail_view')*event_count) details,
            SUM((event_type='outbound_click')*event_count) clicks
            FROM {$table} WHERE {$where} GROUP BY event_date ORDER BY event_date ASC", ARRAY_A);
        $max_trend = 1;
        foreach ( $trend as $row ) $max_trend = max($max_trend, (int)$row['impressions'], (int)$row['details'], (int)$row['clicks']);

        $products = array();
        if ( $product_detail ) {
            $products = $wpdb->get_results("SELECT product_id,
                SUM((event_type='product_impression')*event_count) impressions,
                SUM((event_type='detail_view')*event_count) details,
                SUM((event_type='outbound_click')*event_count) clicks
                FROM {$table} WHERE {$where} AND product_id > 0 GROUP BY product_id
                ORDER BY clicks DESC, details DESC, impressions DESC LIMIT 15", ARRAY_A);
        }
        $sources = $advanced ? $wpdb->get_results("SELECT IF(referrer_host='', 'direct', referrer_host) label, SUM(event_count) total FROM {$table} WHERE {$where} AND event_type='catalog_view' GROUP BY label ORDER BY total DESC LIMIT 10", ARRAY_A) : array();
        $devices = $advanced ? $wpdb->get_results("SELECT device label, SUM(event_count) total FROM {$table} WHERE {$where} AND event_type='catalog_view' GROUP BY device ORDER BY total DESC", ARRAY_A) : array();
        $portals = $wpdb->get_results("SELECT IF(portal='', 'other', portal) label, SUM(event_count) total FROM {$table} WHERE {$where} AND event_type='outbound_click' GROUP BY label ORDER BY total DESC", ARRAY_A);
        $filters = $advanced ? $wpdb->get_results("SELECT keyword, category_slug, municipality_slug, SUM(event_count) total FROM {$table} WHERE {$where} AND event_type='filter_use' GROUP BY keyword,category_slug,municipality_slug ORDER BY total DESC LIMIT 12", ARRAY_A) : array();
        ?>
        <div class="wrap nf-analytics-admin">
          <div class="nf-analytics-heading"><div><span>TRAFFIC &amp; REFERRAL</span><h1>アクセス・送客分析</h1><p>公開サイトへの流入から返礼品表示、詳細閲覧、楽天・Yahoo!への送客まで確認できます。</p></div><form method="get"><input type="hidden" name="page" value="nf-customer-analytics"><select name="days" onchange="this.form.submit()"><option value="7" <?php selected($days,7); ?>>直近7日</option><option value="30" <?php selected($days,30); ?>>直近30日</option><option value="90" <?php selected($days,90); ?>>直近90日</option></select></form></div>
          <div class="nf-analytics-cards">
            <?php foreach (array('利用者'=>$visitors.'人','セッション'=>$sessions.'回','一覧表示'=>$catalog_views.'回','商品インプレッション'=>$impressions.'回','詳細閲覧'=>$details.'回','送客クリック'=>$clicks.'回','詳細閲覧率'=>self::percent($details,$impressions).'%','送客率'=>self::percent($clicks,$details).'%') as $label=>$value): ?>
              <div class="nf-analytics-card"><small><?php echo esc_html($label); ?></small><strong><?php echo esc_html($value); ?></strong></div>
            <?php endforeach; ?>
          </div>

          <section class="nf-analytics-panel"><h2>日別推移</h2><div class="nf-trend-legend"><span class="is-impression">商品表示</span><span class="is-detail">詳細閲覧</span><span class="is-click">送客</span></div><div class="nf-trend">
          <?php if(!$trend): ?><p>計測データはまだありません。</p><?php else: foreach($trend as $row): ?>
            <div class="nf-trend-row"><time><?php echo esc_html(substr($row['event_date'],5)); ?></time><div class="nf-trend-bars"><i class="is-impression" style="width:<?php echo esc_attr(max(1,round($row['impressions']/$max_trend*100))); ?>%" title="表示 <?php echo intval($row['impressions']); ?>"></i><i class="is-detail" style="width:<?php echo esc_attr(max(1,round($row['details']/$max_trend*100))); ?>%" title="詳細 <?php echo intval($row['details']); ?>"></i><i class="is-click" style="width:<?php echo esc_attr(max(1,round($row['clicks']/$max_trend*100))); ?>%" title="送客 <?php echo intval($row['clicks']); ?>"></i></div><b><?php echo intval($row['impressions']); ?> / <?php echo intval($row['details']); ?> / <?php echo intval($row['clicks']); ?></b></div>
          <?php endforeach; endif; ?></div></section>

          <div class="nf-analytics-grid">
            <section class="nf-analytics-panel"><h2>送客先</h2><?php self::render_rank($portals); ?></section>
            <?php if($advanced): ?><section class="nf-analytics-panel"><h2>流入元</h2><?php self::render_rank($sources); ?></section><section class="nf-analytics-panel"><h2>デバイス</h2><?php self::render_rank($devices); ?></section><?php else: ?><section class="nf-analytics-panel is-locked"><h2>流入元・デバイス</h2><p>Growthプランで利用できます。</p></section><?php endif; ?>
          </div>

          <?php if($product_detail): ?><section class="nf-analytics-panel"><h2>返礼品別の表示・送客</h2><div class="nf-table-scroll"><table class="widefat striped"><thead><tr><th>返礼品</th><th>インプレッション</th><th>詳細閲覧</th><th>送客</th><th>送客率</th><th>状態</th></tr></thead><tbody><?php foreach($products as $row): $rate=self::percent((int)$row['clicks'],(int)$row['details']); $product_url=current_user_can('edit_post',(int)$row['product_id'])?get_edit_post_link((int)$row['product_id']):get_permalink((int)$row['product_id']); ?><tr><td><a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html(get_the_title((int)$row['product_id'])); ?></a></td><td><?php echo intval($row['impressions']); ?></td><td><?php echo intval($row['details']); ?></td><td><?php echo intval($row['clicks']); ?></td><td><?php echo esc_html($rate); ?>%</td><td><?php echo ((int)$row['impressions']>=20 && (int)$row['clicks']===0)?'<span class="nf-needs-work">改善候補</span>':'—'; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php else: ?><section class="nf-analytics-panel is-locked"><h2>返礼品別分析</h2><p>Standard以上のプランで利用できます。</p></section><?php endif; ?>

          <?php if($advanced): ?><section class="nf-analytics-panel"><h2>検索・絞り込みの利用</h2><table class="widefat striped"><thead><tr><th>キーワード</th><th>カテゴリ</th><th>自治体</th><th>利用回数</th></tr></thead><tbody><?php foreach($filters as $row): ?><tr><td><?php echo esc_html($row['keyword'] ?: '—'); ?></td><td><?php echo esc_html($row['category_slug'] ?: '—'); ?></td><td><?php echo esc_html($row['municipality_slug'] ?: '—'); ?></td><td><?php echo intval($row['total']); ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
          <p class="nf-analytics-note">「送客クリック」は楽天・Yahoo!等へのリンクが押された回数です。ポータル側の寄附件数を表すものではありません。IPアドレス・氏名・メールアドレスは保存しません。</p>
        </div>
        <style>
        .nf-analytics-admin{max-width:1380px}.nf-analytics-heading{display:flex;justify-content:space-between;align-items:end;gap:20px;padding:26px 28px;border-radius:18px;background:linear-gradient(135deg,#155f36,#259447);color:#fff}.nf-analytics-heading span{font-size:12px;font-weight:800;letter-spacing:.12em;opacity:.78}.nf-analytics-heading h1{margin:5px 0 6px;color:#fff}.nf-analytics-heading p{margin:0;opacity:.88}.nf-analytics-heading select{min-width:130px}.nf-analytics-cards{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:13px;margin:18px 0}.nf-analytics-card,.nf-analytics-panel{border:1px solid #dce4df;border-radius:14px;background:#fff;box-shadow:0 5px 20px rgba(26,68,43,.05)}.nf-analytics-card{padding:18px}.nf-analytics-card small{display:block;color:#657168;font-weight:700}.nf-analytics-card strong{display:block;margin-top:6px;color:#153f27;font-size:27px}.nf-analytics-panel{margin:16px 0;padding:22px}.nf-analytics-panel h2{margin-top:0}.nf-analytics-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.nf-rank-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:10px 0;border-bottom:1px solid #edf0ee}.nf-rank-row:last-child{border-bottom:0}.nf-rank-row b{text-transform:capitalize}.nf-trend-legend{display:flex;gap:18px;margin-bottom:14px;font-size:12px;font-weight:700}.nf-trend-legend span:before{content:'';display:inline-block;width:10px;height:10px;margin-right:6px;border-radius:2px;background:#8dbda0}.nf-trend-legend .is-detail:before{background:#4b83c3}.nf-trend-legend .is-click:before{background:#e09a2d}.nf-trend-row{display:grid;grid-template-columns:52px minmax(200px,1fr) 125px;gap:12px;align-items:center;margin:8px 0}.nf-trend-bars{display:grid;gap:3px}.nf-trend-bars i{display:block;height:5px;border-radius:9px;background:#8dbda0}.nf-trend-bars .is-detail{background:#4b83c3}.nf-trend-bars .is-click{background:#e09a2d}.nf-needs-work{display:inline-block;padding:4px 8px;border-radius:99px;background:#fff1d5;color:#8a5700;font-weight:700}.nf-analytics-panel.is-locked{background:#f7f8f7;color:#667069}.nf-analytics-note{padding:14px 16px;border-radius:10px;background:#eef6f0;color:#385844}.nf-table-scroll{overflow:auto}@media(max-width:900px){.nf-analytics-cards{grid-template-columns:repeat(2,1fr)}.nf-analytics-grid{grid-template-columns:1fr}.nf-analytics-heading{align-items:start;flex-direction:column}.nf-trend-row{grid-template-columns:46px minmax(120px,1fr)}}
        </style>
        <?php
    }

    private static function render_rank($rows) {
        if ( ! $rows ) { echo '<p>データはまだありません。</p>'; return; }
        foreach ( $rows as $row ) echo '<div class="nf-rank-row"><span>' . esc_html($row['label'] ?: 'その他') . '</span><b>' . intval($row['total']) . '件</b></div>';
    }
}
