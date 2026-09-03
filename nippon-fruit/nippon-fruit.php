<?php
/**
 * Plugin Name: Furusato Catalog
 * Description: ふるさと納税返礼品の管理・カテゴリ分類・楽天／Yahoo!ショッピングAPI・アフィリエイト連携に対応した汎用カタログプラグイン
 * Version: 0.14.5
 * Author: Furusato Catalog
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NF_VERSION', '0.14.5' );
define( 'NF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NF_PLUGIN_DIR . 'includes/class-nf-system-page.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-commercial-config.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-capabilities.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-customer-portal.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-core.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-rakuten.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-csv.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-bulk-import.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-discovery.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-catalog.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-single.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-ogp.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-auto-sync.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-variant-spec.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-product-title.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-yahoo.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-settings.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-content-classifier.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-category.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-category-consistency.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-category-classifier.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-classification-metrics.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-classification-evidence.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-classification-history.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-ai-category-classifier.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-image-category-classifier.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-classification-admin.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-admin-hub.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-quality.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-furusato-header.php';
require_once NF_PLUGIN_DIR . 'includes/class-nf-furusato-pages.php';

register_activation_hook( __FILE__, array( 'NF_System_Page', 'migrate_legacy' ) );
register_activation_hook( __FILE__, array( 'NF_Capabilities', 'activate' ) );
register_activation_hook( __FILE__, array( 'NF_Core', 'activate' ) );
register_activation_hook( __FILE__, array( 'NF_Auto_Sync', 'activate' ) );
register_activation_hook( __FILE__, array( 'NF_Furusato_Pages', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NF_Auto_Sync', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'NF_System_Page', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Commercial_Config', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Capabilities', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Customer_Portal', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Core', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Rakuten', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_CSV', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Bulk_Import', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Discovery', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Catalog', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Single', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_OGP', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Auto_Sync', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Yahoo', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Settings', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Category', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Category_Consistency', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Category_Classifier', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Classification_History', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_AI_Category_Classifier', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Classification_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Admin_Hub', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Quality', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Furusato_Header', 'init' ) );
add_action( 'plugins_loaded', array( 'NF_Furusato_Pages', 'init' ) );
