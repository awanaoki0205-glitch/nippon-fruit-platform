<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_OGP {

    private static function archive_title() {
        $brand = class_exists('NF_Settings') ? NF_Settings::brand_name() : get_bloginfo('name');
        return $brand . 'のふるさと納税返礼品';
    }

    private static function archive_description() {
        return 'ふるさと納税の返礼品を、自治体・カテゴリ・発送時期などから探せます。';
    }

    private static function archive_image() {
        $logo_id = class_exists('NF_Settings') ? NF_Settings::header_logo_id() : 0;
        if ( $logo_id ) {
            $url = wp_get_attachment_image_url($logo_id, 'full');
            if ( $url ) return $url;
        }
        return '';
    }

    public static function init() {
        // Cocoon
        add_filter( 'ogp_card_title', array( __CLASS__, 'filter_title' ), 20 );
        add_filter( 'ogp_card_description', array( __CLASS__, 'filter_description' ), 20 );
        add_filter( 'ogp_card_ogp_image', array( __CLASS__, 'filter_image' ), 20 );

        // ブラウザタイトルも揃える。
        add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ), 20 );

        // Jetpack Open Graph が有効な場合も同じ値に統一。
        add_filter( 'jetpack_open_graph_tags', array( __CLASS__, 'filter_jetpack_tags' ), 20 );

        // 通常のmeta descriptionも /furusato/ のみ専用化。
        add_action( 'wp_head', array( __CLASS__, 'output_meta_description' ), 1 );
    }

    private static function is_furusato_archive() {
        return is_post_type_archive( NF_Core::POST_TYPE ) || (class_exists('NF_System_Page') && NF_System_Page::is_system_page());
    }

    public static function filter_title( $title ) {
        if ( self::is_furusato_archive() ) {
            return self::archive_title();
        }

        return $title;
    }

    public static function filter_description( $description ) {
        if ( self::is_furusato_archive() ) {
            return self::archive_description();
        }

        return $description;
    }

    public static function filter_image( $image_url ) {
        if ( self::is_furusato_archive() ) {
            $image = self::archive_image();
            return $image ? esc_url_raw($image) : $image_url;
        }

        return $image_url;
    }

    public static function filter_document_title( $title ) {
        if ( self::is_furusato_archive() ) {
            return self::archive_title();
        }

        return $title;
    }

    public static function filter_jetpack_tags( $tags ) {
        if ( ! self::is_furusato_archive() || ! is_array($tags) ) {
            return $tags;
        }

        $tags['og:title'] = self::archive_title();
        $tags['og:description'] = self::archive_description();
        $image = self::archive_image();
        if ( $image ) $tags['og:image'] = esc_url_raw($image);
        $tags['og:url'] = get_post_type_archive_link( NF_Core::POST_TYPE );
        $tags['og:type'] = 'website';

        $tags['twitter:title'] = self::archive_title();
        $tags['twitter:description'] = self::archive_description();
        if ( $image ) $tags['twitter:image'] = esc_url_raw($image);

        return $tags;
    }

    public static function output_meta_description() {
        if ( ! self::is_furusato_archive() ) {
            return;
        }

        echo '<meta name="description" content="' .
            esc_attr( self::archive_description() ) .
            '">' . "\n";
    }
}
