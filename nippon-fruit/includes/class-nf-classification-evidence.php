<?php
if (!defined('ABSPATH')) exit;

/** Combines text and visual evidence without allowing either source to blindly overwrite the other. */
class NF_Classification_Evidence {
    const MIN_CANDIDATE_SCORE = .50;
    const SINGLE_ROOT_MARGIN = .20;
    const MULTI_ITEM_IMAGE_WEIGHT = .55;
    const FINAL_RESULT_META = '_nf_multimodal_classification_result';
    const FINAL_CONFIDENCE_META = '_nf_final_classification_confidence';
    const TEXT_CONFIDENCE_META = '_nf_text_classification_confidence';
    const IMAGE_CONFIDENCE_META = '_nf_image_classification_confidence';
    const IMAGE_USED_META = '_nf_image_classification_used';
    const EVIDENCE_META = '_nf_classification_evidence';

    private static function root_id($id) {
        $anc = get_ancestors((int)$id, NF_Category::TAXONOMY, 'taxonomy');
        return $anc ? (int)end($anc) : (int)$id;
    }

    public static function combine($post_id, $text, $image) {
        $type = sanitize_key((string)($text['detected_product_type'] ?? 'single'));
        $text_conf = max(0, min(1, (float)($text['text_confidence'] ?? $text['confidence'] ?? 0)));
        $image_conf = !empty($image['can_determine_from_image']) ? max(0, min(1, (float)($image['image_confidence'] ?? 0))) : 0;
        if (in_array($type, array('subscription','assortment'), true)) $image_conf *= self::MULTI_ITEM_IMAGE_WEIGHT;
        $scores = array(); $sources = array();
        foreach (array_map('intval',(array)($text['accepted_categories'] ?? array())) as $id) {
            if ($id < 1) continue; $scores[$id] = max($scores[$id] ?? 0, $text_conf); $sources[$id][] = 'text_ai';
        }
        foreach (array_map('intval',(array)($image['candidate_categories'] ?? array())) as $id) {
            if ($id < 1 || $image_conf <= 0) continue;
            $scores[$id] = isset($scores[$id]) ? 1 - ((1-$scores[$id]) * (1-$image_conf)) : $image_conf;
            $sources[$id][] = 'image_ai';
        }
        arsort($scores);
        $accepted = array_keys(array_filter($scores,function($score){ return $score >= self::MIN_CANDIDATE_SCORE; }));
        $conflict = false;
        if ($type === 'single' && count($accepted) > 1) {
            $by_root = array(); foreach ($accepted as $id) $by_root[self::root_id($id)][] = $id;
            if (count($by_root) > 1) {
                $ranked = array_keys($scores); $first = $scores[$ranked[0]]; $second = $scores[$ranked[1]] ?? 0;
                if (($first-$second) >= self::SINGLE_ROOT_MARGIN) {
                    $winner = self::root_id($ranked[0]);
                    $accepted = array_values(array_filter($accepted,function($id) use ($winner){ return self::root_id($id)===$winner; }));
                } else $conflict = true;
            }
        }
        $validation = NF_Category_Consistency::validate($post_id,$accepted,NF_Category_Classifier::input($post_id),array(),$type);
        NF_Category_Consistency::save_result($post_id,$validation);
        $accepted = $validation['accepted_ids'];
        $final = 0;
        foreach ($accepted as $id) $final = max($final,(float)($scores[$id] ?? 0));
        if ($conflict || !empty($validation['unresolved'])) $final = min($final,.89);
        $result = array(
            'product_type'=>$type,'accepted_categories'=>$accepted,'rejected_categories'=>$validation['rejected_ids'],
            'text_confidence'=>$text_conf,'image_confidence'=>(float)($image['image_confidence'] ?? 0),
            'final_confidence'=>$final,'conflict'=>$conflict || !empty($validation['unresolved']),
            'sources'=>$sources,'text_evidence'=>(array)($text['text_evidence'] ?? array()),
            'visual_evidence'=>(array)($image['visual_evidence'] ?? array()),
        );
        update_post_meta($post_id,self::FINAL_RESULT_META,$result);
        update_post_meta($post_id,self::FINAL_CONFIDENCE_META,$final);
        update_post_meta($post_id,self::TEXT_CONFIDENCE_META,$text_conf);
        update_post_meta($post_id,self::IMAGE_CONFIDENCE_META,(float)($image['image_confidence'] ?? 0));
        update_post_meta($post_id,self::IMAGE_USED_META,'1');
        update_post_meta($post_id,self::EVIDENCE_META,array('rule','text_ai','image_ai'));
        return $result;
    }
}
