<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product-name formatter.
 *
 * Keep source product names informative. The previous catalog formatter rebuilt
 * names from municipality + taxonomy + capacity and unintentionally dropped
 * cultivar, count, size and packing information. This class instead starts from
 * the official Rakuten/Yahoo! product names and removes only obvious marketplace
 * / SEO noise.
 */
class NF_Product_Title {

    public static function display_title( $post_id ) {
        $custom = trim((string)get_post_meta($post_id, '_nf_display_name', true));
        if ( $custom !== '' ) {
            return sanitize_text_field($custom);
        }

        $candidates = array(
            array('source' => 'rakuten', 'title' => (string)get_post_meta($post_id, '_nf_rakuten_item_name', true)),
            array('source' => 'yahoo',   'title' => (string)get_post_meta($post_id, '_nf_yahoo_item_name', true)),
            array('source' => 'post',    'title' => (string)get_the_title($post_id)),
        );

        $best = '';
        $best_score = -1;

        foreach ( $candidates as $candidate ) {
            $clean = self::minimal_cleanup($candidate['title']);
            $clean = self::identity_cleanup($clean);
            if ( $clean === '' ) continue;

            $score = self::information_score($clean);

            if ( $candidate['source'] === 'rakuten' ) $score += 3;
            if ( $candidate['source'] === 'yahoo' )   $score += 2;

            if ( $score > $best_score ) {
                $best = $clean;
                $best_score = $score;
            }
        }

        if ( $best === '' ) {
            $best = sanitize_text_field((string)get_the_title($post_id));
        }

        return self::append_missing_specs($post_id, $best);
    }

    private static function variety_rules() {
        return array(
            'シャインマスカット' => array('シャインマスカット'),
            'ナガノパープル' => array('ナガノパープル'),
            'ピオーネ' => array('ピオーネ'),
            'デラウェア' => array('デラウェア'),
            '巨峰' => array('巨峰'),
            '太秋柿' => array('太秋柿', 'たいしゅう', 'タイシュウ'),
            '不知火・デコポン' => array('不知火', 'しらぬい', 'シラヌイ', 'デコポン', 'でこぽん'),
            '晩白柚' => array('晩白柚', 'ばんぺいゆ', 'バンペイユ'),
            '温州みかん' => array('温州みかん', '温州ミカン'),
            'ポンカン' => array('ポンカン'),
            'せとか' => array('せとか'),
            '文旦' => array('文旦'),
            '秋月梨' => array('秋月梨', 'あきづき'),
            '豊水梨' => array('豊水梨'),
            '新高梨' => array('新高梨'),
        );
    }

    private static function is_multi_variety_title($title) {
        return (bool)preg_match(
            '/食べ比べ|詰め合わせ|詰合せ|セット|アソート|ミックス|定期便|選べる品種|選べる種類/u',
            (string)$title
        );
    }

    private static function variety_occurrences($title) {
        $found = array();
        foreach (self::variety_rules() as $canonical => $needles) {
            $best_position = null;
            foreach ($needles as $needle) {
                $position = mb_stripos((string)$title, $needle, 0, 'UTF-8');
                if ($position === false) continue;
                if ($best_position === null || $position < $best_position) {
                    $best_position = $position;
                }
            }
            if ($best_position !== null) {
                $found[] = array('name' => $canonical, 'position' => (int)$best_position);
            }
        }
        usort($found, function($a, $b){
            return $a['position'] <=> $b['position'];
        });
        return $found;
    }

    public static function primary_variety($title) {
        $found = self::variety_occurrences($title);
        return ! empty($found[0]['name']) ? $found[0]['name'] : '';
    }

    public static function has_conflicting_variety($text, $primary_variety) {
        $primary_variety = trim((string)$primary_variety);
        if ($primary_variety === '' || self::is_multi_variety_title($text)) return false;
        foreach (self::variety_occurrences($text) as $row) {
            if ($row['name'] !== $primary_variety) return true;
        }
        return false;
    }

    /** 単一品種の商品名に後付けされた別品種SEO語句を表示名から除く。 */
    public static function identity_cleanup($title) {
        $title = trim((string)$title);
        if ($title === '' || self::is_multi_variety_title($title)) return $title;

        $found = self::variety_occurrences($title);
        if (count($found) < 2) return $title;

        $primary = $found[0]['name'];
        foreach (array_slice($found, 1) as $row) {
            if ($row['name'] === $primary || $row['position'] < 1) continue;
            $title = trim(mb_substr($title, 0, $row['position'], 'UTF-8'));
            break;
        }

        return sanitize_text_field($title);
    }

    /**
     * Remove only unmistakable marketplace/SEO decoration.
     * Important product facts such as 品種, 訳あり, 先行予約, 重量, 玉数,
     * 房数, サイズ and packing information are intentionally retained.
     */
    public static function minimal_cleanup( $title ) {
        $title = html_entity_decode(wp_strip_all_tags((string)$title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = str_replace(array('｜', '¦'), '|', $title);

        $title = preg_replace_callback('/【([^】]+)】|\[([^\]]+)\]/u', function($m){
            $inside = isset($m[1]) && $m[1] !== '' ? $m[1] : (isset($m[2]) ? $m[2] : '');
            $plain = preg_replace('/\s+/u', '', $inside);

            if ( preg_match('/^(?:ふるさと納税|返礼品|送料無料|送料込|楽天(?:市場)?|Yahoo!?ショッピング|ヤフーショッピング|ポイント(?:アップ)?|クーポン|ランキング(?:入賞)?|レビュー(?:高評価)?|スーパーSALE|お買い物マラソン|ショップ限定|ストア限定)+$/iu', $plain) ) {
                return ' ';
            }

            return '【' . trim($inside) . '】';
        }, $title);

        $title = preg_replace('/(?<!\S)(?:ふるさと納税|返礼品|送料無料|送料込|楽天市場|Yahoo!?ショッピング|ヤフーショッピング)(?!\S)/iu', ' ', $title);
        $title = preg_replace('/(?:\s*[|｜]\s*){2,}/u', ' | ', $title);
        $title = preg_replace('/\s+/u', ' ', trim($title));
        $title = trim($title, " \t\n\r\0\x0B|｜-");

        return sanitize_text_field($title);
    }

    private static function information_score( $title ) {
        $length = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        $score = min(90, $length);

        $patterns = array(
            '/\d+(?:\.\d+)?\s*(?:kg|g)\b/iu',
            '/\d+\s*(?:玉|個|房|パック|袋|本|箱|粒)\b/u',
            '/(?:[2-5]L|[23]S|SSS|LLL|SS|LL|S|M|L)(?:〜|～|-|・|\/)?(?:[2-5]L|[23]S|SSS|LLL|SS|LL|S|M|L)?(?:サイズ)?/u',
            '/(?:豊水|秋月|新高|あきづき|幸水|シャインマスカット|巨峰|ピオーネ|不知火|デコポン|晩白柚|太秋|肥後グリーン|金色羅皇|羅皇)/u',
            '/(?:訳あり|家庭用|秀品|優品|特秀|大玉|小玉|定期便|先行予約)/u',
        );
        foreach ( $patterns as $pattern ) {
            if ( preg_match($pattern, $title) ) $score += 15;
        }

        return $score;
    }

    private static function append_missing_specs( $post_id, $title ) {
        $parts = array();

        $capacity = trim((string)get_post_meta($post_id, '_nf_capacity', true));
        if ( $capacity !== '' && ! self::contains_normalized($title, $capacity) ) {
            $parts[] = $capacity;
        }

        // Only use a Yahoo variant as a safeguard when the source title itself
        // contains no obvious weight/count/size information. Do not concatenate
        // specifications from multiple selectable variants into one product name.
        $has_specs = (bool) preg_match(
            '/(?:\d+(?:\.\d+)?\s*(?:kg|g)|\d+\s*(?:玉|個|房|パック|袋|本|箱|粒)|(?:[2-5]L|[23]S|SSS|LLL|SS|LL|S|M|L)(?:サイズ)?)/iu',
            $title
        );

        if ( ! $has_specs ) {
            $variants = get_post_meta($post_id, '_nf_yahoo_variants', true);
            if ( is_string($variants) ) {
                $decoded = json_decode($variants, true);
                if ( is_array($decoded) ) $variants = $decoded;
            }

            if ( is_array($variants) ) {
                foreach ( $variants as $variant ) {
                    if ( ! is_array($variant) ) continue;

                    $spec = '';
                    foreach ( array('specLabel','packLabel','weightLabel','countLabel','sizeLabel') as $key ) {
                        if ( ! empty($variant[$key]) ) {
                            $spec = trim((string)$variant[$key]);
                            break;
                        }
                    }

                    if ( $spec !== '' && ! self::contains_normalized($title, $spec) ) {
                        $parts[] = $spec;
                        break;
                    }
                }
            }
        }

        $parts = array_values(array_unique(array_filter(array_map('sanitize_text_field', $parts))));
        if ( ! $parts ) return $title;

        return trim($title . ' ' . implode(' / ', $parts));
    }

    private static function contains_normalized( $haystack, $needle ) {
        $normalize = function($value){
            $value = (string)$value;
            if ( function_exists('mb_strtolower') ) {
                $value = mb_strtolower($value, 'UTF-8');
            } else {
                $value = strtolower($value);
            }

            return str_replace(
                array(' ', '　', '～', '〜', '-', '−', '㎏', 'ｋｇ', 'ｇ'),
                array('', '', '〜', '〜', '〜', '〜', 'kg', 'kg', 'g'),
                $value
            );
        };

        $h = $normalize($haystack);
        $n = $normalize($needle);

        if ( $n === '' ) return false;

        if ( function_exists('mb_strpos') ) {
            return mb_strpos($h, $n, 0, 'UTF-8') !== false;
        }

        return strpos($h, $n) !== false;
    }
}
