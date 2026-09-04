<?php
if ( ! defined('ABSPATH') ) exit;

/** Privacy-conscious first-party traffic and outbound referral analytics. */
class NF_Analytics {
    const DB_VERSION = '1.2';
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

    public static function summary_table() {
        global $wpdb;
        return $wpdb->prefix . 'nf_analytics_summary';
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
        $summary = self::summary_table();
        $summary_sql = "CREATE TABLE {$summary} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            period_start date NOT NULL,
            granularity varchar(8) NOT NULL,
            event_type varchar(32) NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            portal varchar(24) NOT NULL DEFAULT '',
            referrer_host varchar(190) NOT NULL DEFAULT '',
            device varchar(16) NOT NULL DEFAULT '',
            keyword varchar(190) NOT NULL DEFAULT '',
            category_slug varchar(190) NOT NULL DEFAULT '',
            municipality_slug varchar(190) NOT NULL DEFAULT '',
            event_count bigint(20) unsigned NOT NULL DEFAULT 0,
            visitor_count bigint(20) unsigned NOT NULL DEFAULT 0,
            session_count bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY period_granularity (period_start, granularity),
            KEY event_type (event_type),
            KEY product_id (product_id)
        ) {$charset};";
        dbDelta($summary_sql);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
        self::backfill_popularity();
        self::refresh_all_summaries();
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
        self::refresh_recent_summaries();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE event_date < %s', wp_date('Y-m-d', strtotime('-400 days'))));
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::summary_table() . " WHERE granularity='day' AND period_start < %s", wp_date('Y-m-d', strtotime('-5 years'))));
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::summary_table() . " WHERE granularity='month' AND period_start < %s", wp_date('Y-m-01', strtotime('-10 years'))));
    }

    private static function aggregate_period($granularity, $start, $end) {
        global $wpdb;
        $events = self::table();
        $summary = self::summary_table();
        $period_sql = $granularity === 'month' ? "DATE_FORMAT(event_date, '%%Y-%%m-01')" : 'event_date';
        $wpdb->query($wpdb->prepare("DELETE FROM {$summary} WHERE granularity=%s AND period_start BETWEEN %s AND %s", $granularity, $start, $end));
        $wpdb->query($wpdb->prepare("INSERT INTO {$summary}
            (period_start,granularity,event_type,product_id,portal,referrer_host,device,keyword,category_slug,municipality_slug,event_count,visitor_count,session_count)
            SELECT {$period_sql}, %s, event_type, product_id, portal, referrer_host, device, keyword, category_slug, municipality_slug,
                   SUM(event_count), COUNT(DISTINCT NULLIF(visitor_hash,'')), COUNT(DISTINCT NULLIF(session_hash,''))
            FROM {$events} WHERE event_date BETWEEN %s AND %s
            GROUP BY {$period_sql},event_type,product_id,portal,referrer_host,device,keyword,category_slug,municipality_slug",
            $granularity, $start, $end));
    }

    private static function refresh_all_summaries() {
        global $wpdb;
        $bounds = $wpdb->get_row('SELECT MIN(event_date) first_day, MAX(event_date) last_day FROM ' . self::table(), ARRAY_A);
        if ( empty($bounds['first_day']) || empty($bounds['last_day']) ) return;
        self::aggregate_period('day', max($bounds['first_day'], wp_date('Y-m-d', strtotime('-5 years'))), $bounds['last_day']);
        self::aggregate_period('month', max(substr($bounds['first_day'],0,7).'-01', wp_date('Y-m-01', strtotime('-10 years'))), $bounds['last_day']);
    }

    private static function refresh_recent_summaries() {
        $today = wp_date('Y-m-d');
        self::aggregate_period('day', wp_date('Y-m-d', strtotime('-2 days')), $today);
        self::aggregate_period('month', wp_date('Y-m-01', strtotime('-1 month')), $today);
    }

    private static function scalar($sql, $args = array()) {
        global $wpdb;
        return (int)$wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    private static function percent($a, $b) {
        return $b > 0 ? round(($a / $b) * 100, 1) : 0;
    }

    private static function ranges() {
        return array(
            '7d'=>array('label'=>'直近7日','days'=>7,'summary'=>false),
            '30d'=>array('label'=>'直近30日','days'=>30,'summary'=>false),
            '90d'=>array('label'=>'直近90日','days'=>90,'summary'=>false),
            '1y'=>array('label'=>'直近1年','days'=>365,'summary'=>false),
            '5y'=>array('label'=>'直近5年','days'=>1826,'summary'=>true),
            '10y'=>array('label'=>'直近10年','days'=>3652,'summary'=>true),
        );
    }

    private static function date_window($days, $offset = 0) {
        $end_offset = max(0, $offset);
        return array(
            'start'=>wp_date('Y-m-d', strtotime('-' . ($days + $end_offset - 1) . ' days')),
            'end'=>wp_date('Y-m-d', strtotime('-' . $end_offset . ' days')),
        );
    }

    private static function total_snapshot($window, $summary_granularity = false) {
        global $wpdb;
        $table = $summary_granularity ? self::summary_table() : self::table();
        $date_col = $summary_granularity ? 'period_start' : 'event_date';
        $extra = $summary_granularity ? $wpdb->prepare(' AND granularity=%s', $summary_granularity) : '';
        $where = $wpdb->prepare("{$date_col} BETWEEN %s AND %s{$extra}", $window['start'], $window['end']);
        $row = $wpdb->get_row("SELECT
            COALESCE(SUM((event_type='catalog_view')*event_count),0) catalog_views,
            COALESCE(SUM((event_type='product_impression')*event_count),0) impressions,
            COALESCE(SUM((event_type='detail_view')*event_count),0) details,
            COALESCE(SUM((event_type='outbound_click')*event_count),0) clicks
            FROM {$table} WHERE {$where}", ARRAY_A);
        $row = is_array($row) ? array_map('intval', $row) : array('catalog_views'=>0,'impressions'=>0,'details'=>0,'clicks'=>0);
        $row['has_data'] = array_sum($row) > 0;
        return $row;
    }

    private static function comparison_value($current, $previous, $has_data) {
        if ( ! $has_data ) return array('text'=>'比較データなし','class'=>'is-neutral');
        $delta = (int)$current - (int)$previous;
        if ( (int)$previous === 0 ) return array('text'=>sprintf('%+d', $delta) . '（率算出不可）','class'=>$delta >= 0?'is-up':'is-down');
        $rate = round(($delta / (int)$previous) * 100, 1);
        return array('text'=>sprintf('%+d / %+.1f%%', $delta, $rate),'class'=>$delta >= 0?'is-up':'is-down');
    }

    public static function render_dashboard() {
        if ( ! current_user_can('nf_view_analytics') ) wp_die('権限がありません。');
        if ( class_exists('NF_Commercial_Config') && ! NF_Commercial_Config::feature('feature_basic_analytics') ) wp_die('現在の契約プランでは分析機能を利用できません。');
        self::maybe_upgrade();
        global $wpdb;
        $ranges = self::ranges();
        $range_key = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '';
        if ( $range_key === '' && isset($_GET['days']) ) $range_key = absint($_GET['days']).'d';
        if ( ! isset($ranges[$range_key]) ) $range_key = '30d';
        $range = $ranges[$range_key];
        $days = $range['days'];
        $summary_mode = ! empty($range['summary']);
        if ( $summary_mode && ! get_transient('nf_analytics_summary_refreshed') ) {
            self::refresh_recent_summaries();
            set_transient('nf_analytics_summary_refreshed', 1, HOUR_IN_SECONDS);
        }
        $window = self::date_window($days);
        $previous_window = self::date_window($days, $days);
        $year_window = array('start'=>wp_date('Y-m-d', strtotime($window['start'].' -1 year')),'end'=>wp_date('Y-m-d', strtotime($window['end'].' -1 year')));
        $table = $summary_mode ? self::summary_table() : self::table();
        $date_col = $summary_mode ? 'period_start' : 'event_date';
        $extra = $summary_mode ? " AND granularity='month'" : '';
        $where = $wpdb->prepare("{$date_col} BETWEEN %s AND %s{$extra}", $window['start'], $window['end']);
        $coverage = $wpdb->get_row("SELECT MIN({$date_col}) first_day, MAX({$date_col}) last_day FROM {$table} WHERE {$where}", ARRAY_A);
        $raw_cutoff = wp_date('Y-m-d', strtotime('-399 days'));
        $current_snapshot = self::total_snapshot($window, $summary_mode ? 'month' : false);
        $previous_snapshot = self::total_snapshot($previous_window, $summary_mode ? 'month' : ($previous_window['start'] < $raw_cutoff ? 'day' : false));
        $year_snapshot = self::total_snapshot($year_window, $summary_mode ? 'month' : ($year_window['start'] < $raw_cutoff ? 'day' : false));

        $catalog_views = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='catalog_view'");
        $impressions = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='product_impression'");
        $details = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='detail_view'");
        $clicks = self::scalar("SELECT COALESCE(SUM(event_count),0) FROM {$table} WHERE {$where} AND event_type='outbound_click'");
        if ( $summary_mode ) {
            $visitors = self::scalar("SELECT COALESCE(SUM(visitor_count),0) FROM {$table} WHERE {$where} AND event_type='catalog_view'");
            $sessions = self::scalar("SELECT COALESCE(SUM(session_count),0) FROM {$table} WHERE {$where} AND event_type='catalog_view'");
        } else {
            $visitors = self::scalar("SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE {$where} AND visitor_hash<>''");
            $sessions = self::scalar("SELECT COUNT(DISTINCT session_hash) FROM {$table} WHERE {$where} AND session_hash<>''");
        }
        $advanced = ! class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_advanced_analytics');
        $product_detail = ! class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_product_analytics');

        $trend = $wpdb->get_results("SELECT {$date_col} event_date,
            SUM((event_type='product_impression')*event_count) impressions,
            SUM((event_type='detail_view')*event_count) details,
            SUM((event_type='outbound_click')*event_count) clicks
            FROM {$table} WHERE {$where} GROUP BY {$date_col} ORDER BY {$date_col} ASC", ARRAY_A);
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
          <div class="nf-analytics-heading"><div><span>TRAFFIC &amp; REFERRAL</span><h1>アクセス・送客分析</h1><p>公開サイトへの流入から返礼品表示、詳細閲覧、楽天・Yahoo!への送客まで確認できます。</p></div><form method="get"><input type="hidden" name="page" value="nf-customer-analytics"><select name="range" onchange="this.form.submit()"><?php foreach($ranges as $key=>$definition): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($range_key,$key); ?>><?php echo esc_html($definition['label']); ?></option><?php endforeach; ?></select></form></div>
          <div class="nf-analytics-cards">
            <?php foreach (array('利用者'=>$visitors.'人','セッション'=>$sessions.'回','一覧表示'=>$catalog_views.'回','商品インプレッション'=>$impressions.'回','詳細閲覧'=>$details.'回','送客クリック'=>$clicks.'回','詳細閲覧率'=>self::percent($details,$impressions).'%','送客率'=>self::percent($clicks,$details).'%') as $label=>$value): ?>
              <div class="nf-analytics-card"><small><?php echo esc_html($label); ?></small><strong><?php echo esc_html($value); ?></strong></div>
            <?php endforeach; ?>
          </div>

          <section class="nf-analytics-panel"><h2>期間比較</h2><p class="description"><?php echo esc_html($range['label']); ?>を、同じ日数の直前期間および前年同期と比較します。<?php if(!empty($coverage['first_day'])): ?> この表示に含まれる計測期間：<?php echo esc_html($coverage['first_day']); ?>〜<?php echo esc_html($coverage['last_day']); ?>。<?php else: ?> 選択期間の計測データはまだありません。<?php endif; ?></p><div class="nf-comparison-table"><div><b>指標</b><b>現在</b><b>直前期間比</b><b>前年同期比</b></div><?php foreach(array('catalog_views'=>'一覧表示','impressions'=>'商品表示','details'=>'詳細閲覧','clicks'=>'送客クリック') as $metric=>$label): $previous_compare=self::comparison_value($current_snapshot[$metric],$previous_snapshot[$metric],$previous_snapshot['has_data']); $year_compare=self::comparison_value($current_snapshot[$metric],$year_snapshot[$metric],$year_snapshot['has_data']); ?><div><strong><?php echo esc_html($label); ?></strong><span><?php echo intval($current_snapshot[$metric]); ?></span><span class="<?php echo esc_attr($previous_compare['class']); ?>"><?php echo esc_html($previous_compare['text']); ?></span><span class="<?php echo esc_attr($year_compare['class']); ?>"><?php echo esc_html($year_compare['text']); ?></span></div><?php endforeach; ?></div></section>

          <section class="nf-analytics-panel"><h2><?php echo $summary_mode?'月別':'日別'; ?>推移</h2><div class="nf-trend-legend"><span class="is-impression">商品表示</span><span class="is-detail">詳細閲覧</span><span class="is-click">送客</span></div><div class="nf-trend">
          <?php if(!$trend): ?><p>計測データはまだありません。</p><?php else: foreach($trend as $row): ?>
            <div class="nf-trend-row"><time><?php echo esc_html($summary_mode?substr($row['event_date'],0,7):substr($row['event_date'],5)); ?></time><div class="nf-trend-bars"><i class="is-impression" style="width:<?php echo esc_attr(max(1,round($row['impressions']/$max_trend*100))); ?>%" title="表示 <?php echo intval($row['impressions']); ?>"></i><i class="is-detail" style="width:<?php echo esc_attr(max(1,round($row['details']/$max_trend*100))); ?>%" title="詳細 <?php echo intval($row['details']); ?>"></i><i class="is-click" style="width:<?php echo esc_attr(max(1,round($row['clicks']/$max_trend*100))); ?>%" title="送客 <?php echo intval($row['clicks']); ?>"></i></div><b><?php echo intval($row['impressions']); ?> / <?php echo intval($row['details']); ?> / <?php echo intval($row['clicks']); ?></b></div>
          <?php endforeach; endif; ?></div></section>

          <div class="nf-analytics-grid">
            <section class="nf-analytics-panel"><h2>送客先</h2><?php self::render_rank($portals); ?></section>
            <?php if($advanced): ?><section class="nf-analytics-panel"><h2>流入元</h2><?php self::render_rank($sources); ?></section><section class="nf-analytics-panel"><h2>デバイス</h2><?php self::render_rank($devices); ?></section><?php else: ?><section class="nf-analytics-panel is-locked"><h2>流入元・デバイス</h2><p>Growthプランで利用できます。</p></section><?php endif; ?>
          </div>

          <?php if($product_detail): ?><section class="nf-analytics-panel"><h2>返礼品別の表示・送客</h2><div class="nf-table-scroll"><table class="widefat striped"><thead><tr><th>返礼品</th><th>インプレッション</th><th>詳細閲覧</th><th>送客</th><th>送客率</th><th>状態</th></tr></thead><tbody><?php foreach($products as $row): $rate=self::percent((int)$row['clicks'],(int)$row['details']); $product_url=current_user_can('edit_post',(int)$row['product_id'])?get_edit_post_link((int)$row['product_id']):get_permalink((int)$row['product_id']); ?><tr><td><a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html(get_the_title((int)$row['product_id'])); ?></a></td><td><?php echo intval($row['impressions']); ?></td><td><?php echo intval($row['details']); ?></td><td><?php echo intval($row['clicks']); ?></td><td><?php echo esc_html($rate); ?>%</td><td><?php echo ((int)$row['impressions']>=20 && (int)$row['clicks']===0)?'<span class="nf-needs-work">改善候補</span>':'—'; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php else: ?><section class="nf-analytics-panel is-locked"><h2>返礼品別分析</h2><p>Standard以上のプランで利用できます。</p></section><?php endif; ?>

          <?php if($advanced): ?><section class="nf-analytics-panel"><h2>検索・絞り込みの利用</h2><table class="widefat striped"><thead><tr><th>キーワード</th><th>カテゴリ</th><th>自治体</th><th>利用回数</th></tr></thead><tbody><?php foreach($filters as $row): ?><tr><td><?php echo esc_html($row['keyword'] ?: '—'); ?></td><td><?php echo esc_html($row['category_slug'] ?: '—'); ?></td><td><?php echo esc_html($row['municipality_slug'] ?: '—'); ?></td><td><?php echo intval($row['total']); ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
          <p class="nf-analytics-note">「送客クリック」は楽天・Yahoo!等へのリンクが押された回数です。ポータル側の寄附件数を表すものではありません。IPアドレス・氏名・メールアドレスは保存しません。<?php if($summary_mode): ?> 5年・10年表示の利用者とセッションは月次集計値の合計であり、期間内の延べ値です。計測開始前のデータは復元されません。<?php endif; ?></p>
        </div>
        <style>
        .nf-analytics-admin{max-width:1380px}.nf-analytics-heading{display:flex;justify-content:space-between;align-items:end;gap:20px;padding:26px 28px;border-radius:18px;background:linear-gradient(135deg,#155f36,#259447);color:#fff}.nf-analytics-heading span{font-size:12px;font-weight:800;letter-spacing:.12em;opacity:.78}.nf-analytics-heading h1{margin:5px 0 6px;color:#fff}.nf-analytics-heading p{margin:0;opacity:.88}.nf-analytics-heading select{min-width:130px}.nf-analytics-cards{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:13px;margin:18px 0}.nf-analytics-card,.nf-analytics-panel{border:1px solid #dce4df;border-radius:14px;background:#fff;box-shadow:0 5px 20px rgba(26,68,43,.05)}.nf-analytics-card{padding:18px}.nf-analytics-card small{display:block;color:#657168;font-weight:700}.nf-analytics-card strong{display:block;margin-top:6px;color:#153f27;font-size:27px}.nf-analytics-panel{margin:16px 0;padding:22px}.nf-analytics-panel h2{margin-top:0}.nf-analytics-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.nf-comparison-table{margin-top:16px;border:1px solid #e2e7e4;border-radius:10px;overflow:hidden}.nf-comparison-table>div{display:grid;grid-template-columns:1.2fr .7fr 1fr 1fr;gap:12px;padding:12px 14px;border-bottom:1px solid #edf0ee}.nf-comparison-table>div:first-child{background:#f4f7f5}.nf-comparison-table>div:last-child{border-bottom:0}.nf-comparison-table .is-up{color:#187b3d;font-weight:700}.nf-comparison-table .is-down{color:#b43d32;font-weight:700}.nf-comparison-table .is-neutral{color:#68716b}.nf-rank-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:10px 0;border-bottom:1px solid #edf0ee}.nf-rank-row:last-child{border-bottom:0}.nf-rank-row b{text-transform:capitalize}.nf-trend-legend{display:flex;gap:18px;margin-bottom:14px;font-size:12px;font-weight:700}.nf-trend-legend span:before{content:'';display:inline-block;width:10px;height:10px;margin-right:6px;border-radius:2px;background:#8dbda0}.nf-trend-legend .is-detail:before{background:#4b83c3}.nf-trend-legend .is-click:before{background:#e09a2d}.nf-trend-row{display:grid;grid-template-columns:52px minmax(200px,1fr) 125px;gap:12px;align-items:center;margin:8px 0}.nf-trend-bars{display:grid;gap:3px}.nf-trend-bars i{display:block;height:5px;border-radius:9px;background:#8dbda0}.nf-trend-bars .is-detail{background:#4b83c3}.nf-trend-bars .is-click{background:#e09a2d}.nf-needs-work{display:inline-block;padding:4px 8px;border-radius:99px;background:#fff1d5;color:#8a5700;font-weight:700}.nf-analytics-panel.is-locked{background:#f7f8f7;color:#667069}.nf-analytics-note{padding:14px 16px;border-radius:10px;background:#eef6f0;color:#385844}.nf-table-scroll{overflow:auto}@media(max-width:900px){.nf-analytics-cards{grid-template-columns:repeat(2,1fr)}.nf-analytics-grid{grid-template-columns:1fr}.nf-analytics-heading{align-items:start;flex-direction:column}.nf-trend-row{grid-template-columns:46px minmax(120px,1fr)}.nf-comparison-table{overflow:auto}.nf-comparison-table>div{min-width:650px}}
        </style>
        <?php
    }

    private static function render_rank($rows) {
        if ( ! $rows ) { echo '<p>データはまだありません。</p>'; return; }
        foreach ( $rows as $row ) echo '<div class="nf-rank-row"><span>' . esc_html($row['label'] ?: 'その他') . '</span><b>' . intval($row['total']) . '件</b></div>';
    }
}
