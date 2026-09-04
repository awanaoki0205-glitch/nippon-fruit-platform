<?php
if (!defined('ABSPATH')) exit;

/** Optional visual fallback. It runs only after inconclusive text classification. */
class NF_Image_Category_Classifier {
    const OPT_ENABLED = 'nf_image_classification_enabled';
    const OPT_MODEL = 'nf_image_classification_model';
    const OPT_TRIGGER = 'nf_image_classification_trigger_threshold';
    const OPT_FINAL_HIGH = 'nf_multimodal_high_threshold';
    const OPT_FINAL_MEDIUM = 'nf_multimodal_medium_threshold';
    const HASH_META = '_nf_image_classification_hash';
    const RESULT_META = '_nf_image_classification_result';
    const RETRY_META = '_nf_image_classification_retry_at';

    public static function enabled() { return get_option(self::OPT_ENABLED,'0') === '1' && NF_AI_Category_Classifier::enabled(); }

    public static function image_urls($post_id, $limit = 1) {
        $urls = array();
        $thumb = get_the_post_thumbnail_url($post_id,'full'); if ($thumb) $urls[] = $thumb;
        foreach (array('_nf_rakuten_image_urls','_nf_yahoo_image_urls','_nf_manual_image_urls') as $key) foreach ((array)get_post_meta($post_id,$key,true) as $url) $urls[] = $url;
        foreach (array('_nf_rakuten_image_url','_nf_yahoo_image_url') as $key) { $url=get_post_meta($post_id,$key,true); if ($url) $urls[]=$url; }
        $urls = array_values(array_unique(array_filter(array_map('esc_url_raw',$urls),function($url){ return preg_match('#^https?://#i',$url); })));
        return array_slice($urls,0,max(1,min(3,(int)$limit)));
    }

    public static function classify($post_id) {
        if (get_post_meta($post_id,NF_Category::CLASSIFICATION_LOCK_META,true)==='1') return;
        if (class_exists('NF_Commercial_Config') && !NF_Commercial_Config::can_use_ai('image_ai')) {
            NF_Category_Classifier::set_status($post_id,'image_ai_pending','text_ai',(float)get_post_meta($post_id,NF_Classification_Evidence::TEXT_CONFIDENCE_META,true),'契約機能または月間AI利用上限により保留');
            return;
        }
        $text = get_post_meta($post_id,NF_Category_Classifier::AI_RESULT_META,true);
        if (!is_array($text)) return self::finish_without_image($post_id,'テキスト判定結果がありません');
        $urls = self::image_urls($post_id,1);
        if (!$urls) return self::finish_without_image($post_id,'代表画像を取得できないため画像判定をスキップしました');
        $catalog = NF_AI_Category_Classifier::category_catalog();
        $hash = hash('sha256',wp_json_encode(array($urls,$text,$catalog,get_option(self::OPT_MODEL,''))));
        $cached = get_post_meta($post_id,self::RESULT_META,true);
        if (get_post_meta($post_id,self::HASH_META,true)===$hash && is_array($cached)) return self::accept($post_id,$text,$cached,true);

        NF_Classification_Metrics::increment('image_ai_calls');
        $schema = array('type'=>'object','additionalProperties'=>false,'properties'=>array(
            'detected_visual_items'=>array('type'=>'array','items'=>array('type'=>'string')),
            'detected_visual_attributes'=>array('type'=>'array','items'=>array('type'=>'string')),
            'candidate_categories'=>array('type'=>'array','items'=>array('type'=>'integer')),
            'image_confidence'=>array('type'=>'number','minimum'=>0,'maximum'=>1),
            'visual_evidence'=>array('type'=>'array','items'=>array('type'=>'string')),
            'can_determine_from_image'=>array('type'=>'boolean'),'requires_review'=>array('type'=>'boolean'),
        ),'required'=>array('detected_visual_items','detected_visual_attributes','candidate_categories','image_confidence','visual_evidence','can_determine_from_image','requires_review'));
        $prompt = '画像は追加証拠です。外観だけで区別できない品種・産地・内容物を推測しないでください。画像内で明確に見える品目、調理状態、色、形状、包装、数量だけを根拠にし、登録済みカテゴリIDのみ返してください。定期便・詰め合わせでは画像にない商品を否定しないでください。判定不能ならcan_determine_from_image=false、requires_review=trueを返してください。テキスト判定:'.wp_json_encode($text,JSON_UNESCAPED_UNICODE).' カテゴリ:'.wp_json_encode($catalog,JSON_UNESCAPED_UNICODE);
        $content = array(array('type'=>'input_text','text'=>$prompt));
        foreach ($urls as $url) $content[] = array('type'=>'input_image','image_url'=>$url,'detail'=>'low');
        $body = array('model'=>(string)get_option(self::OPT_MODEL,get_option(NF_AI_Category_Classifier::OPT_MODEL,'gpt-5-mini')),'store'=>false,
            'input'=>array(array('role'=>'user','content'=>$content)),
            'text'=>array('format'=>array('type'=>'json_schema','name'=>'product_visual_classification','strict'=>true,'schema'=>$schema)));
        $response = wp_remote_post('https://api.openai.com/v1/responses',array('timeout'=>30,'headers'=>array('Authorization'=>'Bearer '.trim((string)get_option(NF_AI_Category_Classifier::OPT_API_KEY,'')),'Content-Type'=>'application/json'),'body'=>wp_json_encode($body)));
        if (is_wp_error($response)) return self::error($post_id,$response->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($response); $json=json_decode(wp_remote_retrieve_body($response),true);
        if ($code<200||$code>=300) {
            $message=(string)($json['error']['message']??('API HTTP '.$code));
            if (preg_match('/image|画像|download|fetch|URL/i',$message)) return self::finish_without_image($post_id,'商品画像を取得できないため画像判定をスキップしました');
            return self::error($post_id,$message);
        }
        $text_out=''; foreach ((array)($json['output']??array()) as $out) foreach ((array)($out['content']??array()) as $part) if (($part['type']??'')==='output_text') $text_out.=(string)($part['text']??'');
        $result=json_decode($text_out,true); if (!is_array($result)) return self::error($post_id,'画像AI応答を解析できませんでした');
        $allowed=array_map('intval',wp_list_pluck($catalog,'id'));
        $result['candidate_categories']=array_values(array_intersect($allowed,array_map('intval',(array)$result['candidate_categories'])));
        update_post_meta($post_id,self::HASH_META,$hash); update_post_meta($post_id,self::RESULT_META,$result);
        return self::accept($post_id,$text,$result,false);
    }

    private static function accept($post_id,$text,$image,$cached) {
        $merged=NF_Classification_Evidence::combine($post_id,$text,$image);
        $high=(float)get_option(self::OPT_FINAL_HIGH,.90); $medium=(float)get_option(self::OPT_FINAL_MEDIUM,.70);
        $reason=implode(' / ',array_merge((array)($text['text_evidence']??array()),(array)($image['visual_evidence']??array())));
        if ($merged['accepted_categories'] && !$merged['conflict'] && $merged['final_confidence'] >= $high) {
            NF_Category_Classifier::apply($post_id,$merged['accepted_categories'],$merged['rejected_categories']);
            NF_Category_Classifier::set_status($post_id,'image_ai_classified',$cached?'image_ai_cache':'image_ai',$merged['final_confidence'],$reason ?: 'テキストと画像の証拠を統合');
        } elseif ($merged['final_confidence'] >= $medium || $merged['accepted_categories']) {
            NF_Classification_Metrics::increment('review');
            NF_Category_Classifier::set_status($post_id,'review','multimodal',$merged['final_confidence'],$merged['conflict']?'テキストと画像の候補が競合しています':($reason?:'画像を含む判定を確認してください'));
        } else {
            NF_Classification_Metrics::increment('review');
            NF_Category_Classifier::set_status($post_id,'unclassified','multimodal',$merged['final_confidence'],'テキストと画像の根拠が不足しています');
        }
        delete_post_meta($post_id,'_nf_classification_requested_stage');
    }

    public static function finish_without_image($post_id,$reason) {
        update_post_meta($post_id,NF_Classification_Evidence::IMAGE_USED_META,'0');
        NF_Classification_Metrics::increment('review');
        NF_Category_Classifier::set_status($post_id,'review','text_ai',(float)get_post_meta($post_id,NF_Classification_Evidence::TEXT_CONFIDENCE_META,true),$reason);
        delete_post_meta($post_id,'_nf_classification_requested_stage');
    }

    private static function error($post_id,$message) {
        update_post_meta($post_id,self::RETRY_META,time()+HOUR_IN_SECONDS); NF_Classification_Metrics::increment('api_errors');
        NF_Category_Classifier::set_status($post_id,'ai_error','image_ai',0,'画像AI分類エラー: '.sanitize_text_field($message));
        if (!wp_next_scheduled(NF_Category_Classifier::CRON_HOOK)) wp_schedule_single_event(time()+HOUR_IN_SECONDS,NF_Category_Classifier::CRON_HOOK);
    }
}
