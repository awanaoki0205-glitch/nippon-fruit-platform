<?php
if ( ! defined('ABSPATH') ) exit;

/** Rule-first prefecture assignment with AI used only for unresolved municipalities. */
class NF_Municipality_Classifier {
    const CRON_HOOK = 'nf_classify_municipality_prefectures';
    const VERSION_OPTION = 'nf_municipality_classifier_version';
    const STATUS_META = '_nf_prefecture_classification_status';
    const CONFIDENCE_META = '_nf_prefecture_classification_confidence';
    const REASON_META = '_nf_prefecture_classification_reason';
    const AI_CALLS_OPTION = 'nf_municipality_ai_calls';
    private static $running = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_schedule'), 50);
        add_action(self::CRON_HOOK, array(__CLASS__, 'process'));
        add_action('created_nf_municipality', array(__CLASS__, 'term_changed'));
        add_action('edited_nf_municipality', array(__CLASS__, 'term_changed'));
    }

    public static function activate() { self::schedule(); }

    public static function term_changed() {
        if ( self::$running ) return;
        delete_option(self::VERSION_OPTION);
        self::schedule();
    }

    public static function maybe_schedule() {
        if ( get_option(self::VERSION_OPTION, '') !== '1' ) self::schedule();
    }

    private static function schedule() {
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) wp_schedule_single_event(time() + 30, self::CRON_HOOK);
    }

    private static function prefectures() {
        $roots = get_terms(array('taxonomy'=>'nf_municipality','hide_empty'=>false,'parent'=>0));
        if ( is_wp_error($roots) ) return array();
        return array_values(array_filter($roots, function($term){
            return preg_match('/(都|道|府|県)$/u', (string)$term->name) === 1;
        }));
    }

    private static function candidates($prefecture_ids) {
        $roots = get_terms(array('taxonomy'=>'nf_municipality','hide_empty'=>false,'parent'=>0));
        if ( is_wp_error($roots) ) return array();
        return array_values(array_filter($roots, function($term) use ($prefecture_ids){
            if ( in_array((int)$term->term_id,$prefecture_ids,true) || !preg_match('/(市|区|町|村)$/u',(string)$term->name) ) return false;
            return get_term_meta((int)$term->term_id,self::STATUS_META,true) !== 'requires_review';
        }));
    }

    private static function evidence($term_id) {
        $ids = get_objects_in_term((int)$term_id, 'nf_municipality');
        if ( is_wp_error($ids) ) $ids = array();
        $samples = array();
        foreach ( array_slice(array_map('absint',(array)$ids),0,5) as $post_id ) {
            if ( get_post_type($post_id) !== NF_Core::POST_TYPE ) continue;
            $post = get_post($post_id);
            if ( ! $post ) continue;
            $samples[] = trim(wp_strip_all_tags($post->post_title.' '.$post->post_excerpt.' '.
                get_post_meta($post_id,'_nf_rakuten_item_name',true).' '.get_post_meta($post_id,'_nf_yahoo_item_name',true).' '.
                mb_substr((string)$post->post_content,0,500,'UTF-8')));
        }
        return array_values(array_filter($samples));
    }

    private static function assign($term, $prefecture_id, $source, $confidence, $reason) {
        self::$running = true;
        $result = wp_update_term((int)$term->term_id, 'nf_municipality', array('parent'=>(int)$prefecture_id));
        self::$running = false;
        if ( is_wp_error($result) ) return false;
        update_term_meta((int)$term->term_id,self::STATUS_META,$source);
        update_term_meta((int)$term->term_id,self::CONFIDENCE_META,max(0,min(1,(float)$confidence)));
        update_term_meta((int)$term->term_id,self::REASON_META,sanitize_text_field($reason));
        return true;
    }

    private static function rule_parent($term, $prefectures, $evidence) {
        if ( count($prefectures) === 1 ) return array('id'=>(int)$prefectures[0]->term_id,'confidence'=>1,'reason'=>'登録済みの都道府県が1件のため');
        $text = $term->name.' '.implode(' ', $evidence);
        $matches = array_values(array_filter($prefectures,function($prefecture)use($text){ return mb_strpos($text,(string)$prefecture->name,0,'UTF-8')!==false; }));
        if ( count($matches) === 1 ) return array('id'=>(int)$matches[0]->term_id,'confidence'=>.99,'reason'=>'商品情報に都道府県名が明記されているため');
        return null;
    }

    private static function ai_parent($term, $prefectures, $evidence) {
        if ( ! class_exists('NF_AI_Category_Classifier') || ! NF_AI_Category_Classifier::enabled() ) return null;
        $allowed = array_map(function($prefecture){ return array('id'=>(int)$prefecture->term_id,'name'=>$prefecture->name); },$prefectures);
        $schema = array('type'=>'object','additionalProperties'=>false,'properties'=>array(
            'selected_parent_id'=>array('type'=>'integer'),
            'confidence'=>array('type'=>'number','minimum'=>0,'maximum'=>1),
            'unknown'=>array('type'=>'boolean'),
            'reason'=>array('type'=>'string'),
        ),'required'=>array('selected_parent_id','confidence','unknown','reason'));
        $prompt = "自治体を登録済み都道府県のいずれかに所属判定してください。根拠が不足する場合はunknown=trueとし、推測で決めないでください。登録済みID以外を返してはいけません。\n自治体:".$term->name."\n商品情報:".wp_json_encode($evidence,JSON_UNESCAPED_UNICODE)."\n都道府県:".wp_json_encode($allowed,JSON_UNESCAPED_UNICODE);
        $body = array(
            'model'=>(string)get_option(NF_AI_Category_Classifier::OPT_MODEL,'gpt-5.4-nano'),'store'=>false,
            'input'=>array(array('role'=>'system','content'=>'あなたは日本の自治体所属判定器です。JSON Schemaに厳密に従ってください。'),array('role'=>'user','content'=>$prompt)),
            'text'=>array('format'=>array('type'=>'json_schema','name'=>'municipality_prefecture','strict'=>true,'schema'=>$schema)),
        );
        update_option(self::AI_CALLS_OPTION,absint(get_option(self::AI_CALLS_OPTION,0))+1,false);
        $response = wp_remote_post('https://api.openai.com/v1/responses',array('timeout'=>25,'headers'=>array('Authorization'=>'Bearer '.trim((string)get_option(NF_AI_Category_Classifier::OPT_API_KEY,'')),'Content-Type'=>'application/json'),'body'=>wp_json_encode($body)));
        if ( is_wp_error($response) || (int)wp_remote_retrieve_response_code($response)<200 || (int)wp_remote_retrieve_response_code($response)>=300 ) return null;
        $json = json_decode(wp_remote_retrieve_body($response),true); $text='';
        foreach((array)($json['output']??array()) as $output) foreach((array)($output['content']??array()) as $content) if(($content['type']??'')==='output_text') $text.=(string)($content['text']??'');
        $result=json_decode($text,true); if(!is_array($result)||!empty($result['unknown'])) return null;
        $allowed_ids=array_map('intval',wp_list_pluck($allowed,'id'));
        $selected=absint($result['selected_parent_id']??0); $confidence=(float)($result['confidence']??0);
        if(!in_array($selected,$allowed_ids,true)||$confidence<(float)apply_filters('nf_municipality_ai_threshold',.90)) return null;
        return array('id'=>$selected,'confidence'=>$confidence,'reason'=>(string)($result['reason']??'AIによる都道府県判定'));
    }

    public static function process() {
        if ( self::$running || ! taxonomy_exists('nf_municipality') ) return;
        $prefectures=self::prefectures(); if(!$prefectures){ update_option(self::VERSION_OPTION,'1',false); return; }
        $prefecture_ids=array_map(function($term){return (int)$term->term_id;},$prefectures);
        $candidates=array_slice(self::candidates($prefecture_ids),0,20);
        foreach($candidates as $term){
            $evidence=self::evidence((int)$term->term_id);
            $decision=self::rule_parent($term,$prefectures,$evidence);
            $source='rule';
            if(!$decision){ $decision=self::ai_parent($term,$prefectures,$evidence); $source='ai'; }
            if($decision) self::assign($term,$decision['id'],$source,$decision['confidence'],$decision['reason']);
            else {
                update_term_meta((int)$term->term_id,self::STATUS_META,'requires_review');
                update_term_meta((int)$term->term_id,self::REASON_META,'所属都道府県を安全に特定できませんでした');
            }
        }
        $remaining=self::candidates($prefecture_ids);
        if(count($remaining)>count($candidates)) self::schedule(); else update_option(self::VERSION_OPTION,'1',false);
    }
}
