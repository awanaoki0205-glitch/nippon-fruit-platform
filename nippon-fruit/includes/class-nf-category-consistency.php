<?php
if (!defined('ABSPATH')) exit;

/**
 * Validates category results after rule/AI classification and before persistence.
 * Category relationships are read from the taxonomy and term metadata so customer
 * taxonomies can use the same engine without company-specific PHP branches.
 */
class NF_Category_Consistency {
    const EXCLUSIVE_TERMS_META = '_nf_category_exclusive_term_ids';
    const CONFLICT_META = '_nf_category_conflict_result';
    const ATTRIBUTES_META = '_nf_classification_attributes';

    public static function init() {}

    public static function extract_attributes($input, $matched_terms = array()) {
        $title = isset($input['title']) ? (string)$input['title'] : '';
        $source_titles = isset($input['source_titles']) ? implode(' ', (array)$input['source_titles']) : '';
        $all = self::normalize($title . ' ' . $source_titles . ' ' . (isset($input['description']) ? $input['description'] : ''));
        $title_text = self::normalize($title . ' ' . $source_titles);
        $type = preg_match('/定期便|全\s*[0-9０-９]+\s*回/u', $all) ? 'subscription'
            : (preg_match('/詰め合わせ|セット|食べ比べ|アソート|品種おまかせ|各種.+おまかせ|選べる品種/u', $all) ? 'assortment' : 'single');
        $delivery_count = 0;
        if (preg_match('/全\s*([0-9０-９]+)\s*回/u', $all, $m)) $delivery_count = (int)mb_convert_kana($m[1], 'n');

        $items = array();
        $varieties = array();
        foreach ((array)$matched_terms as $row) {
            if (empty($row['term']) || empty($row['title_match'])) continue;
            $term = $row['term'];
            if ((int)$term->parent > 0) {
                $parent = get_term((int)$term->parent, NF_Category::TAXONOMY);
                if ($parent && !is_wp_error($parent) && (int)$parent->parent > 0) $varieties[] = $term->name;
                else $items[] = $term->name;
            }
        }
        return array(
            'product_type'=>$type,
            'items'=>array_values(array_unique($items)),
            'varieties'=>array_values(array_unique($varieties)),
            'weight'=>isset($input['capacity']) ? (string)$input['capacity'] : '',
            'quantity'=>isset($input['quantity']) ? (string)$input['quantity'] : '',
            'size'=>isset($input['size']) ? (string)$input['size'] : '',
            'delivery_count'=>$delivery_count,
            'title_text'=>$title_text,
        );
    }

    /**
     * @return array accepted_ids, rejected_ids, conflicts, unresolved, attributes
     */
    public static function validate($post_id, $leaf_ids, $input, $candidates = array(), $product_type = '') {
        $attributes = self::extract_attributes($input, $candidates);
        if ($product_type !== '') $attributes['product_type'] = $product_type;
        $leaf_ids = array_values(array_unique(array_filter(array_map('intval', (array)$leaf_ids))));
        $assigned = wp_get_object_terms((int)$post_id, NF_Category::TAXONOMY, array('fields'=>'ids'));
        $assigned = is_wp_error($assigned) ? array() : array_map('intval', $assigned);
        $accepted_roots = array();
        foreach ($leaf_ids as $id) $accepted_roots[self::root_id($id)] = true;

        $title_roots = array();
        foreach ((array)$candidates as $id=>$row) {
            if (!empty($row['title_match']) && isset($row['score']) && (int)$row['score'] >= 100) {
                $title_roots[self::root_id((int)$id)] = true;
            }
        }

        $rejected = array();
        $conflicts = array();
        $unresolved = false;
        $is_multi = in_array($attributes['product_type'], array('subscription','assortment'), true);

        // Multiple roots are valid for sets/subscriptions. For a clearly identified
        // single item, stale terms outside the evidenced root are contradictory.
        if (!$is_multi && count($title_roots) === 1 && count($accepted_roots) === 1) {
            $dominant_root = (int)array_key_first($title_roots);
            if (isset($accepted_roots[$dominant_root])) {
                foreach ($assigned as $assigned_id) {
                    $assigned_root = self::root_id($assigned_id);
                    if ($assigned_root !== $dominant_root && self::roots_are_exclusive($dominant_root, $assigned_root, true)) {
                        $rejected = array_merge($rejected, self::branch_assigned_ids($assigned_root, $assigned));
                        $conflicts[] = self::conflict_row($dominant_root, $assigned_root, '単品の商品名で明示された品目と異なる大分類');
                    }
                }
            }
        } elseif (!$is_multi && count($accepted_roots) > 1) {
            $roots = array_keys($accepted_roots);
            for ($i=0; $i<count($roots); $i++) {
                for ($j=$i+1; $j<count($roots); $j++) {
                    if (self::roots_are_exclusive($roots[$i], $roots[$j], false)) {
                        $unresolved = true;
                        $conflicts[] = self::conflict_row($roots[$i], $roots[$j], '単品に排他設定されたカテゴリが同時候補');
                    }
                }
            }
        }

        $rejected = array_values(array_unique(array_map('intval', $rejected)));
        $accepted = array_values(array_diff($leaf_ids, $rejected));
        return array('accepted_ids'=>$accepted,'rejected_ids'=>$rejected,'conflicts'=>$conflicts,'unresolved'=>$unresolved,'attributes'=>$attributes);
    }

    public static function save_result($post_id, $validation) {
        update_post_meta((int)$post_id, self::ATTRIBUTES_META, isset($validation['attributes']) ? $validation['attributes'] : array());
        if (!empty($validation['conflicts'])) update_post_meta((int)$post_id, self::CONFLICT_META, $validation);
        else delete_post_meta((int)$post_id, self::CONFLICT_META);
    }

    public static function remove_rejected($post_id, $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
        if ($ids) wp_remove_object_terms((int)$post_id, $ids, NF_Category::TAXONOMY);
    }

    public static function exclusive_ids($term_id) {
        $ids = get_term_meta((int)$term_id, self::EXCLUSIVE_TERMS_META, true);
        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : array();
    }

    private static function roots_are_exclusive($a, $b, $allow_dominant_default) {
        $a = self::root_id($a); $b = self::root_id($b);
        if ($a === $b) return false;
        if (in_array($b, self::exclusive_ids($a), true) || in_array($a, self::exclusive_ids($b), true)) return true;
        // Safe generic fallback: only the root proven by an exact title match may
        // evict a stale, unsupported root on a single-item product.
        return (bool)$allow_dominant_default;
    }

    private static function branch_assigned_ids($root_id, $assigned) {
        $out = array();
        foreach ((array)$assigned as $id) if (self::root_id($id) === (int)$root_id) $out[] = (int)$id;
        return $out;
    }

    private static function conflict_row($accepted_root, $rejected_root, $reason) {
        $accepted = get_term((int)$accepted_root, NF_Category::TAXONOMY);
        $rejected = get_term((int)$rejected_root, NF_Category::TAXONOMY);
        return array(
            'accepted_root_id'=>(int)$accepted_root,
            'accepted_root'=>$accepted && !is_wp_error($accepted) ? $accepted->name : (string)$accepted_root,
            'rejected_root_id'=>(int)$rejected_root,
            'rejected_root'=>$rejected && !is_wp_error($rejected) ? $rejected->name : (string)$rejected_root,
            'reason'=>$reason,
        );
    }

    private static function root_id($term_id) {
        $ancestors = get_ancestors((int)$term_id, NF_Category::TAXONOMY, 'taxonomy');
        return $ancestors ? (int)end($ancestors) : (int)$term_id;
    }

    private static function normalize($text) {
        $text = mb_strtolower(wp_strip_all_tags((string)$text), 'UTF-8');
        return preg_replace('/[[:space:]　・･,，、\/／()（）【】\[\]「」]+/u', ' ', $text);
    }
}
