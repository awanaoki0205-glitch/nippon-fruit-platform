<?php
if (!defined('ABSPATH')) exit;

class NF_AI_Category_Classifier {
    const OPT_ENABLED = 'nf_ai_classification_enabled';
    const OPT_API_KEY = 'nf_ai_classification_api_key';
    const OPT_MODEL = 'nf_ai_classification_model';
    const OPT_HIGH = 'nf_ai_classification_high_threshold';
    const OPT_MEDIUM = 'nf_ai_classification_medium_threshold';
    const OPT_BATCH = 'nf_ai_classification_batch_size';
    const QUEUE_LOCK = 'nf_ai_category_queue_lock';
    const LAST_STARTED = 'nf_ai_category_last_started';
    const LAST_SUCCESS = 'nf_ai_category_last_success';
    const LAST_ERROR = 'nf_ai_category_last_error';
    const LAST_ERROR_AT = 'nf_ai_category_last_error_at';

    public static function init() {
        // WP-Cron is traffic driven. Recover a lost single event whenever an
        // administrator returns to WordPress, without running the API call in
        // the page-rendering request itself.
        add_action('admin_init', array(__CLASS__, 'watchdog'));
    }
    public static function enabled() { return get_option(self::OPT_ENABLED, '0') === '1' && trim((string)get_option(self::OPT_API_KEY, '')) !== ''; }

    public static function schedule($post_id) {
        if (!wp_next_scheduled(NF_Category_Classifier::CRON_HOOK)) wp_schedule_single_event(time() + 20, NF_Category_Classifier::CRON_HOOK);
    }

    public static function watchdog() {
        if (!self::enabled() || get_transient('nf_ai_category_watchdog')) return;
        set_transient('nf_ai_category_watchdog', '1', MINUTE_IN_SECONDS);
        $next = wp_next_scheduled(NF_Category_Classifier::CRON_HOOK);
        if ($next && $next < time() - 5 * MINUTE_IN_SECONDS) {
            wp_unschedule_event($next, NF_Category_Classifier::CRON_HOOK);
            $next = false;
        }
        if (self::pending_count() > 0 && !$next) self::schedule(0);
    }

    public static function pending_count() {
        $q = new WP_Query(array(
            'post_type'=>NF_Core::POST_TYPE, 'post_status'=>'any', 'posts_per_page'=>1,
            'fields'=>'ids', 'no_found_rows'=>false,
            'meta_query'=>array('relation'=>'OR',
                array('key'=>NF_Category_Classifier::STATUS_META,'value'=>'ai_pending'),
                array('key'=>NF_Category_Classifier::STATUS_META,'value'=>'image_ai_pending'),
            ),
        ));
        return (int)$q->found_posts;
    }

    private static function acquire_lock() {
        $now = time();
        $expires = (int)get_option(self::QUEUE_LOCK, 0);
        if ($expires > $now) return false;
        if ($expires) delete_option(self::QUEUE_LOCK);
        return add_option(self::QUEUE_LOCK, $now + 5 * MINUTE_IN_SECONDS, '', 'no');
    }

    private static function release_lock() { delete_option(self::QUEUE_LOCK); }

    public static function record_queue_error($message) {
        update_option(self::LAST_ERROR, sanitize_text_field((string)$message), false);
        update_option(self::LAST_ERROR_AT, time(), false);
    }

    public static function process_queue($limit_override = 0) {
        if (!self::enabled() || !self::acquire_lock()) return false;
        update_option(self::LAST_STARTED, time(), false);
        $ran = false;
        try {
            $limit = $limit_override > 0 ? (int)$limit_override : (int)get_option(self::OPT_BATCH, 3);
            $limit = max(1, min(20, $limit));
            $q = new WP_Query(array(
                'post_type'=>NF_Core::POST_TYPE,'post_status'=>'any','posts_per_page'=>$limit,'fields'=>'ids','orderby'=>'modified','order'=>'ASC',
                'meta_query'=>array('relation'=>'OR',
                    array('key'=>NF_Category_Classifier::STATUS_META,'value'=>'ai_pending'),
                    array('key'=>NF_Category_Classifier::STATUS_META,'value'=>'image_ai_pending'),
                    array('relation'=>'AND',
                        array('key'=>NF_Category_Classifier::STATUS_META,'value'=>'ai_error'),
                        array('relation'=>'OR',
                            array('key'=>NF_Category_Classifier::AI_RETRY_META,'value'=>time(),'compare'=>'<=','type'=>'NUMERIC'),
                            array('key'=>NF_Image_Category_Classifier::RETRY_META,'value'=>time(),'compare'=>'<=','type'=>'NUMERIC'),
                        ),
                    ),
                ),
            ));
            if (!$q->posts && self::pending_count() > 0) self::record_queue_error('AI待ち商品を取得できませんでした。投稿状態または検索条件を確認してください。');
            foreach ($q->posts as $post_id) {
                $status=get_post_meta($post_id,NF_Category_Classifier::STATUS_META,true);
                $method=get_post_meta($post_id,NF_Category_Classifier::METHOD_META,true);
                if (($status==='image_ai_pending'||($status==='ai_error'&&$method==='image_ai')) && class_exists('NF_Image_Category_Classifier')) NF_Image_Category_Classifier::classify((int)$post_id);
                else self::classify((int)$post_id);
                $ran = true;
            }
            if (class_exists('NF_Category')) NF_Category::update_ai_progress();
        } catch (Throwable $e) {
            self::record_queue_error('AI処理内部エラー: ' . $e->getMessage());
        } finally {
            self::release_lock();
        }
        if (self::pending_count() > 0) self::schedule(0);
        return $ran;
    }

    public static function category_catalog() {
        $terms = get_terms(array('taxonomy'=>NF_Category::TAXONOMY,'hide_empty'=>false));
        $out = array();
        if (is_wp_error($terms)) return $out;
        foreach ($terms as $term) {
            if (get_term_meta($term->term_id, NF_Category_Classifier::AI_TARGET_META, true) === '0') continue;
            $path = array();
            foreach (array_reverse(get_ancestors($term->term_id, NF_Category::TAXONOMY, 'taxonomy')) as $id) { $p = get_term($id, NF_Category::TAXONOMY); if ($p && !is_wp_error($p)) $path[] = $p->name; }
            $path[] = $term->name;
            $out[] = array(
                'id'=>(int)$term->term_id,
                'parent_id'=>(int)$term->parent,
                'path'=>implode(' > ', array_values(array_unique($path))),
                'description'=>wp_strip_all_tags((string)$term->description),
                'aliases'=>(array)get_term_meta($term->term_id,NF_Category_Classifier::ALIASES_META,true),
                'exclusive_term_ids'=>class_exists('NF_Category_Consistency') ? NF_Category_Consistency::exclusive_ids($term->term_id) : array(),
            );
        }
        return $out;
    }

    public static function should_use_image($result,$logical_conflict=false,$requested_stage='',$has_image=true) {
        if (!$has_image || !class_exists('NF_Image_Category_Classifier') || !NF_Image_Category_Classifier::enabled()) return false;
        if ($requested_stage==='text') return false;
        if ($requested_stage==='image') return true;
        $confidence=max(0,min(1,(float)($result['text_confidence']??$result['confidence']??0)));
        $trigger=(float)get_option(NF_Image_Category_Classifier::OPT_TRIGGER,.75);
        return !empty($result['image_analysis_required']) || !empty($result['unclassified']) || empty($result['accepted_categories']) || $confidence<$trigger || $logical_conflict;
    }

    public static function classify($post_id) {
        if (get_post_meta($post_id, NF_Category::CLASSIFICATION_LOCK_META, true) === '1') {
            NF_Category_Classifier::set_status($post_id, 'manual', 'manual', 1, '管理者が手動確定');
            return;
        }
        if (class_exists('NF_Commercial_Config') && !NF_Commercial_Config::can_use_ai('text_ai')) {
            NF_Category_Classifier::set_status($post_id,'text_ai_pending','rule',0,'契約機能または月間AI利用上限により保留');
            return;
        }
        $input = NF_Category_Classifier::input($post_id);
        $catalog = self::category_catalog();
        $rule_result = get_post_meta($post_id, NF_Category_Classifier::RESULT_META, true);
        $current_terms = wp_get_object_terms($post_id, NF_Category::TAXONOMY, array('fields'=>'ids'));
        $current_terms = is_wp_error($current_terms) ? array() : array_map('intval', $current_terms);
        $attributes = get_post_meta($post_id, NF_Category_Consistency::ATTRIBUTES_META, true);
        $hash = hash('sha256', wp_json_encode(array($input, $catalog, $rule_result, $current_terms)));
        $cached_hash = get_post_meta($post_id, NF_Category_Classifier::AI_HASH_META, true);
        $cached = get_post_meta($post_id, NF_Category_Classifier::AI_RESULT_META, true);
        if ($cached_hash === $hash && is_array($cached)) {
            update_option(self::LAST_SUCCESS, time(), false);
            delete_option(self::LAST_ERROR);
            delete_option(self::LAST_ERROR_AT);
            return self::accept($post_id, $cached, true);
        }

        $allowed = array_map('intval', wp_list_pluck($catalog, 'id'));
        $prompt = "登録済みカテゴリだけから返礼品を分類してください。商品情報に明確な根拠が存在するカテゴリのみ選択し、根拠がない場合は推測しないでください。商品名を最優先し、説明は補助情報です。発送月だけで梨品種等を推測してはいけません。単品では含まれない別品目を付けず、定期便・詰め合わせだけ実際に含まれる複数品目を許可します。判断不能ならunclassified=true、requires_review=trueを返してください。\n\n商品:\n" . wp_json_encode($input, JSON_UNESCAPED_UNICODE) . "\n\n抽出済み属性:\n" . wp_json_encode($attributes, JSON_UNESCAPED_UNICODE) . "\n\n既存ルール判定:\n" . wp_json_encode($rule_result, JSON_UNESCAPED_UNICODE) . "\n\n現在カテゴリID:\n" . wp_json_encode($current_terms) . "\n\n利用可能カテゴリ・階層・排他関係:\n" . wp_json_encode($catalog, JSON_UNESCAPED_UNICODE);
        $schema = array('type'=>'object','additionalProperties'=>false,'properties'=>array(
            'detected_product_type'=>array('type'=>'string','enum'=>array('single','assortment','subscription')),
            'detected_items'=>array('type'=>'array','items'=>array('type'=>'string')),
            'detected_varieties'=>array('type'=>'array','items'=>array('type'=>'string')),
            'accepted_categories'=>array('type'=>'array','items'=>array('type'=>'integer')),
            'rejected_categories'=>array('type'=>'array','items'=>array('type'=>'integer')),
            'delivery_count'=>array('type'=>'integer','minimum'=>0),
            'text_confidence'=>array('type'=>'number','minimum'=>0,'maximum'=>1),
            'text_evidence'=>array('type'=>'array','items'=>array('type'=>'string')),
            'image_analysis_required'=>array('type'=>'boolean'),
            'requires_review'=>array('type'=>'boolean'),
            'reason'=>array('type'=>'string'),
            'unclassified'=>array('type'=>'boolean'),
        ),'required'=>array('detected_product_type','detected_items','detected_varieties','accepted_categories','rejected_categories','delivery_count','text_confidence','text_evidence','image_analysis_required','requires_review','reason','unclassified'));
        $body = array(
            'model'=>(string)get_option(self::OPT_MODEL, 'gpt-5-mini'), 'store'=>false,
            'input'=>array(array('role'=>'system','content'=>'あなたは商品分類器です。JSON Schemaに厳密に従ってください。'),array('role'=>'user','content'=>$prompt)),
            'text'=>array('format'=>array('type'=>'json_schema','name'=>'product_category_classification','strict'=>true,'schema'=>$schema)),
        );
        if (class_exists('NF_Classification_Metrics')) NF_Classification_Metrics::increment('text_ai_calls');
        $response = wp_remote_post('https://api.openai.com/v1/responses', array('timeout'=>25,'headers'=>array('Authorization'=>'Bearer ' . trim((string)get_option(self::OPT_API_KEY,'')),'Content-Type'=>'application/json'),'body'=>wp_json_encode($body)));
        if (is_wp_error($response)) return self::error($post_id, $response->get_error_message());
        $code = (int)wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) return self::error($post_id, isset($json['error']['message']) ? $json['error']['message'] : 'API HTTP ' . $code);
        $text = '';
        foreach ((array)($json['output'] ?? array()) as $output) foreach ((array)($output['content'] ?? array()) as $content) if (($content['type'] ?? '') === 'output_text') $text .= (string)($content['text'] ?? '');
        $result = json_decode($text, true);
        if (!is_array($result)) return self::error($post_id, 'AI応答を解析できませんでした');
        $result['accepted_categories'] = array_values(array_intersect($allowed, array_map('intval',(array)$result['accepted_categories'])));
        $result['rejected_categories'] = array_values(array_intersect($allowed, array_map('intval',(array)$result['rejected_categories'])));
        update_post_meta($post_id, NF_Category_Classifier::AI_HASH_META, $hash);
        update_post_meta($post_id, NF_Category_Classifier::AI_RESULT_META, $result);
        update_option(self::LAST_SUCCESS, time(), false);
        delete_option(self::LAST_ERROR);
        delete_option(self::LAST_ERROR_AT);
        return self::accept($post_id, $result, false);
    }

    private static function accept($post_id, $result, $cached) {
        $confidence = max(0, min(1, (float)($result['text_confidence'] ?? $result['confidence'] ?? 0)));
        $result['text_confidence']=$confidence;
        if (!isset($result['text_evidence'])) $result['text_evidence']=array((string)($result['reason']??''));
        update_post_meta($post_id,NF_Classification_Evidence::TEXT_CONFIDENCE_META,$confidence);
        update_post_meta($post_id,NF_Classification_Evidence::FINAL_CONFIDENCE_META,$confidence);
        update_post_meta($post_id,NF_Classification_Evidence::IMAGE_USED_META,'0');
        update_post_meta($post_id,NF_Classification_Evidence::EVIDENCE_META,array('rule','text_ai'));
        update_post_meta($post_id,NF_Classification_Evidence::FINAL_RESULT_META,array('product_type'=>$result['detected_product_type']??'single','accepted_categories'=>(array)($result['accepted_categories']??array()),'rejected_categories'=>(array)($result['rejected_categories']??array()),'text_confidence'=>$confidence,'image_confidence'=>0,'final_confidence'=>$confidence,'sources'=>array('rule','text_ai'),'text_evidence'=>(array)$result['text_evidence']));
        $high = (float)get_option(NF_Image_Category_Classifier::OPT_FINAL_HIGH, .90);
        $medium = (float)get_option(NF_Image_Category_Classifier::OPT_FINAL_MEDIUM, .70);
        $ids = array_values(array_unique(array_map('intval', (array)($result['accepted_categories'] ?? array()))));
        $rejected = array_values(array_unique(array_map('intval', (array)($result['rejected_categories'] ?? array()))));
        $reason = sanitize_text_field((string)($result['reason'] ?? ''));
        $leaf_ids = $ids;
        foreach ($ids as $id) foreach ($ids as $other) if ($id !== $other && in_array((int)$id, get_ancestors((int)$other, NF_Category::TAXONOMY, 'taxonomy'), true)) $leaf_ids = array_values(array_diff($leaf_ids, array($id)));
        $roots = array();
        $parents = array();
        foreach ($leaf_ids as $id) {
            $anc = get_ancestors((int)$id, NF_Category::TAXONOMY, 'taxonomy');
            $roots[$anc ? (int)end($anc) : (int)$id] = true;
            $term = get_term((int)$id, NF_Category::TAXONOMY);
            if ($term && !is_wp_error($term)) $parents[(int)$term->parent] = true;
        }
        $product_type = isset($result['detected_product_type']) ? $result['detected_product_type'] : 'single';
        $validation = class_exists('NF_Category_Consistency')
            ? NF_Category_Consistency::validate($post_id, $leaf_ids, NF_Category_Classifier::input($post_id), array(), $product_type)
            : array('accepted_ids'=>$leaf_ids,'rejected_ids'=>array(),'conflicts'=>array(),'unresolved'=>false,'attributes'=>array('product_type'=>$product_type));
        $leaf_ids = $validation['accepted_ids'];
        $rejected = array_values(array_unique(array_merge($rejected, $validation['rejected_ids'])));
        if (class_exists('NF_Category_Consistency')) NF_Category_Consistency::save_result($post_id, $validation);
        $logical_conflict = !empty($validation['unresolved']) || ($product_type === 'single' && (count($roots) > 1 || (count($leaf_ids) > 1 && count($parents) === 1)));
        $requested_stage=sanitize_key((string)get_post_meta($post_id,'_nf_classification_requested_stage',true));
        $images=NF_Image_Category_Classifier::enabled()?NF_Image_Category_Classifier::image_urls($post_id,1):array();
        if (self::should_use_image($result,$logical_conflict,$requested_stage,!empty($images))) {
                NF_Category_Classifier::set_status($post_id,'image_ai_pending','text_ai',$confidence,'テキスト判定の根拠が不足しているため画像確認待ち');
                self::schedule($post_id);
                return;
        }
        if (!empty($result['unclassified']) || !$ids || $confidence < $medium) {
            // Low-confidence AI must not erase a usable existing classification.
            $current = wp_get_object_terms($post_id, NF_Category::TAXONOMY, array('fields'=>'ids'));
            $has_current = !is_wp_error($current) && !empty($current);
            NF_Category_Classifier::set_status($post_id, ($ids || $has_current) ? 'review' : 'unclassified', 'text_ai', $confidence, $reason ?: 'テキストAIでも根拠不足（現在のカテゴリは維持）');
            NF_Classification_Metrics::increment('review');
            delete_post_meta($post_id,'_nf_classification_requested_stage');
        } elseif (!empty($result['requires_review'])) {
            NF_Category_Classifier::set_status($post_id, 'review', 'text_ai', $confidence, $reason ?: 'テキストAIが管理者確認を要求しました');
            NF_Classification_Metrics::increment('review');
            delete_post_meta($post_id,'_nf_classification_requested_stage');
        } elseif ($logical_conflict) {
            NF_Category_Classifier::set_status($post_id, 'review', 'text_ai', $confidence, '単品に排他的な複数カテゴリ候補があります: ' . $reason);
            NF_Classification_Metrics::increment('review');
            delete_post_meta($post_id,'_nf_classification_requested_stage');
        } elseif ($confidence >= $high) {
            NF_Category_Classifier::apply($post_id, $leaf_ids, $rejected);
            NF_Category_Classifier::set_status($post_id, 'text_ai_classified', $cached ? 'text_ai_cache' : 'text_ai', $confidence, $reason);
            delete_post_meta($post_id,'_nf_classification_requested_stage');
        } else {
            NF_Category_Classifier::set_status($post_id, 'review', 'text_ai', $confidence, $reason ?: 'テキストAI候補を管理者が確認してください');
            NF_Classification_Metrics::increment('review');
            delete_post_meta($post_id,'_nf_classification_requested_stage');
        }
    }

    private static function error($post_id, $message) {
        update_post_meta($post_id, NF_Category_Classifier::AI_RETRY_META, time() + HOUR_IN_SECONDS);
        NF_Category_Classifier::set_status($post_id, 'ai_error', 'ai', 0, 'AI分類エラー: ' . sanitize_text_field($message));
        if (class_exists('NF_Classification_Metrics')) NF_Classification_Metrics::increment('api_errors');
        self::record_queue_error($message);
        if (!wp_next_scheduled(NF_Category_Classifier::CRON_HOOK)) wp_schedule_single_event(time() + HOUR_IN_SECONDS, NF_Category_Classifier::CRON_HOOK);
    }
}
