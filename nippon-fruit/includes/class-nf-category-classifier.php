<?php
if (!defined('ABSPATH')) exit;

/** Independent, rule-first product classifier. It never touches portal/sync data. */
class NF_Category_Classifier {
    const STATUS_META = '_nf_classification_status';
    const METHOD_META = '_nf_classification_method';
    const RESULT_META = '_nf_classification_result';
    const INPUT_HASH_META = '_nf_classification_input_hash';
    const AI_HASH_META = '_nf_ai_classification_hash';
    const AI_RESULT_META = '_nf_ai_classification_result';
    const AI_RETRY_META = '_nf_ai_classification_retry_at';
    const ALIASES_META = '_nf_category_aliases';
    const AI_TARGET_META = '_nf_category_ai_target';
    const PRIORITY_META = '_nf_category_priority';
    const CRON_HOOK = 'nf_process_ai_category_queue';

    private static $queue = array();
    private static $running = false;

    public static function init() {
        add_action('added_post_meta', array(__CLASS__, 'meta_changed'), 20, 4);
        add_action('updated_post_meta', array(__CLASS__, 'meta_changed'), 20, 4);
        add_action('deleted_post_meta', array(__CLASS__, 'meta_changed'), 20, 4);
        add_action('shutdown', array(__CLASS__, 'flush'), 20);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_ai_queue'));
    }

    public static function queue($post_id) {
        if ($post_id > 0) self::$queue[(int)$post_id] = true;
    }

    public static function meta_changed($meta_id, $post_id, $key, $value) {
        if (self::$running || strpos((string)$key, '_nf_classification_') === 0 || strpos((string)$key, '_nf_ai_classification_') === 0) return;
        if (get_post_type($post_id) !== NF_Core::POST_TYPE) return;
        $relevant = array('_nf_rakuten_item_name','_nf_rakuten_description','_nf_yahoo_item_name','_nf_yahoo_variants','_nf_capacity','_nf_quantity','_nf_size','_nf_rakuten_genre','_nf_yahoo_category');
        if (in_array($key, $relevant, true)) self::queue((int)$post_id);
    }

    public static function flush() {
        if (self::$running || !self::$queue) return;
        self::$running = true;
        $ids = array_keys(self::$queue);
        self::$queue = array();
        foreach ($ids as $id) self::classify_now((int)$id);
        self::$running = false;
    }

    public static function input($post_id) {
        $post = get_post($post_id);
        if (!$post) return array();
        $display = class_exists('NF_Product_Title') ? NF_Product_Title::display_title($post_id) : $post->post_title;
        $fields = array(
            'title' => wp_strip_all_tags((string)$display),
            'source_titles' => array_filter(array(
                wp_strip_all_tags((string)get_post_meta($post_id, '_nf_rakuten_item_name', true)),
                wp_strip_all_tags((string)get_post_meta($post_id, '_nf_yahoo_item_name', true)),
            )),
            'description' => wp_strip_all_tags((string)get_post_meta($post_id, '_nf_rakuten_description', true) . ' ' . (string)$post->post_content),
            'capacity' => (string)get_post_meta($post_id, '_nf_capacity', true),
            'quantity' => (string)get_post_meta($post_id, '_nf_quantity', true),
            'size' => (string)get_post_meta($post_id, '_nf_size', true),
            'source_categories' => array_filter(array((string)get_post_meta($post_id, '_nf_rakuten_genre', true), (string)get_post_meta($post_id, '_nf_yahoo_category', true))),
            'variants' => get_post_meta($post_id, '_nf_yahoo_variants', true),
        );
        return $fields;
    }

    private static function normalize($text) {
        $text = mb_strtolower(wp_strip_all_tags((string)$text), 'UTF-8');
        return preg_replace('/[[:space:]　・･,，、\/／()（）【】\[\]「」]+/u', ' ', $text);
    }

    private static function aliases($term) {
        $aliases = get_term_meta((int)$term->term_id, self::ALIASES_META, true);
        $aliases = is_array($aliases) ? $aliases : array();
        $aliases[] = $term->name;
        $built_in = array(
            '不知火・デコポン'=>array('不知火','しらぬい','デコポン','デコみかん'),
            '晩白柚'=>array('晩白柚','ばんぺいゆ','バンペイユ'),
            '柿（カキ）'=>array('柿','かき','カキ'),
            'すいか'=>array('すいか','スイカ','西瓜','小玉すいか','小玉スイカ'),
            '栗'=>array('栗','くり','クリ','生栗','マロン'),
            'みかん'=>array('みかん','ミカン','温州みかん','温州ミカン'),
            'その他柑橘'=>array('八朔','はっさく','ハッサク','ザボン','パール柑'),
            '梨'=>array('梨','なし','ナシ'),
            '豊水梨'=>array('豊水梨','豊水'), '秋月梨'=>array('秋月梨','あきづき梨','あきづき'), '新高梨'=>array('新高梨','新高'),
            'さつまいも'=>array('さつまいも','サツマイモ','薩摩芋','甘藷'),
            'じゃがいも'=>array('じゃがいも','ジャガイモ','馬鈴薯'), '里芋'=>array('里芋','さといも','サトイモ'),
        );
        if (isset($built_in[$term->name])) $aliases = array_merge($aliases, $built_in[$term->name]);
        return array_values(array_unique(array_filter(array_map('trim', $aliases))));
    }

    private static function contains($haystack, $needle) {
        $haystack = self::normalize($haystack);
        $needle = self::normalize($needle);
        if ($needle === '') return false;
        // Short generic words must be tokens. This prevents いも matching 甘いもの.
        if ($needle === 'いも' || $needle === 'イモ') {
            return preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:$|\s)/u', $haystack) === 1;
        }
        return mb_strpos(str_replace(' ', '', $haystack), str_replace(' ', '', $needle), 0, 'UTF-8') !== false;
    }

    private static function ancestors($term_id) {
        $ids = array((int)$term_id);
        foreach (get_ancestors((int)$term_id, NF_Category::TAXONOMY, 'taxonomy') as $id) $ids[] = (int)$id;
        return array_values(array_unique($ids));
    }

    private static function root_id($term_id) {
        $ancestors = get_ancestors((int)$term_id, NF_Category::TAXONOMY, 'taxonomy');
        return $ancestors ? (int)end($ancestors) : (int)$term_id;
    }

    public static function classify_now($post_id, $force = false) {
        if (get_post_meta($post_id, NF_Category::CLASSIFICATION_LOCK_META, true) === '1') {
            self::set_status($post_id, 'manual', 'manual', 1, '管理者が手動確定');
            return;
        }
        $input = self::input($post_id);
        if (!$input) return;
        $terms = get_terms(array('taxonomy'=>NF_Category::TAXONOMY, 'hide_empty'=>false));
        if (is_wp_error($terms)) return;
        $definition_version = array(NF_Category::RECLASSIFY_VERSION, array_map(function($term){
            return array(
                'id'=>(int)$term->term_id,
                'parent'=>(int)$term->parent,
                'name'=>$term->name,
                'aliases'=>get_term_meta($term->term_id, self::ALIASES_META, true),
                'excludes'=>get_term_meta($term->term_id, NF_Category::EXCLUDE_KEYWORDS_META, true),
                'priority'=>(int)get_term_meta($term->term_id, self::PRIORITY_META, true),
                'ai_target'=>get_term_meta($term->term_id, self::AI_TARGET_META, true),
                'exclusive'=>class_exists('NF_Category_Consistency') ? NF_Category_Consistency::exclusive_ids($term->term_id) : array(),
            );
        }, $terms));
        $hash = hash('sha256', wp_json_encode(array($input, $definition_version)));
        if (!$force && get_post_meta($post_id, self::INPUT_HASH_META, true) === $hash) return;

        $title = $input['title'] . ' ' . implode(' ', $input['source_titles']);
        $support = $input['description'] . ' ' . $input['capacity'] . ' ' . implode(' ', $input['source_categories']);
        $type_text = self::normalize($title . ' ' . $support);
        $product_type = preg_match('/定期便|全\s*[0-9０-９]+\s*回/u', $type_text) ? 'subscription' : (preg_match('/詰め合わせ|セット|食べ比べ|アソート|品種おまかせ|各種.+おまかせ|選べる品種/u', $type_text) ? 'assortment' : 'single');
        $delivery_count = 0;
        if (preg_match('/全\s*([0-9０-９]+)\s*回/u', $type_text, $m)) $delivery_count = (int)mb_convert_kana($m[1], 'n');

        $candidates = array();
        foreach ($terms as $term) {
            $score = 0;
            $matched = array();
            foreach (self::aliases($term) as $alias) {
                if (self::contains($title, $alias)) {
                    $compact_title = str_replace(' ', '', self::normalize($title));
                    $compact_alias = str_replace(' ', '', self::normalize($alias));
                    $position = mb_strpos($compact_title, $compact_alias, 0, 'UTF-8');
                    $early_bonus = $position === false ? 0 : max(0, 60 - min(60, (int)$position));
                    $score = max($score, 100 + min(30, mb_strlen($alias,'UTF-8') * 3) + $early_bonus);
                    $matched[] = '商品名:' . $alias;
                }
                elseif (self::contains($support, $alias)) { $score = max($score, 25 + min(15, mb_strlen($alias,'UTF-8'))); $matched[] = '説明・規格:' . $alias; }
            }
            $excludes = get_term_meta((int)$term->term_id, NF_Category::EXCLUDE_KEYWORDS_META, true);
            foreach ((array)$excludes as $exclude) if (self::contains($title . ' ' . $support, $exclude)) $score = -999;
            if ($score > 0) $candidates[(int)$term->term_id] = array(
                'term'=>$term,
                'score'=>$score + (int)get_term_meta($term->term_id, self::PRIORITY_META, true),
                'reason'=>$matched,
                'title_match'=>(bool)array_filter($matched, function($reason){ return strpos($reason, '商品名:') === 0; }),
            );
        }

        uasort($candidates, function($a,$b){ return $b['score'] <=> $a['score']; });
        $strong = array_filter($candidates, function($row){ return $row['score'] >= 100; });
        // These varieties semantically imply their product group even on sites whose
        // legacy taxonomy currently stores both at the same depth.
        $semantic_parents = array('紅はるか'=>'さつまいも','シルクスイート'=>'さつまいも');
        $extra_selected = array();
        foreach ($semantic_parents as $child_name=>$parent_name) {
            $child_id = 0; $parent_id = 0;
            foreach ($strong as $id=>$row) {
                if ($row['term']->name === $child_name) $child_id = (int)$id;
                if ($row['term']->name === $parent_name) $parent_id = (int)$id;
            }
            if ($child_id && $parent_id) { unset($strong[$parent_id]); $extra_selected[] = $parent_id; }
        }
        // A matched parent is implied by a matched child and must not look like a second product.
        foreach (array_keys($strong) as $id) {
            foreach (array_keys($strong) as $other) {
                if ($id !== $other && in_array((int)$id, get_ancestors((int)$other, NF_Category::TAXONOMY, 'taxonomy'), true)) {
                    unset($strong[$id]);
                    break;
                }
            }
        }
        $strong_roots = array();
        foreach ($strong as $id=>$row) $strong_roots[self::root_id($id)] = true;
        // A single item chooses one dominant leaf only when its evidence is clearly stronger.
        if ($product_type === 'single' && count($strong) > 1) {
            $strong_rows = array_values($strong);
            $top_score = (int)$strong_rows[0]['score'];
            $second_score = (int)$strong_rows[1]['score'];
            if ($top_score - $second_score >= 20) {
                $top_id = (int)array_key_first($strong);
                $strong = array($top_id=>$strong[$top_id]);
            }
        }
        $selected = array();
        $roots = array();
        foreach ($strong as $id=>$row) {
            $root = self::root_id($id);
            if ($product_type === 'single' && $roots && !isset($roots[$root])) continue;
            // Prefer the deepest/specific term and omit its broader candidate; ancestors are added later.
            $is_ancestor = false;
            foreach (array_keys($strong) as $other) if ($other !== $id && in_array($id, get_ancestors($other, NF_Category::TAXONOMY, 'taxonomy'), true)) $is_ancestor = true;
            if (!$is_ancestor) { $selected[] = (int)$id; $roots[$root] = true; }
        }
        $selected = array_values(array_unique($selected));
        $selected = array_values(array_unique(array_merge($selected, $extra_selected)));
        $validation = class_exists('NF_Category_Consistency')
            ? NF_Category_Consistency::validate($post_id, $selected, $input, $candidates, $product_type)
            : array('accepted_ids'=>$selected,'rejected_ids'=>array(),'conflicts'=>array(),'unresolved'=>false,'attributes'=>array('product_type'=>$product_type));
        $selected = $validation['accepted_ids'];
        if (class_exists('NF_Category_Consistency')) NF_Category_Consistency::save_result($post_id, $validation);
        $ambiguous = !$selected || !empty($validation['unresolved']) || ($product_type === 'single' && (count($strong) > 1 || count($strong_roots) > 1));
        $confidence = !$selected ? 0 : (($product_type === 'single' && count($strong_roots) === 1 && empty($validation['unresolved'])) ? 0.98 : ($product_type === 'single' ? 0.55 : 0.9));
        $result = array(
            'category_ids'=>$selected,
            'variety_ids'=>$selected,
            'product_type'=>$product_type,
            'detected_items'=>isset($validation['attributes']['items']) ? $validation['attributes']['items'] : array(),
            'detected_varieties'=>isset($validation['attributes']['varieties']) ? $validation['attributes']['varieties'] : array(),
            'delivery_count'=>$delivery_count,
            'confidence'=>$confidence,
            'reasons'=>array_values(array_filter(array_map(function($id) use ($candidates){ return isset($candidates[$id]) ? implode('、',$candidates[$id]['reason']) : ''; }, $selected))),
            'rejected_category_ids'=>$validation['rejected_ids'],
            'conflicts'=>$validation['conflicts'],
            'unclassified'=>!$selected,
        );
        update_post_meta($post_id, self::INPUT_HASH_META, $hash);

        $high = (float)get_option(NF_AI_Category_Classifier::OPT_HIGH, 0.85);
        if (!$ambiguous && $confidence >= $high) {
            self::apply($post_id, $selected, $validation['rejected_ids']);
            update_post_meta($post_id, self::RESULT_META, $result);
            $status = !empty($validation['conflicts']) ? 'conflict_resolved' : 'rule_classified';
            $reason = implode(' / ', $result['reasons']);
            if (!empty($validation['conflicts'])) $reason = 'カテゴリ矛盾を自動補正: ' . implode('、', wp_list_pluck($validation['conflicts'], 'rejected_root'));
            self::set_status($post_id, $status, 'rule', $confidence, $reason);
            return;
        }
        if (class_exists('NF_AI_Category_Classifier') && NF_AI_Category_Classifier::enabled()) {
            update_post_meta($post_id, self::RESULT_META, $result);
            self::set_status($post_id, 'ai_pending', 'rule', $confidence, 'ルールだけでは確定できないためAI判定待ち');
            NF_AI_Category_Classifier::schedule($post_id);
        } else {
            self::apply($post_id, $selected, $validation['rejected_ids']);
            update_post_meta($post_id, self::RESULT_META, $result);
            self::set_status($post_id, $selected ? 'review' : 'unclassified', 'rule', $confidence, $selected ? '候補はありますが確定根拠が不足しています' : '明確な分類根拠がありません');
        }
    }

    public static function apply($post_id, $leaf_ids, $rejected_ids = array()) {
        $new = array();
        foreach ((array)$leaf_ids as $id) $new = array_merge($new, self::ancestors((int)$id));
        $new = array_values(array_unique(array_map('intval', $new)));
        $old = get_post_meta($post_id, NF_Category::AUTO_TERMS_META, true);
        $old = is_array($old) ? array_map('intval', $old) : array();
        $remove = array_values(array_diff($old, $new));
        $remove = array_values(array_unique(array_merge($remove, array_map('intval', (array)$rejected_ids))));
        if ($remove) wp_remove_object_terms($post_id, $remove, NF_Category::TAXONOMY);
        if ($new) wp_set_object_terms($post_id, $new, NF_Category::TAXONOMY, true);
        update_post_meta($post_id, NF_Category::AUTO_TERMS_META, $new);
    }

    public static function set_status($post_id, $status, $method, $confidence, $reason) {
        update_post_meta($post_id, self::STATUS_META, sanitize_key($status));
        update_post_meta($post_id, self::METHOD_META, sanitize_key($method));
        update_post_meta($post_id, NF_Category::CONFIDENCE_META, $status === 'manual' ? 'manual' : ($confidence >= .85 ? 'high' : ($confidence >= .6 ? 'medium' : 'low')));
        if ($reason !== '') update_post_meta($post_id, NF_Category::REVIEW_REASON_META, sanitize_text_field($reason)); else delete_post_meta($post_id, NF_Category::REVIEW_REASON_META);
    }

    public static function run_ai_queue() { NF_AI_Category_Classifier::process_queue(); }
}
