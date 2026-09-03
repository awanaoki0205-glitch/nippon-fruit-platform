<?php
if ( ! defined('ABSPATH') ) exit;

class NF_Quality {
    const PAGE_SLUG = 'nf-quality-review';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 55);
        add_action('add_meta_boxes_' . NF_Core::POST_TYPE, array(__CLASS__, 'meta_box'));
        add_action('save_post_' . NF_Core::POST_TYPE, array(__CLASS__, 'save'), 5, 2);
        add_filter('manage_' . NF_Core::POST_TYPE . '_posts_columns', array(__CLASS__, 'columns'));
        add_action('manage_' . NF_Core::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'column'), 10, 2);
        add_action('restrict_manage_posts', array(__CLASS__, 'filter_control'));
        add_action('pre_get_posts', array(__CLASS__, 'apply_filter'));
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            '品質チェック', '品質チェック', 'edit_posts', self::PAGE_SLUG,
            array(__CLASS__, 'page')
        );
    }

    public static function meta_box() {
        add_meta_box('nf-quality-lock', '自動分類の確認', array(__CLASS__, 'meta_box_html'), NF_Core::POST_TYPE, 'side', 'high');
    }

    public static function meta_box_html($post) {
        wp_nonce_field('nf_quality_save', 'nf_quality_nonce');
        $locked = get_post_meta($post->ID, NF_Category::CLASSIFICATION_LOCK_META, true) === '1';
        $confidence = get_post_meta($post->ID, NF_Category::CONFIDENCE_META, true) ?: '未判定';
        $status = get_post_meta($post->ID, NF_Category_Classifier::STATUS_META, true) ?: '未判定';
        $status_labels = array('rule_classified'=>'ルール判定済み','conflict_resolved'=>'カテゴリ矛盾補正済み','ai_classified'=>'AI判定済み','text_ai_classified'=>'テキストAI判定済み','image_ai_classified'=>'画像AI判定済み','ai_pending'=>'テキストAI待ち','image_ai_pending'=>'画像AI待ち','review'=>'要確認','unclassified'=>'未分類','ai_error'=>'AIエラー','manual'=>'手動確定');
        $labels = array('high'=>'高い','medium'=>'要確認','low'=>'低い','manual'=>'手動確定');
        $terms=wp_get_post_terms($post->ID,NF_Category::TAXONOMY,array('fields'=>'names')); $terms=is_wp_error($terms)?array():$terms;
        $reason=get_post_meta($post->ID,NF_Category::REVIEW_REASON_META,true);
        ?>
        <p><strong>分類状態：</strong><?php echo esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status); ?></p>
        <p><strong>最終カテゴリ：</strong><?php echo esc_html($terms?implode('、',$terms):'未分類'); ?></p>
        <p><strong>信頼度：</strong><?php echo esc_html(isset($labels[$confidence]) ? $labels[$confidence] : $confidence); ?></p>
        <?php $text_conf=get_post_meta($post->ID,NF_Classification_Evidence::TEXT_CONFIDENCE_META,true); $image_conf=get_post_meta($post->ID,NF_Classification_Evidence::IMAGE_CONFIDENCE_META,true); $final_conf=get_post_meta($post->ID,NF_Classification_Evidence::FINAL_CONFIDENCE_META,true); $method=get_post_meta($post->ID,NF_Category_Classifier::METHOD_META,true); $image_used=get_post_meta($post->ID,NF_Classification_Evidence::IMAGE_USED_META,true)==='1'; ?>
        <p><strong>判定元：</strong><?php echo esc_html($method?:'未判定'); ?></p>
        <p><strong>テキスト信頼度：</strong><?php echo esc_html($text_conf!==''?number_format((float)$text_conf,2):'—'); ?><br><strong>画像信頼度：</strong><?php echo esc_html($image_conf!==''?number_format((float)$image_conf,2):'—'); ?><br><strong>最終信頼度：</strong><?php echo esc_html($final_conf!==''?number_format((float)$final_conf,2):'—'); ?></p>
        <p><strong>画像AI：</strong><?php echo $image_used?'使用済み':'未使用'; ?></p>
        <p><strong>判定理由：</strong><?php echo esc_html($reason?:'—'); ?></p>
        <label style="display:block;line-height:1.6">
          <input type="checkbox" name="nf_category_manual_lock" value="1" <?php checked($locked); ?>>
          <strong>現在のカテゴリを手動確定する</strong>
        </label>
        <p class="description">チェックすると、1時間ごとの同期や一括再分類でカテゴリが上書き・除去されません。</p>
        <?php if(current_user_can('manage_options')): ?><hr><p><strong>この商品の再判定</strong></p><?php foreach(array('rule'=>'ルールから','text'=>'テキストAIまで','image'=>'画像AIを含める') as $stage=>$label): $url=wp_nonce_url(admin_url('admin-post.php?action=nf_reclassify_product&post_id='.$post->ID.'&stage='.$stage),'nf_reclassify_product_'.$post->ID.'_'.$stage); ?><p><a class="button" href="<?php echo esc_url($url); ?>" onclick="return confirm('この商品を<?php echo esc_js($label); ?>再判定します。よろしいですか？')"><?php echo esc_html($label); ?>再判定</a></p><?php endforeach; endif; ?>
        <?php
    }

    public static function save($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (empty($_POST['nf_quality_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nf_quality_nonce'])), 'nf_quality_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $locked = !empty($_POST['nf_category_manual_lock']);
        update_post_meta($post_id, NF_Category::CLASSIFICATION_LOCK_META, $locked ? '1' : '0');
        if ($locked) {
            update_post_meta($post_id, NF_Category::CONFIDENCE_META, 'manual');
            update_post_meta($post_id, NF_Category_Classifier::STATUS_META, 'manual');
            update_post_meta($post_id, NF_Category_Classifier::METHOD_META, 'manual');
            delete_post_meta($post_id, NF_Category::REVIEW_REASON_META);
        } else {
            delete_post_meta($post_id, NF_Category_Classifier::INPUT_HASH_META);
            NF_Category_Classifier::queue($post_id);
        }
    }

    public static function columns($columns) {
        $columns['nf_quality'] = '分類状態';
        return $columns;
    }

    public static function column($column, $post_id) {
        if ($column !== 'nf_quality') return;
        $value = get_post_meta($post_id, NF_Category_Classifier::STATUS_META, true);
        $labels = array('rule_classified'=>'ルール判定済み','conflict_resolved'=>'カテゴリ矛盾補正済み','ai_classified'=>'AI判定済み','text_ai_classified'=>'テキストAI判定済み','image_ai_classified'=>'画像AI判定済み','ai_pending'=>'テキストAI待ち','image_ai_pending'=>'画像AI待ち','review'=>'要確認','unclassified'=>'未分類','ai_error'=>'AIエラー','manual'=>'手動確定');
        echo esc_html(isset($labels[$value]) ? $labels[$value] : '未判定');
    }

    public static function filter_control() {
        global $typenow;
        if ($typenow !== NF_Core::POST_TYPE) return;
        $current = isset($_GET['nf_quality']) ? sanitize_key($_GET['nf_quality']) : '';
        ?>
        <select name="nf_quality">
          <option value="">すべての分類状態</option>
          <option value="review" <?php selected($current, 'review'); ?>>要確認・未判定</option>
          <option value="manual" <?php selected($current, 'manual'); ?>>手動確定</option>
          <option value="high" <?php selected($current, 'high'); ?>>自動判定・高</option>
          <option value="conflict" <?php selected($current, 'conflict'); ?>>カテゴリ矛盾検出</option>
        </select>
        <?php
    }

    public static function apply_filter($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== NF_Core::POST_TYPE) return;
        $filter = isset($_GET['nf_quality']) ? sanitize_key($_GET['nf_quality']) : '';
        if ($filter === 'review') {
            $query->set('meta_query', array('relation'=>'OR',
                array('key'=>NF_Category::CONFIDENCE_META, 'value'=>'low'),
                array('key'=>NF_Category::CONFIDENCE_META, 'compare'=>'NOT EXISTS'),
            ));
        } elseif ($filter === 'conflict') {
            $query->set('meta_query', array(array('key'=>NF_Category_Consistency::CONFLICT_META,'compare'=>'EXISTS')));
        } elseif (in_array($filter, array('manual','high'), true)) {
            $query->set('meta_key', NF_Category::CONFIDENCE_META);
            $query->set('meta_value', $filter);
        }
    }

    private static function count_by_confidence($value, $include_missing = false) {
        $meta = array(array('key'=>NF_Category::CONFIDENCE_META, 'value'=>$value));
        if ($include_missing) $meta = array('relation'=>'OR', $meta[0], array('key'=>NF_Category::CONFIDENCE_META,'compare'=>'NOT EXISTS'));
        $q = new WP_Query(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_query'=>$meta));
        return (int)$q->found_posts;
    }

    private static function count_by_statuses($statuses) {
        $q = new WP_Query(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_query'=>array(array('key'=>NF_Category_Classifier::STATUS_META,'value'=>(array)$statuses,'compare'=>'IN'))));
        return (int)$q->found_posts;
    }

    public static function page() {
        if (!current_user_can('edit_posts')) return;
        $review = self::count_by_statuses(array('review','unclassified','ai_error'));
        $pending = self::count_by_statuses(array('ai_pending','image_ai_pending'));
        $manual = self::count_by_statuses(array('manual'));
        $high = self::count_by_statuses(array('rule_classified','conflict_resolved','ai_classified','text_ai_classified','image_ai_classified'));
        $conflict_query = new WP_Query(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_query'=>array(array('key'=>NF_Category_Consistency::CONFLICT_META,'compare'=>'EXISTS'))));
        $conflicts = (int)$conflict_query->found_posts;
        $base = admin_url('edit.php?post_type=' . NF_Core::POST_TYPE);
        $items = new WP_Query(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>30,'orderby'=>'modified','order'=>'DESC','meta_query'=>array(array('key'=>NF_Category_Classifier::STATUS_META,'value'=>array('review','unclassified','ai_error','ai_pending','image_ai_pending'),'compare'=>'IN'))));
        $conflict_items = new WP_Query(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>30,'orderby'=>'modified','order'=>'DESC','meta_query'=>array(array('key'=>NF_Category_Consistency::CONFLICT_META,'compare'=>'EXISTS'))));
        ?>
        <div class="wrap nf-admin-hub">
          <h1>返礼品の品質チェック</h1>
          <p class="nf-hub-lead">自動分類の確信度が低い商品だけを確認します。正しいカテゴリに直した後、「現在のカテゴリを手動確定する」にチェックしてください。</p>
          <?php NF_Classification_Admin::render_progress_panel(); ?>
          <div class="nf-status-grid nf-quality-summary">
            <a class="nf-status-card is-warning" href="<?php echo esc_url(add_query_arg('nf_quality','review',$base)); ?>"><span>要確認・未分類・AIエラー</span><strong><?php echo intval($review); ?>件</strong></a>
            <div class="nf-status-card"><span>AI判定待ち</span><strong><?php echo intval($pending); ?>件</strong></div>
            <a class="nf-status-card is-ok" href="<?php echo esc_url(add_query_arg('nf_quality','high',$base)); ?>"><span>ルール・AI判定済み</span><strong><?php echo intval($high); ?>件</strong></a>
            <a class="nf-status-card is-warning" href="<?php echo esc_url(add_query_arg('nf_quality','conflict',$base)); ?>"><span>カテゴリ矛盾検出</span><strong><?php echo intval($conflicts); ?>件</strong></a>
            <a class="nf-status-card" href="<?php echo esc_url(add_query_arg('nf_quality','manual',$base)); ?>"><span>手動確定</span><strong><?php echo intval($manual); ?>件</strong></a>
          </div>
          <?php if ($conflict_items->have_posts()): ?>
          <div class="nf-quality-table">
            <h2>カテゴリ矛盾検出</h2>
            <table class="widefat striped"><thead><tr><th>商品</th><th>判定</th><th>自動補正</th><th></th></tr></thead><tbody>
            <?php foreach ($conflict_items->posts as $item):
                $conflict = get_post_meta($item->ID, NF_Category_Consistency::CONFLICT_META, true);
                $rows = isset($conflict['conflicts']) ? (array)$conflict['conflicts'] : array();
                $accepted_names = array_values(array_filter(wp_list_pluck($rows, 'accepted_root')));
                $rejected_names = array_values(array_filter(wp_list_pluck($rows, 'rejected_root'))); ?>
              <tr><td><strong><?php echo esc_html(get_the_title($item)); ?></strong></td><td>カテゴリ矛盾</td><td><?php echo esc_html($rejected_names ? implode('、', array_unique($rejected_names)) . ' を除外（採用: ' . implode('、', array_unique($accepted_names)) . '）' : '管理者確認が必要'); ?></td><td><a class="button" href="<?php echo esc_url(get_edit_post_link($item->ID)); ?>">確認する</a></td></tr>
            <?php endforeach; ?></tbody></table>
          </div>
          <?php endif; wp_reset_postdata(); ?>
          <div class="nf-quality-table">
            <h2>優先して確認する商品</h2>
            <?php if (!$items->have_posts()): ?><p>現在、要確認商品はありません。</p><?php else: ?>
            <table class="widefat striped"><thead><tr><th>商品</th><th>理由</th><th>現在のカテゴリ</th><th></th></tr></thead><tbody>
            <?php foreach ($items->posts as $item): $terms = get_the_terms($item->ID, NF_Category::TAXONOMY); ?>
              <tr><td><strong><?php echo esc_html(get_the_title($item)); ?></strong></td><td><?php echo esc_html(get_post_meta($item->ID, NF_Category::REVIEW_REASON_META, true) ?: '未判定（再分類または確認が必要）'); ?></td><td><?php echo esc_html($terms && !is_wp_error($terms) ? implode('、', wp_list_pluck($terms,'name')) : 'なし'); ?></td><td><a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($item->ID)); ?>">確認する</a></td></tr>
            <?php endforeach; ?></tbody></table><?php endif; wp_reset_postdata(); ?>
          </div>
        </div>
        <?php
    }
}
