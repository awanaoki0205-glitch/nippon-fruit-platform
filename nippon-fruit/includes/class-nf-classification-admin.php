<?php
if (!defined('ABSPATH')) exit;

class NF_Classification_Admin {
    const PAGE_SLUG = 'nf-classification-settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 54);
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_action(NF_Category::TAXONOMY . '_add_form_fields', array(__CLASS__, 'add_term_fields'));
        add_action(NF_Category::TAXONOMY . '_edit_form_fields', array(__CLASS__, 'edit_term_fields'), 20, 2);
        add_action('created_' . NF_Category::TAXONOMY, array(__CLASS__, 'save_term_fields'), 20);
        add_action('edited_' . NF_Category::TAXONOMY, array(__CLASS__, 'save_term_fields'), 20);
        add_action('admin_post_nf_classification_requeue', array(__CLASS__, 'requeue'));
        add_action('wp_ajax_nf_classification_progress', array(__CLASS__, 'ajax_progress'));
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type=' . NF_Core::POST_TYPE, 'カテゴリ自動分類設定', '自動分類設定', 'manage_options', self::PAGE_SLUG, array(__CLASS__, 'page'));
    }

    public static function settings() {
        register_setting('nf_classification_settings', NF_AI_Category_Classifier::OPT_ENABLED, array('sanitize_callback'=>array(__CLASS__,'checkbox'),'default'=>'0'));
        register_setting('nf_classification_settings', NF_AI_Category_Classifier::OPT_API_KEY, array('sanitize_callback'=>'sanitize_text_field','default'=>''));
        register_setting('nf_classification_settings', NF_AI_Category_Classifier::OPT_MODEL, array('sanitize_callback'=>'sanitize_text_field','default'=>'gpt-5-mini'));
        register_setting('nf_classification_settings', NF_AI_Category_Classifier::OPT_HIGH, array('sanitize_callback'=>array(__CLASS__,'confidence'),'default'=>.85));
        register_setting('nf_classification_settings', NF_AI_Category_Classifier::OPT_MEDIUM, array('sanitize_callback'=>array(__CLASS__,'confidence'),'default'=>.60));
        register_setting('nf_classification_settings', NF_AI_Category_Classifier::OPT_BATCH, array('sanitize_callback'=>array(__CLASS__,'batch'),'default'=>3));
    }
    public static function checkbox($v) { return empty($v) ? '0' : '1'; }
    public static function confidence($v) { return max(0, min(1, (float)$v)); }
    public static function batch($v) { return max(1, min(20, (int)$v)); }

    private static function parse_lines($value) {
        $parts = preg_split('/[\r\n,、]+/u', (string)$value);
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }
    public static function add_term_fields() { wp_nonce_field('nf_category_rules','nf_category_rules_nonce'); ?>
        <div class="form-field"><label for="nf-category-aliases">判定キーワード・別名</label><textarea id="nf-category-aliases" name="nf_category_aliases" rows="5"></textarea><p>1行に1語。例：晩白柚、ばんぺいゆ、バンペイユ。カテゴリ名自体は自動で候補になります。</p></div>
        <div class="form-field"><label><input type="checkbox" name="nf_category_ai_target" value="1" checked> 曖昧な場合にAIの選択候補へ含める</label></div>
        <div class="form-field"><label for="nf-category-priority">判定優先度</label><input id="nf-category-priority" name="nf_category_priority" type="number" value="0"><p>同じ強さの候補がある場合だけ使用します。通常は0のままです。</p></div>
        <div class="form-field"><label for="nf-category-exclusive">単品時の排他カテゴリ</label><?php self::exclusive_select(0, array()); ?><p>単品では同時成立しないカテゴリを選択します。定期便・詰め合わせには適用されません。</p></div>
    <?php }
    public static function edit_term_fields($term, $taxonomy) {
        $aliases = get_term_meta($term->term_id, NF_Category_Classifier::ALIASES_META, true);
        $enabled = get_term_meta($term->term_id, NF_Category_Classifier::AI_TARGET_META, true) !== '0';
        $priority = (int)get_term_meta($term->term_id, NF_Category_Classifier::PRIORITY_META, true);
        $exclusive = class_exists('NF_Category_Consistency') ? NF_Category_Consistency::exclusive_ids($term->term_id) : array();
        wp_nonce_field('nf_category_rules','nf_category_rules_nonce'); ?>
        <tr class="form-field"><th><label for="nf-category-aliases">判定キーワード・別名</label></th><td><textarea class="large-text" id="nf-category-aliases" name="nf_category_aliases" rows="6"><?php echo esc_textarea(implode("\n",(array)$aliases)); ?></textarea><p class="description">表記違いを1行に1語で登録します。説明文だけの弱い一致は自動確定に使いません。</p></td></tr>
        <tr class="form-field"><th>AI分類</th><td><label><input type="checkbox" name="nf_category_ai_target" value="1" <?php checked($enabled); ?>> AIの選択候補へ含める</label></td></tr>
        <tr class="form-field"><th><label for="nf-category-priority">判定優先度</label></th><td><input id="nf-category-priority" name="nf_category_priority" type="number" value="<?php echo esc_attr($priority); ?>"></td></tr>
        <tr class="form-field"><th><label for="nf-category-exclusive">単品時の排他カテゴリ</label></th><td><?php self::exclusive_select((int)$term->term_id, $exclusive); ?><p class="description">単品では同時成立しないカテゴリを選択します。定期便・詰め合わせでは複数カテゴリを維持します。</p></td></tr>
    <?php }

    private static function exclusive_select($current_id, $selected) {
        $roots = get_terms(array('taxonomy'=>NF_Category::TAXONOMY,'hide_empty'=>false,'parent'=>0,'orderby'=>'name'));
        if (is_wp_error($roots)) $roots = array();
        echo '<select id="nf-category-exclusive" name="nf_category_exclusive_ids[]" multiple size="7" style="min-width:280px">';
        foreach ($roots as $root) {
            if ((int)$root->term_id === (int)$current_id) continue;
            echo '<option value="' . esc_attr($root->term_id) . '" ' . selected(in_array((int)$root->term_id, (array)$selected, true), true, false) . '>' . esc_html($root->name) . '</option>';
        }
        echo '</select>';
    }

    public static function save_term_fields($term_id) {
        if (empty($_POST['nf_category_rules_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nf_category_rules_nonce'])),'nf_category_rules') || !current_user_can('manage_categories')) return;
        $aliases = self::parse_lines(isset($_POST['nf_category_aliases']) ? sanitize_textarea_field(wp_unslash($_POST['nf_category_aliases'])) : '');
        if ($aliases) update_term_meta($term_id, NF_Category_Classifier::ALIASES_META, $aliases); else delete_term_meta($term_id, NF_Category_Classifier::ALIASES_META);
        update_term_meta($term_id, NF_Category_Classifier::AI_TARGET_META, !empty($_POST['nf_category_ai_target']) ? '1' : '0');
        update_term_meta($term_id, NF_Category_Classifier::PRIORITY_META, isset($_POST['nf_category_priority']) ? intval($_POST['nf_category_priority']) : 0);
        $exclusive = isset($_POST['nf_category_exclusive_ids']) ? array_values(array_unique(array_filter(array_map('absint', (array)wp_unslash($_POST['nf_category_exclusive_ids']))))) : array();
        if ($exclusive) update_term_meta($term_id, NF_Category_Consistency::EXCLUSIVE_TERMS_META, $exclusive); else delete_term_meta($term_id, NF_Category_Consistency::EXCLUSIVE_TERMS_META);
    }

    public static function count_status($status) {
        $q = new WP_Query(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>NF_Category_Classifier::STATUS_META,'meta_value'=>$status));
        return (int)$q->found_posts;
    }

    public static function requeue() {
        if (!current_user_can('manage_options')) wp_die('権限がありません。');
        check_admin_referer('nf_classification_requeue');
        $ids = get_posts(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
        foreach ($ids as $id) delete_post_meta($id, NF_Category_Classifier::INPUT_HASH_META);
        NF_Category::start_reclassification_progress();
        NF_Category::queue_existing_reclassification();
        wp_safe_redirect(add_query_arg('requeued','1',admin_url('edit.php?post_type=' . NF_Core::POST_TYPE . '&page=' . self::PAGE_SLUG)));
        exit;
    }

    public static function progress_payload() {
        $progress = NF_Category::get_reclassification_progress();
        $total = max(0, (int)($progress['total'] ?? 0));
        $processed = max(0, min($total, (int)($progress['processed'] ?? 0)));
        $phase = (string)($progress['phase'] ?? 'idle');
        $state = (string)($progress['state'] ?? 'idle');
        $ai_total = max(0, (int)($progress['ai_total'] ?? 0));
        $ai_processed = max(0, min($ai_total, (int)($progress['ai_processed'] ?? 0)));
        $percent = $total > 0 ? (int)floor(($processed / $total) * 100) : 0;
        if ($phase === 'ai' && $ai_total > 0) $percent = min(99, (int)floor((($total + $ai_processed) / ($total + $ai_total)) * 100));
        if ($state === 'completed') $percent = 100;
        $labels = array('idle'=>'待機中','queued'=>'開始待ち','running'=>'処理中','completed'=>'完了');
        $phase_labels = array('rule'=>'ルール分類','ai'=>'AI補完','done'=>'完了','idle'=>'');
        return array(
            'state'=>$state, 'state_label'=>$labels[$state] ?? '待機中',
            'phase'=>$phase, 'phase_label'=>$phase_labels[$phase] ?? '',
            'total'=>$total, 'processed'=>$processed, 'remaining'=>max(0,$total-$processed),
            'ai_total'=>$ai_total, 'ai_processed'=>$ai_processed,
            'pending'=>self::count_status('ai_pending'), 'percent'=>$percent,
            'updated_at'=>!empty($progress['updated_at']) ? wp_date('Y/m/d H:i:s',(int)$progress['updated_at']) : '',
        );
    }

    public static function ajax_progress() {
        check_ajax_referer('nf_classification_progress','nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message'=>'権限がありません。'),403);
        wp_send_json_success(self::progress_payload());
    }

    public static function render_progress_panel() {
        $data = self::progress_payload();
        $nonce = wp_create_nonce('nf_classification_progress'); ?>
        <section class="nf-progress-panel" id="nf-classification-progress" data-nonce="<?php echo esc_attr($nonce); ?>">
          <div class="nf-progress-heading"><div><h2>自動分類の進行状況</h2><p>画面を開いたままにすると自動更新されます。</p></div><strong class="nf-progress-state"><?php echo esc_html(trim($data['state_label'].' '.$data['phase_label'])); ?></strong></div>
          <div class="nf-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($data['percent']); ?>"><span style="width:<?php echo esc_attr($data['percent']); ?>%"></span></div>
          <div class="nf-progress-numbers"><b class="nf-progress-percent"><?php echo intval($data['percent']); ?>%</b><span>完了 <b class="nf-progress-processed"><?php echo intval($data['processed']); ?></b> / <b class="nf-progress-total"><?php echo intval($data['total']); ?></b>件</span><span>残り <b class="nf-progress-remaining"><?php echo intval($data['remaining']); ?></b>件</span><span>AI待ち <b class="nf-progress-pending"><?php echo intval($data['pending']); ?></b>件</span><small class="nf-progress-updated"><?php echo esc_html($data['updated_at'] ? '最終更新 '.$data['updated_at'] : 'まだ実行されていません'); ?></small></div>
        </section>
        <script>(function(){var root=document.getElementById('nf-classification-progress');if(!root||root.dataset.polling)return;root.dataset.polling='1';function put(s,v){var e=root.querySelector(s);if(e)e.textContent=v}function poll(){var body=new URLSearchParams({action:'nf_classification_progress',nonce:root.dataset.nonce});fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json()}).then(function(r){if(!r.success)return;var d=r.data;put('.nf-progress-state',(d.state_label+' '+d.phase_label).trim());put('.nf-progress-percent',d.percent+'%');put('.nf-progress-processed',d.processed);put('.nf-progress-total',d.total);put('.nf-progress-remaining',d.remaining);put('.nf-progress-pending',d.pending);put('.nf-progress-updated',d.updated_at?'最終更新 '+d.updated_at:'まだ実行されていません');var bar=root.querySelector('.nf-progress-track');if(bar){bar.setAttribute('aria-valuenow',d.percent);bar.querySelector('span').style.width=d.percent+'%'}}).catch(function(){}).finally(function(){window.setTimeout(poll,3000)})}window.setTimeout(poll,1000)})();</script>
    <?php }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $statuses = array('rule_classified'=>'ルール判定済み','conflict_resolved'=>'カテゴリ矛盾補正済み','ai_classified'=>'AI判定済み','ai_pending'=>'AI判定待ち','review'=>'要確認','unclassified'=>'未分類','ai_error'=>'AIエラー','manual'=>'手動確定'); ?>
        <div class="wrap"><h1>カテゴリ自動分類設定</h1>
        <?php if (!empty($_GET['requeued'])): ?><div class="notice notice-success"><p>全返礼品の再分類を予約しました。</p></div><?php endif; ?>
        <p>商品名を最優先するルール判定を行い、曖昧・矛盾する商品のみAIへ送ります。API障害が起きても商品登録は継続します。</p>
        <?php self::render_progress_panel(); ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin:18px 0"><?php foreach($statuses as $key=>$label): ?><div style="background:#fff;border:1px solid #ccd0d4;border-radius:5px;padding:12px 18px"><span><?php echo esc_html($label); ?></span><br><strong style="font-size:22px"><?php echo self::count_status($key); ?>件</strong></div><?php endforeach; ?></div>
        <form method="post" action="options.php"><?php settings_fields('nf_classification_settings'); ?>
          <table class="form-table"><tr><th>AI補完</th><td><label><input type="checkbox" name="<?php echo NF_AI_Category_Classifier::OPT_ENABLED; ?>" value="1" <?php checked(get_option(NF_AI_Category_Classifier::OPT_ENABLED,'0'),'1'); ?>> 曖昧な商品だけAIで判定する</label></td></tr>
          <tr><th>OpenAI APIキー</th><td><input class="regular-text" type="password" autocomplete="new-password" name="<?php echo NF_AI_Category_Classifier::OPT_API_KEY; ?>" value="<?php echo esc_attr(get_option(NF_AI_Category_Classifier::OPT_API_KEY,'')); ?>"><p class="description">WordPress内に保存され、画面には公開されません。</p></td></tr>
          <tr><th>モデル</th><td><input class="regular-text" name="<?php echo NF_AI_Category_Classifier::OPT_MODEL; ?>" value="<?php echo esc_attr(get_option(NF_AI_Category_Classifier::OPT_MODEL,'gpt-5-mini')); ?>"></td></tr>
          <tr><th>自動反映の信頼度</th><td><input type="number" min="0" max="1" step="0.01" name="<?php echo NF_AI_Category_Classifier::OPT_HIGH; ?>" value="<?php echo esc_attr(get_option(NF_AI_Category_Classifier::OPT_HIGH,.85)); ?>"></td></tr>
          <tr><th>要確認の下限</th><td><input type="number" min="0" max="1" step="0.01" name="<?php echo NF_AI_Category_Classifier::OPT_MEDIUM; ?>" value="<?php echo esc_attr(get_option(NF_AI_Category_Classifier::OPT_MEDIUM,.60)); ?>"></td></tr>
          <tr><th>1回の処理件数</th><td><input type="number" min="1" max="20" name="<?php echo NF_AI_Category_Classifier::OPT_BATCH; ?>" value="<?php echo esc_attr(get_option(NF_AI_Category_Classifier::OPT_BATCH,3)); ?>"></td></tr></table><?php submit_button('設定を保存'); ?></form>
        <hr><h2>再分類</h2><p>カテゴリ・別名・除外語を変更した場合、既存の全返礼品を新しいルールで再分類できます。</p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=nf_classification_requeue'),'nf_classification_requeue')); ?>">全返礼品を再分類</a>
        </div><?php
    }
}
