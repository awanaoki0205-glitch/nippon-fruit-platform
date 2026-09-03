<?php
if (!defined('ABSPATH')) exit;

/**
 * Records classification evidence and compares it with administrator-confirmed
 * Gold Standard data. It never calls an AI API and never changes classification.
 */
class NF_Classification_History {
    const PAGE_SLUG = 'nf-classification-accuracy';
    const HISTORY_META = '_nf_classification_history';
    const CURRENT_META = '_nf_classification_audit_current';
    const HUMAN_STATUS_META = '_nf_classification_human_status';
    const GOLD_TERMS_META = '_nf_classification_gold_terms';
    const VERIFIED_AT_META = '_nf_classification_verified_at';
    const VERIFIED_BY_META = '_nf_classification_verified_by';
    const AI_CORRECT_META = '_nf_classification_ai_correct';
    const BEFORE_TERMS_META = '_nf_classification_before_terms';
    const AFTER_TERMS_META = '_nf_classification_after_terms';
    const MAX_HISTORY = 50;

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 56);
        add_action('add_meta_boxes_' . NF_Core::POST_TYPE, array(__CLASS__, 'meta_box'));
        add_action('admin_post_nf_classification_verify', array(__CLASS__, 'verify'));
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            '商品分類AIの判定履歴・精度検証',
            '分類精度',
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'page')
        );
    }

    public static function meta_box() {
        add_meta_box(
            'nf-classification-history',
            '判定履歴・正解確認',
            array(__CLASS__, 'meta_box_html'),
            NF_Core::POST_TYPE,
            'normal',
            'high'
        );
    }

    private static function clean_ids($ids) {
        return array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    }

    private static function leaf_ids($ids) {
        $ids = self::clean_ids($ids);
        $leaf = $ids;
        foreach ($ids as $id) {
            foreach ($ids as $other) {
                if ($id !== $other && in_array($id, get_ancestors($other, NF_Category::TAXONOMY, 'taxonomy'), true)) {
                    $leaf = array_values(array_diff($leaf, array($id)));
                    break;
                }
            }
        }
        sort($leaf);
        return $leaf;
    }

    private static function current_term_ids($post_id) {
        $ids = wp_get_object_terms($post_id, NF_Category::TAXONOMY, array('fields'=>'ids'));
        return is_wp_error($ids) ? array() : self::clean_ids($ids);
    }

    private static function result_ids($result, $keys) {
        if (!is_array($result)) return array();
        foreach ($keys as $key) {
            if (isset($result[$key]) && is_array($result[$key])) return self::clean_ids($result[$key]);
        }
        return array();
    }

    private static function term_names($ids) {
        $names = array();
        foreach (self::clean_ids($ids) as $id) {
            $term = get_term($id, NF_Category::TAXONOMY);
            if ($term && !is_wp_error($term)) $names[] = $term->name;
        }
        return $names;
    }

    public static function snapshot($post_id, $status = '', $method = '', $confidence = null, $reason = '') {
        $rule = get_post_meta($post_id, NF_Category_Classifier::RESULT_META, true);
        $text = get_post_meta($post_id, NF_Category_Classifier::AI_RESULT_META, true);
        $image = get_post_meta($post_id, NF_Image_Category_Classifier::RESULT_META, true);
        $final = get_post_meta($post_id, NF_Classification_Evidence::FINAL_RESULT_META, true);
        $status = $status !== '' ? sanitize_key($status) : sanitize_key((string)get_post_meta($post_id, NF_Category_Classifier::STATUS_META, true));
        $method = $method !== '' ? sanitize_key($method) : sanitize_key((string)get_post_meta($post_id, NF_Category_Classifier::METHOD_META, true));
        $rule_conf = is_array($rule) ? (float)($rule['confidence'] ?? 0) : 0;
        $text_conf = get_post_meta($post_id, NF_Classification_Evidence::TEXT_CONFIDENCE_META, true);
        $image_conf = get_post_meta($post_id, NF_Classification_Evidence::IMAGE_CONFIDENCE_META, true);
        $final_conf = get_post_meta($post_id, NF_Classification_Evidence::FINAL_CONFIDENCE_META, true);
        if ($confidence !== null && ($final_conf === '' || $final_conf === false)) $final_conf = (float)$confidence;
        $rule_ids = self::result_ids($rule, array('category_ids','variety_ids','accepted_categories'));
        $text_ids = self::result_ids($text, array('accepted_categories','category_ids'));
        $image_ids = self::result_ids($image, array('candidate_categories','accepted_categories'));
        $final_ids = self::result_ids($final, array('accepted_categories','category_ids'));
        if (!$final_ids) $final_ids = self::current_term_ids($post_id);
        $image_used = get_post_meta($post_id, NF_Classification_Evidence::IMAGE_USED_META, true) === '1' || !empty($image);
        $text_used = !empty($text) || in_array($method, array('text_ai','text_ai_cache','image_ai','image_ai_cache','multimodal'), true);
        $stage = $image_used ? 'image_ai' : ($text_used ? 'text_ai' : ($method === 'manual' ? 'manual' : 'algorithm'));
        return array(
            'product_id'=>(int)$post_id,
            'product_name'=>get_the_title($post_id),
            'status'=>$status,
            'method'=>$method,
            'algorithm'=>array('categories'=>$rule_ids,'confidence'=>max(0,min(1,$rule_conf))),
            'text_ai'=>array('used'=>$text_used,'categories'=>$text_ids,'confidence'=>$text_conf === '' ? null : max(0,min(1,(float)$text_conf))),
            'image_ai'=>array('used'=>$image_used,'categories'=>$image_ids,'confidence'=>$image_conf === '' ? null : max(0,min(1,(float)$image_conf))),
            'final'=>array('categories'=>$final_ids,'confidence'=>$final_conf === '' ? max(0,min(1,(float)$confidence)) : max(0,min(1,(float)$final_conf))),
            'confirmed_stage'=>$stage,
            'reason'=>sanitize_text_field((string)$reason),
            'decided_at'=>current_time('mysql'),
        );
    }

    public static function record($post_id, $status, $method, $confidence, $reason = '') {
        if (get_post_type($post_id) !== NF_Core::POST_TYPE) return;
        $snapshot = self::snapshot($post_id, $status, $method, $confidence, $reason);
        update_post_meta($post_id, self::CURRENT_META, $snapshot);
        $history = get_post_meta($post_id, self::HISTORY_META, true);
        $history = is_array($history) ? $history : array();
        $last = $history ? end($history) : array();
        $fingerprint = hash('sha256', wp_json_encode(array($snapshot['status'],$snapshot['method'],$snapshot['algorithm'],$snapshot['text_ai'],$snapshot['image_ai'],$snapshot['final'])));
        $last_fingerprint = is_array($last) ? (string)($last['fingerprint'] ?? '') : '';
        if ($fingerprint !== $last_fingerprint) {
            $snapshot['fingerprint'] = $fingerprint;
            $history[] = $snapshot;
            if (count($history) > self::MAX_HISTORY) $history = array_slice($history, -self::MAX_HISTORY);
            update_post_meta($post_id, self::HISTORY_META, $history);
        }
    }

    public static function current($post_id) {
        $current = get_post_meta($post_id, self::CURRENT_META, true);
        if (!is_array($current)) {
            $current = self::snapshot($post_id);
            update_post_meta($post_id, self::CURRENT_META, $current);
        }
        return $current;
    }

    private static function same_categories($predicted, $gold) {
        return self::leaf_ids($predicted) === self::leaf_ids($gold);
    }

    public static function verify() {
        if (!current_user_can('manage_options')) wp_die('権限がありません。');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || get_post_type($post_id) !== NF_Core::POST_TYPE) wp_die('対象商品が正しくありません。');
        check_admin_referer('nf_classification_verify_' . $post_id);
        $action = isset($_POST['verification']) ? sanitize_key(wp_unslash($_POST['verification'])) : '';
        if (!in_array($action, array('correct','corrected'), true)) wp_die('確認内容を選択してください。');
        $before = self::current_term_ids($post_id);
        $gold = $action === 'correct'
            ? $before
            : self::clean_ids(isset($_POST['gold_terms']) ? wp_unslash($_POST['gold_terms']) : array());
        if ($action === 'corrected' && !$gold) wp_die('正しいカテゴリを選択してください。');
        $current = self::current($post_id);
        update_post_meta($post_id, self::HUMAN_STATUS_META, $action);
        update_post_meta($post_id, self::GOLD_TERMS_META, $gold);
        update_post_meta($post_id, self::VERIFIED_AT_META, current_time('mysql'));
        update_post_meta($post_id, self::VERIFIED_BY_META, get_current_user_id());
        update_post_meta($post_id, self::BEFORE_TERMS_META, $before);
        update_post_meta($post_id, self::AFTER_TERMS_META, $gold);
        update_post_meta($post_id, self::AI_CORRECT_META, self::same_categories($current['final']['categories'] ?? array(), $gold) ? '1' : '0');
        if ($action === 'corrected') {
            wp_set_object_terms($post_id, $gold, NF_Category::TAXONOMY, false);
            update_post_meta($post_id, NF_Category::AUTO_TERMS_META, $gold);
        }
        update_post_meta($post_id, NF_Category::CLASSIFICATION_LOCK_META, '1');
        update_post_meta($post_id, NF_Category_Classifier::STATUS_META, 'manual');
        update_post_meta($post_id, NF_Category_Classifier::METHOD_META, 'manual');
        self::record($post_id, 'manual', 'manual', 1, $action === 'correct' ? '管理者が分類を正解として確認' : '管理者が正解分類へ修正');
        $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : get_edit_post_link($post_id, 'raw');
        wp_safe_redirect(add_query_arg('nf_verified','1',$redirect));
        exit;
    }

    private static function category_checklist($post_id, $selected) {
        $terms = get_terms(array('taxonomy'=>NF_Category::TAXONOMY,'hide_empty'=>false,'orderby'=>'name'));
        if (is_wp_error($terms)) return;
        $selected = self::clean_ids($selected);
        echo '<div class="nf-gold-checklist" style="columns:3;max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:12px;background:#fff">';
        foreach ($terms as $term) {
            $depth = count(get_ancestors($term->term_id, NF_Category::TAXONOMY, 'taxonomy'));
            echo '<label style="display:block;margin:0 0 7px;padding-left:'.esc_attr($depth*14).'px"><input type="checkbox" name="gold_terms[]" value="'.esc_attr($term->term_id).'" '.checked(in_array((int)$term->term_id,$selected,true),true,false).'> '.esc_html($term->name).'</label>';
        }
        echo '</div>';
    }

    private static function percent($value) {
        return $value === null ? '—' : number_format_i18n((float)$value * 100, 2) . '%';
    }

    private static function stage_html($label, $data, $used = true) {
        echo '<div class="nf-history-step"><strong>'.esc_html($label).'</strong>';
        if (!$used) { echo '<p>未使用</p></div>'; return; }
        $names = self::term_names($data['categories'] ?? array());
        echo '<p>判定：'.esc_html($names ? implode(' ＞ ', $names) : '判定不能').'<br>信頼度：'.esc_html(self::percent(isset($data['confidence']) ? $data['confidence'] : null)).'</p></div>';
    }

    public static function meta_box_html($post) {
        $current = self::current($post->ID);
        $human = get_post_meta($post->ID, self::HUMAN_STATUS_META, true);
        $gold = get_post_meta($post->ID, self::GOLD_TERMS_META, true);
        $history = get_post_meta($post->ID, self::HISTORY_META, true);
        self::stage_html('STEP 1：Algorithm', $current['algorithm'] ?? array());
        self::stage_html('STEP 2：Text AI', $current['text_ai'] ?? array(), !empty($current['text_ai']['used']));
        self::stage_html('STEP 3：Image AI', $current['image_ai'] ?? array(), !empty($current['image_ai']['used']));
        echo '<div class="nf-history-step"><strong>FINAL</strong><p>分類：'.esc_html(implode(' ＞ ',self::term_names($current['final']['categories'] ?? array())) ?: '判定不能').'<br>最終信頼度：'.esc_html(self::percent($current['final']['confidence'] ?? null)).'<br>確定段階：'.esc_html($current['confirmed_stage'] ?? '—').'</p></div>';
        echo '<p><strong>人間確認：</strong>'.esc_html($human === 'correct' ? '正解' : ($human === 'corrected' ? '修正済み' : '未確認')).'</p>';
        echo '<p class="description">信頼度は判定時の自己評価です。実測精度は、人間確認済みデータとの一致率として別に集計します。</p>';
        ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="nf_classification_verify">
          <input type="hidden" name="post_id" value="<?php echo intval($post->ID); ?>">
          <input type="hidden" name="redirect_to" value="<?php echo esc_attr(get_edit_post_link($post->ID,'raw')); ?>">
          <?php wp_nonce_field('nf_classification_verify_'.$post->ID); ?>
          <p><label><input type="radio" name="verification" value="correct" checked> 分類は正しい</label>　<label><input type="radio" name="verification" value="corrected"> 分類を修正</label></p>
          <details><summary>正しい分類を選択</summary><?php self::category_checklist($post->ID, $gold ?: self::current_term_ids($post->ID)); ?></details>
          <?php submit_button('人間確認を保存','primary','submit',false); ?>
        </form><?php
        if (is_array($history) && $history) {
            echo '<details style="margin-top:18px"><summary>過去の判定履歴（'.intval(count($history)).'件）</summary><table class="widefat striped"><thead><tr><th>日時</th><th>状態</th><th>方式</th><th>最終分類</th><th>信頼度</th></tr></thead><tbody>';
            foreach (array_reverse($history) as $row) echo '<tr><td>'.esc_html($row['decided_at']??'').'</td><td>'.esc_html($row['status']??'').'</td><td>'.esc_html($row['method']??'').'</td><td>'.esc_html(implode('、',self::term_names($row['final']['categories']??array()))).'</td><td>'.esc_html(self::percent($row['final']['confidence']??null)).'</td></tr>';
            echo '</tbody></table></details>';
        }
    }

    private static function all_ids() {
        return get_posts(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC'));
    }

    private static function blank_stage() { return array('processed'=>0,'verified'=>0,'correct'=>0,'incorrect'=>0); }

    private static function add_stage(&$stage, $predicted, $gold, $processed = true) {
        if (!$processed) return;
        $stage['processed']++;
        if (!$gold) return;
        $stage['verified']++;
        if (self::same_categories($predicted,$gold)) $stage['correct']++; else $stage['incorrect']++;
    }

    public static function statistics() {
        $stats = array(
            'total'=>0,'decided'=>0,'verified'=>0,'unverified'=>0,'correct'=>0,'incorrect'=>0,'unclassified'=>0,'corrected'=>0,
            'algorithm'=>self::blank_stage(),'text_ai'=>self::blank_stage(),'image_ai'=>self::blank_stage(),'final'=>self::blank_stage(),
            'cumulative_algorithm'=>self::blank_stage(),'cumulative_text'=>self::blank_stage(),'cumulative_image'=>self::blank_stage(),
            'route'=>array('algorithm'=>0,'text_ai'=>0,'image_ai'=>0),'buckets'=>array(),
        );
        foreach (self::all_ids() as $post_id) {
            $stats['total']++;
            $row = self::current($post_id);
            if (!empty($row['status'])) $stats['decided']++;
            if (($row['status'] ?? '') === 'unclassified') $stats['unclassified']++;
            $human = get_post_meta($post_id,self::HUMAN_STATUS_META,true);
            $gold = self::clean_ids(get_post_meta($post_id,self::GOLD_TERMS_META,true));
            if ($human && $gold) { $stats['verified']++; if ($human === 'corrected') $stats['corrected']++; }
            $final_correct = $gold ? self::same_categories($row['final']['categories']??array(),$gold) : false;
            if ($gold) { if ($final_correct) $stats['correct']++; else $stats['incorrect']++; }
            $text_used = !empty($row['text_ai']['used']);
            $image_used = !empty($row['image_ai']['used']);
            $route = $image_used ? 'image_ai' : ($text_used ? 'text_ai' : 'algorithm');
            $stats['route'][$route]++;
            self::add_stage($stats['algorithm'],$row['algorithm']['categories']??array(),$gold,true);
            self::add_stage($stats['text_ai'],$row['text_ai']['categories']??array(),$gold,$text_used);
            self::add_stage($stats['image_ai'],$row['image_ai']['categories']??array(),$gold,$image_used);
            self::add_stage($stats['final'],$row['final']['categories']??array(),$gold,true);
            self::add_stage($stats['cumulative_algorithm'],$row['algorithm']['categories']??array(),$gold,true);
            $through_text = $text_used ? ($row['text_ai']['categories']??array()) : ($row['algorithm']['categories']??array());
            self::add_stage($stats['cumulative_text'],$through_text,$gold,true);
            $through_image = $image_used ? ($row['final']['categories']??array()) : $through_text;
            self::add_stage($stats['cumulative_image'],$through_image,$gold,true);
            if ($gold) {
                $confidence = max(0,min(1,(float)($row['final']['confidence']??0)));
                $bucket = $confidence >= .95 ? '95〜100%' : ($confidence >= .90 ? '90〜94%' : ($confidence >= .80 ? '80〜89%' : ($confidence >= .70 ? '70〜79%' : '70%未満')));
                if (!isset($stats['buckets'][$bucket])) $stats['buckets'][$bucket]=array('verified'=>0,'correct'=>0);
                $stats['buckets'][$bucket]['verified']++;
                if ($final_correct) $stats['buckets'][$bucket]['correct']++;
            }
        }
        $stats['unverified'] = max(0,$stats['total']-$stats['verified']);
        return $stats;
    }

    private static function accuracy($stage) {
        return !empty($stage['verified']) ? ((int)$stage['correct']/(int)$stage['verified'])*100 : null;
    }

    private static function rate($number,$total) { return $total > 0 ? ($number/$total)*100 : 0; }

    private static function card($label,$value,$sub='') {
        echo '<div class="nf-accuracy-card"><span>'.esc_html($label).'</span><strong>'.esc_html($value).'</strong>'.($sub!==''?'<small>'.esc_html($sub).'</small>':'').'</div>';
    }

    private static function metric_row($label,$stage) {
        $accuracy=self::accuracy($stage);
        echo '<tr><th>'.esc_html($label).'</th><td>'.intval($stage['processed']).'件</td><td>'.intval($stage['verified']).'件</td><td>'.intval($stage['correct']).'件</td><td>'.intval($stage['incorrect']).'件</td><td><strong>'.esc_html($accuracy===null?'—':number_format_i18n($accuracy,2).'%').'</strong><br><small>'.intval($stage['correct']).' / '.intval($stage['verified']).'件</small></td></tr>';
    }

    private static function review_query() {
        $filter = isset($_GET['nf_audit_filter']) ? sanitize_key(wp_unslash($_GET['nf_audit_filter'])) : 'unverified';
        $orderby = isset($_GET['nf_audit_order']) ? sanitize_key(wp_unslash($_GET['nf_audit_order'])) : 'confidence';
        $category = isset($_GET['nf_audit_category']) ? absint($_GET['nf_audit_category']) : 0;
        $ids = self::all_ids(); $rows=array();
        foreach ($ids as $id) {
            $row=self::current($id); $human=get_post_meta($id,self::HUMAN_STATUS_META,true);
            $reason = (string)($row['reason'] ?? get_post_meta($id,NF_Category::REVIEW_REASON_META,true));
            $conflict = get_post_meta($id,NF_Category_Consistency::CONFLICT_META,true);
            $match = $filter==='all' || ($filter==='unverified'&&!$human) || ($filter==='text_ai'&&!empty($row['text_ai']['used'])) || ($filter==='image_ai'&&!empty($row['image_ai']['used'])) || ($filter==='unclassified'&&($row['status']??'')==='unclassified') || ($filter==='corrected'&&$human==='corrected') || ($filter==='low'&&(float)($row['final']['confidence']??0)<.70) || ($filter==='close'&&(!empty($conflict)||preg_match('/競合|僅差|複数候補/u',$reason)));
            if ($match && $category && !in_array($category,self::clean_ids($row['final']['categories']??array()),true)) $match=false;
            if ($match) $rows[]=array('id'=>$id,'row'=>$row,'human'=>$human);
        }
        usort($rows,function($a,$b)use($orderby){ if($orderby==='name')return strcasecmp($a['row']['product_name'],$b['row']['product_name']); if($orderby==='method')return strcmp($a['row']['confirmed_stage'],$b['row']['confirmed_stage']); return (($a['row']['final']['confidence']??0)<=>($b['row']['final']['confidence']??0)); });
        return array_slice($rows,0,100);
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $s=self::statistics(); $final_accuracy=self::accuracy($s['final']);
        $algo_accuracy=self::accuracy($s['algorithm']); $text_accuracy=self::accuracy($s['text_ai']); $image_accuracy=self::accuracy($s['image_ai']);
        $review_rows=self::review_query();
        ?>
        <style>
        .nf-accuracy-wrap{max-width:1500px}.nf-accuracy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}.nf-accuracy-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:7px}.nf-accuracy-card strong{font-size:25px}.nf-accuracy-card small{color:#646970}.nf-accuracy-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:18px 0}.nf-accuracy-panel h2{margin-top:0}.nf-route{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.nf-route div{background:#f6f7f7;border-radius:7px;padding:15px}.nf-improvement{font-size:18px;color:#167331}.nf-history-step{display:inline-block;vertical-align:top;width:22%;min-width:180px;margin:0 1% 12px 0;padding:12px;border-left:4px solid #2271b1;background:#f6f7f7}.nf-audit-filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:15px}@media(max-width:782px){.nf-route{grid-template-columns:1fr}.nf-history-step{width:auto;display:block}}
        </style>
        <div class="wrap nf-accuracy-wrap"><h1>商品分類AIの判定履歴・精度検証</h1><p>信頼度（判定側の自己評価）と実測精度（人間確認済みGold Standardとの一致率）を分けて集計します。未確認商品は実測精度の分母に含みません。</p>
        <div class="nf-accuracy-grid"><?php
          self::card('総商品数',number_format_i18n($s['total']).'件');
          self::card('最終実測精度',$final_accuracy===null?'—':number_format_i18n($final_accuracy,2).'%',intval($s['correct']).' / '.intval($s['verified']).'件');
          self::card('Algorithm精度',$algo_accuracy===null?'—':number_format_i18n($algo_accuracy,2).'%',intval($s['algorithm']['correct']).' / '.intval($s['algorithm']['verified']).'件');
          self::card('Text AI精度',$text_accuracy===null?'—':number_format_i18n($text_accuracy,2).'%',intval($s['text_ai']['correct']).' / '.intval($s['text_ai']['verified']).'件');
          self::card('Image AI精度',$image_accuracy===null?'—':number_format_i18n($image_accuracy,2).'%',intval($s['image_ai']['correct']).' / '.intval($s['image_ai']['verified']).'件');
          self::card('未確認件数',number_format_i18n($s['unverified']).'件');
          self::card('判定不能件数',number_format_i18n($s['unclassified']).'件');
        ?></div>
        <div class="nf-accuracy-panel"><h2>全体統計</h2><table class="widefat striped"><tbody>
        <tr><th>判定済み商品</th><td><?php echo intval($s['decided']); ?>件</td><th>人間確認済み</th><td><?php echo intval($s['verified']); ?>件</td><th>未確認</th><td><?php echo intval($s['unverified']); ?>件</td></tr>
        <tr><th>正解</th><td><?php echo intval($s['correct']); ?>件</td><th>誤分類</th><td><?php echo intval($s['incorrect']); ?>件</td><th>人間修正</th><td><?php echo intval($s['corrected']); ?>件</td></tr>
        <tr><th>誤分類率</th><td><?php echo esc_html(number_format_i18n(self::rate($s['incorrect'],$s['verified']),2)); ?>%</td><th>判定不能率</th><td><?php echo esc_html(number_format_i18n(self::rate($s['unclassified'],$s['total']),2)); ?>%</td><th>人間修正率</th><td><?php echo esc_html(number_format_i18n(self::rate($s['corrected'],$s['verified']),2)); ?>%</td></tr>
        </tbody></table></div>
        <div class="nf-accuracy-panel"><h2>段階別の実測精度</h2><table class="widefat striped"><thead><tr><th>判定方式</th><th>処理／使用件数</th><th>人間確認済み</th><th>正解</th><th>誤分類</th><th>実測精度</th></tr></thead><tbody><?php self::metric_row('Algorithm',$s['algorithm']); self::metric_row('Text AI',$s['text_ai']); self::metric_row('Image AI',$s['image_ai']); self::metric_row('Final',$s['final']); ?></tbody></table></div>
        <?php $ca=self::accuracy($s['cumulative_algorithm']);$ct=self::accuracy($s['cumulative_text']);$ci=self::accuracy($s['cumulative_image']); ?>
        <div class="nf-accuracy-panel"><h2>累積実測精度</h2><table class="widefat striped"><tbody><tr><th>Algorithmまで</th><td><?php echo esc_html($ca===null?'—':number_format_i18n($ca,2).'%'); ?></td><td><?php echo intval($s['cumulative_algorithm']['correct']).' / '.intval($s['cumulative_algorithm']['verified']); ?>件</td></tr><tr><th>Algorithm + Text AI</th><td><?php echo esc_html($ct===null?'—':number_format_i18n($ct,2).'%'); ?></td><td><?php echo intval($s['cumulative_text']['correct']).' / '.intval($s['cumulative_text']['verified']); ?>件</td></tr><tr><th>Algorithm + Text AI + Image AI</th><td><?php echo esc_html($ci===null?'—':number_format_i18n($ci,2).'%'); ?></td><td><?php echo intval($s['cumulative_image']['correct']).' / '.intval($s['cumulative_image']['verified']); ?>件</td></tr></tbody></table><?php if($ca!==null&&$ct!==null): ?><p class="nf-improvement">Text AI追加による改善：<?php echo esc_html(sprintf('%+.2f',$ct-$ca)); ?>pt　<?php if($ci!==null): ?>Image AI追加による改善：<?php echo esc_html(sprintf('%+.2f',$ci-$ct)); ?>pt<?php endif; ?></p><?php endif; ?></div>
        <div class="nf-accuracy-panel"><h2>各判定方式の処理比率</h2><div class="nf-route"><?php foreach(array('algorithm'=>'Algorithmのみ','text_ai'=>'Text AI使用','image_ai'=>'Image AI使用') as $key=>$label): ?><div><strong><?php echo esc_html($label); ?></strong><p><?php echo esc_html(number_format_i18n(self::rate($s['route'][$key],$s['total']),2)); ?>%（<?php echo intval($s['route'][$key]); ?>件）</p></div><?php endforeach; ?></div></div>
        <div class="nf-accuracy-panel"><h2>最終信頼度別の実測正解率</h2><table class="widefat striped"><thead><tr><th>信頼度帯</th><th>実測正解率</th><th>正解／検証</th></tr></thead><tbody><?php foreach(array('95〜100%','90〜94%','80〜89%','70〜79%','70%未満') as $bucket): $b=$s['buckets'][$bucket]??array('verified'=>0,'correct'=>0); ?><tr><th><?php echo esc_html($bucket); ?></th><td><?php echo $b['verified']?esc_html(number_format_i18n(self::rate($b['correct'],$b['verified']),2)).'%':'—'; ?></td><td><?php echo intval($b['correct']).' / '.intval($b['verified']); ?>件</td></tr><?php endforeach; ?></tbody></table></div>
        <div class="nf-accuracy-panel"><h2>要確認商品</h2><form class="nf-audit-filters" method="get"><input type="hidden" name="post_type" value="<?php echo esc_attr(NF_Core::POST_TYPE); ?>"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>"><label>絞り込み<br><select name="nf_audit_filter"><?php foreach(array('unverified'=>'未確認','low'=>'最終信頼度70%未満','text_ai'=>'Text AI使用','image_ai'=>'Image AI使用','close'=>'複数候補・矛盾','unclassified'=>'判定不能','corrected'=>'人間修正済み','all'=>'すべて') as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($_GET['nf_audit_filter']??'unverified',$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>カテゴリ<br><?php wp_dropdown_categories(array('taxonomy'=>NF_Category::TAXONOMY,'hide_empty'=>false,'name'=>'nf_audit_category','show_option_all'=>'すべて','selected'=>absint($_GET['nf_audit_category']??0),'hierarchical'=>true)); ?></label><label>並び順<br><select name="nf_audit_order"><option value="confidence">信頼度の低い順</option><option value="name" <?php selected($_GET['nf_audit_order']??'','name'); ?>>商品名</option><option value="method" <?php selected($_GET['nf_audit_order']??'','method'); ?>>判定方式</option></select></label><?php submit_button('表示','secondary','',false); ?></form><table class="widefat striped"><thead><tr><th>商品</th><th>最終カテゴリ</th><th>判定方式</th><th>最終信頼度</th><th>人間確認</th><th></th></tr></thead><tbody><?php if(!$review_rows): ?><tr><td colspan="6">該当商品はありません。</td></tr><?php else: foreach($review_rows as $entry): $row=$entry['row']; ?><tr><td><strong><?php echo esc_html($row['product_name']); ?></strong></td><td><?php echo esc_html(implode('、',self::term_names($row['final']['categories']??array()))?:'判定不能'); ?></td><td><?php echo esc_html($row['confirmed_stage']??'—'); ?></td><td><?php echo esc_html(self::percent($row['final']['confidence']??null)); ?></td><td><?php echo esc_html($entry['human']==='correct'?'正解':($entry['human']==='corrected'?'修正済み':'未確認')); ?></td><td><a class="button" href="<?php echo esc_url(get_edit_post_link($entry['id'])); ?>">確認する</a></td></tr><?php endforeach; endif; ?></tbody></table><p class="description">管理画面の負荷を抑えるため最大100件を表示します。</p></div>
        </div><?php
    }
}
