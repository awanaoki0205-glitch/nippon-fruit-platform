<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Extract display specifications only; never use these labels for product matching. */
class NF_Variant_Spec {

    /** Keep HTML block boundaries, so unrelated description sections do not join. */
    public static function plain_text( $value ) {
        if ( ! is_scalar($value) ) return '';
        $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*(?:br\b[^>]*|\/(?:p|div|li|tr|td|th|h[1-6])\s*)>/iu', "\n", $text);
        return trim(wp_strip_all_tags($text));
    }

    private static function normalize_text( $text ) {
        $text = self::plain_text($text);
        // strtr also works on WordPress installations without mbstring/intl.
        $from = preg_split('//u', '０１２３４５６７８９ＡＢＣＤＥＦＧＨＩＪＫＬＭＮＯＰＱＲＳＴＵＶＷＸＹＺａｂｃｄｅｆｇｈｉｊｋｌｍｎｏｐｑｒｓｔｕｖｗｘｙｚ．，／（）＋：', -1, PREG_SPLIT_NO_EMPTY);
        $to = str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz.,/()+:');
        $text = strtr($text, array_combine($from, $to));
        $text = strtr($text, array(
            '㎏' => 'kg', 'ｋｇ' => 'kg', 'キログラム' => 'kg', 'キロ' => 'kg',
            'グラム' => 'g', 'ｇ' => 'g', '㌘' => 'g',
            '～' => '〜', '∼' => '〜', '〜' => '〜', '~' => '〜',
            '‐' => '-', '‑' => '-', '–' => '-', '—' => '-', '−' => '-', '－' => '-',
            '✕' => '×', '✖' => '×', '＊' => '×', '*' => '×',
            '　' => ' ', "\xc2\xa0" => ' ', 'おおよそ' => '約', 'およそ' => '約',
        ));
        // Thousands separators are not list/range separators.
        $text = preg_replace('/(?<=\d),(?=\d{3}(?:\D|$))/u', '', $text);
        // Compact titles sometimes omit spaces between weight, quantity and size.
        $text = preg_replace('/(kg|g)(?=\d+\s*(?:玉|房|パック|袋|本|個|箱))/iu', '$1 ', $text);
        $text = preg_replace('/(玉|房|パック|袋|本|個|箱)(?=(?:[2-5]L|[23]S|SS|LL|S|M|L)(?![a-zA-Z0-9_]))/u', '$1 ', $text);
        $text = preg_replace('/https?:\/\/[^\s<>]+/iu', ' ', $text);
        return preg_replace('/[^\S\r\n]+/u', ' ', $text);
    }

    /** Public for reuse by imports and for deterministic regression testing. */
    public static function extract( $source ) {
        $groups = array('weight' => array(), 'count' => array(), 'size' => array(), 'pack' => array());
        foreach ( array('name', 'headline', 'description') as $field ) {
            $text = self::normalize_text(isset($source[$field]) ? $source[$field] : '');
            $skip_section = false;
            foreach ( preg_split('/[\r\n。；;]+/u', $text) as $line ) {
                $line = trim($line);
                if ( $line === '' ) continue;
                if ( $field === 'description' ) {
                    // Recipes/nutrition and cross-selling are not this variant's contents.
                    if ( preg_match('/(?:栄養成分|栄養表示|レシピ|調理方法|関連商品|他の返礼品|おすすめ商品)/u', $line) ) {
                        $skip_section = true;
                    }
                    if ( preg_match('/(?:内容量|内容・規格|商品規格|サイズ|お届け内容)\s*[:：\]】]?/u', $line) ) {
                        $skip_section = false;
                    }
                    if ( $skip_section || preg_match('/(?:栄養成分|エネルギー|たんぱく質|炭水化物|食塩相当量|レシピ|関連商品|他の返礼品|別容量|ラインナップ)/u', $line) ) continue;
                    if ( preg_match('/(?:選べる|お選び|選択でき)/u', $line) &&
                         ! preg_match('/(?:内容量|お届け内容)\s*[:：]|サイズ/u', $line) ) continue;
                }
                self::scan_line($line, $groups);
            }
        }

        // Legacy labels fill missing categories only. They cannot override richer source text.
        $fallback = array('weight' => array(), 'count' => array(), 'size' => array(), 'pack' => array());
        foreach ( array('weightLabel', 'countLabel', 'sizeLabel', 'packLabel', 'specLabel', 'capacityLabel') as $field ) {
            if ( ! empty($source[$field]) ) self::scan_line(self::normalize_text($source[$field]), $fallback);
        }
        foreach ( $groups as $type => $items ) {
            if ( ! $items && $fallback[$type] ) $groups[$type] = $fallback[$type];
        }

        // A package expression already contains its weight/count. Do not print it twice.
        foreach ( $groups['pack'] as $pack ) {
            foreach ( array('weight', 'count') as $type ) {
                foreach ( $groups[$type] as $key => $label ) {
                    if ( $label !== $pack && self::contained_in_pack($label, $pack) ) unset($groups[$type][$key]);
                }
            }
        }
        $labels = array();
        foreach ( array('weight', 'count', 'size', 'pack') as $type ) {
            $labels[$type . 'Label'] = implode(' / ', array_values($groups[$type]));
        }
        $parts = array_merge(array_values($groups['weight']), array_values($groups['count']), array_values($groups['size']));
        $labels['specLabel'] = implode(' / ', array_values(array_unique($parts)));
        if ( $labels['specLabel'] === '' && ! empty($source['capacityLabel']) ) {
            $labels['specLabel'] = sanitize_text_field($source['capacityLabel']);
        }
        return $labels;
    }

    private static function contained_in_pack( $label, $pack ) {
        $label = preg_replace('/^約/u', '', $label);
        return (bool)preg_match('/(?:^|×)約?' . preg_quote($label, '/') . '(?:×|$)/u', $pack);
    }

    private static function add( &$groups, $type, $label ) {
        $label = trim($label);
        if ( $label === '' ) return;
        $key = preg_replace('/約|前後|程度/u', '', $label);
        // Collapse equivalent kg/g repetitions, keeping the first (product-name) spelling.
        $key = preg_replace_callback('/(\d+(?:\.\d+)?)kg/iu', function($m) {
            return (string)(floatval($m[1]) * 1000) . 'g';
        }, $key);
        if ( ! isset($groups[$type][$key]) ) $groups[$type][$key] = $label;
    }

    private static function format_measure( $m ) {
        $first = $m['a'];
        $unit = strtolower($m['u']);
        if ( ! empty($m['b']) ) {
            $first_unit = ! empty($m['au']) ? strtolower($m['au']) : $unit;
            $first .= $first_unit === $unit ? '' : $first_unit;
            $first .= '〜' . (! empty($m['bapprox']) ? '約' : '') . $m['b'];
        }
        return (! empty($m['approx']) ? '約' : '') . $first . $unit . (isset($m['suffix']) ? $m['suffix'] : '');
    }

    private static function scan_line( $line, &$groups ) {
        $number = '\d+(?:\.\d+)?';
        $range = '(?:〜|-|から)';
        $count_unit = '(?:パック|ケース|玉|房|袋|本|個|箱|粒|束)';
        $weight = '(?:約\s*)?' . $number . '\s*(?:(?:kg|g)?\s*' . $range . '\s*(?:約\s*)?' . $number . '\s*)?(?:kg|g)(?:前後|程度|以上|以下|未満|超)?';
        $count = '(?:約\s*)?\d+\s*(?:' . $count_unit . '?\s*' . $range . '\s*(?:約\s*)?\d+\s*)?' . $count_unit;
        $start = '(?<![a-zA-Z0-9_.-])';
        $end = '(?![a-zA-Z0-9_])';
        $multiply = '\s*[×xX]\s*';

        // Mask consumed expressions with equal-length spaces to preserve PCRE byte offsets.
        $masked = $line;
        $package_pattern = '/' . $start . '(?:' . $weight . $multiply . $count . '(?:' . $multiply . $count . ')*|' . $count . $multiply . $weight . '|' . $weight . '\s*(?:入り|入)\s*' . $count . ')' . $end . '/iu';
        preg_match_all($package_pattern, $line, $packages, PREG_OFFSET_CAPTURE);
        foreach ( $packages[0] as $match ) {
            $label = preg_replace('/\s+/u', '', $match[0]);
            $label = preg_replace('/[xX]|入り|入/u', '×', $label);
            $label = self::canonical_expression($label);
            self::add($groups, 'count', $label);
            self::add($groups, 'pack', $label);
            $prefix = substr($masked, 0, $match[1]);
            // "1パック250g×8パック" contains eight packs, not an additional single pack.
            if ( preg_match('/1\s*(パック|袋|本|箱|個)\s*$/u', $prefix, $basis, PREG_OFFSET_CAPTURE) &&
                 preg_match('/\d+' . preg_quote($basis[1][0], '/') . '/u', $label) ) {
                $masked = substr_replace($masked, str_repeat(' ', strlen($basis[0][0])), $basis[0][1], strlen($basis[0][0]));
            }
            $masked = substr_replace($masked, str_repeat(' ', strlen($match[0])), $match[1], strlen($match[0]));
        }

        $measure_pattern = '/' . $start . '(?<approx>約\s*)?(?<a>' . $number . ')\s*(?:(?<au>kg|g)?\s*' . $range . '\s*(?<bapprox>約\s*)?(?<b>' . $number . ')\s*)?(?<u>kg|g)(?<suffix>前後|程度|以上|以下|未満|超)?' . $end . '/iu';
        preg_match_all($measure_pattern, $masked, $weights, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ( $weights as $match ) {
            $values = array();
            foreach ( $match as $key => $value ) $values[$key] = $value[0];
            $label = self::format_measure($values);
            $prefix = substr($masked, 0, $match[0][1]);
            if ( preg_match('/(1\s*' . $count_unit . ')\s*(?:当たり|あたり|当り|あたリ|につき)\s*$/u', $prefix, $per) ) {
                $label = preg_replace('/\s+/u', '', $per[1]) . 'あたり' . $label;
            }
            self::add($groups, 'weight', $label);
        }

        $count_pattern = '/' . $start . '(?<approx>約\s*)?(?<a>\d+)\s*(?:(?<au>' . $count_unit . ')?\s*' . $range . '\s*(?<bapprox>約\s*)?(?<b>\d+)\s*)?(?<u>' . $count_unit . ')(?<suffix>前後|程度|以上|以下|未満)?' . $end . '/u';
        preg_match_all($count_pattern, $masked, $counts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ( $counts as $match ) {
            $after = substr($masked, $match[0][1] + strlen($match[0][0]));
            // "1玉あたり" is a unit basis, not the number of fruit being delivered.
            if ( preg_match('/^\s*(?:当たり|あたり|当り|につき)/u', $after) ) continue;
            $values = array();
            foreach ( $match as $key => $value ) $values[$key] = $value[0];
            if ( ! empty($values['au']) && $values['au'] !== $values['u'] ) continue;
            self::add($groups, 'count', self::format_measure($values));
        }

        $size = '(?:[2-5]L|[23]S|SSS|LLL|SS|LL|S|M|L)';
        $size_pattern = '/(?<![a-zA-Z0-9_.-])(?<size>' . $size . '(?:\s*(?:サイズ)?\s*(?:' . $range . '|[\/・、])\s*' . $size . ')*)(?![a-zA-Z0-9_\/-])/u';
        preg_match_all($size_pattern, $masked, $sizes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ( $sizes as $match ) {
            $before = substr($masked, 0, $match['size'][1]);
            $after = substr($masked, $match['size'][1] + strlen($match['size'][0]));
            // Do not interpret liquid litres or dimensions as fruit sizes.
            if ( preg_match('/(?:ジュース|飲料|飲む|果汁|牛乳|ボトル|ペットボトル|リットル|寸法|奥行|幅|高さ)/u', $line) &&
                 ! preg_match('/サイズ\s*[:：]?\s*$/u', $before) && ! preg_match('/^\s*サイズ/u', $after) ) continue;
            $label = preg_replace('/\s+|サイズ/u', '', $match['size'][0]);
            $label = preg_replace('/(?:から|-)/u', '〜', $label);
            $label = str_replace(array('/', '、'), '・', $label);
            self::add($groups, 'size', $label);
        }
    }

    private static function canonical_expression( $label ) {
        $label = preg_replace('/KG|Kg|kG/u', 'kg', $label);
        $label = str_replace('G', 'g', $label);
        $label = preg_replace('/(?:から|-)/u', '〜', $label);
        return preg_replace('/(\d+(?:\.\d+)?)(kg|g|玉|房|パック|袋|本|個|箱|粒|束)〜(約?\d+(?:\.\d+)?)\2/u', '$1〜$3$2', $label);
    }

    /** Retain capacityLabel for callers from v0.7.7; save all source text for future refreshes. */
    public static function enrich( $variant ) {
        foreach ( array('headline', 'description') as $field ) {
            $variant[$field] = self::plain_text(isset($variant[$field]) ? $variant[$field] : '');
        }
        $labels = self::extract($variant);
        foreach ( $labels as $key => $value ) $variant[$key] = $value;
        $variant['capacityLabel'] = ! empty($variant['capacityLabel'])
            ? sanitize_text_field($variant['capacityLabel'])
            : ($labels['weightLabel'] ?: ($labels['countLabel'] ?: $labels['sizeLabel']));
        return $variant;
    }
}
