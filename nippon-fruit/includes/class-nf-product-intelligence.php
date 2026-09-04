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
    private static function price($id) {
        $prices=array();
        foreach(array('_nf_price_min','_nf_price','_nf_price_max','_nf_yahoo_price') as $key) {
            $value=absint(get_post_meta($id,$key,true)); if($value>100) $prices[]=$value;
        }
        if(class_exists('NF_Yahoo')&&method_exists('NF_Yahoo','public_variants')) {
            foreach((array)NF_Yahoo::public_variants($id) as $variant) {
                $value=absint($variant['price']??0); if($value>100) $prices[]=$value;
            }
        }
        return $prices?min($prices):0;
    }
    private static function term_names($id,$taxonomy) {
        $terms=wp_get_post_terms($id,$taxonomy,array('fields'=>'names')); return is_wp_error($terms)?array():$terms;
    }
    private static function text_value($value) {
        if (is_scalar($value) || $value===null) return trim((string)$value);
        $flat=array(); self::flatten_scalars($value,$flat);
        return implode(' / ',array_values(array_unique($flat)));
    }
    private static function flatten_scalars($value,&$flat) {
        if (is_array($value)) {
            foreach ($value as $item) self::flatten_scalars($item,$flat);
            return;
        }
        if (is_scalar($value) && trim((string)$value)!=='') $flat[]=trim((string)$value);
    }
    private static function evidence_sources($final,$stored) {
        $values=array(); $sources=array($final['sources']??array(),$stored);
        self::flatten_scalars($sources,$values);
        $flat=array(); foreach($values as $item) {
            $item=sanitize_key($item); if(in_array($item,array('rule','text_ai','image_ai','manual'),true)) $flat[]=$item;
        }
        return array_values(array_unique($flat));
    }
    public static function row($id) {
        $title=get_the_title($id); $capacity=self::text_value(get_post_meta($id,'_nf_capacity',true)); $quantity=self::text_value(get_post_meta($id,'_nf_quantity',true));
        $attrs=(array)get_post_meta($id,NF_Category_Consistency::ATTRIBUTES_META,true);
        $final=(array)get_post_meta($id,NF_Classification_Evidence::FINAL_RESULT_META,true);
        $kg=self::normalize_weight_kg($capacity.' '.$title); $count=self::normalize_count($quantity.' '.$title); $price=self::price($id);
        $categories=self::term_names($id,NF_Category::TAXONOMY); $municipalities=self::term_names($id,'nf_municipality');
        $sources=self::evidence_sources($final,get_post_meta($id,NF_Classification_Evidence::EVIDENCE_META,true));
        return array('id'=>$id,'title'=>$title,'municipality'=>self::text_value($municipalities),'categories'=>$categories,
            'item'=>!empty($attrs['items'])?self::text_value($attrs['items']):($categories?end($categories):''),'variety'=>!empty($attrs['varieties'])?self::text_value($attrs['varieties']):'',
            'capacity'=>$capacity,'kg'=>$kg,'count'=>$count,'quality'=>self::quality($title),'type'=>self::text_value($attrs['product_type']??'single'),'price'=>$price,
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
        $priced_count=count($priced); $missing_price=count($rows)-$priced_count;
        echo '<div class="wrap nf-pi"><style>.nf-pi{max-width:1500px}.nf-pi-lead{color:#50575e;max-width:1100px}.nf-pi-filter{display:flex;gap:8px;align-items:center;margin:18px 0}.nf-pi-filter select{min-width:320px}.nf-pi-cards{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:14px;margin:18px 0}.nf-pi-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.nf-pi-card span{display:block;color:#646970;margin-bottom:7px}.nf-pi-card strong{font-size:24px;line-height:1.2}.nf-pi-table-wrap{overflow-x:auto;background:#fff;border:1px solid #dcdcde;border-radius:10px}.nf-pi-table{border:0;box-shadow:none;table-layout:fixed;min-width:980px}.nf-pi-table th{padding:13px 12px}.nf-pi-table td{padding:14px 12px;vertical-align:top;line-height:1.55;overflow-wrap:anywhere}.nf-pi-title{font-weight:600}.nf-pi-sub,.nf-pi-note{display:block;color:#646970;margin-top:5px}.nf-pi-price{white-space:nowrap;font-weight:600}.nf-pi-source{display:inline-block;background:#f0f6fc;border-radius:12px;padding:1px 8px;margin:4px 4px 0 0;font-size:11px}.nf-pi-empty{color:#8c8f94;font-weight:400}@media(max-width:782px){.nf-pi-cards{grid-template-columns:1fr 1fr}.nf-pi-filter{align-items:stretch;flex-direction:column}.nf-pi-filter select{min-width:0;width:100%}}</style><h1>Product Intelligence</h1><p class="nf-pi-lead">分類後の既存データを利用した類似返礼品比較です。比較のためにAIを再実行せず、保存済みの分類根拠・規格・寄附額を利用します。</p>';
        echo '<form method="get" class="nf-pi-filter"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><select name="group"><option value="">全品目</option>'; foreach($groups as $name=>$items) echo '<option value="'.esc_attr($name).'" '.selected($group,$name,false).'>'.esc_html($name).'（'.count($items).'）</option>'; echo '</select><button class="button button-primary">表示</button></form>';
        echo '<div class="nf-pi-cards"><div class="nf-pi-card"><span>比較対象</span><strong>'.count($rows).'件</strong></div><div class="nf-pi-card"><span>寄附額取得済み</span><strong>'.$priced_count.'件</strong></div><div class="nf-pi-card"><span>寄附額中央値</span><strong>'.($median?number_format($median).'円':'算出不可').'</strong></div><div class="nf-pi-card"><span>AI再実行</span><strong>0件</strong><small class="nf-pi-note">既存証拠を再利用</small></div></div>';
        if($missing_price) echo '<p class="notice notice-info inline"><span>寄附額未取得の商品が'.intval($missing_price).'件あります。楽天・Yahoo!同期後に自動的に比較へ反映されます。</span></p>';
        echo '<div class="nf-pi-table-wrap"><table class="widefat striped nf-pi-table"><colgroup><col style="width:34%"><col style="width:16%"><col style="width:16%"><col style="width:13%"><col style="width:21%"></colgroup><thead><tr><th>返礼品・自治体</th><th>品目・品種</th><th>規格・品質</th><th>寄附額・単価</th><th>比較妥当性・判断根拠</th></tr></thead><tbody>';
        foreach($rows as $i=>$r){$basis='比較対象不足'; if(count($rows)>1){$other=$rows[$i?0:1];$check=self::comparable($r,$other);$basis=$check['valid']?'同品目・主要規格が近い比較候補':'完全同条件ではありません（'.implode('・',$check['differences']).'に差）';}
            $position=$median&&$r['price']?round(($r['price']-$median)/$median*100,1):null;
            $unit=$r['per_kg']?number_format($r['per_kg']).'円/kg':($r['per_item']?number_format($r['per_item']).'円/個':'');
            $source_html=''; foreach($r['sources'] as $source) $source_html.='<span class="nf-pi-source">'.esc_html($source).'</span>';
            echo '<tr><td><a class="nf-pi-title" href="'.esc_url(get_edit_post_link($r['id'])).'">'.esc_html($r['title']).'</a><span class="nf-pi-sub">'.esc_html($r['municipality']?:'自治体未設定').'</span></td><td>'.esc_html($r['item']?:'未分類').($r['variety']?'<span class="nf-pi-sub">'.esc_html($r['variety']).'</span>':'').'</td><td>'.esc_html($r['capacity']?:'規格未取得').($r['count']?'<span class="nf-pi-sub">'.$r['count'].'個</span>':'').'<span class="nf-pi-sub">'.esc_html($r['quality']).'</span></td><td>'.($r['price']?'<span class="nf-pi-price">'.number_format($r['price']).'円</span>'.($unit?'<span class="nf-pi-sub">'.$unit.'</span>':''):'<span class="nf-pi-empty">未取得</span>').'</td><td>'.esc_html($basis).($position!==null?'<span class="nf-pi-sub">中央値比 '.esc_html(($position>0?'+':'').$position).'%</span>':'').($source_html?:'<div>'.$source_html.'</div>':'<span class="nf-pi-note">構造化規格を使用</span>').'</td></tr>';
        } echo '</tbody></table></div><p class="nf-pi-note">数値は比較材料です。品種・等級・サイズ・訳あり・定期便・発送条件が異なる場合、優劣を断定しません。</p></div>';
    }
}
