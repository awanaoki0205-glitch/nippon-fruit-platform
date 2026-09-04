<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only intelligence layer over saved classification evidence.
 * It never invokes an AI model and never changes the classification pipeline.
 */
class NF_Intelligence_Dashboard {
    const PAGE = 'nf-customer-intelligence';
    const AUDIT_OPTION = 'nf_intelligence_audit_log';
    const QUALITY_META = '_nf_data_quality_score';
    const QUALITY_ISSUES_META = '_nf_data_quality_issues';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 45);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_nf_intelligence_csv', array(__CLASS__, 'export_csv'));
        add_action('admin_post_nf_intelligence_confirm', array(__CLASS__, 'confirm'));
        add_action('wp_login', array(__CLASS__, 'login_log'), 10, 2);
        add_action('before_delete_post', array(__CLASS__, 'delete_log'));
        add_action('set_user_role', array(__CLASS__, 'role_log'), 10, 3);
        add_action('updated_option', array(__CLASS__, 'setting_log'), 10, 3);
        add_action('admin_post_nf_reclassify_product', array(__CLASS__, 'ai_action_log'), 1);
        add_action('admin_post_nf_classification_resume', array(__CLASS__, 'ai_action_log'), 1);
    }

    public static function tenant_id() {
        return 'site-' . get_current_blog_id() . '-' . substr(hash('sha256', home_url('/')), 0, 12);
    }

    private static function can_view() {
        return current_user_can('nf_view_intelligence') || current_user_can('manage_options');
    }

    private static function can_review() {
        return current_user_can('nf_review_classification') || current_user_can('manage_options');
    }

    public static function menu() {
        add_submenu_page(
            'nf-customer-dashboard',
            'Classification Intelligence Dashboard',
            '分類インテリジェンス',
            'nf_view_intelligence',
            self::PAGE,
            array(__CLASS__, 'page')
        );
    }

    public static function assets($hook) {
        if (strpos((string)$hook, self::PAGE) === false) return;
        wp_enqueue_style('nf-intelligence', NF_PLUGIN_URL . 'assets/intelligence-dashboard.css', array(), NF_VERSION);
    }

    private static function ids() {
        // Each customer installation has its own WordPress DB. On multisite,
        // WordPress already scopes this query to the current blog tables.
        return get_posts(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC'));
    }

    private static function clean_ids($ids) {
        return array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    }

    private static function same($a, $b) {
        $a=self::clean_ids($a); $b=self::clean_ids($b); sort($a); sort($b);
        return $a === $b;
    }

    private static function quality($post_id, $row) {
        $input = NF_Category_Classifier::input($post_id);
        $score = 100; $issues = array();
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        if ($title === '') { $score-=30; $issues[]='商品名がありません'; }
        if (mb_strlen($title) > 140) { $score-=8; $issues[]='商品名が長く、SEO語が過多の可能性'; }
        if ($description === '') { $score-=12; $issues[]='商品説明がありません'; }
        if (empty($input['capacity']) && !preg_match('/\d+(?:\.\d+)?\s*(?:kg|g|ml|l|個|本|尾|玉|房)/iu',$title.' '.$description)) { $score-=12; $issues[]='容量・数量表記が不明確'; }
        if (!has_post_thumbnail($post_id) && !get_post_meta($post_id,'_nf_rakuten_image_url',true) && !get_post_meta($post_id,'_nf_yahoo_image_url',true)) { $score-=12; $issues[]='代表画像がありません'; }
        $municipalities=wp_get_post_terms($post_id,'nf_municipality',array('fields'=>'ids'));
        if (is_wp_error($municipalities) || !$municipalities) { $score-=8; $issues[]='自治体が未設定'; }
        $final_conf=(float)($row['final']['confidence']??0);
        if ($final_conf > 0 && $final_conf < .70) { $score-=15; $issues[]='最終confidenceが70%未満'; }
        elseif ($final_conf > 0 && $final_conf < .90) { $score-=7; $issues[]='最終confidenceが90%未満'; }
        if (in_array((string)($row['status']??''),array('review','unclassified','ai_error'),true)) { $score-=15; $issues[]='分類結果の確認が必要'; }
        if (!empty($row['text_ai']['used']) && !empty($row['image_ai']['used']) && !self::same($row['text_ai']['categories']??array(),$row['image_ai']['categories']??array())) { $score-=8; $issues[]='Text AIとImage AIの分類が不一致'; }
        $score=max(0,min(100,$score));
        if ((int)get_post_meta($post_id,self::QUALITY_META,true)!==$score) update_post_meta($post_id,self::QUALITY_META,$score);
        if (get_post_meta($post_id,self::QUALITY_ISSUES_META,true)!==$issues) update_post_meta($post_id,self::QUALITY_ISSUES_META,$issues);
        return array('score'=>$score,'issues'=>$issues);
    }

    private static function add_count(&$map, $label, $amount=1) {
        $label=trim((string)$label); if ($label==='') $label='未設定';
        $map[$label]=(int)($map[$label]??0)+(int)$amount;
    }

    private static function analyse() {
        $ids=self::ids(); $history=NF_Classification_History::statistics();
        $a=array(
            'ids'=>$ids,'history'=>$history,'classified'=>0,'low'=>0,'review'=>0,'quality_total'=>0,
            'category'=>array(),'municipality'=>array(),'portal'=>array(),'price'=>array(),'status'=>array(),
            'quality_issues'=>array(),'rows'=>array(),'confidence'=>array('algorithm'=>array(),'text_ai'=>array(),'image_ai'=>array()),
        );
        foreach($ids as $post_id) {
            $row=NF_Classification_History::current($post_id);
            $status=(string)($row['status']??'');
            if ($status && !in_array($status,array('unclassified','ai_error'),true)) $a['classified']++;
            $final_conf=max(0,min(1,(float)($row['final']['confidence']??0)));
            if ($final_conf < .70) $a['low']++;
            if (in_array($status,array('review','unclassified','ai_error','ai_pending','image_ai_pending'),true)) $a['review']++;
            foreach(array('algorithm','text_ai','image_ai') as $stage) {
                $used=$stage==='algorithm'||!empty($row[$stage]['used']);
                if ($used && isset($row[$stage]['confidence']) && $row[$stage]['confidence']!==null) $a['confidence'][$stage][]=(float)$row[$stage]['confidence'];
            }
            $terms=wp_get_post_terms($post_id,NF_Category::TAXONOMY);
            if (!is_wp_error($terms)) foreach($terms as $term) if ((int)$term->parent===0) self::add_count($a['category'],$term->name);
            $munis=wp_get_post_terms($post_id,'nf_municipality');
            if (!is_wp_error($munis) && $munis) foreach($munis as $term) self::add_count($a['municipality'],$term->name); else self::add_count($a['municipality'],'未設定');
            $rakuten=(bool)get_post_meta($post_id,'_nf_rakuten_url',true); $yahoo=(bool)get_post_meta($post_id,'_nf_yahoo_url',true);
            if ($rakuten) self::add_count($a['portal'],'楽天'); if ($yahoo) self::add_count($a['portal'],'Yahoo!'); if (!$rakuten&&!$yahoo) self::add_count($a['portal'],'未設定');
            $price=absint(get_post_meta($post_id,'_nf_price_min',true)?:get_post_meta($post_id,'_nf_price',true));
            $band=$price<10000?'1万円未満':($price<20000?'1〜2万円':($price<50000?'2〜5万円':'5万円以上')); self::add_count($a['price'],$band);
            self::add_count($a['status'],get_post_meta($post_id,'_nf_status',true)?:'未設定');
            $quality=self::quality($post_id,$row); $a['quality_total']+=$quality['score'];
            foreach($quality['issues'] as $issue) self::add_count($a['quality_issues'],$issue);
            $human=get_post_meta($post_id,NF_Classification_History::HUMAN_STATUS_META,true);
            if (!$human || $quality['score']<80 || $final_conf<.70 || in_array($status,array('review','unclassified','ai_error'),true)) {
                $a['rows'][]=array('id'=>$post_id,'title'=>get_the_title($post_id),'method'=>$row['confirmed_stage']??'algorithm','confidence'=>$final_conf,'quality'=>$quality['score'],'issues'=>$quality['issues'],'human'=>$human,'status'=>$status);
            }
        }
        foreach(array('category','municipality','portal','price','status','quality_issues') as $key) arsort($a[$key]);
        usort($a['rows'],function($x,$y){ return $x['confidence']===$y['confidence'] ? $x['quality']<=>$y['quality'] : $x['confidence']<=>$y['confidence']; });
        $a['quality_average']=$ids?round($a['quality_total']/count($ids),1):0;
        foreach($a['confidence'] as $stage=>$values) $a['confidence'][$stage]=$values?array_sum($values)/count($values):null;
        return $a;
    }

    private static function rate($n,$d) { return $d ? round(100*$n/$d,2) : 0; }
    private static function card($label,$value,$note='') { ?><div class="nf-ci-card"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($value); ?></strong><?php if($note): ?><small><?php echo esc_html($note); ?></small><?php endif; ?></div><?php }
    private static function bars($title,$data,$limit=10) {
        $data=array_slice($data,0,$limit,true); $max=$data?max($data):1;
        ?><section class="nf-ci-panel"><h2><?php echo esc_html($title); ?></h2><div class="nf-ci-bars"><?php foreach($data as $label=>$count): ?><div><span><?php echo esc_html($label); ?></span><i><b style="width:<?php echo esc_attr(round(100*$count/$max,1)); ?>%"></b></i><strong><?php echo intval($count); ?>件</strong></div><?php endforeach; ?></div></section><?php
    }

    private static function correction_panel() {
        if (!self::can_review()) return;
        $post_id=absint($_GET['correct_id']??0);
        if (!$post_id || get_post_type($post_id)!==NF_Core::POST_TYPE) return;
        $selected=wp_get_object_terms($post_id,NF_Category::TAXONOMY,array('fields'=>'ids'));
        $selected=is_wp_error($selected)?array():self::clean_ids($selected);
        $terms=get_terms(array('taxonomy'=>NF_Category::TAXONOMY,'hide_empty'=>false,'orderby'=>'name'));
        if(is_wp_error($terms)) $terms=array();
        ?><section class="nf-ci-panel" id="nf-ci-correction"><h2>正解分類を指定：<?php echo esc_html(get_the_title($post_id)); ?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="nf_intelligence_confirm"><input type="hidden" name="verification" value="corrected"><input type="hidden" name="post_id" value="<?php echo intval($post_id); ?>"><?php wp_nonce_field('nf_intelligence_confirm_'.$post_id); ?><div class="nf-ci-checklist"><?php foreach($terms as $term): $depth=count(get_ancestors($term->term_id,NF_Category::TAXONOMY,'taxonomy')); ?><label style="padding-left:<?php echo esc_attr($depth*12); ?>px"><input type="checkbox" name="gold_terms[]" value="<?php echo intval($term->term_id); ?>" <?php checked(in_array((int)$term->term_id,$selected,true)); ?>> <?php echo esc_html($term->name); ?></label><?php endforeach; ?></div><p><label><strong>修正理由</strong><br><textarea name="reason" rows="3" class="large-text" required></textarea></label></p><button class="button button-primary" type="submit">正解分類として保存</button></form></section><?php
    }

    private static function recommendations($a) {
        $out=array(); $total=max(1,count($a['ids']));
        foreach(array_slice($a['quality_issues'],0,4,true) as $issue=>$count) {
            if ($count<1) continue;
            $action='該当商品の元データを確認してください。';
            if (strpos($issue,'容量')!==false) $action='商品名または規格に「5kg」「2尾」など統一した容量・数量を追加すると、ルール確定率の改善が期待できます。';
            elseif (strpos($issue,'画像')!==false) $action='代表画像を設定すると、必要時の画像AI監査が可能になります。';
            elseif (strpos($issue,'自治体')!==false) $action='自治体を設定すると検索・集計・重複確認の精度が上がります。';
            elseif (strpos($issue,'不一致')!==false) $action='Text AIとImage AIの証拠が異なるため、人間確認を優先してください。';
            $out[]=array('title'=>$issue.'：'.$count.'件','body'=>$action,'impact'=>self::rate($count,$total).'%の商品が対象');
        }
        if (($a['history']['route']['image_ai']??0)>0) $out[]=array('title'=>'Image AI監査を限定利用中','body'=>'画像AIは全商品ではなく、テキストだけで確定困難な商品に限定されています。二重チェックの不一致商品を優先確認してください。','impact'=>intval($a['history']['route']['image_ai']).'件で使用');
        return $out;
    }

    public static function page() {
        if (!self::can_view()) wp_die('この分析画面を閲覧する権限がありません。');
        $a=self::analyse(); $s=$a['history']; $total=count($a['ids']); $verified=(int)$s['verified'];
        $final_accuracy=$verified?self::rate($s['correct'],$verified):null; $metrics=NF_Classification_Metrics::snapshot(); $current_period=NF_Classification_Metrics::period(30,0); $previous_period=NF_Classification_Metrics::period(30,30);
        $routes=$s['route']; $recs=self::recommendations($a);
        ?><div class="wrap nf-ci"><header class="nf-ci-head"><div><span>PRODUCT INTELLIGENCE</span><h1>Classification Intelligence Dashboard</h1><p>分類精度・商品データ品質・AI利用効率を、自社データだけで継続的に確認できます。</p></div><?php if(current_user_can('nf_export_intelligence')||current_user_can('manage_options')): ?><div><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=nf_intelligence_csv'),'nf_intelligence_csv')); ?>">CSV出力</a></div><?php endif; ?></header>
        <div class="nf-ci-cards"><?php self::card('総商品数',$total.'件'); self::card('分類済み',$a['classified'].'件'); self::card('未分類',max(0,$total-$a['classified']).'件'); self::card('最終実測精度',$final_accuracy===null?'—':number_format_i18n($final_accuracy,2).'%',$s['correct'].' / '.$verified.'件'); self::card('未確認',$s['unverified'].'件'); self::card('要確認',$a['review'].'件'); self::card('Data Quality Score',$a['quality_average'].' / 100'); ?></div>
        <section class="nf-ci-panel"><h2>段階別分類分析</h2><table class="widefat striped"><thead><tr><th>段階</th><th>処理／使用</th><th>人間確認</th><th>正解</th><th>誤分類</th><th>実測Accuracy</th><th>平均Confidence</th></tr></thead><tbody><?php foreach(array('algorithm'=>'Algorithm','text_ai'=>'Text AI','image_ai'=>'Image AI','final'=>'Final') as $key=>$label): $srow=$s[$key]; $avg=$key==='final'?null:($a['confidence'][$key]??null); ?><tr><th><?php echo esc_html($label); ?></th><td><?php echo intval($srow['processed']); ?>件</td><td><?php echo intval($srow['verified']); ?>件</td><td><?php echo intval($srow['correct']); ?>件</td><td><?php echo intval($srow['incorrect']); ?>件</td><td><strong><?php echo $srow['verified']?esc_html(number_format_i18n(self::rate($srow['correct'],$srow['verified']),2)).'%':'—'; ?></strong><small><?php echo intval($srow['correct']).' / '.intval($srow['verified']); ?>件</small></td><td><?php echo $avg===null?'—':esc_html(number_format_i18n(100*$avg,1)).'%'; ?></td></tr><?php endforeach; ?></tbody></table><p class="description">Confidenceは判定側の自己評価、Accuracyは人間確認済みGold Standardとの一致率です。未確認商品はAccuracyの分母に含みません。</p></section>
        <section class="nf-ci-panel"><h2>AI利用効率</h2><div class="nf-ci-cards compact"><?php self::card('Algorithmのみ',self::rate($routes['algorithm'],$total).'%',$routes['algorithm'].'件'); self::card('Text AI使用',self::rate($routes['text_ai'],$total).'%',$routes['text_ai'].'件'); self::card('Image AI使用',self::rate($routes['image_ai'],$total).'%',$routes['image_ai'].'件'); self::card('Text AI累計呼出',$metrics['text_ai_calls'].'件'); self::card('Image AI累計呼出',$metrics['image_ai_calls'].'件'); self::card('AI利用削減率',self::rate(max(0,$total-$routes['text_ai']-$routes['image_ai']),$total).'%','全商品AI判定との件数比較'); ?></div><p class="description">API実費はトークン数が過去データに保存されていないため未確定です。推測額を請求実績として表示しません。</p></section>
        <section class="nf-ci-panel"><h2>直近30日と前30日のAI利用比較</h2><table class="widefat striped"><thead><tr><th>指標</th><th>直近30日</th><th>前30日</th><th>増減</th></tr></thead><tbody><?php foreach(array('text_ai_calls'=>'Text AI呼出','image_ai_calls'=>'Image AI呼出','review'=>'要確認化','api_errors'=>'APIエラー') as $key=>$label): $diff=$current_period[$key]-$previous_period[$key]; ?><tr><th><?php echo esc_html($label); ?></th><td><?php echo intval($current_period[$key]); ?>件</td><td><?php echo intval($previous_period[$key]); ?>件</td><td><?php echo esc_html(sprintf('%+d',$diff)); ?>件</td></tr><?php endforeach; ?></tbody></table><p class="description">分類Accuracyの厳密な期間比較は、各期間に人間確認されたGold Standardが十分に蓄積された後に表示可能になります。</p></section>
        <div class="nf-ci-grid"><?php self::bars('大カテゴリ別商品数',$a['category']); self::bars('自治体別商品数',$a['municipality']); self::bars('掲載ポータル',$a['portal']); self::bars('価格帯別商品数',$a['price']); self::bars('商品状態別',$a['status']); self::bars('データ品質上の課題',$a['quality_issues']); ?></div>
        <section class="nf-ci-panel"><h2>改善提案</h2><div class="nf-ci-recommendations"><?php foreach($recs as $rec): ?><article><strong><?php echo esc_html($rec['title']); ?></strong><p><?php echo esc_html($rec['body']); ?></p><small><?php echo esc_html($rec['impact']); ?></small></article><?php endforeach; ?><?php if(!$recs): ?><p>現在、優先度の高い改善提案はありません。</p><?php endif; ?></div></section>
        <section class="nf-ci-panel" id="nf-ci-review"><h2>要確認商品</h2><table class="widefat striped"><thead><tr><th>商品</th><th>判定方式</th><th>Final confidence</th><th>品質</th><th>問題</th><th>人間確認</th></tr></thead><tbody><?php foreach(array_slice($a['rows'],0,100) as $item): ?><tr><td><strong><?php echo esc_html($item['title']); ?></strong><small>ID: <?php echo intval($item['id']); ?></small></td><td><?php echo esc_html($item['method']); ?></td><td><?php echo esc_html(number_format_i18n(100*$item['confidence'],1)); ?>%</td><td><?php echo intval($item['quality']); ?>/100</td><td><?php echo esc_html(implode('／',$item['issues'])); ?></td><td><?php if($item['human']): ?><p>確認済み</p><?php elseif(self::can_review()): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="nf_intelligence_confirm"><input type="hidden" name="verification" value="correct"><input type="hidden" name="post_id" value="<?php echo intval($item['id']); ?>"><?php wp_nonce_field('nf_intelligence_confirm_'.$item['id']); ?><button class="button button-primary" type="submit">現在の分類を正解として確認</button></form><?php else: ?>未確認<?php endif; ?><?php if(self::can_review()): ?><p><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE,'correct_id'=>$item['id']),admin_url('admin.php')).'#nf-ci-correction'); ?>">分類を修正</a></p><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
        <?php self::correction_panel(); ?>
        <section class="nf-ci-panel"><h2>監査ログ（最新50件）</h2><table class="widefat striped"><thead><tr><th>日時</th><th>ユーザー</th><th>操作</th><th>対象</th></tr></thead><tbody><?php foreach(array_slice(array_reverse(self::audit_entries()),0,50) as $log): ?><tr><td><?php echo esc_html($log['time']); ?></td><td><?php echo esc_html($log['user']); ?></td><td><?php echo esc_html($log['action']); ?></td><td><?php echo esc_html($log['target']); ?></td></tr><?php endforeach; ?></tbody></table></section></div><?php
    }

    public static function confirm() {
        if (!self::can_review()) wp_die('確認権限がありません。');
        $post_id=absint($_POST['post_id']??0); check_admin_referer('nf_intelligence_confirm_'.$post_id);
        if (get_post_type($post_id)!==NF_Core::POST_TYPE) wp_die('対象商品が正しくありません。');
        $verification=sanitize_key($_POST['verification']??'correct');
        if(!in_array($verification,array('correct','corrected'),true)) wp_die('確認方法が正しくありません。');
        $gold=$verification==='corrected'?self::clean_ids(wp_unslash($_POST['gold_terms']??array())):array();
        $reason=sanitize_textarea_field(wp_unslash($_POST['reason']??''));
        $saved=NF_Classification_History::save_verification($post_id,$verification,$gold,$reason);
        if(is_wp_error($saved)) wp_die(esc_html($saved->get_error_message()));
        wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'confirmed'=>1),admin_url('admin.php'))); exit;
    }

    public static function export_csv() {
        if (!(current_user_can('nf_export_intelligence')||current_user_can('manage_options'))) wp_die('CSV出力権限がありません。');
        check_admin_referer('nf_intelligence_csv'); self::audit('csv_export','classification-intelligence.csv');
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="classification-intelligence-' . wp_date('Ymd-His') . '.csv"');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,array('tenant_id','商品ID','商品名','状態','確定段階','Algorithm confidence','Text AI使用','Text confidence','Image AI使用','Image confidence','Final confidence','人間確認','品質スコア','品質課題'));
        foreach(self::ids() as $post_id) { $row=NF_Classification_History::current($post_id); $q=self::quality($post_id,$row); fputcsv($out,array(self::tenant_id(),$post_id,get_the_title($post_id),$row['status']??'',$row['confirmed_stage']??'', $row['algorithm']['confidence']??'',!empty($row['text_ai']['used'])?'yes':'no',$row['text_ai']['confidence']??'',!empty($row['image_ai']['used'])?'yes':'no',$row['image_ai']['confidence']??'',$row['final']['confidence']??'',get_post_meta($post_id,NF_Classification_History::HUMAN_STATUS_META,true),$q['score'],implode('|',$q['issues']))); }
        fclose($out); exit;
    }

    public static function audit($action,$target='') {
        $entries=get_option(self::AUDIT_OPTION,array()); if(!is_array($entries)) $entries=array(); $user=wp_get_current_user();
        $entries[]=array('tenant'=>self::tenant_id(),'time'=>current_time('mysql'),'user'=>$user->exists()?($user->user_login.' (#'.$user->ID.')'):'system','action'=>sanitize_key($action),'target'=>sanitize_text_field($target));
        if(count($entries)>2000) $entries=array_slice($entries,-2000); update_option(self::AUDIT_OPTION,$entries,false);
    }
    public static function audit_entries() { $v=get_option(self::AUDIT_OPTION,array()); return is_array($v)?array_values(array_filter($v,function($e){ return ($e['tenant']??'')===self::tenant_id(); })):array(); }
    public static function login_log($login,$user) { if ($user instanceof WP_User && user_can($user,'nf_view_dashboard')) self::audit('login','顧客管理画面'); }
    public static function delete_log($post_id) { if (get_post_type($post_id)===NF_Core::POST_TYPE) self::audit('product_deleted','商品ID '.absint($post_id)); }
    public static function role_log($user_id,$role,$old_roles) { self::audit('role_changed','ユーザーID '.absint($user_id).'：'.sanitize_key($role)); }
    public static function setting_log($option,$old,$value) { if($option!==self::AUDIT_OPTION && strpos((string)$option,'nf_')===0) self::audit('setting_changed',sanitize_key($option)); }
    public static function ai_action_log() { self::audit('ai_reclassification_requested','商品ID '.absint($_REQUEST['post_id']??0)); }
}
