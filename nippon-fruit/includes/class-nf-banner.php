<?php
if ( ! defined('ABSPATH') ) exit;

class NF_Banner {
    const POST_TYPE = 'nf_banner';
    public static function init() {
        add_action('init', array(__CLASS__, 'register'));
        add_action('add_meta_boxes', array(__CLASS__, 'boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save'));
        add_shortcode('nf_banners', array(__CLASS__, 'shortcode'));
    }
    public static function register() {
        register_post_type(self::POST_TYPE, array(
            'labels'=>array('name'=>'バナー管理','singular_name'=>'バナー','add_new_item'=>'バナーを追加','edit_item'=>'バナーを編集'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>'nf-customer-dashboard','supports'=>array('title','thumbnail','page-attributes'),
            'capabilities'=>self::caps(),'map_meta_cap'=>false,
        ));
    }
    private static function caps() { return array('edit_post'=>'nf_manage_banners','read_post'=>'nf_manage_banners','delete_post'=>'nf_manage_banners','edit_posts'=>'nf_manage_banners','edit_others_posts'=>'nf_manage_banners','publish_posts'=>'nf_manage_banners','read_private_posts'=>'nf_manage_banners','delete_posts'=>'nf_manage_banners','delete_private_posts'=>'nf_manage_banners','delete_published_posts'=>'nf_manage_banners','delete_others_posts'=>'nf_manage_banners','edit_private_posts'=>'nf_manage_banners','edit_published_posts'=>'nf_manage_banners','create_posts'=>'nf_manage_banners'); }
    public static function boxes() { add_meta_box('nf-banner-settings','バナー設定',array(__CLASS__,'box'),self::POST_TYPE,'normal','high'); }
    public static function box($post) {
        wp_nonce_field('nf_banner_save','nf_banner_nonce');
        $url=get_post_meta($post->ID,'_nf_banner_url',true); $start=get_post_meta($post->ID,'_nf_banner_start',true); $end=get_post_meta($post->ID,'_nf_banner_end',true); $visible=get_post_meta($post->ID,'_nf_banner_visible',true);
        ?><p><label><strong>リンク先</strong><br><input type="url" class="large-text" name="nf_banner_url" value="<?php echo esc_attr($url); ?>" placeholder="<?php echo esc_attr(NF_System_Page::url()); ?>"></label></p>
        <p><label><strong>表示開始</strong><br><input type="datetime-local" name="nf_banner_start" value="<?php echo esc_attr($start); ?>"></label>　<label><strong>表示終了</strong><br><input type="datetime-local" name="nf_banner_end" value="<?php echo esc_attr($end); ?>"></label></p>
        <p><label><input type="checkbox" name="nf_banner_visible" value="1" <?php checked($visible !== '0'); ?>> 公開する</label></p><p class="description">画像は右側の「アイキャッチ画像」、並び順は「順序」で指定します。</p><?php
    }
    public static function save($id) {
        if (!isset($_POST['nf_banner_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nf_banner_nonce'])),'nf_banner_save') || !current_user_can('nf_manage_banners') || wp_is_post_revision($id)) return;
        update_post_meta($id,'_nf_banner_url',esc_url_raw(wp_unslash($_POST['nf_banner_url'] ?? '')));
        foreach(array('start','end') as $key) update_post_meta($id,'_nf_banner_'.$key,sanitize_text_field(wp_unslash($_POST['nf_banner_'.$key] ?? '')));
        update_post_meta($id,'_nf_banner_visible',isset($_POST['nf_banner_visible'])?'1':'0');
    }
    public static function active_ids() {
        $ids=get_posts(array('post_type'=>self::POST_TYPE,'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>array('menu_order'=>'ASC','date'=>'DESC'),'fields'=>'ids'));
        $now=current_time('timestamp');
        return array_values(array_filter($ids,function($id)use($now){
            if(get_post_meta($id,'_nf_banner_visible',true)==='0')return false;
            $s=get_post_meta($id,'_nf_banner_start',true);$e=get_post_meta($id,'_nf_banner_end',true);
            $st=$s?strtotime($s):0;$et=$e?strtotime($e):0;
            return (!$st||$st<=$now)&&(!$et||$et>=$now)&&has_post_thumbnail($id);
        }));
    }
    public static function render() {
        $ids=self::active_ids(); if(!$ids)return;
        echo '<section class="nf-commercial-banners" aria-label="ご案内">';
        foreach($ids as $id){$url=get_post_meta($id,'_nf_banner_url',true)?:NF_System_Page::url();echo '<a href="'.esc_url($url).'">'.get_the_post_thumbnail($id,'large',array('alt'=>get_the_title($id))).'</a>';}
        echo '</section>';
    }
    public static function shortcode(){ob_start();self::render();return ob_get_clean();}
}
