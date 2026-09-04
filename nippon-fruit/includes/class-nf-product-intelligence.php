<?php
if (!defined('ABSPATH')) exit;

/** Read-only benchmark of already classified products. It never re-runs AI. */
class NF_Product_Intelligence {
    const SLUG='nf-product-intelligence';

    public static function init() { add_action('admin_menu',array(__CLASS__,'menu'),18); }
    public static function menu() {
        if (class_exists('NF_Commercial_Config') && !NF_Commercial_Config::feature('product_intelligence')) return;
        add_submenu_page('nf-customer-dashboard','Product Intelligence','商品比較・分析','nf_view_product_intelligence',self::SLUG,array(__CLASS__,'page'));
    }

    public static function normalize_weight_kg($text) {
        $text=mb_convert_kana((string)$text,'asKV');
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(kg|キロ)/iu',$text,$m)) return round((float)$m[1],3);
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*g(?:ram)?/iu',$text,$m)) return round((float)$m[1]/1000,3);
        return 0.0;
    }
    public static function normalize_count($text) {
        $text=mb_convert_kana((string)$text,'asKV');
        return preg_match('/([0-9]+)\s*(個|玉|尾|本|袋|パック)/u',$text,$m) ? absint($m[1]) : 0;
    }
    public static function quality($text) {
        foreach(array('訳あり','秀品','家庭用') as $label) if(mb_strpos((string)$text,$label)!==false) return $label;
        return '指定なし';
    }
    private static function price($id) { return absint(get_post_meta($id,'_nf_price_min',true)?:get_post_meta($id,'_nf_price',true)); }
    private static function term_names($id,$taxonomy) {
        $terms=wp_get_post_terms($id,$taxonomy,array('fields'=>'names')); return is_wp_error($terms)?array():$terms;
    }
    private static function text_value($value) {
        if (is_scalar($value) || $value===null) return trim((string)$value);
        $flat=array(); array_walk_recursive((array)$value,function($item) use (&$flat){ if(is_scalar($item)&&trim((string)$item)!=='') $flat[]=trim((string)$item); });
        return implode(' / ',array_values(array_unique($flat)));
    }
    private static function evidence_sources($final,$stored) {
        $flat=array();
        array_walk_recursive(array($final['sources']??array(),$stored),function($item) use (&$flat){
            $item=sanitize_key((string)$item); if(in_array($item,array('rule','text_ai','image_ai','manual'),true)) $flat[]=$item;
        });
        return array_values(array_unique($flat));
    }
    public static function row($id) {
        $title=get_the_title($id); $capacity=self::text_value(get_post_meta($id,'_nf_capacity',true)); $quantity=self::text_value(get_post_meta($id,'_nf_quantity',true));
        $attrs=(array)get_post_meta($id,NF_Category_Consistency::ATTRIBUTES_META,true);
        $final=(array)get_post_meta($id,NF_Classification_Evidence::FINAL_RESULT_META,true);
        $kg=self::normalize_weight_kg($capacity.' '.$title); $count=self::normalize_count($quantity.' '.$title); $price=self::price($id);
        $categories=self::term_names($id,NF_Category::TAXONOMY); $municipalities=self::term_names($id,'nf_municipality');
        $sources=self::evidence_sources($final,get_post_meta($id,NF_Classification_Evidence::EVIDENCE_META,true));
        return array('id'=>$id,'title'=>$title,'municipality'=>implode(' / ',$municipalities),'categories'=>$categories,
            'item'=>!empty($attrs['items'])?implode(' / ',$attrs['items']):($categories?end($categories):''),'variety'=>!empty($attrs['varieties'])?implode(' / ',$attrs['varieties']):'',
            'capacity'=>$capacity,'kg'=>$kg,'count'=>$count,'quality'=>self::quality($title),'type'=>$attrs['product_type']??'single','price'=>$price,
            'per_kg'=>$kg>0&&$price>0?(int)round($price/$kg):0,'per_item'=>$count>0&&$price>0?(int)round($price/$count):0,
            'shipping'=>self::text_value(get_post_meta($id,'_nf_shipping',true)),'sources'=>$sources,'confidence'=>(float)get_post_meta($id,NF_Classification_Evidence::FINAL_CONFIDENCE_META,true));
    }
    private static function comparable($a,$b) {
        $differences=array();
        foreach(array('quality'=>'品質条件','type'=>'単品・定期便','variety'=>'品種') as $key=>$label) if($a[$key]!==$b[$key] && ($a[$key]||$b[$key])) $differences[]=$label;
        if($a['kg']&&$b['kg']&&abs($a['kg']-$b['kg'])/max($a['kg'],$b['kg'])>.15) $differences[]='重量';
        return array('valid'=>!$differences,'differences'=>$differences);
    }
    public static function page() {
        if(!current_user_can('nf_view_product_intelligence')) wp_die('権限がありません。');
        if(class_exists('NF_Commercial_Config')&&!NF_Commercial_Config::feature('product_intelligence')) wp_die('契約対象外の機能です。');
        $ids=get_posts(array('post_type'=>NF_Core::POST_TYPE,'post_status'=>'publish','posts_per_page'=>1000,'fields'=>'ids','no_found_rows'=>true));
        $rows=array_map(array(__CLASS__,'row'),$ids); $group=sanitize_text_field(wp_unslash($_GET['group']??''));
        $groups=array(); foreach($rows as $row) if($row['item']!=='') $groups[$row['item']][]=$row;
        if($group!==''&&isset($groups[$group])) $rows=$groups[$group];
        $priced=array_values(array_filter($rows,function($r){return $r['price']>0;})); $median=0;
        if($priced){$prices=array_column($priced,'price');sort($prices);$median=$prices[(int)floor((count($prices)-1)/2)];}
        echo '<div class="wrap"><h1>Product Intelligence</h1><p>分類後の既存データを利用した類似返礼品比較です。Classification Intelligence（分類精度）とは分離されています。比較のためのAI再実行は行いません。</p>';
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><select name="group"><option value="">全品目</option>'; foreach($groups as $name=>$items) echo '<option value="'.esc_attr($name).'" '.selected($group,$name,false).'>'.esc_html($name).'（'.count($items).'）</option>'; echo '</select> <button class="button">表示</button></form>';
        echo '<div style="display:flex;gap:12px;margin:18px 0"><div class="card"><strong>比較対象</strong><br>'.count($rows).'件</div><div class="card"><strong>寄附額中央値</strong><br>'.($median?number_format($median).'円':'算出不可').'</div><div class="card"><strong>AI再実行</strong><br>0件（既存証拠を再利用）</div></div>';
        echo '<table class="widefat striped"><thead><tr><th>返礼品</th><th>自治体</th><th>品目・品種</th><th>規格</th><th>品質</th><th>寄附額</th><th>単価</th><th>比較妥当性・判断根拠</th></tr></thead><tbody>';
        foreach($rows as $i=>$r){$basis='比較対象不足'; if(count($rows)>1){$other=$rows[$i?0:1];$check=self::comparable($r,$other);$basis=$check['valid']?'同品目・主要規格が近い比較候補':'完全同条件ではありません（'.implode('・',$check['differences']).'に差）';}
            $position=$median&&$r['price']?round(($r['price']-$median)/$median*100,1):null;
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($r['id'])).'">'.esc_html($r['title']).'</a></td><td>'.esc_html($r['municipality']).'</td><td>'.esc_html($r['item'].($r['variety']?' / '.$r['variety']:'')).'</td><td>'.esc_html($r['capacity'].($r['count']?' / '.$r['count'].'個':'')).'</td><td>'.esc_html($r['quality']).'</td><td>'.($r['price']?number_format($r['price']).'円':'—').'</td><td>'.($r['per_kg']?number_format($r['per_kg']).'円/kg':($r['per_item']?number_format($r['per_item']).'円/個':'—')).'</td><td>'.esc_html($basis).($position!==null?'<br>選択範囲の中央値比 '.esc_html(($position>0?'+':'').$position).'%':'').'<br><small>根拠: '.esc_html(implode(' / ',$r['sources'])?:'構造化規格').'</small></td></tr>';
        } echo '</tbody></table><p>数値は比較材料です。品種・等級・サイズ・訳あり・定期便・発送条件が異なる場合、優劣を断定しません。</p></div>';
    }
}
