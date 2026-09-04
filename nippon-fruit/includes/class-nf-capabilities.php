<?php
if ( ! defined('ABSPATH') ) exit;

class NF_Capabilities {
    const ROLE = 'customer_manager';
    const VERSION_OPTION = 'nf_capabilities_version';
    const VERSION = '0.16.3';
    public static function caps() { return array('nf_view_dashboard','nf_manage_banners','nf_manage_features','nf_manage_categories','nf_manage_content','nf_manage_display','nf_view_analytics','nf_view_contract','nf_view_intelligence','nf_view_product_intelligence','nf_review_classification','nf_export_intelligence','nf_view_ai_costs'); }
    public static function activate() {
        $caps = array('read'=>true,'upload_files'=>true);
        foreach(self::caps() as $cap) $caps[$cap] = true;
        $role = get_role(self::ROLE);
        if (!$role) {
            add_role(self::ROLE, '顧客サイト管理者', $caps);
            $role = get_role(self::ROLE);
        }
        // add_role() does not update an existing role. Repair capabilities on upgrade.
        if ($role) foreach($caps as $cap => $grant) $role->add_cap($cap, $grant);
        $role_sets = array(
            'nf_customer_owner'=>array('read','upload_files','nf_view_dashboard','nf_manage_categories','nf_manage_content','nf_manage_display','nf_view_analytics','nf_view_contract','nf_view_intelligence','nf_view_product_intelligence','nf_review_classification','nf_export_intelligence','nf_view_ai_costs'),
            'nf_customer_manager'=>array('read','upload_files','nf_view_dashboard','nf_manage_categories','nf_manage_content','nf_manage_display','nf_view_analytics','nf_view_contract','nf_view_intelligence','nf_view_product_intelligence','nf_review_classification','nf_export_intelligence','nf_view_ai_costs'),
            'nf_customer_reviewer'=>array('read','nf_view_dashboard','nf_view_intelligence','nf_review_classification','nf_view_contract'),
            'nf_customer_viewer'=>array('read','nf_view_dashboard','nf_view_intelligence','nf_view_contract'),
        );
        $labels=array('nf_customer_owner'=>'顧客 Owner','nf_customer_manager'=>'顧客 Manager','nf_customer_reviewer'=>'顧客 Reviewer','nf_customer_viewer'=>'顧客 Viewer');
        foreach($role_sets as $slug=>$allowed) {
            $r=get_role($slug); if(!$r){ add_role($slug,$labels[$slug],array()); $r=get_role($slug); }
            if($r) foreach($allowed as $cap) $r->add_cap($cap,true);
        }
        $admin = get_role('administrator');
        if ($admin) foreach(array_merge(self::caps(), array('nf_manage_system','nf_manage_integrations','nf_manage_license')) as $cap) $admin->add_cap($cap);
    }
    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 3);
        add_action('admin_menu', array(__CLASS__, 'menu'), 5);
        add_action('admin_init', array(__CLASS__, 'guard_customer_admin'));
    }
    public static function maybe_upgrade() {
        if (get_option(self::VERSION_OPTION, '') === self::VERSION) return;
        self::activate();
        NF_System_Page::migrate_legacy();
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
    public static function menu() {
        add_menu_page('返礼品システム', '返礼品システム', 'nf_view_dashboard', 'nf-customer-dashboard', array(__CLASS__, 'dashboard'), 'dashicons-store', 26);
        if (class_exists('NF_Customer_Portal') && NF_Customer_Portal::is_customer()) {
            add_submenu_page('nf-customer-dashboard','運用ダッシュボード','運用ダッシュボード','nf_view_dashboard','nf-customer-dashboard',array(__CLASS__,'dashboard'));
            add_submenu_page('nf-customer-dashboard','アクセス・送客分析','アクセス・送客分析','nf_view_analytics','nf-customer-analytics',array('NF_Analytics','render_dashboard'));
            add_submenu_page('nf-customer-dashboard','自治体','自治体','nf_manage_categories','edit-tags.php?taxonomy=nf_municipality&post_type=' . NF_Core::POST_TYPE);
            add_submenu_page('nf-customer-dashboard','カテゴリ','カテゴリ','nf_manage_categories','edit-tags.php?taxonomy=' . NF_Category::TAXONOMY . '&post_type=' . NF_Core::POST_TYPE);
            add_submenu_page('nf-customer-dashboard','かんたん設定','かんたん設定','nf_manage_display','nf-customer-easy-settings',array(__CLASS__,'customer_display'));
            add_submenu_page('nf-customer-dashboard','カテゴリ・自治体 表示並び順','表示並び順','nf_manage_categories','nf-customer-category-order',array(__CLASS__,'customer_category_order'));
            add_submenu_page('nf-customer-dashboard','コンテンツ・表示設定','コンテンツ・表示設定','nf_manage_display','nf-customer-display',array(__CLASS__,'customer_display'));
            add_submenu_page('nf-customer-dashboard','契約内容','契約内容','nf_view_contract','nf-customer-contract',array(__CLASS__,'contract'));
        } else {
            add_submenu_page('nf-customer-dashboard','アクセス・送客分析','アクセス・送客分析','nf_view_analytics','nf-customer-analytics',array('NF_Analytics','render_dashboard'));
            add_submenu_page('nf-customer-dashboard','コンテンツ・表示設定','コンテンツ・表示設定','nf_manage_display','nf-customer-display',array(__CLASS__,'customer_display'));
            add_submenu_page('nf-customer-dashboard','カテゴリ・自治体の並び順','カテゴリ・自治体','nf_manage_categories','nf-customer-category-order',array(__CLASS__,'customer_category_order'));
            add_submenu_page('nf-customer-dashboard','契約内容','契約内容','nf_view_contract','nf-customer-contract',array(__CLASS__,'contract'));
        }
    }
    public static function dashboard() {
        if (!current_user_can('nf_view_dashboard')) wp_die('権限がありません。');
        $brand = class_exists('NF_Commercial_Config') ? trim((string)NF_Commercial_Config::get('display_brand','')) : '';
        if ($brand === '' && class_exists('NF_Settings')) $brand = NF_Settings::brand_name();
        if ($brand === '') $brand = get_bloginfo('name');
        $logout_url = class_exists('NF_Customer_Portal') ? NF_Customer_Portal::logout_url() : wp_logout_url(home_url('/'));
        echo '<div class="wrap"><div class="nf-customer-brand" style="position:relative;padding-right:130px"><h1>' . esc_html($brand) . '</h1><p>運用管理ダッシュボード</p><a href="' . esc_url($logout_url) . '" style="position:absolute;right:22px;top:50%;transform:translateY(-50%);padding:9px 14px;border:1px solid rgba(255,255,255,.75);border-radius:8px;color:#fff;text-decoration:none;font-weight:700">ログアウト</a></div><p>公開サイトの表示内容をここから管理できます。契約やシステムの内部設定は運営管理者が管理します。</p><p><a class="button button-primary" href="' . esc_url(NF_System_Page::url()) . '" target="_blank" rel="noopener">公開サイトを確認</a></p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;max-width:950px;margin-top:22px">';
        $items=array(
            array('アクセス・送客分析','admin.php?page=nf-customer-analytics','nf_view_analytics'),
            array('Classification Intelligence','admin.php?page=nf-customer-intelligence','nf_view_intelligence'),
            array('Product Intelligence','admin.php?page=nf-product-intelligence','nf_view_product_intelligence'),
            array('独自カテゴリ管理','edit-tags.php?taxonomy='.NF_Category::TAXONOMY,'nf_manage_categories'),
            array('自治体管理','edit-tags.php?taxonomy=nf_municipality','nf_manage_categories'),
            array('カテゴリ・自治体の並び順','admin.php?page=nf-customer-category-order','nf_manage_categories'),
            array('⑥ 自社コンテンツ誘導ブロック','admin.php?page=nf-customer-display#nf-customer-content-block','nf_manage_content'),
            array('⑦ 表示文言','admin.php?page=nf-customer-display#nf-customer-labels','nf_manage_display'),
            array('契約内容','admin.php?page=nf-customer-contract','nf_view_contract'),
        );
        foreach($items as $item) if(current_user_can($item[2])) echo '<a href="'.esc_url(admin_url($item[1])).'" style="display:block;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:10px;text-decoration:none;font-size:16px;font-weight:700">'.esc_html($item[0]).'</a>';
        echo '</div></div>';
    }
    public static function customer_display() {
        if (!current_user_can('nf_manage_display')) wp_die('権限がありません。');
        NF_Settings::settings_page();
    }
    public static function customer_category_order() {
        if (!current_user_can('nf_manage_categories')) wp_die('権限がありません。');
        NF_Category::render_order_page();
    }
    public static function contract() {
        if (!current_user_can('nf_view_contract')) wp_die('権限がありません。');
        $c = NF_Commercial_Config::all();
        $features = array();
        if (!empty($c['feature_rakuten'])) $features[] = '楽天';
        if (!empty($c['feature_yahoo'])) $features[] = 'Yahoo!';
        if (!empty($c['feature_price'])) $features[] = '寄附額・価格順';
        if (!empty($c['feature_review_sort'])) $features[] = 'レビュー順';
        if (!empty($c['feature_advanced_ranking'])) $features[] = '高度ランキング';
        if (!empty($c['feature_basic_analytics'])) $features[] = '基本アクセス・送客分析';
        if (!empty($c['feature_product_analytics'])) $features[] = '返礼品別分析';
        if (!empty($c['feature_advanced_analytics'])) $features[] = '流入元・高度分析';
        echo '<div class="wrap nf-customer-contract"><div class="nf-page-heading"><span>CONTRACT</span><h1>契約内容</h1><p>現在ご利用いただけるプランと機能です。</p></div><div class="nf-contract-card"><div class="nf-contract-plan"><small>ご利用プラン</small><strong>' . esc_html(ucfirst($c['plan'])) . '</strong></div><table class="widefat striped"><tr><th>利用機能</th><td>' . esc_html(implode(' / ', $features)) . '</td></tr><tr><th>利用URL</th><td><a href="' . esc_url(NF_System_Page::url()) . '" target="_blank" rel="noopener">' . esc_html(NF_System_Page::url()) . '</a></td></tr><tr><th>自治体上限</th><td>' . (absint($c['municipality_limit']) ?: '複数') . '</td></tr></table></div><p class="nf-customer-help">契約機能の変更は運営管理者へお問い合わせください。</p></div>';
    }
    public static function guard_customer_admin() {
        if (current_user_can('manage_options') || !current_user_can('nf_view_dashboard')) return;
        global $pagenow;
        if (in_array($pagenow, array('plugins.php','plugin-editor.php','themes.php','theme-editor.php','options-general.php','customize.php'), true)) wp_die('この画面を利用する権限はありません。');
    }
}
