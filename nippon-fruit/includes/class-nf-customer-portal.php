<?php
if ( ! defined('ABSPATH') ) exit;

/** White-label shell used only for customer_manager accounts. */
class NF_Customer_Portal {
    const REWRITE_VERSION = '0.12.8';
    const REWRITE_OPTION = 'nf_customer_portal_rewrite_version';

    public static function init() {
        add_action('init', array(__CLASS__, 'rewrite'), 1);
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'login_page'), 0);
        add_filter('login_redirect', array(__CLASS__, 'login_redirect'), 20, 3);
        add_filter('login_url', array(__CLASS__, 'login_url_filter'), 20, 3);
        add_action('admin_post_nf_customer_logout', array(__CLASS__, 'logout'));
        add_action('admin_init', array(__CLASS__, 'restrict_admin'), 1);
        add_action('admin_menu', array(__CLASS__, 'trim_menu'), 999);
        add_action('admin_head', array(__CLASS__, 'admin_style'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'customer_assets'), 999);
        add_action('admin_footer', array(__CLASS__, 'sidebar_account'));
        add_filter('admin_footer_text', array(__CLASS__, 'footer'));
        add_filter('update_footer', array(__CLASS__, 'hide_version'), 99);
        add_filter('screen_options_show_screen', array(__CLASS__, 'screen_options'));
        add_filter('show_admin_bar', array(__CLASS__, 'admin_bar'));
        add_action('wp_before_admin_bar_render', array(__CLASS__, 'trim_toolbar'), 999);
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
        add_filter('parent_file', array(__CLASS__, 'parent_file'));
        add_action('init', array(__CLASS__, 'maybe_flush'), 30);
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');
    }

    public static function is_customer() {
        return is_user_logged_in() && current_user_can('nf_view_dashboard') && ! current_user_can('manage_options');
    }

    public static function customer_assets() {
        if ( ! self::is_customer() ) return;
        wp_enqueue_style(
            'nf-customer-portal',
            NF_PLUGIN_URL . 'assets/customer-portal.css',
            array(),
            NF_VERSION
        );
    }

    public static function slug() {
        $slug = class_exists('NF_Commercial_Config')
            ? sanitize_title(NF_Commercial_Config::get('customer_login_slug', 'client-login'))
            : 'client-login';
        return $slug && ! in_array($slug, array('wp-admin','wp-login'), true) ? $slug : 'client-login';
    }

    public static function login_url() {
        return home_url('/' . self::slug() . '/');
    }

    public static function dashboard_url() {
        return admin_url('admin.php?page=nf-customer-dashboard');
    }

    public static function logout_url() {
        return wp_nonce_url(
            admin_url('admin-post.php?action=nf_customer_logout'),
            'nf_customer_logout'
        );
    }

    public static function logout() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect(self::login_url());
            exit;
        }
        check_admin_referer('nf_customer_logout');
        wp_logout();
        wp_safe_redirect(add_query_arg('logged_out', '1', self::login_url()));
        exit;
    }

    public static function rewrite() {
        add_rewrite_rule('^' . preg_quote(self::slug(), '/') . '/?$', 'index.php?nf_customer_login=1', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'nf_customer_login';
        return $vars;
    }

    /**
     * Detect the branded login endpoint even when WordPress rewrite rules have
     * not been flushed yet (or a cache/server prevents the rule from loading).
     */
    private static function is_login_request() {
        if ( get_query_var('nf_customer_login') ) return true;

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $request_path = trim((string)wp_parse_url($request_uri, PHP_URL_PATH), '/');
        $home_path = trim((string)wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

        if ($home_path !== '') {
            if ($request_path === $home_path) $request_path = '';
            elseif (strpos($request_path, $home_path . '/') === 0) $request_path = substr($request_path, strlen($home_path) + 1);
        }

        return trim($request_path, '/') === self::slug();
    }

    public static function maybe_flush() {
        if ( get_option(self::REWRITE_OPTION, '') === self::REWRITE_VERSION . ':' . self::slug() ) return;
        flush_rewrite_rules(false);
        update_option(self::REWRITE_OPTION, self::REWRITE_VERSION . ':' . self::slug(), false);
    }

    private static function brand() {
        $brand = class_exists('NF_Commercial_Config') ? trim((string)NF_Commercial_Config::get('display_brand', '')) : '';
        if ($brand === '' && class_exists('NF_Settings')) $brand = NF_Settings::brand_name();
        return $brand !== '' ? $brand : get_bloginfo('name');
    }

    private static function logo_url() {
        if ( ! class_exists('NF_Settings') ) return '';
        $id = NF_Settings::header_logo_id();
        return $id ? (string)wp_get_attachment_image_url($id, 'medium') : '';
    }

    public static function login_page() {
        if ( ! self::is_login_request() ) return;
        global $wp_query;
        if ($wp_query instanceof WP_Query) {
            $wp_query->is_404 = false;
        }
        nocache_headers();
        $error = '';
        $logged_out = isset($_GET['logged_out']) && sanitize_text_field(wp_unslash($_GET['logged_out'])) === '1';
        if ( is_user_logged_in() ) {
            wp_safe_redirect(current_user_can('nf_view_dashboard') ? self::dashboard_url() : admin_url());
            exit;
        }
        if ( isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST' ) {
            if ( ! isset($_POST['nf_customer_login_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nf_customer_login_nonce'])), 'nf_customer_login') ) {
                $error = 'セッションの有効期限が切れました。もう一度お試しください。';
            } else {
                $credentials = array(
                    'user_login' => sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
                    'user_password' => (string)wp_unslash($_POST['pwd'] ?? ''),
                    'remember' => ! empty($_POST['rememberme']),
                );
                $user = wp_signon($credentials, is_ssl());
                if ( is_wp_error($user) ) {
                    $error = 'ログイン情報を確認してください。';
                } else {
                    wp_safe_redirect(user_can($user, 'nf_view_dashboard') ? self::dashboard_url() : admin_url());
                    exit;
                }
            }
        }
        $brand = self::brand();
        $logo = self::logo_url();
        status_header(200);
        ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html($brand); ?> 管理画面</title><style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7f4;color:#253028;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}.nf-login{width:min(92vw,430px);background:#fff;border:1px solid #dfe7df;border-radius:18px;padding:34px;box-shadow:0 18px 55px rgba(25,55,32,.12)}.nf-login__brand{text-align:center;margin-bottom:27px}.nf-login__brand img{max-width:250px;max-height:72px}.nf-login__brand strong{display:block;font-size:24px}.nf-login h1{font-size:20px;margin:0 0 22px}.nf-login label{display:block;font-weight:700;margin:15px 0 6px}.nf-login input[type=text],.nf-login input[type=password]{width:100%;min-height:48px;border:1px solid #bdc9bf;border-radius:9px;padding:10px 12px;font-size:16px}.nf-login button{width:100%;min-height:50px;margin-top:22px;border:0;border-radius:9px;background:#21853a;color:#fff;font-size:16px;font-weight:700;cursor:pointer}.nf-login__error{padding:11px 13px;background:#fff0f0;color:#a32121;border-radius:8px}.nf-login__remember{display:flex!important;align-items:center;gap:7px;font-weight:400!important}.nf-login__back{text-align:center;margin-top:20px}.nf-login__back a{color:#526158;text-decoration:none}</style></head><body><main class="nf-login">
        <div class="nf-login__brand"><?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($brand); ?>"><?php else: ?><strong><?php echo esc_html($brand); ?></strong><?php endif; ?></div>
        <h1>管理画面へログイン</h1><?php if($logged_out): ?><p style="padding:11px 13px;background:#eef8f0;color:#216a32;border-radius:8px">ログアウトしました。</p><?php endif; ?><?php if($error): ?><p class="nf-login__error"><?php echo esc_html($error); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(self::login_url()); ?>"><?php wp_nonce_field('nf_customer_login','nf_customer_login_nonce'); ?><label for="nf-log">ユーザー名またはメールアドレス</label><input id="nf-log" name="log" type="text" autocomplete="username" required><label for="nf-pwd">パスワード</label><input id="nf-pwd" name="pwd" type="password" autocomplete="current-password" required><label class="nf-login__remember"><input name="rememberme" type="checkbox" value="1"> ログイン状態を保持</label><button type="submit">ログイン</button></form><p class="nf-login__back"><a href="<?php echo esc_url(home_url('/')); ?>">サイトへ戻る</a></p></main></body></html><?php
        exit;
    }

    public static function login_url_filter($login_url, $redirect, $force_reauth) {
        $url = self::login_url();
        if ($redirect) $url = add_query_arg('redirect_to', $redirect, $url);
        return $url;
    }

    public static function login_redirect($redirect_to, $requested, $user) {
        if ($user instanceof WP_User && user_can($user, 'nf_view_dashboard') && ! user_can($user, 'manage_options')) return self::dashboard_url();
        return $redirect_to;
    }

    public static function restrict_admin() {
        if ( ! self::is_customer() || wp_doing_ajax() ) return;
        global $pagenow;
        $allowed_files = array('admin.php','edit-tags.php','term.php','options.php','admin-post.php','async-upload.php','media-upload.php','profile.php');
        $allowed_pages = array('nf-customer-dashboard','nf-customer-easy-settings','nf-customer-display','nf-customer-category-order','nf-customer-contract');
        if ( ! in_array($pagenow, $allowed_files, true) ) {
            wp_safe_redirect(self::dashboard_url()); exit;
        }
        if ($pagenow === 'admin.php') {
            $page = sanitize_key($_GET['page'] ?? '');
            if ( ! in_array($page, $allowed_pages, true) ) { wp_safe_redirect(self::dashboard_url()); exit; }
        }
        if (in_array($pagenow, array('edit-tags.php','term.php'), true)) {
            $taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
            if ( ! in_array($taxonomy, array('nf_category','nf_municipality'), true) ) { wp_safe_redirect(self::dashboard_url()); exit; }
        }
    }

    public static function trim_menu() {
        if ( ! self::is_customer() ) return;
        $legacy_parent = 'edit.php?post_type=' . NF_Core::POST_TYPE;
        foreach (array('index.php','edit.php',$legacy_parent,'upload.php','edit.php?post_type=page','edit-comments.php','themes.php','plugins.php','users.php','tools.php','options-general.php') as $slug) remove_menu_page($slug);

        // Some WordPress/plugin hook orders can reinsert the CPT parent. Remove
        // the exact entry from the completed menu structure as well.
        global $menu, $submenu;
        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (isset($item[2]) && $item[2] === $legacy_parent) unset($menu[$index]);
            }
        }
        unset($submenu[$legacy_parent]);
    }

    public static function parent_file($parent_file) {
        if ( ! self::is_customer() ) return $parent_file;
        global $pagenow;
        $page = sanitize_key($_GET['page'] ?? '');
        $taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
        if (
            in_array($page, array('nf-customer-dashboard','nf-customer-easy-settings','nf-customer-display','nf-customer-category-order','nf-customer-contract'), true) ||
            in_array($taxonomy, array('nf_category','nf_municipality'), true) ||
            in_array($pagenow, array('edit-tags.php','term.php'), true)
        ) return 'nf-customer-dashboard';
        return $parent_file;
    }

    public static function admin_style() {
        if ( ! self::is_customer() ) return;
        $brand = self::brand();
        ?><style>
        #wpadminbar,.update-nag,#wpfooter,#contextual-help-link-wrap,#screen-options-link-wrap{display:none!important}html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:230px}#adminmenuwrap,#adminmenuback,#adminmenu{width:230px}#adminmenu{background:#1f3425;padding-top:18px;padding-bottom:155px}#adminmenuwrap{background:#1f3425}#adminmenu .wp-menu-name{font-weight:650}#adminmenu .wp-has-current-submenu>a.wp-has-current-submenu,#adminmenu .current a.menu-top{background:#21853a}#toplevel_page_nf-customer-dashboard .wp-submenu{display:block!important;position:static!important;visibility:visible!important;opacity:1!important;left:auto!important;top:auto!important;width:auto!important;min-width:0!important;margin:0!important;padding:7px 0 10px!important;background:#14261a!important;box-shadow:none!important}#toplevel_page_nf-customer-dashboard .wp-submenu:before{display:none!important}#toplevel_page_nf-customer-dashboard .wp-submenu a{padding:8px 12px 8px 36px!important}.nf-sidebar-account{position:fixed;z-index:1001;left:0;bottom:0;width:230px;padding:13px 15px 15px;background:#14261a;border-top:1px solid rgba(255,255,255,.16);color:#fff}.nf-sidebar-account__name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700}.nf-sidebar-account__login{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;color:#aebdb2;font-size:12px}.nf-sidebar-account__actions{display:flex;gap:7px;margin-top:10px}.nf-sidebar-account__actions a{flex:1;padding:7px 5px;border:1px solid rgba(255,255,255,.42);border-radius:6px;color:#fff;text-align:center;text-decoration:none;font-size:12px;font-weight:600}.nf-sidebar-account__actions a:last-child{background:#fff;color:#1f3425}.nf-customer-brand{margin:0 0 22px;padding:22px 24px;border-radius:14px;background:linear-gradient(135deg,#1f6f34,#2a9544);color:#fff}.nf-customer-brand h1{color:#fff;margin:0 0 5px}.nf-customer-brand p{margin:0;opacity:.9}@media(max-width:782px){#wpcontent{margin-left:0}.auto-fold #adminmenuwrap,.auto-fold #adminmenuback{width:190px}.auto-fold #adminmenu{width:190px}.nf-sidebar-account{width:190px}}
        #adminmenu{padding-bottom:0}.nf-sidebar-account{position:static;width:100%;box-sizing:border-box}.nf-sidebar-account__actions a{box-sizing:border-box}#menu-posts-nf_furusato{display:none!important}@media(max-width:782px){.nf-sidebar-account{width:100%}}
        </style><script>document.addEventListener('DOMContentLoaded',function(){
            document.title=<?php echo wp_json_encode($brand . ' 管理画面'); ?>;
            var legacyMenu=document.getElementById('menu-posts-nf_furusato');
            if(legacyMenu){legacyMenu.remove();}
            ['toplevel_page_nf-customer-dashboard'].forEach(function(menuId){
                var menu=document.getElementById(menuId);
                if(!menu){return;}
                menu.classList.remove('wp-not-current-submenu');
                menu.classList.add('wp-has-current-submenu','wp-menu-open');
                var link=menu.querySelector('a.menu-top');
                if(link){link.classList.add('wp-has-current-submenu');}
            });
            if(window.matchMedia&&window.matchMedia('(max-width:782px)').matches){
                document.body.classList.add('wp-responsive-open');
            }
        });</script><?php
    }

    public static function sidebar_account() {
        if ( ! self::is_customer() ) return;
        $user = wp_get_current_user();
        $contract = class_exists('NF_Commercial_Config') ? NF_Commercial_Config::all() : array();
        $plan = ! empty($contract['plan']) ? ucfirst((string)$contract['plan']) : '—';
        $system_url = class_exists('NF_System_Page') ? NF_System_Page::url() : home_url('/');
        ?>
        <div class="nf-sidebar-contract" id="nf-sidebar-contract">
            <span class="nf-sidebar-contract__label">ご契約プラン</span>
            <strong><?php echo esc_html($plan); ?></strong>
            <a href="<?php echo esc_url($system_url); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr($system_url); ?>"><?php echo esc_html($system_url); ?></a>
        </div>
        <div class="nf-sidebar-account" id="nf-sidebar-account">
            <span class="nf-sidebar-account__name"><?php echo esc_html($user->display_name ?: $user->user_login); ?></span>
            <span class="nf-sidebar-account__login">@<?php echo esc_html($user->user_login); ?></span>
            <div class="nf-sidebar-account__actions">
                <a href="<?php echo esc_url(admin_url('profile.php')); ?>">プロフィール</a>
                <a href="<?php echo esc_url(self::logout_url()); ?>">ログアウト</a>
            </div>
        </div>
        <script>(function(){var contract=document.getElementById('nf-sidebar-contract'),account=document.getElementById('nf-sidebar-account'),menu=document.getElementById('adminmenuwrap');if(!menu){return;}if(contract){menu.appendChild(contract);}if(account){menu.appendChild(account);}}());</script>
        <?php
    }

    public static function footer($text) { return self::is_customer() ? esc_html(self::brand()) : $text; }
    public static function hide_version($text) { return self::is_customer() ? '' : $text; }
    public static function screen_options($show) { return self::is_customer() ? false : $show; }
    public static function admin_bar($show) { return self::is_customer() ? false : $show; }
    public static function body_class($classes) { return self::is_customer() ? $classes . ' nf-customer-portal' : $classes; }
    public static function trim_toolbar() {
        if ( ! self::is_customer() ) return;
        global $wp_admin_bar;
        if ($wp_admin_bar) foreach(array('wp-logo','about','wporg','documentation','support-forums','feedback','updates','comments','new-content','site-name') as $id) $wp_admin_bar->remove_node($id);
    }
}
