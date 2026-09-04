<?php
if ( ! defined('ABSPATH') ) exit;

class NF_Commercial_Config {
    const OPTION = 'nf_commercial_config';
    const ADMIN_SLUG = 'nf-commercial-settings';

    public static function plans() {
        return array(
            'starter' => array(
                'label'=>'Starter', 'feature_rakuten'=>1, 'feature_yahoo'=>0,
                'municipality_limit'=>1, 'feature_price'=>0,
                'feature_review_sort'=>0, 'feature_advanced_ranking'=>0,
                'feature_basic_analytics'=>1, 'feature_product_analytics'=>0, 'feature_advanced_analytics'=>0,
            ),
            'standard' => array(
                'label'=>'Standard', 'feature_rakuten'=>1, 'feature_yahoo'=>1,
                'municipality_limit'=>0, 'feature_price'=>1,
                'feature_review_sort'=>0, 'feature_advanced_ranking'=>0,
                'feature_basic_analytics'=>1, 'feature_product_analytics'=>1, 'feature_advanced_analytics'=>0,
            ),
            'growth' => array(
                'label'=>'Growth', 'feature_rakuten'=>1, 'feature_yahoo'=>1,
                'municipality_limit'=>0, 'feature_price'=>1,
                'feature_review_sort'=>1, 'feature_advanced_ranking'=>1,
                'feature_basic_analytics'=>1, 'feature_product_analytics'=>1, 'feature_advanced_analytics'=>1,
            ),
        );
    }

    public static function defaults() {
        return array(
            'plan'=>'growth', 'feature_rakuten'=>1, 'feature_yahoo'=>1,
            'municipality_limit'=>0, 'feature_price'=>1, 'feature_review_sort'=>1,
            'feature_advanced_ranking'=>1, 'service_name'=>'ふるさと納税',
            'feature_basic_analytics'=>1, 'feature_product_analytics'=>1, 'feature_advanced_analytics'=>1,
            'display_brand'=>'', 'site_url'=>'', 'contact_name'=>'',
            'contact_email'=>'', 'contact_phone'=>'',
            'customer_login_slug'=>'client-login',
        );
    }

    public static function all() {
        $saved = wp_parse_args((array)get_option(self::OPTION, array()), self::defaults());
        $plans = self::plans();
        $plan = isset($plans[$saved['plan']]) ? $saved['plan'] : 'growth';
        // Contract features are always derived from the selected plan. Saved
        // legacy checkboxes cannot be used to unlock a higher-tier feature.
        return array_merge($saved, $plans[$plan], array('plan'=>$plan));
    }
    public static function get($key, $default = '') { $all = self::all(); return array_key_exists($key, $all) ? $all[$key] : $default; }
    public static function feature($key) { return !empty(self::get($key, 0)); }

    public static function sanitize($value) {
        if (!current_user_can('manage_options')) return self::all();
        $value = is_array($value) ? $value : array();
        $out = self::defaults();
        $out['plan'] = in_array(($value['plan'] ?? ''), array('starter','standard','growth'), true) ? $value['plan'] : 'growth';
        $plan = $out['plan'];
        $out = array_merge($out, self::plans()[$plan]);
        foreach (array('service_name','display_brand','contact_name','contact_phone') as $key) $out[$key] = sanitize_text_field($value[$key] ?? '');
        $login_slug = sanitize_title($value['customer_login_slug'] ?? 'client-login');
        $out['customer_login_slug'] = $login_slug && !in_array($login_slug, array('wp-admin','wp-login'), true) ? $login_slug : 'client-login';
        $out['site_url'] = esc_url_raw($value['site_url'] ?? '');
        $out['contact_email'] = sanitize_email($value['contact_email'] ?? '');
        return $out;
    }

    public static function init() {
        add_action('admin_init', function(){
            register_setting('nf_commercial', self::OPTION, array('type'=>'array','sanitize_callback'=>array(__CLASS__,'sanitize'),'default'=>self::defaults()));
            register_setting('nf_commercial', NF_System_Page::OPTION_PAGE_ID, array('type'=>'integer','sanitize_callback'=>array(__CLASS__,'sanitize_page_id'),'default'=>0));
        });
        add_action('admin_menu', array(__CLASS__, 'menu'));
    }

    public static function sanitize_page_id($value) {
        if (!current_user_can('manage_options')) return NF_System_Page::page_id();
        $id = absint($value);
        return !$id || get_post_type($id) === 'page' ? $id : NF_System_Page::page_id();
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type=' . NF_Core::POST_TYPE, '商用版・契約設定', '商用版設定', 'manage_options', self::ADMIN_SLUG, array(__CLASS__, 'page'));
    }

    public static function page() {
        if (!current_user_can('manage_options')) wp_die('権限がありません。');
        $c = self::all();
        ?>
        <div class="wrap"><h1>商用版・ホワイトラベル設定</h1>
        <p>表示先・ブランド・契約機能を管理します。この画面はAdministrator専用です。</p>
        <form method="post" action="options.php"><?php settings_fields('nf_commercial'); ?>
        <h2>システム表示先ページ</h2>
        <?php wp_dropdown_pages(array('name'=>NF_System_Page::OPTION_PAGE_ID,'selected'=>NF_System_Page::page_id(),'show_option_none'=>'従来の /furusato/ を使用','option_none_value'=>0)); ?>
        <p class="description">ページIDで保存します。スラッグ変更後も自動追従します。現在: <a href="<?php echo esc_url(NF_System_Page::url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(NF_System_Page::url()); ?></a></p>
        <h2>ブランド・問い合わせ</h2><table class="form-table">
        <?php foreach (array('service_name'=>'サービス名','display_brand'=>'表示用ブランド名','contact_name'=>'問い合わせ先名','contact_email'=>'問い合わせメール','contact_phone'=>'問い合わせ電話') as $key=>$label): ?>
        <tr><th><?php echo esc_html($label); ?></th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($c[$key]); ?>"></td></tr><?php endforeach; ?>
        <tr><th>サイトURL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[site_url]" value="<?php echo esc_attr($c['site_url']); ?>"></td></tr></table>
        <h2>顧客管理画面</h2><table class="form-table"><tr><th>専用ログインURL</th><td><code><?php echo esc_html(home_url('/')); ?></code><input type="text" name="<?php echo esc_attr(self::OPTION); ?>[customer_login_slug]" value="<?php echo esc_attr($c['customer_login_slug']); ?>" style="width:220px"><code>/</code><p class="description">保存後のURL: <a href="<?php echo esc_url(home_url('/'.$c['customer_login_slug'].'/')); ?>" target="_blank" rel="noopener"><?php echo esc_html(home_url('/'.$c['customer_login_slug'].'/')); ?></a></p></td></tr></table>
        <h2>契約内容</h2><table class="form-table"><tr><th>プラン</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[plan]"><?php foreach(self::plans() as $v=>$definition): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($c['plan'],$v); ?>><?php echo esc_html($definition['label']); ?></option><?php endforeach; ?></select><p class="description">保存すると契約機能が自動適用されます。個別に上位機能をONにはできません。</p></td></tr></table>
        <table class="widefat striped" style="max-width:1100px;margin:12px 0 20px"><thead><tr><th>プラン</th><th>楽天</th><th>Yahoo!</th><th>自治体</th><th>寄附額・価格順</th><th>レビュー順</th><th>高度ランキング</th><th>基本分析</th><th>商品別分析</th><th>高度分析</th></tr></thead><tbody>
        <?php foreach(self::plans() as $key=>$definition): ?><tr<?php echo $c['plan']===$key?' style="font-weight:700;background:#eef7ee"':''; ?>><td><?php echo esc_html($definition['label']); ?></td><td>○</td><td><?php echo $definition['feature_yahoo']?'○':'—'; ?></td><td><?php echo $definition['municipality_limit']?esc_html($definition['municipality_limit'].'自治体'):'複数'; ?></td><td><?php echo $definition['feature_price']?'○':'—'; ?></td><td><?php echo $definition['feature_review_sort']?'○':'—'; ?></td><td><?php echo $definition['feature_advanced_ranking']?'○':'—'; ?></td><td><?php echo $definition['feature_basic_analytics']?'○':'—'; ?></td><td><?php echo $definition['feature_product_analytics']?'○':'—'; ?></td><td><?php echo $definition['feature_advanced_analytics']?'○':'—'; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php submit_button('Administrator設定を保存'); ?></form></div><?php
    }
}
