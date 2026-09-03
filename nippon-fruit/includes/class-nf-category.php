<?php
if ( ! defined('ABSPATH') ) exit;

class NF_Category {
    const TAXONOMY = 'nf_category';
    const ATTRIBUTE_TAXONOMY = 'nf_attribute';
    const MASTER_VERSION = '2026-09-01-1';
    const ATTRIBUTE_MIGRATION_OPTION = 'nf_attribute_migrated_to_category_v1';
    const RECLASSIFY_VERSION = '2026-09-03-8';
    const RECLASSIFY_OPTION = 'nf_category_reclassify_version';
    const RECLASSIFY_CURSOR_OPTION = 'nf_category_reclassify_cursor';
    const RECLASSIFY_CRON = 'nf_category_continue_reclassification';
    const RECLASSIFY_PROGRESS_OPTION = 'nf_category_reclassify_progress';
    const AUTO_TERMS_META = '_nf_auto_category_term_ids';
    const EXCLUDE_KEYWORDS_META = '_nf_category_exclude_keywords';
    const CLASSIFICATION_LOCK_META = '_nf_category_manual_lock';
    const CONFIDENCE_META = '_nf_category_confidence';
    const REVIEW_REASON_META = '_nf_category_review_reason';
    const ORDER_OPTION = 'nf_category_public_order';
    const MUNICIPALITY_ORDER_OPTION = 'nf_municipality_public_order';

    private static $reclassification_queued = false;
    private static $mark_reclassification_version = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'ensure_master_terms'), 25);
        add_action('init', array(__CLASS__, 'migrate_attribute_terms_to_categories'), 35);
        add_action('init', array(__CLASS__, 'maybe_queue_upgrade_reclassification'), 45);
        add_action('created_' . self::TAXONOMY, array(__CLASS__, 'queue_existing_reclassification'), 20, 1);
        add_action('edited_' . self::TAXONOMY, array(__CLASS__, 'queue_existing_reclassification'), 20, 1);
        add_action(self::TAXONOMY . '_add_form_fields', array(__CLASS__, 'render_exclude_keywords_add_field'));
        add_action(self::TAXONOMY . '_edit_form_fields', array(__CLASS__, 'render_exclude_keywords_edit_field'), 10, 2);
        add_action('created_' . self::TAXONOMY, array(__CLASS__, 'save_exclude_keywords'), 10, 1);
        add_action('edited_' . self::TAXONOMY, array(__CLASS__, 'save_exclude_keywords'), 10, 1);
        add_action('save_post_' . NF_Core::POST_TYPE, array(__CLASS__, 'auto_classify_post'), 30, 3);
        add_action(self::RECLASSIFY_CRON, array(__CLASS__, 'run_scheduled_reclassification'));
        add_action('admin_menu', array(__CLASS__, 'register_order_page'));
        add_action('admin_post_nf_save_category_order', array(__CLASS__, 'save_category_order'));
    }

    public static function register_order_page() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            'カテゴリ・自治体 並び順',
            '表示並び順',
            'nf_manage_categories',
            'nf-category-order',
            array(__CLASS__, 'render_order_page')
        );
    }

    public static function render_order_page() {
        if ( ! current_user_can('nf_manage_categories') ) return;
        wp_enqueue_script('jquery-ui-sortable');
        $terms = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        $terms = is_wp_error($terms) ? array() : $terms;
        $by_parent = array();
        foreach ($terms as $term) {
            $parent = (int)$term->parent;
            if ( ! isset($by_parent[$parent]) ) $by_parent[$parent] = array();
            $by_parent[$parent][] = $term;
        }
        self::sort_term_groups($by_parent);
        $municipality_terms = get_terms(array(
            'taxonomy' => 'nf_municipality',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        $municipality_terms = is_wp_error($municipality_terms) ? array() : $municipality_terms;
        $municipality_by_parent = array();
        foreach ($municipality_terms as $term) {
            $parent = (int)$term->parent;
            if ( ! isset($municipality_by_parent[$parent]) ) $municipality_by_parent[$parent] = array();
            $municipality_by_parent[$parent][] = $term;
        }
        self::sort_term_groups($municipality_by_parent, self::MUNICIPALITY_ORDER_OPTION);
        ?>
        <div class="wrap nf-category-order-admin">
          <h1>カテゴリ・自治体 並び順</h1>
          <p>行をドラッグして並べ替え、「並び順を保存」を押してください。親子関係は変えず、同じ階層内の順序だけを変更します。</p>
          <?php if ( isset($_GET['updated']) ) : ?>
            <div class="notice notice-success is-dismissible"><p>カテゴリの並び順を保存しました。</p></div>
          <?php endif; ?>
          <h2>お礼品カテゴリ</h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nf-order-form">
            <input type="hidden" name="action" value="nf_save_category_order">
            <input type="hidden" name="order_kind" value="category">
            <?php wp_nonce_field('nf_save_category_order', 'nf_category_order_nonce'); ?>
            <input type="hidden" name="term_order" class="nf-order-value" value="">
            <div class="nf-category-order-tree">
              <?php self::render_order_items(0, $by_parent); ?>
            </div>
            <?php submit_button('並び順を保存'); ?>
          </form>
          <hr style="margin:32px 0;max-width:720px">
          <h2>自治体から探す</h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nf-order-form">
            <input type="hidden" name="action" value="nf_save_category_order">
            <input type="hidden" name="order_kind" value="municipality">
            <?php wp_nonce_field('nf_save_category_order', 'nf_category_order_nonce'); ?>
            <input type="hidden" name="term_order" class="nf-order-value" value="">
            <div class="nf-category-order-tree">
              <?php self::render_order_items(0, $municipality_by_parent); ?>
            </div>
            <?php submit_button('自治体の並び順を保存'); ?>
          </form>
        </div>
        <style>
          .nf-category-order-tree{max-width:720px;margin-top:20px}.nf-category-order-list{margin:0;padding:0;list-style:none}.nf-category-order-list .nf-category-order-list{margin:7px 0 7px 34px}.nf-category-order-item{margin:7px 0}.nf-category-order-row{display:flex;align-items:center;gap:12px;min-height:46px;padding:0 15px;border:1px solid #dcdcde;border-radius:7px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}.nf-category-order-handle{color:#777;cursor:grab;font-size:20px}.nf-category-order-count{margin-left:auto;color:#777}.nf-category-order-placeholder{height:46px;border:2px dashed #72aee6;border-radius:7px;background:#f0f6fc}
        </style>
        <script>
        jQuery(function($){
          $('.nf-category-order-list').sortable({
            items:'> .nf-category-order-item', handle:'.nf-category-order-handle',
            placeholder:'nf-category-order-placeholder', axis:'y'
          });
          $('.nf-order-form').on('submit',function(){
            var ids=[];
            $(this).find('.nf-category-order-item').each(function(){ ids.push(String($(this).data('term-id'))); });
            $(this).find('.nf-order-value').val(ids.join(','));
          });
        });
        </script>
        <?php
    }

    private static function render_order_items($parent, $by_parent) {
        if ( empty($by_parent[$parent]) ) return;
        echo '<ul class="nf-category-order-list">';
        foreach ($by_parent[$parent] as $term) {
            echo '<li class="nf-category-order-item" data-term-id="' . esc_attr($term->term_id) . '">';
            echo '<div class="nf-category-order-row"><span class="nf-category-order-handle" aria-hidden="true">☰</span><strong>' . esc_html($term->name) . '</strong><span class="nf-category-order-count">' . intval($term->count) . '件</span></div>';
            self::render_order_items((int)$term->term_id, $by_parent);
            echo '</li>';
        }
        echo '</ul>';
    }

    public static function save_category_order() {
        if ( ! current_user_can('nf_manage_categories') ) wp_die('権限がありません。');
        check_admin_referer('nf_save_category_order', 'nf_category_order_nonce');
        $raw = isset($_POST['term_order']) ? sanitize_text_field(wp_unslash($_POST['term_order'])) : '';
        $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', $raw)))));
        $kind = isset($_POST['order_kind']) ? sanitize_key(wp_unslash($_POST['order_kind'])) : 'category';
        $option = $kind === 'municipality' ? self::MUNICIPALITY_ORDER_OPTION : self::ORDER_OPTION;
        update_option($option, $ids, false);
        wp_safe_redirect(add_query_arg(array(
            'post_type' => NF_Core::POST_TYPE,
            'page' => 'nf-category-order',
            'updated' => 1,
        ), admin_url('edit.php')));
        exit;
    }

    public static function order_rank($option = self::ORDER_OPTION) {
        $ids = array_values(array_filter(array_map('absint', (array)get_option($option, array()))));
        $rank = array();
        foreach ($ids as $index => $id) $rank[$id] = $index;
        return $rank;
    }

    private static function sort_term_groups(&$by_parent, $option = self::ORDER_OPTION) {
        $rank = self::order_rank($option);
        foreach ($by_parent as &$group) {
            usort($group, function($a, $b) use ($rank) {
                $ar = isset($rank[$a->term_id]) ? $rank[$a->term_id] : PHP_INT_MAX;
                $br = isset($rank[$b->term_id]) ? $rank[$b->term_id] : PHP_INT_MAX;
                if ($ar === $br) return strnatcasecmp($a->name, $b->name);
                return $ar <=> $br;
            });
        }
        unset($group);
    }

    public static function render_exclude_keywords_add_field() {
        wp_nonce_field('nf_category_exclude_keywords', 'nf_category_exclude_keywords_nonce');
        ?>
        <div class="form-field term-nf-exclude-wrap">
            <label for="nf-category-exclude-keywords">このカテゴリから除外する商品語句</label>
            <textarea name="nf_category_exclude_keywords" id="nf-category-exclude-keywords" rows="5"></textarea>
            <p>商品名や説明にここで指定した語句が含まれる場合、このカテゴリへ自動登録せず、既存の紐付けも除去します。1行に1語、またはカンマ区切りで入力してください。</p>
        </div>
        <?php
    }

    public static function render_exclude_keywords_edit_field($term, $taxonomy) {
        $value = get_term_meta((int)$term->term_id, self::EXCLUDE_KEYWORDS_META, true);
        $value = is_array($value) ? implode("\n", $value) : '';
        wp_nonce_field('nf_category_exclude_keywords', 'nf_category_exclude_keywords_nonce');
        ?>
        <tr class="form-field term-nf-exclude-wrap">
            <th scope="row"><label for="nf-category-exclude-keywords">このカテゴリから除外する商品語句</label></th>
            <td>
                <textarea name="nf_category_exclude_keywords" id="nf-category-exclude-keywords" rows="6" class="large-text"><?php echo esc_textarea($value); ?></textarea>
                <p class="description">商品名や説明に指定語句が含まれる場合、このカテゴリへ自動登録せず、既存の紐付けも除去します。例：「みかん」カテゴリなら「不知火」「デコポン」「晩白柚」を1行ずつ入力します。</p>
            </td>
        </tr>
        <?php
    }

    public static function save_exclude_keywords($term_id) {
        if (
            empty($_POST['nf_category_exclude_keywords_nonce']) ||
            ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nf_category_exclude_keywords_nonce'])), 'nf_category_exclude_keywords') ||
            ! current_user_can('nf_manage_categories')
        ) {
            return;
        }

        $raw = isset($_POST['nf_category_exclude_keywords'])
            ? sanitize_textarea_field(wp_unslash($_POST['nf_category_exclude_keywords']))
            : '';
        $keywords = self::parse_exclude_keywords($raw);
        if ($keywords) {
            update_term_meta((int)$term_id, self::EXCLUDE_KEYWORDS_META, $keywords);
        } else {
            delete_term_meta((int)$term_id, self::EXCLUDE_KEYWORDS_META);
        }
    }

    private static function parse_exclude_keywords($value) {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[\r\n,、]+/u', (string)$value);
        }
        $out = array();
        foreach ((array)$parts as $part) {
            $part = trim(wp_strip_all_tags((string)$part));
            if ($part === '') continue;
            $key = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
            $out[$key] = $part;
        }
        return array_values($out);
    }

    public static function register_taxonomies() {
        register_taxonomy(self::TAXONOMY, NF_Core::POST_TYPE, array(
            'labels' => array(
                'name' => 'カテゴリ',
                'singular_name' => 'カテゴリ',
                'menu_name' => 'カテゴリ',
                'all_items' => 'カテゴリ一覧',
                'edit_item' => 'カテゴリを編集',
                'add_new_item' => 'カテゴリを追加',
            ),
            'public' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'capabilities' => array(
                'manage_terms' => 'nf_manage_categories',
                'edit_terms' => 'nf_manage_categories',
                'delete_terms' => 'nf_manage_categories',
                'assign_terms' => 'nf_manage_categories',
            ),
            'rewrite' => array('slug' => class_exists('NF_System_Page') ? NF_System_Page::taxonomy_rewrite('category') : 'furusato/category', 'with_front' => false),
        ));

        register_taxonomy(self::ATTRIBUTE_TAXONOMY, NF_Core::POST_TYPE, array(
            'labels' => array(
                'name' => '商品属性',
                'singular_name' => '商品属性',
                'menu_name' => '商品属性',
            ),
            'public' => false,
            'hierarchical' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'show_admin_column' => false,
        ));
    }

    public static function master() {
        return array(
            '肉' => array(
                '牛肉（精肉）' => array('ステーキ','すき焼き','しゃぶしゃぶ','焼肉','牛タン','和牛','黒毛和牛','白老牛','仙台牛','米沢牛','山形牛','常陸牛','上州牛','飛騨牛','近江牛','神戸牛・神戸ビーフ','但馬牛','土佐あかうし','佐賀牛','長崎和牛','あか牛','宮崎牛','その他牛肉（精肉）'),
                '牛肉（加工品）' => array('ハンバーグ','もつ鍋','ローストビーフ','ビーフジャーキー','その他牛肉（加工品）'),
                '豚肉（精肉）' => array('ステーキ','すき焼き','しゃぶしゃぶ','焼肉','アグー豚','その他豚肉（精肉）'),
                '豚肉（加工品）' => array('ハンバーグ','もつ鍋','ハム','ソーセージ・ウインナー','ベーコン・サラミ','その他豚肉（加工品）'),
                '鶏肉' => array('鶏肉（精肉）','ハム・ソーセージ','唐揚げ','中津からあげ','水炊き','地鶏','赤鶏さつま','その他鶏肉'),
                '鹿肉' => array(), '馬肉' => array(), '羊肉・ラム肉（ジンギスカン）' => array(), '鴨肉' => array(), '猪肉' => array(), 'その他肉・加工品' => array(),
            ),
            '魚介・海産物' => array(
                'カニ' => array('ズワイガニ','タラバガニ','毛ガニ','かにしゃぶ','その他カニ'),
                'エビ' => array('甘エビ','ボタンエビ','伊勢海老','その他エビ'),
                'いくら' => array(), 'うに' => array(),
                '明太子・たらこ' => array('明太子','たらこ','その他魚卵'),
                'その他魚卵' => array('数の子','からすみ','キャビア','その他魚卵'),
                '貝' => array('帆立（ホタテ）','鮑（アワビ）','牡蠣（カキ）','あさり','しじみ','サザエ','はまぐり','その他貝'),
                'うなぎ' => array(),
                '鮮魚' => array('鮭・サーモン','マグロ','イワシ','カツオ','金目鯛','クエ','くじら','サバ','さんま','鯛','のどぐろ','ふぐ','ブリ','ほっけ','その他鮮魚'),
                'イカ・タコ' => array('イカ','タコ'),
                '海苔・海藻' => array('海苔','わかめ','ひじき','その他海苔・海藻'),
                '干物' => array('ししゃも','その他干物'),
                'その他魚介・加工品' => array('しらす・ちりめん','かまぼこ・練り製品','その他魚介・加工品'),
            ),
            '米・パン' => array(
                '米' => array('精米','無洗米','玄米','金芽米','ゆめぴりか','つや姫','コシヒカリ','はえぬき','さがびより','あきたこまち','ひとめぼれ','ミルキークィーン','ななつぼし','その他米'),
                '雑穀' => array(), '餅' => array(), 'その他穀物加工品' => array(), 'パン' => array(),
            ),
            '果物・フルーツ' => array(
                'ぶどう・マスカット' => array('巨峰','ナガノパープル','ピオーネ','デラウェア','シャインマスカット','その他ぶどう・マスカット'),
                'いちご' => array(), 'りんご' => array(), 'もも' => array(), 'メロン' => array(), 'さくらんぼ' => array(),
                '梨' => array('豊水梨','秋月梨','新高梨','和梨','洋梨・ラフランス','その他の梨'), 'マンゴー' => array(),
                'みかん・柑橘' => array('みかん','レモン','不知火・デコポン','せとか','文旦','まどんな','ポンカン','その他柑橘'),
                'すいか' => array(), 'キウイ' => array(), '柿（カキ）' => array(),
                'ドライフルーツ' => array('干し柿','干し芋','その他ドライフルーツ'),
                'その他果物' => array('びわ','ブルーベリー','パイナップル','栗','その他果物'),
            ),
            '野菜' => array(
                'いも' => array('じゃがいも','さつまいも','里芋','紅はるか','シルクスイート','その他いも'),
                'トマト' => array('フルーツトマト','ミニトマト','その他トマト'),
                '玉ねぎ' => array(), 'ねぎ' => array(), 'とうもろこし' => array(),
                '根菜' => array('人参','大根','自然薯','レンコン','にんにく・生姜','その他根菜'),
                'アスパラガス' => array(), '豆' => array(), 'きのこ' => array('しいたけ','松茸','その他きのこ'),
                'その他野菜' => array('山菜','かぼちゃ','茄子','レタス','その他野菜'),
            ),
            '卵・乳製品' => array('卵'=>array(),'チーズ'=>array(),'ヨーグルト'=>array(),'牛乳'=>array(),'バター'=>array(),'その他乳製品'=>array()),
            '酒・アルコール' => array(
                'ビール・発泡酒' => array('ビール','発泡酒','地ビール・クラフトビール'),
                '日本酒' => array('純米大吟醸','純米吟醸','大吟醸','吟醸','その他日本酒'),
                '焼酎' => array('芋焼酎','麦焼酎','米焼酎','黒糖焼酎','その他焼酎'),
                '梅酒'=>array(),'泡盛'=>array(),'ワイン'=>array('白ワイン','赤ワイン','シャンパン・スパークリングワイン','その他ワイン'),
                'ウイスキー'=>array(),'リキュール・洋酒'=>array(),'甘酒'=>array(),'ノンアルコール'=>array(),'その他酒'=>array(),
            ),
            '飲料・ドリンク' => array(
                '水・ミネラルウォーター'=>array(),
                'コーヒー・コーヒー豆'=>array('飲料','コーヒー豆','粉','ドリップ'),
                '茶'=>array('飲料','茶葉・ティーバッグ','静岡茶','足柄茶','知覧茶','八女茶','その他茶'),
                '果汁飲料'=>array('りんごジュース','みかんジュース（オレンジジュース）','その他果汁飲料'),
                '紅茶'=>array('飲料','茶葉・ティーバッグ'),
                'その他飲料・ジュース'=>array('野菜ジュース','炭酸飲料','豆乳','その他飲料・ジュース'),
            ),
            '菓子・スイーツ' => array(
                'ケーキ'=>array(),'クッキー'=>array(),'焼き菓子'=>array(),'プリン'=>array(),'ゼリー'=>array(),'チョコレート'=>array(),'カステラ'=>array(),'アイス・ジェラート'=>array(),'その他洋菓子'=>array(),
                '煎餅・おかき'=>array(),'羊羹'=>array(),'饅頭'=>array(),'大福'=>array(),'その他和菓子'=>array(),
            ),
            '麺' => array('ラーメン'=>array(),'うどん'=>array(),'そば'=>array(),'パスタ'=>array(),'ひやむぎ'=>array(),'そうめん'=>array(),'その他麺'=>array()),
            '惣菜・加工品' => array(
                '惣菜'=>array('餃子','シュウマイ','コロッケ','その他惣菜'),
                'カレー・シチュー'=>array('カレー','シチュー'),
                '鍋'=>array('肉','魚','その他鍋'),
                'ピザ'=>array(),'レトルト'=>array(),'スープ'=>array(),
                '豆腐・納豆'=>array('豆腐','納豆'),
                '漬物'=>array('梅干','キムチ','その他漬物'),
                '缶詰・瓶詰'=>array('肉','魚','果物','ジャム','その他缶詰・瓶詰'),
                '乾物'=>array(),'燻製（スモーク）'=>array(),'おせち'=>array(),'その他加工品'=>array(),
            ),
            '調味料' => array('砂糖'=>array(),'塩'=>array(),'醤油'=>array(),'味噌'=>array(),'酢'=>array(),'だし'=>array(),'食用油'=>array('えごま油','オリーブオイル','ごま油','その他食用油'),'はちみつ'=>array(),'ドレッシング'=>array(),'その他調味料'=>array('みりん','ケチャップ','こしょう','その他調味料')),
            '家電製品' => array('季節・空調家電'=>array(),'キッチン家電'=>array(),'照明器具'=>array(),'パソコン・周辺機器'=>array(),'TV・オーディオ・カメラ'=>array(),'美容・健康家電'=>array(),'カー用品'=>array(),'時計'=>array(),'その他家電'=>array()),
            '旅行券・宿泊券' => array('旅行券'=>array('JTBふるさと旅行クーポン（Eメール発行）','JTBふるさと旅行券（紙券）','その他旅行券'),'宿泊券'=>array()),
            '体験・チケット' => array('PayPay商品券'=>array(),'食事券'=>array(),'温泉・サウナ・スパ利用券'=>array(),'水族館'=>array(),'動物園'=>array(),'釣り'=>array(),'ダイビング'=>array(),'スキーチケット・リフト券'=>array(),'ゴルフプレー券'=>array('GDOふるさとゴルフプレークーポン','その他のゴルフプレー券'),'花火大会チケット'=>array(),'カタログギフト'=>array(),'その他体験・チケット'=>array()),
            '雑貨・日用品' => array(
                '家具・インテリア'=>array('タンス','机・テーブル','椅子・チェア・ソファ','その他家具・インテリア'),
                '寝具'=>array('布団','枕','毛布','タオルケット','その他寝具'),
                'タオル'=>array('泉州タオル','その他タオル'),
                '文房具・印鑑'=>array('ボールペン','ノート・ファイル','印鑑','その他文房具'),
                '食器'=>array('グラス・カップ','タンブラー','箸','スプーン・フォーク・ナイフ','皿・椀','弁当箱','その他食器'),
                'キッチン用品'=>array('包丁','フライパン','鍋','まな板','土鍋','その他キッチン用品'),
                '日用品'=>array('洗剤','トイレットペーパー','ティッシュ','その他日用品'),
                '楽器・器材'=>array(),'本・CD・DVD'=>array(),'おもちゃ・ぬいぐるみ'=>array(),'ご当地キャラクター'=>array(),'ベビー用品'=>array(),'ペット用品'=>array(),'防災グッズ'=>array(),'その他雑貨'=>array(),
            ),
            'スポーツ・アウトドア' => array('ゴルフ'=>array('ゴルフボール','ゴルフクラブ','ゴルフウェア','その他ゴルフ'),'釣り'=>array(),'サイクリング'=>array(),'アウトドア・キャンプ'=>array(),'その他スポーツ'=>array(),'ウェア・ユニフォーム'=>array()),
            '美容' => array('スキンケア'=>array('化粧水・乳液・美容液','洗顔','その他スキンケア'),'シャンプー・リンス'=>array(),'石鹸・ボディーソープ'=>array(),'入浴剤'=>array(),'アロマ'=>array(),'プロテイン'=>array(),'その他美容'=>array()),
            'ファッション' => array('鞄・バッグ'=>array('トートバッグ・ショルダーバッグ','キャリーバッグ・スーツケース','その他鞄・バッグ'),'洋服'=>array('女性・レディース','男性・メンズ','子供・ベビー','その他洋服'),'和服'=>array(),'靴・履物'=>array('靴・シューズ','スリッパ・下駄・草履','その他靴・履物'),'アクセサリー'=>array('ペンダント・ネックレス','ピアス・イヤリング','真珠・パール','その他アクセサリー'),'その他服飾小物'=>array('財布','ショール・ストール','ネクタイ・ベルト','マフラー・手袋','その他服飾小物')),
            '工芸品' => array('織物'=>array('本場奄美大島紬','その他織物'),'陶器・漆器'=>array('信楽焼','唐津焼','備前焼','美濃焼','村上木彫堆朱','その他陶器・漆器'),'その他装飾品・工芸品'=>array('数珠','工芸品','播州そろばん','美濃和紙','民芸品')),
            '花・観葉植物' => array('観葉植物・苗木'=>array(),'花'=>array('胡蝶蘭','造花・プリザーブドフラワー','その他花'),'盆栽・その他'=>array()),
            'その他' => array('地域サービス'=>array(),'その他'=>array()),
            '定期便' => array(),
            'あとから選べるお礼品' => array(),
        );
    }

    public static function ensure_master_terms() {
        if (
            class_exists('NF_Settings') &&
            ! NF_Settings::category_assist_mode()
        ) {
            return;
        }

        if ( get_option('nf_category_master_version') === self::MASTER_VERSION ) return;
        self::seed_master();
        self::migrate_existing_fruit_terms();
        self::classify_existing_posts();
        update_option('nf_category_master_version', self::MASTER_VERSION, false);
    }

    private static function seed_master() {
        foreach ( self::master() as $top => $children ) {
            $top_id = self::ensure_term($top, 0, $top);
            foreach ( $children as $second => $thirds ) {
                $second_id = self::ensure_term($second, $top_id, $top . '>' . $second);
                foreach ( (array)$thirds as $third ) {
                    self::ensure_term($third, $second_id, $top . '>' . $second . '>' . $third);
                }
            }
        }
    }

    private static function ensure_term($name, $parent, $path) {
        $existing = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'name' => $name,
            'parent' => (int)$parent,
            'number' => 1,
        ));
        if ( ! is_wp_error($existing) && ! empty($existing) ) return (int)$existing[0]->term_id;

        $base = sanitize_title($name);
        if ( $base === '' ) $base = 'category';
        $slug = $base . '-' . substr(md5($path), 0, 7);
        $result = wp_insert_term($name, self::TAXONOMY, array('parent'=>(int)$parent, 'slug'=>$slug));
        if ( is_wp_error($result) ) {
            $term = get_term_by('slug', $slug, self::TAXONOMY);
            return $term ? (int)$term->term_id : 0;
        }
        return (int)$result['term_id'];
    }

    public static function tree_for_public() {
        $terms = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        if ( is_wp_error($terms) ) return array();

        $by_parent = array();
        foreach ($terms as $term) {
            $parent_id = (int)$term->parent;
            if ( ! isset($by_parent[$parent_id]) ) $by_parent[$parent_id] = array();
            $by_parent[$parent_id][] = $term;
        }
        self::sort_term_groups($by_parent);

        $effective_counts = self::effective_term_counts($terms);
        $allowed_top_slugs = self::allowed_top_slugs();
        $out = array();
        foreach (isset($by_parent[0]) ? $by_parent[0] : array() as $top) {
            if ($allowed_top_slugs && ! in_array($top->slug, $allowed_top_slugs, true)) continue;
            $row = self::public_tree_row($top, $by_parent, $effective_counts, 1);
            if ($row) $out[] = $row;
        }
        return $out;
    }

    /**
     * Build one public tree row. A parent remains available when only one of its
     * descendants has products, which is common after assigning a leaf category
     * from the standard WordPress taxonomy UI.
     */
    private static function public_tree_row($term, $by_parent, $effective_counts, $depth) {
        $term_id = (int)$term->term_id;
        $count = isset($effective_counts[$term_id]) ? (int)$effective_counts[$term_id] : 0;
        if ($count < 1) return null;

        $row = array(
            'slug' => $term->slug,
            'name' => $term->name,
            'count' => $count,
            'children' => array(),
        );

        // category/subcategory/type supports three public levels.
        if ($depth < 3 && ! empty($by_parent[$term_id])) {
            foreach ($by_parent[$term_id] as $child) {
                $child_row = self::public_tree_row($child, $by_parent, $effective_counts, $depth + 1);
                if ($child_row) $row['children'][] = $child_row;
            }
        }

        return $row;
    }

    /**
     * Use the larger of a term's own count and its visible descendant total.
     * This avoids double-counting sites that attach ancestors explicitly while
     * still keeping ancestors visible when products are assigned only to leaves.
     */
    private static function effective_term_counts($terms) {
        $own_counts = array();
        $children = array();
        foreach ((array)$terms as $term) {
            $term_id = (int)$term->term_id;
            $parent_id = (int)$term->parent;
            $own_counts[$term_id] = (int)$term->count;
            if ( ! isset($children[$parent_id]) ) $children[$parent_id] = array();
            $children[$parent_id][] = $term_id;
        }

        $effective = array();
        $calculate = function($term_id) use (&$calculate, &$effective, $own_counts, $children) {
            if (isset($effective[$term_id])) return $effective[$term_id];

            $descendant_total = 0;
            foreach (isset($children[$term_id]) ? $children[$term_id] : array() as $child_id) {
                $descendant_total += $calculate($child_id);
            }

            $own = isset($own_counts[$term_id]) ? (int)$own_counts[$term_id] : 0;
            $effective[$term_id] = max($own, $descendant_total);
            return $effective[$term_id];
        };

        foreach ($own_counts as $term_id => $unused) $calculate($term_id);
        return $effective;
    }

    private static function allowed_top_slugs() {
        return class_exists('NF_Settings')
            ? NF_Settings::category_allowed_top_slugs()
            : array();
    }



    /**
     * Categories shown in the public "カテゴリから探す" entry area.
     * The master taxonomy remains complete; only the public entry points are optimized.
     */
    public static function public_nav_items() {
        $mode = class_exists('NF_Settings') ? NF_Settings::category_nav_mode() : 'auto';
        $limit = class_exists('NF_Settings') ? NF_Settings::category_nav_limit() : 12;
        $suppress = ! class_exists('NF_Settings') || NF_Settings::category_nav_suppress_parent_child();

        if ($mode === 'custom') {
            $custom = class_exists('NF_Settings') ? NF_Settings::category_nav_custom() : '';
            $items = self::custom_nav_items($custom);
            return array_slice(self::dedupe_nav_items($items, $suppress), 0, $limit);
        }

        $terms = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'count',
            'order' => 'DESC',
        ));
        if (is_wp_error($terms)) return array();

        $effective_counts = self::effective_term_counts($terms);
        $allowed_top_slugs = self::allowed_top_slugs();

        $rows = array();
        foreach ($terms as $term) {
            $row = self::nav_row_from_term($term);
            if (!$row) continue;
            if ($allowed_top_slugs && ! in_array($row['category'], $allowed_top_slugs, true)) continue;
            $row['count'] = isset($effective_counts[$row['id']])
                ? (int)$effective_counts[$row['id']]
                : (int)$row['count'];
            // Public category controls must never offer a zero-product category.
            if ($row['count'] < 1) continue;
            $rows[] = $row;
        }

        if ($mode === 'parent') {
            $rows = array_values(array_filter($rows, function($row){ return $row['level'] === 1; }));
            usort($rows, array(__CLASS__, 'sort_nav_rows'));
            return array_slice(self::dedupe_nav_items($rows, false), 0, $limit);
        }

        if ($mode === 'child') {
            $child_rows = array_values(array_filter($rows, function($row){ return $row['level'] >= 2; }));
            usort($child_rows, array(__CLASS__, 'sort_nav_rows'));
            if (count($child_rows) < $limit) {
                foreach ($rows as $row) {
                    if ($row['level'] === 1 && $row['count'] > 0) $child_rows[] = $row;
                }
            }
            return array_slice(self::dedupe_nav_items($child_rows, $suppress), 0, $limit);
        }

        // Auto: prefer the most useful level 2/3 terms from the site's real inventory.
        // Deeper terms receive a small bonus so "みかん" can beat the generic parent
        // when both carry the same attached product count.
        foreach ($rows as &$row) {
            $depth_bonus = $row['level'] === 3 ? 1.18 : ($row['level'] === 2 ? 1.08 : 0.72);
            $row['_score'] = max(1, $row['count']) * $depth_bonus;
        }
        unset($row);
        usort($rows, function($a, $b){
            if ($a['_score'] == $b['_score']) return strcmp($a['name'], $b['name']);
            return $a['_score'] < $b['_score'] ? 1 : -1;
        });
        return array_slice(self::dedupe_nav_items($rows, $suppress), 0, $limit);
    }

    private static function sort_nav_rows($a, $b) {
        if ($a['count'] === $b['count']) return strcmp($a['name'], $b['name']);
        return $a['count'] < $b['count'] ? 1 : -1;
    }

    private static function custom_nav_items($custom) {
        $lines = preg_split('/\r\n|\r|\n/', (string)$custom);
        $all_terms = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
        ));
        $effective_counts = is_wp_error($all_terms)
            ? array()
            : self::effective_term_counts($all_terms);
        $allowed_top_slugs = self::allowed_top_slugs();
        $out = array();
        foreach ((array)$lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $term = get_term_by('slug', sanitize_title($line), self::TAXONOMY);
            if (!$term || is_wp_error($term)) {
                $matches = get_terms(array('taxonomy'=>self::TAXONOMY,'hide_empty'=>false,'name'=>$line,'number'=>1));
                $term = (!is_wp_error($matches) && $matches) ? $matches[0] : false;
            }
            if (!$term || is_wp_error($term)) continue;
            $row = self::nav_row_from_term($term);
            if (!$row) continue;
            if ($allowed_top_slugs && ! in_array($row['category'], $allowed_top_slugs, true)) continue;
            if (isset($effective_counts[$row['id']])) {
                $row['count'] = (int)$effective_counts[$row['id']];
            }
            if ($row['count'] < 1) continue;
            $out[] = $row;
        }
        return $out;
    }

    private static function nav_row_from_term($term) {
        if (!$term || is_wp_error($term)) return null;
        $path = array();
        $cursor = $term;
        while ($cursor && !is_wp_error($cursor)) {
            array_unshift($path, $cursor);
            if (!$cursor->parent) break;
            $cursor = get_term($cursor->parent, self::TAXONOMY);
        }
        $level = count($path);
        $slugs = array_map(function($t){ return $t->slug; }, $path);
        return array(
            'id' => (int)$term->term_id,
            'slug' => $term->slug,
            'name' => $term->name,
            'count' => (int)$term->count,
            'level' => $level,
            'category' => isset($slugs[0]) ? $slugs[0] : '',
            'subcategory' => isset($slugs[1]) ? $slugs[1] : '',
            'type' => isset($slugs[2]) ? $slugs[2] : '',
            'path' => $slugs,
        );
    }

    private static function dedupe_nav_items($items, $suppress_parent_child) {
        $out = array();
        $names = array();
        foreach ((array)$items as $row) {
            $key = function_exists('mb_strtolower') ? mb_strtolower(trim($row['name']), 'UTF-8') : strtolower(trim($row['name']));
            if (isset($names[$key])) continue;

            if ($suppress_parent_child) {
                $conflict = false;
                foreach ($out as $chosen) {
                    if (self::paths_overlap($row['path'], $chosen['path'])) {
                        $conflict = true;
                        break;
                    }
                }
                if ($conflict) continue;
            }

            $names[$key] = true;
            unset($row['_score']);
            $out[] = $row;
        }
        return $out;
    }

    private static function paths_overlap($a, $b) {
        $min = min(count($a), count($b));
        if ($min < 1) return false;
        for ($i=0; $i<$min; $i++) {
            if ($a[$i] !== $b[$i]) return false;
        }
        return true;
    }

    private static function classify_existing_posts($offset = 0, $limit = 80) {
        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array('publish','draft','pending','private'),
            'posts_per_page' => max(1, (int)$limit),
            'offset' => max(0, (int)$offset),
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'DESC',
        ));
        foreach ((array)$ids as $id) {
            $post = get_post($id);
            if ($post) self::auto_classify_post((int)$id, $post, true);
        }
        return count((array)$ids);
    }

    /**
     * カテゴリ追加・編集が連続しても、全件再分類は1リクエストにつき1回にまとめる。
     */
    public static function queue_existing_reclassification($term_id = 0) {
        if ( self::$reclassification_queued ) return;
        if ( (int)$term_id > 0 ) {
            update_option(self::RECLASSIFY_CURSOR_OPTION, 0, false);
            update_option(self::RECLASSIFY_OPTION, 'pending-' . self::RECLASSIFY_VERSION, false);
            self::start_reclassification_progress();
        }
        self::$reclassification_queued = true;
        add_action('shutdown', array(__CLASS__, 'run_queued_reclassification'), 5);
    }

    /** v0.9.33導入時、登録済みのカテゴリを既存商品へ一度だけ遡及反映する。 */
    public static function maybe_queue_upgrade_reclassification() {
        if ( get_option(self::RECLASSIFY_OPTION, '') === self::RECLASSIFY_VERSION ) return;
        if ( get_option(self::RECLASSIFY_CURSOR_OPTION, null) === null ) {
            update_option(self::RECLASSIFY_CURSOR_OPTION, 0, false);
        }
        self::$mark_reclassification_version = true;
        $progress = self::get_reclassification_progress();
        if (!$progress || in_array(($progress['state'] ?? ''), array('idle','completed'), true)) {
            self::start_reclassification_progress();
        }
        self::queue_existing_reclassification();
    }

    public static function start_reclassification_progress() {
        $counts = wp_count_posts(NF_Core::POST_TYPE);
        $total = 0;
        if ($counts) foreach ((array)$counts as $count) $total += (int)$count;
        update_option(self::RECLASSIFY_CURSOR_OPTION, 0, false);
        update_option(self::RECLASSIFY_OPTION, 'pending-' . self::RECLASSIFY_VERSION, false);
        update_option(self::RECLASSIFY_PROGRESS_OPTION, array(
            'state'=>'queued', 'phase'=>'rule', 'total'=>$total, 'processed'=>0,
            'ai_total'=>0, 'ai_processed'=>0, 'started_at'=>time(), 'updated_at'=>time(),
        ), false);
    }

    public static function get_reclassification_progress() {
        $progress = get_option(self::RECLASSIFY_PROGRESS_OPTION, array());
        return is_array($progress) ? $progress : array();
    }

    public static function update_ai_progress() {
        $progress = self::get_reclassification_progress();
        if (!$progress || !in_array(($progress['state'] ?? ''), array('running','queued'), true)) return;
        $pending = NF_Classification_Admin::count_status('ai_pending') + NF_Classification_Admin::count_status('image_ai_pending');
        if (($progress['phase'] ?? '') !== 'ai') {
            $progress['phase'] = 'ai';
            $progress['ai_total'] = $pending;
        }
        $progress['ai_total'] = max((int)($progress['ai_total'] ?? 0), $pending);
        $progress['ai_processed'] = max(0, (int)$progress['ai_total'] - $pending);
        $progress['updated_at'] = time();
        if ($pending < 1) {
            $progress['state'] = 'completed';
            $progress['completed_at'] = time();
        }
        update_option(self::RECLASSIFY_PROGRESS_OPTION, $progress, false);
    }

    public static function run_scheduled_reclassification() {
        if ( get_option(self::RECLASSIFY_OPTION, '') === self::RECLASSIFY_VERSION ) return;
        self::$reclassification_queued = true;
        self::$mark_reclassification_version = true;
        self::run_queued_reclassification();
    }

    public static function run_queued_reclassification() {
        if ( ! self::$reclassification_queued ) return;
        self::$reclassification_queued = false;
        $offset = max(0, (int)get_option(self::RECLASSIFY_CURSOR_OPTION, 0));
        $limit = 80;
        $processed = self::classify_existing_posts($offset, $limit);
        $progress = self::get_reclassification_progress();
        if (!$progress) self::start_reclassification_progress();
        $progress = self::get_reclassification_progress();
        $progress['state'] = 'running';
        $progress['phase'] = 'rule';
        $progress['processed'] = min((int)($progress['total'] ?? 0), $offset + $processed);
        $progress['updated_at'] = time();
        update_option(self::RECLASSIFY_PROGRESS_OPTION, $progress, false);

        if ( $processed >= $limit ) {
            update_option(self::RECLASSIFY_CURSOR_OPTION, $offset + $processed, false);
            if ( ! wp_next_scheduled(self::RECLASSIFY_CRON) ) {
                wp_schedule_single_event(time() + 10, self::RECLASSIFY_CRON);
            }
        } else {
            delete_option(self::RECLASSIFY_CURSOR_OPTION);
            update_option(self::RECLASSIFY_OPTION, self::RECLASSIFY_VERSION, false);
            self::$mark_reclassification_version = false;
            $progress['processed'] = (int)($progress['total'] ?? ($offset + $processed));
            $pending = class_exists('NF_Classification_Admin') ? NF_Classification_Admin::count_status('ai_pending') + NF_Classification_Admin::count_status('image_ai_pending') : 0;
            $progress['phase'] = $pending > 0 ? 'ai' : 'done';
            $progress['ai_total'] = $pending;
            $progress['ai_processed'] = 0;
            $progress['state'] = $pending > 0 ? 'running' : 'completed';
            $progress['updated_at'] = time();
            if ($pending < 1) $progress['completed_at'] = time();
            update_option(self::RECLASSIFY_PROGRESS_OPTION, $progress, false);
        }
    }

    public static function migrate_existing_fruit_terms() {
        $fruit_terms = get_terms(array('taxonomy'=>'nf_fruit','hide_empty'=>false));
        if ( is_wp_error($fruit_terms) ) return;
        foreach ($fruit_terms as $fruit) {
            $matches = self::terms_by_name($fruit->name);
            if ( empty($matches) ) continue;
            $posts = get_objects_in_term($fruit->term_id, 'nf_fruit');
            if ( is_wp_error($posts) ) continue;
            foreach ($posts as $post_id) {
                self::attach_with_ancestors((int)$post_id, (int)$matches[0]->term_id);
            }
        }
    }

    private static function terms_by_name($name) {
        $terms = get_terms(array('taxonomy'=>self::TAXONOMY,'hide_empty'=>false,'name'=>$name));
        return is_wp_error($terms) ? array() : $terms;
    }

    private static function attach_with_ancestors($post_id, $term_id) {
        $ids = self::term_with_ancestor_ids($term_id);
        if ($ids) wp_set_object_terms($post_id, $ids, self::TAXONOMY, true);
        return $ids;
    }

    private static function term_with_ancestor_ids($term_id) {
        $ids = array();
        $term = get_term($term_id, self::TAXONOMY);
        while ($term && ! is_wp_error($term)) {
            $ids[] = (int)$term->term_id;
            if ( ! $term->parent ) break;
            $term = get_term($term->parent, self::TAXONOMY);
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 公開画面に表示する正式カテゴリを返す。
     * 子カテゴリがある場合は同時付与された親カテゴリを省き、具体的な名称を優先する。
     */
    public static function public_terms_for_post($post_id) {
        $terms = wp_get_post_terms((int)$post_id, self::TAXONOMY);
        if ( is_wp_error($terms) || empty($terms) ) return array();

        $assigned = array();
        foreach ($terms as $term) $assigned[(int)$term->term_id] = true;

        $leaf_terms = array();
        foreach ($terms as $term) {
            $has_assigned_child = false;
            foreach ($terms as $candidate) {
                if ((int)$candidate->term_id === (int)$term->term_id) continue;
                $ancestors = get_ancestors((int)$candidate->term_id, self::TAXONOMY, 'taxonomy');
                if (in_array((int)$term->term_id, array_map('intval', $ancestors), true)) {
                    $has_assigned_child = true;
                    break;
                }
            }
            if (!$has_assigned_child) $leaf_terms[] = $term;
        }

        return $leaf_terms ? $leaf_terms : $terms;
    }

    private static function strict_category_rules() {
        return array(
            '不知火・デコポン' => array('不知火','しらぬい','シラヌイ','デコポン','でこぽん'),
        );
    }

    private static function strict_category_exclusion_rules() {
        return array(
            '不知火・デコポン' => array('温州', '太秋柿', '太秋'),
        );
    }

    private static function strict_category_allowed($category_name, $strong_text) {
        $exclusion_rules = self::strict_category_exclusion_rules();
        $excluded_words = isset($exclusion_rules[$category_name])
            ? (array)$exclusion_rules[$category_name]
            : array();
        foreach ($excluded_words as $excluded_word) {
            if ( mb_stripos($strong_text, $excluded_word, 0, 'UTF-8') !== false ) {
                return false;
            }
        }

        $rules = self::strict_category_rules();
        if ( empty($rules[$category_name]) ) return true;
        foreach ($rules[$category_name] as $needle) {
            if ( mb_stripos($strong_text, $needle, 0, 'UTF-8') !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function term_exclusion_matches($term_id, $text) {
        $keywords = get_term_meta((int)$term_id, self::EXCLUDE_KEYWORDS_META, true);
        $keywords = self::parse_exclude_keywords($keywords);
        foreach ($keywords as $keyword) {
            if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) return true;
        }
        return false;
    }

    /** 管理画面で指定された除外語に一致するカテゴリは、過去の紐付けも解除する。 */
    private static function remove_custom_excluded_terms($post_id, $text, $terms) {
        $excluded_ids = array();
        foreach ((array)$terms as $term) {
            if (self::term_exclusion_matches((int)$term->term_id, $text)) {
                $excluded_ids[] = (int)$term->term_id;
                wp_remove_object_terms($post_id, (int)$term->term_id, self::TAXONOMY);
            }
        }
        return array_values(array_unique($excluded_ids));
    }

    /** 明確な固有語が商品名にない場合、過去の誤分類だけを除去する。 */
    private static function remove_strict_false_positives($post_id, $strong_text) {
        foreach (self::strict_category_rules() as $category_name => $needles) {
            if ( self::strict_category_allowed($category_name, $strong_text) ) continue;
            foreach (self::terms_by_name($category_name) as $term) {
                wp_remove_object_terms($post_id, (int)$term->term_id, self::TAXONOMY);
            }
        }
    }

    /**
     * 商品名から品目が明確な場合、別品目のカテゴリ枝を候補から除外する。
     * 外部説明や古い投稿本文に残った語句による誤分類を防ぐ。
     */
    private static function exclusive_category_branch_names($strong_text) {
        $strong_text = (string)$strong_text;
        $has_persimmon = preg_match('/太秋柿|柿|かき|カキ/u', $strong_text);
        $has_citrus = preg_match('/温州みかん|みかん|ミカン|不知火|しらぬい|デコポン|晩白柚|ばんぺいゆ|バンペイユ|ポンカン/u', $strong_text);

        if ( $has_persimmon && ! $has_citrus ) {
            return array('みかん・柑橘');
        }

        if ( $has_citrus && ! $has_persimmon ) {
            return array('柿（カキ）', '柿');
        }

        return array();
    }

    private static function term_is_in_named_branch($term, $branch_names) {
        $cursor = $term;
        while ($cursor && ! is_wp_error($cursor)) {
            if ( in_array(trim((string)$cursor->name), (array)$branch_names, true) ) {
                return true;
            }
            if ( ! $cursor->parent ) break;
            $cursor = get_term((int)$cursor->parent, self::TAXONOMY);
        }
        return false;
    }

    private static function term_root_name($term) {
        $cursor = $term;
        while ($cursor && ! is_wp_error($cursor) && $cursor->parent) {
            $cursor = get_term((int)$cursor->parent, self::TAXONOMY);
        }
        return ($cursor && ! is_wp_error($cursor))
            ? trim((string)$cursor->name)
            : '';
    }

    private static function remove_exclusive_conflicts($post_id, $strong_text, $terms) {
        $branch_names = self::exclusive_category_branch_names($strong_text);
        if ( ! $branch_names ) return array();

        $excluded_ids = array();
        foreach ((array)$terms as $term) {
            if ( ! self::term_is_in_named_branch($term, $branch_names) ) continue;
            $excluded_ids[] = (int)$term->term_id;
            wp_remove_object_terms($post_id, (int)$term->term_id, self::TAXONOMY);
        }
        return array_values(array_unique($excluded_ids));
    }

    /** 商品タイトルから具体的に判定できる果物カテゴリ名を返す。 */
    private static function dominant_fruit_term_names($strong_text) {
        if ( class_exists('NF_Product_Title') ) {
            $primary = NF_Product_Title::primary_variety($strong_text);
            if ( $primary !== '' && ! preg_match('/食べ比べ|詰め合わせ|詰合せ|セット|アソート|ミックス|定期便|選べる品種|選べる種類/u', (string)$strong_text) ) {
                $canonical = array(
                    '温州みかん' => 'みかん',
                    '秋月梨' => '梨',
                    '豊水梨' => '梨',
                    '新高梨' => '梨',
                );
                return array(isset($canonical[$primary]) ? $canonical[$primary] : $primary);
            }
        }

        $rules = array(
            'シャインマスカット' => array('シャインマスカット'),
            'ナガノパープル' => array('ナガノパープル'),
            'ピオーネ' => array('ピオーネ'),
            'デラウェア' => array('デラウェア'),
            '巨峰' => array('巨峰'),
            '太秋柿' => array('太秋柿', 'たいしゅう', 'タイシュウ'),
            '不知火・デコポン' => array('不知火', 'しらぬい', 'シラヌイ', 'デコポン', 'でこぽん'),
            '晩白柚' => array('晩白柚', 'ばんぺいゆ', 'バンペイユ'),
            'みかん' => array('温州みかん', '温州ミカン', 'みかん', 'ミカン'),
            'ポンカン' => array('ポンカン'),
            'せとか' => array('せとか'),
            '文旦' => array('文旦'),
            '梨' => array('秋月梨', 'あきづき', '豊水梨', '新高梨'),
        );

        $matched = array();
        foreach ($rules as $term_name => $needles) {
            foreach ($needles as $needle) {
                if ( mb_stripos((string)$strong_text, $needle, 0, 'UTF-8') === false ) continue;
                $matched[] = $term_name;
                break;
            }
        }
        return array_values(array_unique($matched));
    }

    /**
     * タイトルで具体的な果物が判定できたら、その経路以外の果物カテゴリを解除する。
     * 説明文や統合元タイトルに残った別品種名を分類へ混ぜない。
     */
    private static function remove_non_dominant_fruit_terms($post_id, $strong_text, $terms) {
        $dominant_names = self::dominant_fruit_term_names($strong_text);
        if ( ! $dominant_names ) return array();

        $allowed_ids = array();
        foreach ($dominant_names as $name) {
            // 太秋柿は公開カテゴリ上では「柿（カキ）」へ集約する。
            $lookup_name = $name === '太秋柿' ? '柿（カキ）' : $name;
            foreach (self::terms_by_name($lookup_name) as $term) {
                $allowed_ids = array_merge(
                    $allowed_ids,
                    self::term_with_ancestor_ids((int)$term->term_id)
                );
            }
        }
        $allowed_ids = array_values(array_unique(array_map('intval', $allowed_ids)));
        if ( ! $allowed_ids ) return array();

        $excluded_ids = array();
        foreach ((array)$terms as $term) {
            $is_fruit = self::term_is_in_named_branch($term, array('果物・フルーツ'));
            if ( $is_fruit && in_array((int)$term->term_id, $allowed_ids, true) ) continue;

            if ( ! $is_fruit ) {
                $root_name = self::term_root_name($term);
                if ( in_array($root_name, array('定期便', 'あとから選べるお礼品'), true) ) {
                    continue;
                }
            }

            $excluded_ids[] = (int)$term->term_id;
            wp_remove_object_terms($post_id, (int)$term->term_id, self::TAXONOMY);
        }
        return array_values(array_unique($excluded_ids));
    }

    /**
     * 旧「商品属性」を正式なカテゴリへ一度だけ統合する。
     * 旧タクソノミー自体は、ロールバックできるよう非表示で保持する。
     */
    public static function migrate_attribute_terms_to_categories() {
        if ( get_option(self::ATTRIBUTE_MIGRATION_OPTION, '') === 'done' ) return;

        $attributes = get_terms(array(
            'taxonomy' => self::ATTRIBUTE_TAXONOMY,
            'hide_empty' => false,
        ));
        if ( is_wp_error($attributes) ) return;

        foreach ((array)$attributes as $attribute) {
            $name = trim((string)$attribute->name);
            if ($name === '') continue;

            $matches = self::terms_by_name($name);
            $category_id = ! empty($matches) ? (int)$matches[0]->term_id : 0;

            if ( ! $category_id ) {
                $created = wp_insert_term($name, self::TAXONOMY, array(
                    'description' => '旧「商品属性」からカテゴリへ統合',
                ));
                if ( ! is_wp_error($created) ) {
                    $category_id = (int)$created['term_id'];
                }
            }

            if ( ! $category_id ) continue;

            $post_ids = get_objects_in_term((int)$attribute->term_id, self::ATTRIBUTE_TAXONOMY);
            if ( is_wp_error($post_ids) ) continue;

            foreach ((array)$post_ids as $post_id) {
                self::attach_with_ancestors((int)$post_id, $category_id);
            }
        }

        update_option(self::ATTRIBUTE_MIGRATION_OPTION, 'done', false);
    }

    public static function auto_classify_post($post_id, $post, $update) {
        if (class_exists('NF_Category_Classifier')) {
            NF_Category_Classifier::queue((int)$post_id);
            return;
        }
        if ( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;
        if ( ! $post || $post->post_status === 'auto-draft' ) return;
        if ( get_post_meta($post_id, self::CLASSIFICATION_LOCK_META, true) === '1' ) {
            update_post_meta($post_id, self::CONFIDENCE_META, 'manual');
            delete_post_meta($post_id, self::REVIEW_REASON_META);
            return;
        }

        // 品種固有カテゴリは、一覧・詳細に実際に表示される商品タイトルで厳格判定する。
        // 楽天/Yahoo!の統合商品では post_title と表示名が異なるため、表示名を最優先する。
        $strong_text = class_exists('NF_Product_Title')
            ? NF_Product_Title::display_title($post_id)
            : wp_strip_all_tags((string)$post->post_title);
        if ( trim($strong_text) === '' ) {
            $strong_text = wp_strip_all_tags((string)$post->post_title);
        }
        $text = wp_strip_all_tags(
            $strong_text . ' ' .
            get_post_meta($post_id, '_nf_rakuten_item_name', true) . ' ' .
            $post->post_content . ' ' .
            get_post_meta($post_id, '_nf_rakuten_description', true)
        );
        if ( trim($text) === '' ) return;

        self::remove_strict_false_positives($post_id, $strong_text);

        $terms = get_terms(array('taxonomy'=>self::TAXONOMY,'hide_empty'=>false));
        if ( is_wp_error($terms) ) return;
        $custom_excluded_ids = self::remove_custom_excluded_terms($post_id, $text, $terms);
        $exclusive_excluded_ids = self::remove_exclusive_conflicts($post_id, $strong_text, $terms);
        $dominant_excluded_ids = self::remove_non_dominant_fruit_terms($post_id, $strong_text, $terms);
        usort($terms, function($a,$b){ return mb_strlen($b->name,'UTF-8') <=> mb_strlen($a->name,'UTF-8'); });

        $matched = array();
        foreach ($terms as $term) {
            $name = trim($term->name);
            if ( mb_strlen($name,'UTF-8') < 2 ) continue;
            $needle = str_replace(array('（','）','・',' '), '', $name);
            $hay = str_replace(array('（','）','・',' '), '', $text);
            if ( $needle !== '' && mb_stripos($hay, $needle, 0, 'UTF-8') !== false ) {
                $matched[] = $term;
            }
        }

        // Fruit compatibility aliases.
        $aliases = array(
            'シャインマスカット'=>'シャインマスカット','巨峰'=>'巨峰','ピオーネ'=>'ピオーネ',
            '秋月梨'=>'梨','あきづき'=>'梨','豊水'=>'梨','新高'=>'梨','梨'=>'梨',
            'デコポン'=>'不知火・デコポン','不知火'=>'不知火・デコポン','みかん'=>'みかん',
            '晩白柚'=>'晩白柚','ばんぺいゆ'=>'晩白柚','バンペイユ'=>'晩白柚',
            'いちご'=>'いちご','すいか'=>'すいか','メロン'=>'メロン','柿'=>'柿（カキ）','栗'=>'栗',
        );
        $strict_rules = self::strict_category_rules();
        foreach ($aliases as $needle=>$target) {
            $alias_text = isset($strict_rules[$target]) ? $strong_text : $text;
            if ( mb_stripos($alias_text, $needle, 0, 'UTF-8') !== false ) {
                foreach (self::terms_by_name($target) as $term) $matched[] = $term;
            }
        }

        $seen = array();
        $new_auto_ids = array();
        foreach ($matched as $term) {
            if (isset($seen[$term->term_id])) continue;
            $seen[$term->term_id] = true;
            if ( ! self::strict_category_allowed($term->name, $strong_text) ) continue;
            if ( self::term_exclusion_matches((int)$term->term_id, $text) ) continue;
            $new_auto_ids = array_merge(
                $new_auto_ids,
                self::term_with_ancestor_ids($term->term_id)
            );
        }

        $new_auto_ids = array_values(array_unique(array_map('intval', $new_auto_ids)));
        if ($custom_excluded_ids) {
            $new_auto_ids = array_values(array_diff($new_auto_ids, $custom_excluded_ids));
        }
        if ($exclusive_excluded_ids) {
            $new_auto_ids = array_values(array_diff($new_auto_ids, $exclusive_excluded_ids));
        }
        if ($dominant_excluded_ids) {
            $new_auto_ids = array_values(array_diff($new_auto_ids, $dominant_excluded_ids));
        }
        $previous_auto_ids = get_post_meta($post_id, self::AUTO_TERMS_META, true);
        $previous_auto_ids = is_array($previous_auto_ids)
            ? array_values(array_unique(array_map('intval', $previous_auto_ids)))
            : array();

        $obsolete_ids = array_values(array_diff($previous_auto_ids, $new_auto_ids));
        if ($obsolete_ids) {
            wp_remove_object_terms($post_id, $obsolete_ids, self::TAXONOMY);
        }
        if ($new_auto_ids) {
            wp_set_object_terms($post_id, $new_auto_ids, self::TAXONOMY, true);
        }
        update_post_meta($post_id, self::AUTO_TERMS_META, $new_auto_ids);

        // 管理画面で迷わず確認できるよう、自動判定の強さを保存する。
        $specific = self::dominant_fruit_term_names($strong_text);
        $confidence = count($specific) === 1 ? 'high' : (count($specific) > 1 ? 'low' : 'medium');
        update_post_meta($post_id, self::CONFIDENCE_META, $confidence);
        if ($confidence === 'low') {
            update_post_meta($post_id, self::REVIEW_REASON_META, '商品名から複数の品目候補が検出されました');
        } elseif ( ! $new_auto_ids ) {
            update_post_meta($post_id, self::REVIEW_REASON_META, '自動分類できるカテゴリが見つかりませんでした');
            update_post_meta($post_id, self::CONFIDENCE_META, 'low');
        } else {
            delete_post_meta($post_id, self::REVIEW_REASON_META);
        }
    }
}
