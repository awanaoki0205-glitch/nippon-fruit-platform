<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_Auto_Sync {

    const PAGE_SLUG = 'nippon-fruit-auto-sync';

    const OPTION_ENABLED = 'nf_auto_sync_enabled';
    const OPTION_DISCOVERY_ENABLED = 'nf_auto_sync_discovery_enabled';
    const OPTION_AUTO_PUBLISH = 'nf_auto_sync_auto_publish';
    const OPTION_BATCH_SIZE = 'nf_auto_sync_batch_size';

    const OPTION_CURSOR = 'nf_auto_sync_cursor';
    const OPTION_LAST_RUN = 'nf_auto_sync_last_run';
    const OPTION_LAST_EXISTING = 'nf_auto_sync_last_existing';
    const OPTION_LAST_DISCOVERY = 'nf_auto_sync_last_discovery';
    const OPTION_DISCOVERY_STATE = 'nf_auto_sync_discovery_state';
    const OPTION_LOG = 'nf_auto_sync_log';

    const CRON_HOOK = 'nf_auto_sync_tick';
    const CRON_SCHEDULE = 'nf_every_hour';
    const LOCK_KEY = 'nf_auto_sync_lock';

    const API_ELEMENTS = 'itemName,itemPrice,itemPriceMin3,itemPriceMax3,itemUrl,itemCode,shopName,shopCode,mediumImageUrls,smallImageUrls,availability,affiliateUrl,startTime,endTime,catchcopy,itemCaption,reviewAverage,reviewCount';

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_tick'));

        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));

        add_action(
            'admin_post_nf_auto_sync_run_now',
            array(__CLASS__, 'admin_run_now')
        );

        add_action(
            'admin_post_nf_auto_sync_start_discovery',
            array(__CLASS__, 'admin_start_discovery')
        );

        add_action(
            'admin_post_nf_auto_sync_reset_review',
            array(__CLASS__, 'admin_reset_review')
        );

        // プラグイン更新時はactivation hookが走らない場合があるため毎回確認。
        add_action('init', array(__CLASS__, 'ensure_schedule'), 20);
        add_action('init', array(__CLASS__, 'maybe_upgrade_061'), 21);
        add_action('init', array(__CLASS__, 'maybe_upgrade_091'), 22);
    }

    public static function maybe_upgrade_091() {
        if ( get_option('nf_auto_sync_091_hourly', '') === '1' ) {
            return;
        }

        // 旧15分スケジュールを一度解除して、1時間ごとへ張り直す。
        self::unschedule_all();

        if ( self::is_enabled() ) {
            wp_schedule_event(
                time() + 120,
                self::CRON_SCHEDULE,
                self::CRON_HOOK
            );
        }

        if ( ! get_option(self::OPTION_BATCH_SIZE, 0) ) {
            update_option(self::OPTION_BATCH_SIZE, 25, false);
        }

        update_option(
            'nf_auto_sync_091_hourly',
            '1',
            false
        );
    }

    public static function maybe_upgrade_061() {
        if ( get_option('nf_auto_sync_061_migrated', '') === '1' ) {
            return;
        }

        delete_option(self::OPTION_DISCOVERY_STATE);
        delete_option(self::OPTION_LAST_DISCOVERY);

        update_option(
            'nf_auto_sync_061_migrated',
            '1',
            false
        );
    }

    public static function activate() {
        self::ensure_schedule();
    }

    public static function deactivate() {
        self::unschedule_all();
        delete_transient(self::LOCK_KEY);
    }

    public static function cron_schedules( $schedules ) {
        if ( empty($schedules[self::CRON_SCHEDULE]) ) {
            $schedules[self::CRON_SCHEDULE] = array(
                'interval' => HOUR_IN_SECONDS,
                'display'  => '1時間ごと',
            );
        }

        return $schedules;
    }

    public static function register_settings() {
        register_setting(
            'nf_auto_sync_settings',
            self::OPTION_ENABLED,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_checkbox'),
                'default' => '1',
            )
        );

        register_setting(
            'nf_auto_sync_settings',
            self::OPTION_DISCOVERY_ENABLED,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_checkbox'),
                'default' => '1',
            )
        );

        register_setting(
            'nf_auto_sync_settings',
            self::OPTION_AUTO_PUBLISH,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_checkbox'),
                'default' => '1',
            )
        );

        register_setting(
            'nf_auto_sync_settings',
            self::OPTION_BATCH_SIZE,
            array(
                'type' => 'integer',
                'sanitize_callback' => array(__CLASS__, 'sanitize_batch_size'),
                'default' => 25,
            )
        );
    }

    public static function sanitize_checkbox( $value ) {
        return ! empty($value) ? '1' : '0';
    }

    public static function sanitize_batch_size( $value ) {
        $value = intval($value);

        if ( ! in_array($value, array(10,15,20,25,30,40), true) ) {
            return 25;
        }

        return $value;
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . NF_Core::POST_TYPE,
            '自動同期',
            '自動同期',
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    public static function ensure_schedule() {
        if ( self::is_enabled() ) {
            if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
                wp_schedule_event(
                    time() + 60,
                    self::CRON_SCHEDULE,
                    self::CRON_HOOK
                );
            }
        } else {
            self::unschedule_all();
        }
    }

    private static function unschedule_all() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        while ( $timestamp ) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    private static function is_enabled() {
        return get_option(self::OPTION_ENABLED, '1') === '1';
    }

    private static function is_discovery_enabled() {
        return get_option(self::OPTION_DISCOVERY_ENABLED, '1') === '1';
    }

    private static function auto_publish() {
        return get_option(self::OPTION_AUTO_PUBLISH, '1') === '1';
    }

    private static function batch_size() {
        return self::sanitize_batch_size(
            get_option(self::OPTION_BATCH_SIZE, 25)
        );
    }

    public static function cron_tick() {
        if ( ! self::is_enabled() ) {
            return;
        }

        self::run_one_cycle(false);
    }

    /**
     * 1時間ごとの1サイクル。
     * 既存商品を分割バッチで同期し、新商品探索も安全に少しずつ進める。
     */
    public static function run_one_cycle( $manual = false ) {
        if ( get_transient(self::LOCK_KEY) ) {
            return array(
                'ok' => false,
                'message' => '別の同期処理が実行中です。',
            );
        }

        set_transient(self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS);

        $started = microtime(true);

        $result = array(
            'ok' => true,
            'existing' => array(
                'processed' => 0,
                'updated' => 0,
                'errors' => 0,
            ),
            'discovery' => array(
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'state' => '',
            ),
        );

        try {
            if ( ! class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_rakuten') ) {
                $result['existing'] = self::sync_existing_batch();
            }

            if ( self::is_discovery_enabled() && (!class_exists('NF_Commercial_Config') || NF_Commercial_Config::feature('feature_rakuten')) ) {
                self::maybe_start_discovery();
                $result['discovery'] = self::process_discovery_page();
            }

            update_option(
                self::OPTION_LAST_RUN,
                current_time('mysql'),
                false
            );

            $elapsed = round(microtime(true) - $started, 2);

            self::add_log(
                sprintf(
                    '同期完了：既存 %d件（更新%d / エラー%d）、探索 %d件（新規%d / 更新%d / スキップ%d / エラー%d）、%s秒',
                    intval($result['existing']['processed']),
                    intval($result['existing']['updated']),
                    intval($result['existing']['errors']),
                    intval($result['discovery']['processed']),
                    intval($result['discovery']['created']),
                    intval($result['discovery']['updated']),
                    intval($result['discovery']['skipped']),
                    intval($result['discovery']['errors']),
                    $elapsed
                )
            );

        } catch ( Throwable $e ) {
            $result['ok'] = false;
            $result['message'] = $e->getMessage();

            self::add_log(
                '同期処理エラー：' . sanitize_text_field($e->getMessage()),
                'error'
            );
        }

        delete_transient(self::LOCK_KEY);

        return $result;
    }

    /**
     * 既存返礼品をカーソル方式で小分け同期。
     */
    private static function sync_existing_batch() {
        $post_ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish',
                'draft',
                'pending',
                'private',
                'future',
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_nf_rakuten_item_code',
                    'value' => '',
                    'compare' => '!=',
                ),
            ),
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ));

        $total = count($post_ids);

        if ( ! $total ) {
            update_option(self::OPTION_CURSOR, 0, false);

            return array(
                'processed' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }

        $cursor = max(
            0,
            intval(get_option(self::OPTION_CURSOR, 0))
        );

        if ( $cursor >= $total ) {
            $cursor = 0;
        }

        $batch_size = self::batch_size();

        $processed = 0;
        $updated = 0;
        $errors = 0;

        for ( $i = 0; $i < $batch_size; $i++ ) {
            $index = ($cursor + $i) % $total;
            $post_id = intval($post_ids[$index]);

            if ( ! $post_id ) {
                continue;
            }

            $item_code = trim((string)get_post_meta(
                $post_id,
                '_nf_rakuten_item_code',
                true
            ));

            if ( $item_code === '' ) {
                continue;
            }

            if ( $processed > 0 ) {
                self::rate_limit_pause();
            }

            $processed++;

            $api = NF_Rakuten::bulk_search_page(array(
                'itemCode' => $item_code,
                'hits' => 1,
                'elements' => self::API_ELEMENTS,
            ));

            if ( is_wp_error($api) ) {
                $errors++;
                self::record_failure(
                    $post_id,
                    $api->get_error_message()
                );
                continue;
            }

            $raw = ! empty($api['items'][0])
                ? $api['items'][0]
                : null;

            if ( ! is_array($raw) ) {
                $errors++;
                self::record_failure(
                    $post_id,
                    '楽天APIでitemCodeの商品が見つかりませんでした。'
                );
                continue;
            }

            $returned_code = isset($raw['itemCode'])
                ? trim((string)$raw['itemCode'])
                : '';

            if (
                $returned_code !== '' &&
                $returned_code !== $item_code
            ) {
                $errors++;
                self::record_failure(
                    $post_id,
                    '楽天APIから異なるitemCodeが返されました。'
                );
                continue;
            }

            $sync = NF_Discovery::auto_sync_existing_post(
                $post_id,
                $raw
            );

            if ( is_wp_error($sync) ) {
                $errors++;
                self::record_failure(
                    $post_id,
                    $sync->get_error_message()
                );
                continue;
            }

            $updated++;
        }

        $next_cursor = ($cursor + $batch_size) % max(1, $total);

        update_option(
            self::OPTION_CURSOR,
            $next_cursor,
            false
        );

        update_option(
            self::OPTION_LAST_EXISTING,
            current_time('mysql'),
            false
        );

        return array(
            'processed' => $processed,
            'updated' => $updated,
            'errors' => $errors,
        );
    }

    private static function record_failure( $post_id, $message ) {
        $count = absint(get_post_meta(
            $post_id,
            '_nf_sync_fail_count',
            true
        ));

        $count++;

        update_post_meta(
            $post_id,
            '_nf_sync_fail_count',
            $count
        );

        update_post_meta(
            $post_id,
            '_nf_sync_last_error',
            sanitize_text_field($message)
        );

        if ( $count >= 3 ) {
            update_post_meta(
                $post_id,
                '_nf_sync_needs_review',
                '1'
            );
        }
    }

    /**
     * 1日1回、新商品探索の巡回を開始。
     */
    private static function maybe_start_discovery() {
        $state = get_option(
            self::OPTION_DISCOVERY_STATE,
            array()
        );

        if (
            is_array($state) &&
            ! empty($state['active'])
        ) {
            return;
        }

        $last = get_option(
            self::OPTION_LAST_DISCOVERY,
            ''
        );

        $last_ts = $last
            ? strtotime($last . ' ' . wp_timezone_string())
            : 0;

        if (
            $last_ts &&
            (current_time('timestamp') - $last_ts) < DAY_IN_SECONDS
        ) {
            return;
        }

        self::start_discovery_state();
    }

    private static function start_discovery_state() {
        update_option(
            self::OPTION_DISCOVERY_STATE,
            array(
                'active' => 1,
                'stage' => 'global',
                'page' => 1,
                'pageCount' => 1,
                'probeIndex' => 0,
                'probes' => array(),
                'shopIndex' => 0,
                'shopPage' => 1,
                'shopPageCount' => 1,
                'shops' => array(),
                'startedAt' => current_time('mysql'),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ),
            false
        );

        self::add_log('新商品探索を開始しました。');
    }

    /**
     * 1サイクルにつき新商品探索は1APIページだけ。
     */
    private static function process_discovery_page() {
        $state = get_option(
            self::OPTION_DISCOVERY_STATE,
            array()
        );

        if (
            ! is_array($state) ||
            empty($state['active'])
        ) {
            return array(
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'state' => 'idle',
            );
        }

        // 既存商品同期の最後のAPIアクセスから間隔を空ける。
        self::rate_limit_pause();

        $params = array(
            'hits' => 30,
            'elements' => self::API_ELEMENTS,
        );

        $stage = isset($state['stage'])
            ? sanitize_key($state['stage'])
            : 'global';

        if ( $stage === 'global' ) {
            $page = max(1, intval($state['page']));
            $params['keyword'] = class_exists('NF_Settings') ? NF_Settings::brand_name() : get_bloginfo('name');
            $params['page'] = $page;

        } elseif ( $stage === 'probes' ) {
            $probes = isset($state['probes']) && is_array($state['probes'])
                ? array_values($state['probes'])
                : array();

            $probe_index = max(0, intval($state['probeIndex']));

            if ( empty($probes[$probe_index]) ) {
                $known = NF_Discovery::auto_sync_known_shops();

                $state['stage'] = 'shops';
                $state['shops'] = array_values(
                    array_keys((array)$known)
                );
                $state['shopIndex'] = 0;
                $state['shopPage'] = 1;
                $state['shopPageCount'] = 1;

                update_option(
                    self::OPTION_DISCOVERY_STATE,
                    $state,
                    false
                );

                return array(
                    'processed' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'state' => 'shops',
                );
            }

            $params['keyword'] =
                (class_exists('NF_Settings') ? NF_Settings::brand_name() : get_bloginfo('name')) . ' ' . sanitize_text_field(
                    $probes[$probe_index]
                );
            $params['page'] = 1;

        } else {
            $shops = isset($state['shops']) && is_array($state['shops'])
                ? array_values($state['shops'])
                : array();

            $shop_index = max(0, intval($state['shopIndex']));

            if ( empty($shops[$shop_index]) ) {
                self::complete_discovery($state);

                return array(
                    'processed' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'state' => 'completed',
                );
            }

            // 確認済み自治体ショップはキーワード無しで全商品走査。
            $params['shopCode'] = sanitize_key($shops[$shop_index]);
            $params['page'] = max(1, intval($state['shopPage']));
        }

        $api = NF_Rakuten::bulk_search_page($params);

        if ( is_wp_error($api) ) {
            $state['errors'] = intval($state['errors']) + 1;
            update_option(
                self::OPTION_DISCOVERY_STATE,
                $state,
                false
            );

            self::add_log(
                '新商品探索APIエラー：' .
                sanitize_text_field($api->get_error_message()),
                'error'
            );

            return array(
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 1,
                'state' => $stage,
            );
        }

        $processed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $post_status = self::auto_publish()
            ? 'publish'
            : 'draft';

        foreach ( (array)$api['items'] as $raw_item ) {
            $processed++;

            $sync = NF_Discovery::auto_sync_discovered_item(
                $raw_item,
                $post_status,
                class_exists('NF_Settings') ? NF_Settings::provider_name() : ''
            );

            if ( is_wp_error($sync) ) {
                $errors++;
                continue;
            }

            $action = isset($sync['action'])
                ? $sync['action']
                : 'skipped';

            if ( $action === 'created' ) {
                $created++;
            } elseif ( $action === 'updated' ) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        $state['created'] = intval($state['created']) + $created;
        $state['updated'] = intval($state['updated']) + $updated;
        $state['skipped'] = intval($state['skipped']) + $skipped;
        $state['errors'] = intval($state['errors']) + $errors;

        if ( $stage === 'global' ) {
            $page = max(1, intval($state['page']));
            $page_count = min(
                20,
                max(1, intval($api['pageCount']))
            );

            $state['pageCount'] = $page_count;

            if ( $page < $page_count ) {
                $state['page'] = $page + 1;
            } else {
                $probes = NF_Discovery::auto_sync_unconfirmed_municipalities();

                if ( $probes ) {
                    $state['stage'] = 'probes';
                    $state['probes'] = array_values($probes);
                    $state['probeIndex'] = 0;
                } else {
                    $known = NF_Discovery::auto_sync_known_shops();

                    $state['stage'] = 'shops';
                    $state['shops'] = array_values(
                        array_keys((array)$known)
                    );
                    $state['shopIndex'] = 0;
                    $state['shopPage'] = 1;
                    $state['shopPageCount'] = 1;
                }
            }

        } elseif ( $stage === 'probes' ) {
            $state['probeIndex'] = intval($state['probeIndex']) + 1;

            $probes = isset($state['probes']) &&
                      is_array($state['probes'])
                ? array_values($state['probes'])
                : array();

            if ( intval($state['probeIndex']) >= count($probes) ) {
                $known = NF_Discovery::auto_sync_known_shops();

                $state['stage'] = 'shops';
                $state['shops'] = array_values(
                    array_keys((array)$known)
                );
                $state['shopIndex'] = 0;
                $state['shopPage'] = 1;
                $state['shopPageCount'] = 1;
            }

        } else {
            $shop_page = max(1, intval($state['shopPage']));
            $shop_page_count = min(
                20,
                max(1, intval($api['pageCount']))
            );

            $state['shopPageCount'] = $shop_page_count;

            if ( $shop_page < $shop_page_count ) {
                $state['shopPage'] = $shop_page + 1;
            } else {
                $state['shopIndex'] =
                    intval($state['shopIndex']) + 1;

                $state['shopPage'] = 1;
                $state['shopPageCount'] = 1;

                $shops = isset($state['shops']) &&
                         is_array($state['shops'])
                    ? array_values($state['shops'])
                    : array();

                if (
                    intval($state['shopIndex']) >= count($shops)
                ) {
                    self::complete_discovery($state);

                    return array(
                        'processed' => $processed,
                        'created' => $created,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'errors' => $errors,
                        'state' => 'completed',
                    );
                }
            }
        }

        update_option(
            self::OPTION_DISCOVERY_STATE,
            $state,
            false
        );

        return array(
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'state' => $state['stage'],
        );
    }

    private static function complete_discovery( $state ) {
        update_option(
            self::OPTION_LAST_DISCOVERY,
            current_time('mysql'),
            false
        );

        $state['active'] = 0;
        $state['completedAt'] = current_time('mysql');

        update_option(
            self::OPTION_DISCOVERY_STATE,
            $state,
            false
        );

        self::add_log(
            sprintf(
                '新商品探索完了：新規%d件 / 更新%d件 / スキップ%d件 / エラー%d件',
                intval($state['created']),
                intval($state['updated']),
                intval($state['skipped']),
                intval($state['errors'])
            )
        );
    }

    private static function rate_limit_pause() {
        // 楽天API 1QPSを意識して約1.15秒空ける。
        usleep(1150000);
    }

    private static function add_log( $message, $type = 'info' ) {
        $logs = get_option(
            self::OPTION_LOG,
            array()
        );

        if ( ! is_array($logs) ) {
            $logs = array();
        }

        array_unshift(
            $logs,
            array(
                'time' => current_time('mysql'),
                'type' => sanitize_key($type),
                'message' => sanitize_text_field($message),
            )
        );

        $logs = array_slice($logs, 0, 30);

        update_option(
            self::OPTION_LOG,
            $logs,
            false
        );
    }

    public static function admin_run_now() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        check_admin_referer('nf_auto_sync_run_now');

        $result = self::run_one_cycle(true);

        $message = ! empty($result['ok'])
            ? '自動同期を1サイクル実行しました。'
            : '同期を実行できませんでした。';

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type' => NF_Core::POST_TYPE,
                    'page' => self::PAGE_SLUG,
                    'nf_sync_message' => rawurlencode($message),
                ),
                admin_url('edit.php')
            )
        );

        exit;
    }

    public static function admin_start_discovery() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        check_admin_referer('nf_auto_sync_start_discovery');

        self::start_discovery_state();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type' => NF_Core::POST_TYPE,
                    'page' => self::PAGE_SLUG,
                    'nf_sync_message' => rawurlencode(
                        '新商品探索を開始しました。1時間ごとに少しずつ進みます。'
                    ),
                ),
                admin_url('edit.php')
            )
        );

        exit;
    }

    public static function admin_reset_review() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        check_admin_referer('nf_auto_sync_reset_review');

        $ids = get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish',
                'draft',
                'pending',
                'private',
                'future',
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_nf_sync_needs_review',
            'meta_value' => '1',
            'no_found_rows' => true,
        ));

        foreach ( $ids as $post_id ) {
            delete_post_meta(
                $post_id,
                '_nf_sync_needs_review'
            );
            update_post_meta(
                $post_id,
                '_nf_sync_fail_count',
                0
            );
            delete_post_meta(
                $post_id,
                '_nf_sync_last_error'
            );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type' => NF_Core::POST_TYPE,
                    'page' => self::PAGE_SLUG,
                    'nf_sync_message' => rawurlencode(
                        '要確認フラグをリセットしました。'
                    ),
                ),
                admin_url('edit.php')
            )
        );

        exit;
    }

    public static function render_page() {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        $enabled = self::is_enabled();
        $discovery_enabled = self::is_discovery_enabled();
        $auto_publish = self::auto_publish();
        $batch_size = self::batch_size();

        $next = wp_next_scheduled(self::CRON_HOOK);
        $last_run = get_option(self::OPTION_LAST_RUN, '');
        $last_existing = get_option(self::OPTION_LAST_EXISTING, '');
        $last_discovery = get_option(self::OPTION_LAST_DISCOVERY, '');
        $cursor = intval(get_option(self::OPTION_CURSOR, 0));

        $state = get_option(
            self::OPTION_DISCOVERY_STATE,
            array()
        );

        $logs = get_option(
            self::OPTION_LOG,
            array()
        );

        if ( ! is_array($logs) ) {
            $logs = array();
        }

        $counts = wp_count_posts(NF_Core::POST_TYPE);

        $total = 0;
        foreach (
            array('publish','draft','pending','private','future')
            as $status_key
        ) {
            if ( isset($counts->{$status_key}) ) {
                $total += intval($counts->{$status_key});
            }
        }

        $review_count = count(get_posts(array(
            'post_type' => NF_Core::POST_TYPE,
            'post_status' => array(
                'publish',
                'draft',
                'pending',
                'private',
                'future',
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_nf_sync_needs_review',
            'meta_value' => '1',
            'no_found_rows' => true,
        )));

        $message = isset($_GET['nf_sync_message'])
            ? sanitize_text_field(
                rawurldecode(wp_unslash($_GET['nf_sync_message']))
            )
            : '';
        ?>
        <div class="wrap">
            <h1>Nippon Fruit 自動同期</h1>

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <p class="description">
                楽天APIから返礼品の価格・受付状況・販売期間・画像・商品名を自動更新し、
                新しい対象商品も自動で発見します。
                1回の処理を小さく分け、約1時間ごとに進めます。
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0">
                <?php
                $cards = array(
                    array('自動同期', $enabled ? '有効' : '停止'),
                    array('登録返礼品', $total . '件'),
                    array('1回の更新', $batch_size . '件'),
                    array('要確認', $review_count . '件'),
                    array(
                        '次回予定',
                        $next
                            ? wp_date('Y/m/d H:i', $next, wp_timezone())
                            : '未設定'
                    ),
                    array(
                        '新商品探索',
                        ! empty($state['active'])
                            ? '実行中'
                            : '待機'
                    ),
                );

                foreach ( $cards as $card ) :
                ?>
                    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px">
                        <div style="color:#666;font-size:12px">
                            <?php echo esc_html($card[0]); ?>
                        </div>
                        <strong style="display:block;font-size:22px;margin-top:5px">
                            <?php echo esc_html($card[1]); ?>
                        </strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post" action="options.php" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px;max-width:900px">
                <?php settings_fields('nf_auto_sync_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">自動同期</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_ENABLED); ?>"
                                    value="1"
                                    <?php checked($enabled); ?>
                                >
                                有効にする
                            </label>
                            <p class="description">
                                1時間ごとに既存返礼品を小分け更新します。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">新商品探索</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_DISCOVERY_ENABLED); ?>"
                                    value="1"
                                    <?php checked($discovery_enabled); ?>
                                >
                                1日1回、自動で新商品を探す
                            </label>
                            <p class="description">
                                熊本県・熊本県内自治体の公式楽天ショップだけを対象にします。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">新商品</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_AUTO_PUBLISH); ?>"
                                    value="1"
                                    <?php checked($auto_publish); ?>
                                >
                                見つけた新商品を自動公開する
                            </label>
                            <p class="description">
                                OFFの場合は下書きで登録します。
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            1回に更新する既存商品
                        </th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_BATCH_SIZE); ?>">
                                <?php foreach ( array(10,15,20,25,30,40) as $size ) : ?>
                                    <option
                                        value="<?php echo intval($size); ?>"
                                        <?php selected($batch_size, $size); ?>
                                    >
                                        <?php echo intval($size); ?>件
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="description">
                                推奨は5件です。楽天APIへのアクセス間隔を約1.15秒空けます。
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('自動同期設定を保存'); ?>
            </form>

            <div style="display:flex;flex-wrap:wrap;gap:10px;margin:18px 0">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="nf_auto_sync_run_now">
                    <?php wp_nonce_field('nf_auto_sync_run_now'); ?>
                    <button type="submit" class="button button-primary">
                        今すぐ1サイクル同期
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="nf_auto_sync_start_discovery">
                    <?php wp_nonce_field('nf_auto_sync_start_discovery'); ?>
                    <button type="submit" class="button">
                        新商品探索を今すぐ開始
                    </button>
                </form>

                <?php if ( $review_count > 0 ) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="nf_auto_sync_reset_review">
                        <?php wp_nonce_field('nf_auto_sync_reset_review'); ?>
                        <button type="submit" class="button">
                            要確認フラグをリセット
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <table class="widefat striped" style="max-width:900px;margin-top:18px">
                <tbody>
                    <tr>
                        <th style="width:220px">最終自動同期</th>
                        <td><?php echo esc_html($last_run ?: 'まだありません'); ?></td>
                    </tr>
                    <tr>
                        <th>最終既存商品更新</th>
                        <td><?php echo esc_html($last_existing ?: 'まだありません'); ?></td>
                    </tr>
                    <tr>
                        <th>最終新商品探索完了</th>
                        <td><?php echo esc_html($last_discovery ?: 'まだありません'); ?></td>
                    </tr>
                    <tr>
                        <th>既存商品カーソル</th>
                        <td><?php echo intval($cursor); ?></td>
                    </tr>
                    <tr>
                        <th>新商品探索状態</th>
                        <td>
                            <?php
                            if ( ! empty($state['active']) ) {
                                echo esc_html(
                                    sprintf(
                                        '%s / global %d/%d / probe %d/%d / shop %d/%d',
                                        isset($state['stage'])
                                            ? $state['stage']
                                            : 'unknown',
                                        isset($state['page'])
                                            ? intval($state['page'])
                                            : 0,
                                        isset($state['pageCount'])
                                            ? intval($state['pageCount'])
                                            : 0,
                                        isset($state['probeIndex'])
                                            ? intval($state['probeIndex']) + 1
                                            : 0,
                                        isset($state['probes']) &&
                                        is_array($state['probes'])
                                            ? count($state['probes'])
                                            : 0,
                                        isset($state['shopIndex'])
                                            ? intval($state['shopIndex']) + 1
                                            : 0,
                                        isset($state['shops']) &&
                                        is_array($state['shops'])
                                            ? count($state['shops'])
                                            : 0
                                    )
                                );
                            } else {
                                echo '待機';
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php
            $municipality_status = NF_Discovery::auto_sync_municipality_status();
            $confirmed_count = count(array_filter($municipality_status));
            $unconfirmed_count = count($municipality_status) - $confirmed_count;
            ?>

            <h2 style="margin-top:28px">自治体ショップ探索状況</h2>

            <p>
                確認済み <strong><?php echo intval($confirmed_count); ?></strong> /
                未確認 <strong><?php echo intval($unconfirmed_count); ?></strong>
            </p>

            <div style="display:flex;flex-wrap:wrap;gap:6px;max-width:1000px;margin-bottom:22px">
                <?php foreach ( $municipality_status as $municipality => $shop_code ) : ?>
                    <span style="
                        display:inline-block;
                        padding:5px 8px;
                        border-radius:999px;
                        border:1px solid <?php echo $shop_code ? '#b8d7b9' : '#ddd'; ?>;
                        background:<?php echo $shop_code ? '#edf7ee' : '#f6f6f6'; ?>;
                        color:<?php echo $shop_code ? '#276b2e' : '#777'; ?>;
                        font-size:11px;
                    ">
                        <?php echo esc_html($municipality); ?>
                        <?php if ( $shop_code ) : ?> ✓<?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:28px">同期ログ</h2>

            <table class="widefat striped" style="max-width:1000px">
                <thead>
                    <tr>
                        <th style="width:160px">日時</th>
                        <th style="width:70px">種別</th>
                        <th>内容</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! $logs ) : ?>
                        <tr>
                            <td colspan="3">まだログはありません。</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( array_slice($logs, 0, 20) as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html(isset($log['time']) ? $log['time'] : ''); ?></td>
                                <td><?php echo esc_html(isset($log['type']) ? $log['type'] : 'info'); ?></td>
                                <td><?php echo esc_html(isset($log['message']) ? $log['message'] : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="notice notice-info inline" style="max-width:860px;margin-top:20px">
                <p>
                    <strong>補足：</strong>
                    WordPress標準のWP-Cronを使うため、厳密な1時間ちょうどではなく、
                    サイトへのアクセスをきっかけに実行されます。
                    商品削除は自動では行いません。API取得に3回連続失敗した商品は
                    「要確認」として記録するだけで、勝手に受付終了にはしません。
                </p>
            </div>
        </div>
        <?php
    }
}
