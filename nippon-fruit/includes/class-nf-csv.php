<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_CSV {

    const PAGE_SLUG = 'nippon-fruit-csv';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_nf_import_csv', array( __CLASS__, 'handle_import' ) );
        add_action( 'admin_post_nf_download_csv_sample', array( __CLASS__, 'download_sample' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            'CSV一括登録',
            'CSV一括登録',
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $result = get_transient( 'nf_csv_import_result_' . get_current_user_id() );
        if ( $result ) {
            delete_transient( 'nf_csv_import_result_' . get_current_user_id() );
        }
        ?>
        <div class="wrap">
            <h1>Nippon Fruit CSV一括登録</h1>

            <?php if ( $result ) : ?>
                <div class="notice notice-<?php echo empty($result['errors']) ? 'success' : 'warning'; ?> is-dismissible">
                    <p>
                        <strong>CSV処理完了：</strong>
                        新規 <?php echo intval($result['created']); ?>件 /
                        更新 <?php echo intval($result['updated']); ?>件 /
                        スキップ <?php echo intval($result['skipped']); ?>件 /
                        エラー <?php echo count($result['errors']); ?>件
                    </p>
                    <?php if ( ! empty($result['errors']) ) : ?>
                        <ul style="list-style:disc;padding-left:20px">
                            <?php foreach ( array_slice($result['errors'], 0, 30) as $error ) : ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ( count($result['errors']) > 30 ) : ?>
                            <p>エラーは先頭30件のみ表示しています。</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p>
                楽天商品URLをキーにして一括登録します。同じ楽天URLの商品が既にある場合は更新し、
                未登録URLなら新規作成します。
            </p>

            <p>
                <a class="button" href="<?php echo esc_url(
                    wp_nonce_url(
                        admin_url('admin-post.php?action=nf_download_csv_sample'),
                        'nf_download_csv_sample'
                    )
                ); ?>">サンプルCSVをダウンロード</a>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="nf_import_csv">
                <?php wp_nonce_field( 'nf_import_csv', 'nf_csv_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="nf_csv_file">CSVファイル</label></th>
                        <td>
                            <input type="file" name="nf_csv_file" id="nf_csv_file" accept=".csv,text/csv" required>
                            <p class="description">UTF-8 / UTF-8 BOM / Shift_JIS(CP932) を想定しています。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">新規商品の公開状態</th>
                        <td>
                            <select name="nf_default_post_status">
                                <option value="draft" selected>下書き</option>
                                <option value="publish">公開</option>
                            </select>
                            <p class="description">CSVに post_status がある行は、その値を優先します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">既存商品</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nf_update_existing" value="1" checked>
                                楽天URLが一致する既存商品を更新する
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button('CSVを一括登録'); ?>
            </form>

            <hr>

            <h2>CSV列</h2>
            <table class="widefat striped" style="max-width:1100px">
                <thead>
                    <tr>
                        <th>列名</th>
                        <th>必須</th>
                        <th>内容</th>
                        <th>例</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>title</code></td><td>○</td><td>サイト表示用の商品名</td><td>八代市産 不知火・デコポン 約2kg〜約5kg</td></tr>
                    <tr><td><code>rakuten_url</code></td><td>○</td><td>通常の楽天商品URL。既存判定キー</td><td>https://item.rakuten.co.jp/...</td></tr>
                    <tr><td><code>municipality</code></td><td>-</td><td>自治体。複数は | 区切り</td><td>八代市</td></tr>
                    <tr><td><code>fruit</code></td><td>-</td><td>旧分類（互換用）。新規運用はカテゴリを推奨</td><td>不知火・デコポン|柑橘</td></tr>
                    <tr><td><code>capacity</code></td><td>-</td><td>容量</td><td>約2kg〜約5kg</td></tr>
                    <tr><td><code>shipping</code></td><td>-</td><td>発送時期</td><td>2026年12月上旬より順次発送</td></tr>
                    <tr><td><code>origin</code></td><td>-</td><td>産地</td><td>熊本県八代市</td></tr>
                    <tr><td><code>status</code></td><td>-</td><td>受付中 / 先行予約受付中 / 受付終了</td><td>先行予約受付中</td></tr>
                    <tr><td><code>post_status</code></td><td>-</td><td>draft / publish</td><td>draft</td></tr>
                    <tr><td><code>item_code</code></td><td>-</td><td>既知の場合のみ楽天API itemCode</td><td>f432024-yatsushiro:10003854</td></tr>
                    <tr><td><code>affiliate_html</code></td><td>-</td><td>旧方式の楽天アフィリエイトHTML。通常は空欄でOK</td><td></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:28px">一括登録後</h2>
            <p>
                v0.3.0ではCSV取込時に楽天APIを連打しません。楽天側のQPS制限や429を避けるためです。
                取込後、商品編集画面の「楽天から商品情報を取得」でAPI情報を取得できます。
                次版では、このAPI同期自体をキュー方式で一括実行できるようにする予定です。
            </p>
        </div>
        <?php
    }

    public static function download_sample() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }
        check_admin_referer( 'nf_download_csv_sample' );

        $filename = 'nippon-fruit-import-sample.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // Excel向けUTF-8 BOM
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'title',
            'rakuten_url',
            'municipality',
            'fruit',
            'capacity',
            'shipping',
            'origin',
            'status',
            'post_status',
            'item_code',
            'affiliate_html',
        ));

        fputcsv($out, array(
            '八代市産 不知火・デコポン 約2kg〜約5kg',
            'https://item.rakuten.co.jp/f432024-yatsushiro/232-6603/',
            '八代市',
            '不知火・デコポン|柑橘',
            '約2kg〜約5kg',
            '2026年12月上旬より順次発送',
            '熊本県八代市',
            '先行予約受付中',
            'draft',
            'f432024-yatsushiro:10003854',
            '',
        ));

        fclose($out);
        exit;
    }

    public static function handle_import() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        if (
            ! isset($_POST['nf_csv_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['nf_csv_nonce'])),
                'nf_import_csv'
            )
        ) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $redirect = admin_url('edit.php?post_type=' . NF_Core::POST_TYPE . '&page=' . self::PAGE_SLUG);

        if (
            empty($_FILES['nf_csv_file']) ||
            empty($_FILES['nf_csv_file']['tmp_name']) ||
            ! is_uploaded_file($_FILES['nf_csv_file']['tmp_name'])
        ) {
            self::store_result(array(
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => array('CSVファイルを確認してください。')
            ));
            wp_safe_redirect($redirect);
            exit;
        }

        $default_status = isset($_POST['nf_default_post_status'])
            ? sanitize_key($_POST['nf_default_post_status'])
            : 'draft';

        if ( ! in_array($default_status, array('draft','publish'), true) ) {
            $default_status = 'draft';
        }

        $update_existing = ! empty($_POST['nf_update_existing']);

        $raw = file_get_contents($_FILES['nf_csv_file']['tmp_name']);
        if ( $raw === false ) {
            self::store_result(array(
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => array('CSVを読み込めませんでした。')
            ));
            wp_safe_redirect($redirect);
            exit;
        }

        $raw = self::to_utf8($raw);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $temp = fopen('php://temp', 'r+');
        fwrite($temp, $raw);
        rewind($temp);

        $header = fgetcsv($temp);
        if ( ! $header ) {
            fclose($temp);
            self::store_result(array(
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => array('CSVヘッダーを読み込めませんでした。')
            ));
            wp_safe_redirect($redirect);
            exit;
        }

        $header = array_map(array(__CLASS__, 'normalize_header'), $header);

        $required = array('title','rakuten_url');
        foreach ( $required as $required_key ) {
            if ( ! in_array($required_key, $header, true) ) {
                fclose($temp);
                self::store_result(array(
                    'created' => 0, 'updated' => 0, 'skipped' => 0,
                    'errors' => array('必須列 "' . $required_key . '" がありません。')
                ));
                wp_safe_redirect($redirect);
                exit;
            }
        }

        $result = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
        );

        $line = 1;
        while ( ($row = fgetcsv($temp)) !== false ) {
            $line++;

            if ( self::is_empty_row($row) ) continue;

            if ( count($row) < count($header) ) {
                $row = array_pad($row, count($header), '');
            } elseif ( count($row) > count($header) ) {
                $row = array_slice($row, 0, count($header));
            }

            $data = array_combine($header, $row);
            if ( ! is_array($data) ) {
                $result['errors'][] = "{$line}行目: CSV列の読み取りに失敗しました。";
                continue;
            }

            $title = isset($data['title']) ? sanitize_text_field($data['title']) : '';
            $rakuten_url = isset($data['rakuten_url']) ? esc_url_raw(trim($data['rakuten_url'])) : '';

            if ( ! $title || ! $rakuten_url ) {
                $result['errors'][] = "{$line}行目: title または rakuten_url が空です。";
                continue;
            }

            $existing_id = self::find_post_by_rakuten_url($rakuten_url);

            if ( $existing_id && ! $update_existing ) {
                $result['skipped']++;
                continue;
            }

            $row_status = isset($data['post_status']) ? sanitize_key($data['post_status']) : '';
            if ( ! in_array($row_status, array('draft','publish'), true) ) {
                $row_status = $default_status;
            }

            $postarr = array(
                'post_type' => NF_Core::POST_TYPE,
                'post_title' => $title,
                'post_status' => $row_status,
            );

            if ( $existing_id ) {
                $postarr['ID'] = $existing_id;
                $post_id = wp_update_post($postarr, true);
            } else {
                $post_id = wp_insert_post($postarr, true);
            }

            if ( is_wp_error($post_id) ) {
                $result['errors'][] = "{$line}行目: WordPress保存エラー - " . $post_id->get_error_message();
                continue;
            }

            update_post_meta($post_id, '_nf_rakuten_url', $rakuten_url);

            self::update_text_meta($post_id, '_nf_capacity', $data, 'capacity');
            self::update_text_meta($post_id, '_nf_shipping', $data, 'shipping');
            self::update_text_meta($post_id, '_nf_origin', $data, 'origin');

            if ( isset($data['status']) && trim($data['status']) !== '' ) {
                $status = sanitize_text_field($data['status']);
                if ( in_array($status, array('受付中','先行予約受付中','受付終了'), true) ) {
                    update_post_meta($post_id, '_nf_status', $status);
                }
            }

            if ( isset($data['item_code']) && trim($data['item_code']) !== '' ) {
                update_post_meta(
                    $post_id,
                    '_nf_rakuten_item_code',
                    sanitize_text_field($data['item_code'])
                );
            }

            if (
                isset($data['affiliate_html']) &&
                trim($data['affiliate_html']) !== '' &&
                current_user_can('unfiltered_html')
            ) {
                update_post_meta(
                    $post_id,
                    '_nf_rakuten_affiliate_html',
                    $data['affiliate_html']
                );
            }

            if ( isset($data['municipality']) ) {
                self::set_terms_from_cell($post_id, 'nf_municipality', $data['municipality']);
            }

            if ( isset($data['fruit']) ) {
                self::set_terms_from_cell($post_id, 'nf_fruit', $data['fruit']);
            }

            if ( $existing_id ) {
                $result['updated']++;
            } else {
                $result['created']++;
            }
        }

        fclose($temp);

        self::store_result($result);
        wp_safe_redirect($redirect);
        exit;
    }

    private static function store_result( $result ) {
        set_transient(
            'nf_csv_import_result_' . get_current_user_id(),
            $result,
            10 * MINUTE_IN_SECONDS
        );
    }

    private static function normalize_header( $value ) {
        $value = trim((string)$value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        return strtolower($value);
    }

    private static function to_utf8( $raw ) {
        if ( ! function_exists('mb_detect_encoding') || ! function_exists('mb_convert_encoding') ) {
            return $raw;
        }

        $encoding = mb_detect_encoding(
            $raw,
            array('UTF-8','SJIS-win','CP932','Shift_JIS','EUC-JP'),
            true
        );

        if ( ! $encoding || strtoupper($encoding) === 'UTF-8' ) {
            return $raw;
        }

        return mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    private static function is_empty_row( $row ) {
        foreach ( (array)$row as $cell ) {
            if ( trim((string)$cell) !== '' ) return false;
        }
        return true;
    }

    private static function find_post_by_rakuten_url( $url ) {
        $q = new WP_Query(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array('publish','draft','pending','private','future'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_nf_rakuten_url',
                    'value' => $url,
                    'compare' => '=',
                ),
            ),
            'no_found_rows' => true,
        ));

        return ! empty($q->posts[0]) ? intval($q->posts[0]) : 0;
    }

    private static function update_text_meta( $post_id, $meta_key, $data, $column ) {
        if ( isset($data[$column]) && trim((string)$data[$column]) !== '' ) {
            update_post_meta(
                $post_id,
                $meta_key,
                sanitize_text_field($data[$column])
            );
        }
    }

    private static function set_terms_from_cell( $post_id, $taxonomy, $cell ) {
        $cell = trim((string)$cell);
        if ( $cell === '' ) return;

        $names = preg_split('/\s*\|\s*/u', $cell);
        $term_ids = array();

        foreach ( $names as $name ) {
            $name = sanitize_text_field(trim($name));
            if ( $name === '' ) continue;

            $term = term_exists($name, $taxonomy);

            if ( ! $term ) {
                $term = wp_insert_term($name, $taxonomy);
            }

            if ( is_wp_error($term) ) continue;

            if ( is_array($term) && isset($term['term_id']) ) {
                $term_ids[] = intval($term['term_id']);
            } elseif ( is_int($term) ) {
                $term_ids[] = $term;
            }
        }

        if ( $term_ids ) {
            wp_set_object_terms($post_id, array_values(array_unique($term_ids)), $taxonomy, false);
        }
    }
}
