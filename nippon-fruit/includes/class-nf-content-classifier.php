<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Content_Classifier {

    /**
     * 商品説明から「味わい / 食べ方 / 保存 / 配送」に本当に該当する文だけを抽出する。
     * v0.9.12: 元説明全文を各欄へ流用しない。定型文・スペック文・制度説明を除外し、
     * 同一文が複数欄へ重複しないよう最も適合度の高い1カテゴリへだけ割り当てる。
     */
    public static function classify( $text ) {
        $text = self::clean($text);

        $result = array(
            'taste' => '',
            'serving' => '',
            'storage' => '',
            'delivery' => '',
        );

        if ( $text === '' ) {
            return $result;
        }

        $rules = self::rules();
        $candidates = array();

        // ラベル付きの保存・配送情報は、長い商品仕様文の中に埋もれていても拾う。
        foreach ( self::labelled_fragments($text) as $fragment ) {
            $candidates[] = $fragment;
        }

        foreach ( self::sentences($text) as $sentence ) {
            $candidates[] = $sentence;
        }

        $seen = array();
        $bucket = array(
            'taste' => array(),
            'serving' => array(),
            'storage' => array(),
            'delivery' => array(),
        );

        foreach ( $candidates as $sentence ) {
            $sentence = self::normalize_sentence($sentence);

            if ( $sentence === '' || self::is_boilerplate($sentence) ) {
                continue;
            }

            $fingerprint = self::fingerprint($sentence);
            if ( $fingerprint === '' || isset($seen[$fingerprint]) ) {
                continue;
            }

            $scores = array();
            foreach ( $rules as $key => $keywords ) {
                $scores[$key] = self::keyword_score($sentence, $keywords);
            }

            $best_key = '';
            $best_score = 0;

            // 明示的な用途ほど優先。味わいは汎用語が多いため最後に判定する。
            foreach ( array('storage','delivery','serving','taste') as $key ) {
                if ( $scores[$key] > $best_score ) {
                    $best_key = $key;
                    $best_score = $scores[$key];
                }
            }

            if ( $best_key === '' || $best_score <= 0 ) {
                continue;
            }

            // スペック表のような文は、保存/配送の実用情報を含む場合だけ許可。
            if (
                self::looks_like_spec_block($sentence) &&
                ! in_array($best_key, array('storage','delivery'), true)
            ) {
                continue;
            }

            $seen[$fingerprint] = true;
            $bucket[$best_key][] = $sentence;

            // 1項目につき最大2文まで。長文化と重複を防ぐ。
            if ( count($bucket[$best_key]) >= 2 ) {
                continue;
            }
        }

        foreach ( $bucket as $key => $sentences ) {
            if ( ! empty($sentences) ) {
                $result[$key] = self::truncate(
                    implode(' ', array_slice($sentences, 0, 2)),
                    240
                );
            }
        }

        return $result;
    }

    public static function merged_for_post(
        $post_id,
        $source_text
    ) {
        $auto = self::classify($source_text);

        $display_title = class_exists('NF_Product_Title')
            ? NF_Product_Title::display_title($post_id)
            : get_the_title($post_id);
        $primary_variety = class_exists('NF_Product_Title')
            ? NF_Product_Title::primary_variety($display_title)
            : '';

        if ($primary_variety !== '') {
            foreach (array('taste','serving','storage','delivery') as $key) {
                if (
                    $auto[$key] !== '' &&
                    NF_Product_Title::has_conflicting_variety($auto[$key], $primary_variety)
                ) {
                    $auto[$key] = '';
                }
            }
        }

        foreach ( array('taste','serving','storage','delivery') as $key ) {
            $manual = trim((string)get_post_meta(
                $post_id,
                '_nf_feature_' . $key,
                true
            ));

            if ( $manual !== '' ) {
                $auto[$key] = $manual;
            }
        }

        return $auto;
    }

    /**
     * 「商品情報 名称 梨 内容量 ... 保存方法 冷蔵庫で... 配送方法 冷蔵便...」
     * のように1文へ潰れている説明から実用情報だけ切り出す。
     */
    private static function labelled_fragments( $text ) {
        $out = array();
        $patterns = array(
            '/(?:保存方法|保存について|保管方法)\s*[:：]?\s*(.{4,140}?)(?=(?:配送方法|配送について|発送|賞味期限|消費期限|提供元|事業者|地場産品|寄附|※|$))/u',
            '/(?:配送方法|配送について)\s*[:：]?\s*(.{4,140}?)(?=(?:保存方法|賞味期限|消費期限|提供元|事業者|地場産品|寄附|※|$))/u',
            '/(?:おすすめの食べ方|お召し上がり方|食べ方)\s*[:：]?\s*(.{4,140}?)(?=(?:保存方法|配送方法|賞味期限|消費期限|提供元|※|$))/u',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match_all($pattern, $text, $matches) ) {
                foreach ( (array)$matches[1] as $fragment ) {
                    $fragment = self::normalize_sentence($fragment);
                    if ( $fragment !== '' ) {
                        $out[] = $fragment;
                    }
                }
            }
        }

        return $out;
    }

    private static function rules() {
        return array(
            'taste' => array(
                '甘い','甘さ','甘み','糖度','酸味','香り','芳醇','濃厚','果汁',
                'みずみず','ジューシ','食感','やわらか','シャキ','コク','風味',
                '旨味','おいし','美味','肉厚','とろけ','爽やか','さわやか',
                '上品な味','上品な甘','まろやか','歯ごたえ','口当たり'
            ),
            'serving' => array(
                '食べ方','お召し上がり','召し上が','冷やして','常温に戻',
                '皮をむ','カット','スプーン','そのまま','料理','ジャム',
                'ジュース','デザート','サラダ','おすすめの食べ','食べ頃'
            ),
            'storage' => array(
                '保存','保管','冷蔵庫','冷蔵保存','冷暗所','野菜室','冷凍保存',
                '乾燥を避','新聞紙','ポリ袋','直射日光を避','高温多湿を避'
            ),
            'delivery' => array(
                '発送','配送','お届け','順次','出荷','クール便','冷蔵便',
                '常温便','指定日','不在','天候','収穫後','発送予定','配送予定'
            ),
        );
    }

    private static function clean( $text ) {
        $text = html_entity_decode(
            wp_strip_all_tags((string)$text, true),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 改行は文境界として扱う。連続空白だけを圧縮する。
        $text = preg_replace('/[\r\n\t]+/u', '。', $text);
        $text = preg_replace('/[\s\x{3000}]+/u', ' ', $text);
        $text = preg_replace('/。+/u', '。', $text);

        return trim($text, " \t\n\r\0\x0B。");
    }

    private static function sentences( $text ) {
        $parts = preg_split(
            '/(?<=[。！？!?])\s*|[●■◆◇※]\s*/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $clean = array();

        foreach ( (array)$parts as $part ) {
            $part = self::normalize_sentence($part);

            if ( $part === '' || mb_strlen($part, 'UTF-8') < 6 ) {
                continue;
            }

            $clean[] = $part;

            if ( count($clean) >= 120 ) {
                break;
            }
        }

        return $clean;
    }

    private static function normalize_sentence( $sentence ) {
        $sentence = trim((string)$sentence);
        $sentence = preg_replace('/[\s\x{3000}]+/u', ' ', $sentence);
        $sentence = trim($sentence, " \t\n\r\0\x0B・|｜-–—");

        if ( $sentence === '' ) {
            return '';
        }

        if ( ! preg_match('/[。！？!?]$/u', $sentence) ) {
            $sentence .= '。';
        }

        return $sentence;
    }

    /** 定型文・制度説明・通販上の注意事項を特徴欄へ出さない。 */
    private static function is_boilerplate( $sentence ) {
        $phrases = array(
            '商品情報','商品詳細','名称','内容量','賞味期限','消費期限','提供元',
            '提供事業者','事業者名','画像はイメージ','画像はイメージです',
            'ふるさと納税よくある質問','寄附申込みのキャンセル','寄附申込のキャンセル',
            '返礼品の変更','返品はできません','返品・交換','あらかじめご了承ください',
            '地場産品基準','適合理由','告示第5条','寄附金の用途','事業を推進',
            '管理番号','返礼品番号','商品番号','品番','楽天市場','Yahoo!ショッピング',
            'ふるさと納税','送料無料','クーポン','ポイント倍','レビューキャンペーン'
        );

        foreach ( $phrases as $phrase ) {
            if ( self::contains($sentence, $phrase) ) {
                // 保存方法/配送方法のラベル付き実用文は除外対象にしない。
                if (
                    (self::contains($sentence, '保存方法') || self::contains($sentence, '配送方法')) &&
                    mb_strlen($sentence, 'UTF-8') <= 150
                ) {
                    continue;
                }
                return true;
            }
        }

        // URLや規約文が主成分のもの。
        if ( preg_match('/https?:\/\//iu', $sentence) ) {
            return true;
        }

        return false;
    }

    private static function looks_like_spec_block( $sentence ) {
        $labels = array(
            '内容量','容量','名称','賞味期限','消費期限','発送時期','配送方法',
            '産地','原産地','提供元','事業者','申込','寄附額'
        );
        $count = 0;

        foreach ( $labels as $label ) {
            if ( self::contains($sentence, $label) ) {
                $count++;
            }
        }

        if ( $count >= 2 ) {
            return true;
        }

        // kg/g/玉/房/パックなど規格が大量に並ぶ文。
        if ( preg_match_all('/\d+(?:\.\d+)?\s*(?:kg|g|玉|個|房|パック|袋|本)/iu', $sentence, $m) ) {
            if ( count($m[0]) >= 3 ) {
                return true;
            }
        }

        return false;
    }

    private static function keyword_score( $sentence, $keywords ) {
        $score = 0;
        foreach ( $keywords as $keyword ) {
            if ( self::contains($sentence, $keyword) ) {
                $score++;
            }
        }
        return $score;
    }

    private static function fingerprint( $text ) {
        $text = preg_replace('/[\s\x{3000}。、・,，.！!？?「」『』（）()]/u', '', (string)$text);
        if ( function_exists('mb_strtolower') ) {
            $text = mb_strtolower($text, 'UTF-8');
        }
        return trim($text);
    }

    private static function contains( $haystack, $needle ) {
        if ( function_exists('mb_stripos') ) {
            return mb_stripos(
                (string)$haystack,
                (string)$needle,
                0,
                'UTF-8'
            ) !== false;
        }

        return stripos(
            (string)$haystack,
            (string)$needle
        ) !== false;
    }

    private static function truncate( $text, $length ) {
        $text = trim((string)$text);

        if (
            function_exists('mb_strlen') &&
            mb_strlen($text, 'UTF-8') > $length
        ) {
            return mb_substr($text, 0, $length, 'UTF-8') . '…';
        }

        return $text;
    }
}
