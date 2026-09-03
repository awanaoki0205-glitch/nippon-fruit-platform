<?php
if (!defined('ABSPATH')) exit;

/** Lightweight counters for cost monitoring. No product data is stored here. */
class NF_Classification_Metrics {
    const OPTION = 'nf_classification_usage_metrics';
    const KEYS = array('rule_only','text_ai_calls','image_ai_calls','review','api_errors');

    public static function increment($key, $amount = 1) {
        if (!in_array($key, self::KEYS, true)) return;
        $data = get_option(self::OPTION, array());
        if (!is_array($data)) $data = array();
        $day = wp_date('Y-m-d');
        if (!isset($data['total'])) $data['total'] = array();
        if (!isset($data['daily'][$day])) $data['daily'][$day] = array();
        $data['total'][$key] = max(0, (int)($data['total'][$key] ?? 0) + (int)$amount);
        $data['daily'][$day][$key] = max(0, (int)($data['daily'][$day][$key] ?? 0) + (int)$amount);
        $data['updated_at'] = time();
        if (count((array)($data['daily'] ?? array())) > 90) $data['daily'] = array_slice($data['daily'], -90, null, true);
        update_option(self::OPTION, $data, false);
    }

    public static function snapshot() {
        $data = get_option(self::OPTION, array());
        $totals = array_fill_keys(self::KEYS, 0);
        foreach ($totals as $key=>$value) $totals[$key] = (int)($data['total'][$key] ?? 0);
        return $totals;
    }
}
