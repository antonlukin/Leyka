<?php if( !defined('WPINC') ) die;

/** Core class. */
class Leyka extends Leyka_Singleton {

    protected static $_instance;

    /**
     * Unique identifier for the plugin.
     *
     * Use this value (not the variable name) as the text domain when internationalizing strings of text. It should
     * match the Text Domain file header in the main plugin file.
     * @var string
     */
    protected $_plugin_slug = 'leyka';

    /**
     * Templates order.
     * @var array
     */
    protected $_templates_order = array('revo', 'neo', 'toggles', 'radios');

    /**
     * Gateways list.
     * @var array
     */
    protected $_gateways = array();

    /** @var array Of WP_Error instances. */
    protected $_form_errors = array();

    /** @var string Gateway URL to process payment data. */
    protected $_payment_url = '';

    /** @var mixed Donation form submission redirect type.
     * Possible values:
     *  - 'auto' to submit via POST,
     *  - 'redirect' to submit via GET,
     *  - boolean false to turn off auto-submitting
     */
    protected $_submission_redirect_type = 'auto';

    /** @var integer Currently submitted donation ID. */
    protected $_submitted_donation_id = 0;

    /** @var array Of key => value pairs of payment form vars to send to the Gateway URL. */
    protected $_payment_vars = array();

    /**
     * Template list.
     * @var array
     */
    protected $templates = null;

    /** @var bool|null */
    protected $_form_is_screening = false;

    /** Initialize the plugin by setting up localization, filters, administration functions etc. */
    protected function __construct() {

        if( !get_option('leyka_permalinks_flushed') ) {

            function leyka_rewrite_rules() {

                flush_rewrite_rules(false);
                update_option('leyka_permalinks_flushed', 1);

            }
            add_action('init', 'leyka_rewrite_rules');

        }

        // By default, we'll assume some errors in the payment form, so redirect will get us back to it:
        $this->_payment_url = wp_get_referer();

        add_action('wp_head', array($this, 'addGtmDataLayer'), -1000);

        $this->loadPublicCssJs();

        add_action('init', array($this, 'registerPostTypes'), 1);

        add_action('init', array($this, 'registerUserCapabilities'), 1);

        // Add/modify the rewrite rules:
        add_filter('rewrite_rules_array', array($this, 'insertRewriteRules'));
        add_filter('query_vars', array($this, 'insertRewriteQueryVars'));

        add_action('parse_request', array($this, 'parseRequest')); // Service URLs handlers

        function leyka_session_start() {
            if( !session_id() ) {
                session_start();
            }
        }
        add_action('init', 'leyka_session_start', -2);

        if(get_option('leyka_plugin_stats_option_needs_sync')) {

            function leyka_sync_stats_option() {

                $stats_option_synch_res = leyka_sync_plugin_stats_option();

                if(is_wp_error($stats_option_synch_res)) {
                    return $stats_option_synch_res;
                } else {
                    return delete_option('leyka_plugin_stats_option_needs_sync')
                        && update_option('leyka_plugin_stats_option_sync_done', time());
                }

            }
            add_action('admin_init', 'leyka_sync_stats_option');

        }

        add_action('admin_bar_menu', array($this, 'addToolbarMenu'), 999);

        if(get_option('leyka_donor_accounts_available')) {
            add_action('init', function(){
                if(leyka_current_user_has_role('donor')) {
                    add_filter('show_admin_bar', '__return_false');
                }
            }, 9);
        }

        if(is_admin()) { // Admin area only

            require_once(LEYKA_PLUGIN_DIR.'inc/leyka-class-options-allocator.php');
            require_once(LEYKA_PLUGIN_DIR.'inc/leyka-render-settings-old.php');
            require_once(LEYKA_PLUGIN_DIR.'inc/leyka-admin.php');
            require_once(LEYKA_PLUGIN_DIR.'inc/leyka-donations-export.php');

            Leyka_Admin_Setup::getInstance();

            if(get_option('leyka_init_wizard_redirect')) {

                delete_option('leyka_init_wizard_redirect');

                add_action('admin_init', function(){

                    wp_redirect(admin_url('admin.php?page=leyka_settings_new&screen=wizard-init'));
                    exit;

                });

            }

        } else { // Public (non-admin) area only

            function leyka_get_posts(WP_Query $query) {

                if(is_admin() || !$query->is_main_query()) {
                    return;
                }

                if($query->is_post_type_archive(Leyka_Donation_Management::$post_type)) {

                    $query->set('post_status', 'funded');

                    if(get_query_var('leyka_campaign_filter')) {

                        $campaign = get_posts(array(
                                'post_type' => Leyka_Campaign_Management::$post_type,
                                'name' => get_query_var('leyka_campaign_filter'))
                        );
                        if( !$campaign ) {
                            return;
                        }
                        $campaign = reset($campaign);

                        $query->set('meta_query', array(array(
                            'key'     => 'leyka_campaign_id',
                            'value'   => $campaign->ID,
                        ),));
                    }

                }

            }
            add_action('pre_get_posts', 'leyka_get_posts', 1);

            function leyka_success_page_widget_template($content) {

                if(
                    is_page(leyka_options()->opt('success_page'))
                    && leyka_options()->opt_template('show_success_widget_on_success')
                    && is_main_query()
                ) {
                    
                    $form_template = leyka_template_from_query_arg();
                    $form_template_suffix = $form_template === 'star' ? '-' . $form_template : '';

                    ob_start();
                    include(LEYKA_PLUGIN_DIR.'templates/service/leyka-template-success-widget'.$form_template_suffix.'.php');
                    $content = ob_get_clean();
                    
                    if($form_template === 'star') {
                        $content .= get_the_content();
                    }

                }

                return $content;

            }
            add_filter('the_content', 'leyka_success_page_widget_template', 1);

            function leyka_failure_page_widget_template($content) {

                if(
                    is_page(leyka_options()->opt('failure_page'))
                    && leyka_options()->opt_template('show_failure_widget_on_failure')
                    && is_main_query()
                ) {

                    ob_start();
                    include(LEYKA_PLUGIN_DIR.'templates/service/leyka-template-failure-widget.php');
                    $content = ob_get_clean();

                }

                return $content;

            }
            add_filter('the_content', 'leyka_failure_page_widget_template', 1);

            function reinstall_cssjs_in_giger() {

                $theme = wp_get_theme();
                if($theme && ($theme->template == 'giger' || $theme->template == 'giger-kms') && !is_singular('leyka_campaign')) {

                    $is_cssjs_reqiured = false;

                    if(get_the_ID() == leyka_options()->opt('failure_page') || get_the_ID() == leyka_options()->opt('success_page')) {
                        $is_cssjs_reqiured = true;
                    }
                    elseif(leyka_form_is_screening()) {
                        $is_cssjs_reqiured = true;
                    }

                    if($is_cssjs_reqiured) {
                        $leyla_template_data = leyka_get_current_template_data();

                        if($leyla_template_data['id'] == 'revo') {
                            $leyka = leyka();
                            $leyka->loadPublicCssJs(); // force add leyka cssjs in giger for revo leyka theme
                        }
                    }

                }
            }
            add_action('template_redirect', 'reinstall_cssjs_in_giger', 90); // Important: in Giger problem code run with priority 80

            add_action('wp_head', 'leyka_inline_scripts');
            function leyka_inline_scripts(){

//                $colors = array('#07C7FD', '#05A6D3', '#8CE4FD'); // Leyka blue
                $colors = array('#1db318', '#1aa316', '#acebaa'); // Leyka green ?>

                <script>
                    document.documentElement.classList.add("leyka-js");
                </script>
                <style>
                    :root {
                        --color-main: 		<?php echo $colors[0];?>;
                        --color-main-dark: 	<?php echo $colors[1];?>;
                        --color-main-light: <?php echo $colors[2];?>;
                    }
                </style>

                <?php
            }

            function leyka_template_init_include() {
                if(is_main_query() && is_singular(Leyka_Campaign_Management::$post_type)) { // Include template init script

                    $campaign = new Leyka_Campaign(get_queried_object_id());
                    $template = leyka_get_current_template_data($campaign);

                    if($template && isset($template['file'])) {

                        $init_file = LEYKA_PLUGIN_DIR.'templates/leyka-'.$template['id'].'/leyka-'.$template['id'].'-init.php';
                        if(file_exists($init_file)) {
                            require_once($init_file);
                        }

                    }

                }
            }
            add_action('wp_head', 'leyka_template_init_include');

            // Embed campaign URL handler:
            function leyka_template_include($template) {

                if(is_main_query() && is_singular(Leyka_Campaign_Management::$post_type) && !empty($_GET['embed_object'])) {

                    $new_template = leyka_get_current_template_data(false, 'embed_'.$_GET['embed_object'], true);
                    if($new_template && !empty($new_template['file'])) {
                        $template = $new_template['file'];
                    }

                }

                return $template;

            }
            add_filter('template_include', 'leyka_template_include', 100);

            add_action('template_redirect', array($this, 'gateway_redirect_page'), 1, 1);

        }

        $this->applyContentFormatting(); // Internal formatting filters

        // Currency rates auto refreshment - disabled for now

        // Mailout for campaigns with successfully reached targets - default processing:
        if(class_exists('Leyka_Options_Controller') && leyka_options()->opt('send_donor_emails_on_campaign_target_reaching')) {
            add_action('leyka_do_campaigns_targets_reaching_mailout', array($this, '_do_campaigns_targets_reaching_mailout'));
        }

        do_action('leyka_initiated');

    }

    public function __get($param) {
        switch($param) {
            case 'version': return LEYKA_VERSION;
            case 'plugin_slug': return $this->_plugin_slug;
            case 'payment_url': return $this->_payment_url;
            case 'payment_vars': return $this->_payment_vars;
            case 'submitted_donation_id':
            case 'donation_id': return $this->_submitted_donation_id;
            case 'auto_redirect': return $this->_submission_redirect_type === 'auto';
            case 'redirect_type':
            case 'submission_redirect_type':
                return $this->_submission_redirect_type;
            case 'form_is_screening': return !!$this->_form_is_screening;
            default: return '';
        }
    }

    public function __set($name, $value) {
        switch($name) {
            case 'form_is_screening':

                $value = !!$value;

                if( !$this->_form_is_screening && $value ) {
                    $this->_form_is_screening = $value;
                }
                break;

            default:
        }
    }

    /**
     * A shorthand wrapper for the options getter method.
     * @param $option_id string
     * @param $new_value mixed
     * @return mixed
     */
    public function opt($option_id, $new_value = null) {
        return leyka_options()->opt($option_id, $new_value);
    }

    public function addGtmDataLayer() {

        if(
            !leyka_options()->opt('show_gtm_dataLayer_on_success')
            || !is_main_query()
            || !is_page(leyka_options()->opt('success_page'))
        ) {
            return;
        }

        $donation_id = leyka_remembered_data('donation_id');
        $campaign = null;
        $campaign_id = null;

        if( !$donation_id ) {
            return;
        }

        $donation = new Leyka_Donation($donation_id);
        $campaign_id = $donation ? $donation->campaign_id : null;
        $campaign = new Leyka_Campaign($campaign_id);

        if( !$campaign->id ) {
            return;
        }

        $donation_amount_total = round((float)$donation->amount_total, 2);?>

        <script>
            window.dataLayer = window.dataLayer || [];

            dataLayer.push({
                'donorEmail': '<?php echo $donation->donor_email;?>',
                'transactionId': '<?php echo (int)$donation_id;?>',
                'transactionAffiliation': '<?php echo get_bloginfo('name');?>',
                'transactionTotal': <?php echo $donation_amount_total;?>,
                'transactionTax': 0,
                'transactionShipping': 0,
                'transactionProducts': [{
                    'sku': '<?php echo (int)$campaign_id;?>',
                    'name': '<?php echo esc_attr($campaign->title);?>',
                    'category': '<?php echo esc_attr($donation->type_label);?>',
                    'price': <?php echo $donation_amount_total;?>,
                    'quantity': 1
                }],
                <?php /** @todo Check if the following params can be passed from the dataLayer to GA somehow. */?>
                'donationCampaignPaymentTitle': '<?php echo esc_attr($campaign->payment_title);?>',
                'donationFundedDate': '<?php echo esc_attr($donation->date_funded);?>',
                'donationGateway': '<?php echo esc_attr($donation->gateway_label);?>',
                'donationPm': '<?php echo esc_attr($donation->pm_label);?>',
                'donationType': '<?php echo esc_attr($donation->type_label);?>',
                'donationAmount': <?php echo esc_attr($donation->amount);?>,
                'donationCurrency': '<?php echo esc_attr($donation->currency_label);?>'
            });
        </script>

    <?php }

    /** @todo Create a procedure to get actual currencies rates and save them in the plugin options values */
    public function doCurrenciesRatesRefresh() {
    }

    public function loadPublicCssJs() {

        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'localizeScripts'));

    }
    /** @deprecated */
    public function load_public_cssjs() {
        $this->loadPublicCssJs();
    }

    public function parseRequest() {

        if(stristr($_SERVER['REQUEST_URI'], 'leyka/service') !== FALSE) { // Leyka service URL

            $request = explode('leyka/service', $_SERVER['REQUEST_URI']);
            $request = explode('/', trim($request[1], '/'));

            if($request[0] === 'do_recurring') { // Recurring payments processing URL
                $this->_doActiveRecurring();
            } else if($request[0] === 'cancel_recurring' && !empty($request[1]) && !empty($request[2])) {

                $donation = new Leyka_Donation($request[1]);
                $init_recurrent_donation = Leyka_Donation::get_init_recurrent_donation($donation);
                $hash = md5($donation->id.'_'.$init_recurrent_donation->id.'_leyka_cancel_recurring_subscription');

                if($donation && $hash == $request[2]) {
                    do_action("leyka_{$donation->gateway_id}_cancel_recurring_subscription", $donation);
                }

            } else if(
                $request[0] === 'do_campaigns_targets_reaching_mailout' &&
                leyka_options()->opt('send_donor_emails_on_campaign_target_reaching')
            ) {
                do_action('leyka_do_campaigns_targets_reaching_mailout');
            } else if($request[0] === 'get_usage_stats') {

                require_once LEYKA_PLUGIN_DIR.'bin/sodium-compat.phar';

                if( !$this->_outerRequestAllowed() ) {
                    exit;
                }

                echo empty($_GET['tst']) ?
                    \Sodium\crypto_box_seal(
                        json_encode($this->_get_usage_stats($_REQUEST)),
                        \Sodium\hex2bin(get_option('leyka_stats_sipher_public_key'))
                    ) :
                    '<pre>'.print_r($this->_get_usage_stats($_REQUEST), 1).'</pre>';
            } else { // Gateway callback URL

                // Callback URLs are: some-website.org/leyka/service/{gateway_id}/{action_name}/
                // E.g., some-website.org/leyka/service/yandex/check_order/

                // $request[0] - Gateway ID, $request[1] - service action:
                do_action('leyka_service_call-'.$request[0], empty($request[1]) ? '' : $request[1]);

            }

            exit();

        }

    }
    /** @deprecated */
    public function parse_request() {
        return $this->parseRequest();
    }

    public function addToolbarMenu(WP_Admin_Bar $wp_admin_bar) {

        if( !current_user_can('leyka_manage_donations') ) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'leyka-toolbar-menu',
            'title' => __('Leyka', 'leyka'),
            'href'  => admin_url('admin.php?page=leyka'),
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'leyka-toolbar-desktop',
            'title'  => __('Desktop', 'leyka'),
            'parent' => 'leyka-toolbar-menu',
            'href'   => admin_url('admin.php?page=leyka'),
        ));
        $wp_admin_bar->add_node(array(
            'id'        => 'leyka-toolbar-donations',
            'title'     => __('Donations', 'leyka'),
            'parent'    => 'leyka-toolbar-menu',
            'href'      => admin_url('edit.php?post_type='.Leyka_Donation_Management::$post_type),
        ));
        $wp_admin_bar->add_node(array(
            'id'        => 'leyka-toolbar-campaigns',
            'title'     => __('Campaigns', 'leyka'),
            'parent'    => 'leyka-toolbar-menu',
            'href'      => admin_url('edit.php?post_type='.Leyka_Campaign_Management::$post_type),
        ));

        if(current_user_can('leyka_manage_options')) {
            $wp_admin_bar->add_node(array(
                'id'        => 'leyka-toolbar-settings',
                'title'     => __('Settings', 'leyka'),
                'parent'    => 'leyka-toolbar-menu',
                'href'      => admin_url('admin.php?page=leyka_settings'),
            ));
        }

    }
    /**
     * @deprecated
     * @param $wp_admin_bar WP_Admin_Bar
     */
    public function add_toolbar_menu(WP_Admin_Bar $wp_admin_bar) {
        return $this->addToolbarMenu($wp_admin_bar);
    }

    public function _doCurrencyRatesRefresh() {
        foreach(leyka_get_actual_currency_rates() as $currency => $rate) {
            update_option('leyka_currency_rur2'.mb_strtolower($currency), $rate);
        }
    }
    /** @deprecated */
    public function _do_currency_rates_refresh() {
        return $this->_doCurrencyRatesRefresh();
    }

    public function _do_campaigns_targets_reaching_mailout($campaign_id = false) {

        if((int)$campaign_id > 0) {
            $_GET['mailout_campaign_id'] = (int)$campaign_id;
        }

        include(LEYKA_PLUGIN_DIR.'procedures/leyka-campaigns-targets-reaching-mailout.php');

    }

    /** Make active rebill requests for all recurring subsriptions for the current day of month. */
    protected function _doActiveRecurring() {

        ini_set('max_execution_time', 0);
        set_time_limit(0);
        ini_set('memory_limit', 268435456); // 256 Mb, just in case

        // Get all active initial donations for the recurring subscriptions:
        $current_day = (int)date('j');
        $max_days_in_month = (int)date('t');
        $current_day_param = $max_days_in_month < 31 && $max_days_in_month === $current_day ? // Last day of short month
            array(array('day' => $current_day, 'compare' => '>='), array('day' => 31, 'compare' => '<=')) :
            array(array('day' => (int)date('j')));

        $params = array(
            'post_type' => Leyka_Donation_Management::$post_type,
            'nopaging' => true,
            'post_status' => 'funded',
            'post_parent' => 0,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'leyka_payment_type',
                    'value' => 'rebill',
                    'compare' => '=',
                ),
                array(
                    'key' => '_rebilling_is_active',
                    'value' => '1',
                    'compare' => '=',
                ),
            ),
            'date_query' => $current_day_param,
        );

        foreach(get_posts($params) as $donation) {

            $donation = new Leyka_Donation($donation);

            $gateway = leyka_get_gateway_by_id($donation->gateway_id);
            if($gateway) {

                $new_recurring_donation = $gateway->do_recurring_donation($donation);
                if($new_recurring_donation && is_a($new_recurring_donation, 'Leyka_Donation')) {
                    Leyka_Donation_Management::send_all_recurring_emails($new_recurring_donation);
                }

            }

        }

    }

    protected function _outerRequestAllowed() {

        $home_url_clear = rtrim(home_url(), '/');

        return isset($_SERVER['PHP_AUTH_USER'])
            && $_SERVER['PHP_AUTH_USER'] === 'stats-collector'
            && $_SERVER['PHP_AUTH_PW'] === md5($home_url_clear.'-'.get_option('leyka_stats_sipher_public_key'));

    }

    protected function _get_usage_stats(array $params = array()) {

        /** @todo Use Donations_Factory here */
        $query_params = array(
            'post_type' => Leyka_Donation_Management::$post_type,
            'post_status' => 'any',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => 'leyka_payment_type', 'value' => 'correction', 'compare' => '!='),
            ),
            'nopaging' => true,
        );
        if( !empty($params['timestamp_from']) && (int)$params['timestamp_from'] > 0 ) { // 'date_from' must be a timestamp

            $query_params['date_query']['after'] = date('Y-m-d H:i:s', (int)$params['timestamp_from']);
            $query_params['date_query']['inclusive'] = true;

            if( !empty($params['period']) ) { // Must be strtotime()-compatible string w/o sign (1 hour, 2 weeks, 3 months, ...)

                $params['period'] = str_replace(array('+', '-'), '', $params['period']);

                $query_params['date_query']['before'] = date(
                    'Y-m-d H:i:s', strtotime($query_params['date_query']['after'].' +'.$params['period'])
                );

            }

        }

        if( !empty($query_params['date_query']) ) {
            $query_params['date_query'] = array($query_params['date_query']);
        }

        $stats = array('donations' => array(),) + leyka_get_env_and_options();

        foreach(get_posts($query_params) as $donation) {

            $donation = new Leyka_Donation($donation);

            $donations_by_status = array();
            foreach(leyka_get_donation_status_list() as $status => $label) {
                $donations_by_status[$status] = 0;
            }

            if(empty($stats['donations'][$donation->gateway][$donation->pm])) {
                $stats['donations'][$donation->gateway][$donation->pm] = array(
                    'main_currency' => 'RUB',
                    'amount_collected' => 0.0, // In main currency
                    'donations_count' => 0,
                    'donations_by_status_count' => $donations_by_status,
                );
            }

            if($donation->status === 'funded') {
                $stats['donations'][$donation->gateway][$donation->pm]['amount_collected'] += $donation->main_curr_amount;
            }

            $stats['donations'][$donation->gateway][$donation->pm]['donations_count'] += 1;
            $stats['donations'][$donation->gateway][$donation->pm]['donations_by_status_count'][$donation->status] += 1;

        }

        return $stats;

    }

    public function addPaymentFormError(WP_Error $error) {
        $this->_form_errors[] = $error;
    }
    /**
     * @deprecated
     * @param $error WP_Error
     */
    public function add_payment_form_error(WP_Error $error) {
        return $this->addPaymentFormError($error);
    }

    /** @return bool */
    public function paymentFormHasErrors() {
        return count($this->_form_errors) > 0;
    }
    /** @deprecated */
    public function payment_form_has_errors() {
        return $this->paymentFormHasErrors();
    }

    /** @return array Of WP_Error instances, if any. */
    public function getPaymentFormErrors() {
        return $this->_form_errors;
    }
    /** @deprecated */
    public function get_payment_form_errors() {
        return $this->getPaymentFormErrors();
    }

    /**
     * Wrapper to work with leyka_errors session var.
     * @param bool $anew
     */
    private function _addSessionErrors($anew = false) {
        if(empty($_SESSION['leyka_errors']) || $anew) {
            $_SESSION['leyka_errors'] = $this->getPaymentFormErrors();
        } else {
            $_SESSION['leyka_errors'] = array_merge($_SESSION['leyka_errors'], $this->getPaymentFormErrors());
        }
    }
    /**
     * @deprecated
     * @param $anew boolean
     */
    private function _add_session_errors($anew = false) {
        return $this->_addSessionErrors($anew);
    }

    /** @return bool */
    public function hasSessionErrors() {
        return !empty($_SESSION['leyka_errors']) && count($_SESSION['leyka_errors']);
    }
    /** @deprecated */
    public function has_session_errors() {
        return $this->hasSessionErrors();
    }

    /** @return array */
    public function getSessionErrors() {
        return empty($_SESSION['leyka_errors']) ? array() : $_SESSION['leyka_errors'];
    }
    /** @deprecated */
    public function get_session_errors() {
        return $this->getSessionErrors();
    }

    public function clearSessionErrors() {
        $_SESSION['leyka_errors'] = array();
    }
    /** @deprecated */
    public function clear_session_errors() {
        return $this->clearSessionErrors();
    }

    public function getDonationTypes() {
        return apply_filters('leyka_donation_types', array(
            'single' => _x('Single', '"Single" donation type name', 'leyka'),
            'recurring' => _x('Recurring', '"Recurring" donation type name', 'leyka'),
            'correction' => _x('Correction', '"Correction" donation type name', 'leyka'),
        ));
    }

    /**
     * Retrieve all available payment/donation statuses.
     *
     * @return array of status_id => status label pairs
     */
    public function getDonationStatuses() {
        return apply_filters('leyka_donation_statuses', array(
            'submitted' => _x('Submitted', '«Submitted» donation status', 'leyka'),
            'funded'    => _x('Funded', '«Completed» donation status', 'leyka'),
            'refunded'  => _x('Refunded', '«Refunded» donation status', 'leyka'),
            'failed'    => _x('Failed', '«Failed» donation status', 'leyka'),
            'trash'     => _x('Trash', '«Deleted» donation status', 'leyka'),
        ));
    }
    /** @deprecated */
    public function get_donation_statuses() {
        return $this->getDonationStatuses();
    }

    /**
     * Retrieve all available payment/donation statuses' descriptions.
     *
     * @return array of status_id => status_description pairs
     */
    public function getDonationStatusesDescriptions() {
        return apply_filters('leyka_donation_statuses_descriptions', array(
            'submitted' => _x("Donation attempt was made, but the payment itself wasn't sent.", '«Submitted» donation status description', 'leyka'),
            'funded' => _x('Donation was finished, the funds were made to your account.', '«Completed» donation status description', 'leyka'),
            'refunded' => _x('Donation funds were returned to the donor.', '«Refunded» donation status description', 'leyka'),
            'failed' => _x("Donation payment was finished with an error. The funds weren't sent.", '«Failed» donation status description', 'leyka'),
            'trash' => _x('Donation information was deleted.', '«Trash» donation status description', 'leyka'),
        ));
    }
    /** @deprecated */
    public function get_donation_statuses_descriptions() {
        return $this->getDonationStatusesDescriptions();
    }

    /**
     * Retrieve all available campaign target states.
     *
     * @return array of state_id => state label pairs
     */
    public function getCampaignTargetStates() {
        return apply_filters('leyka_campaign_target_states', array(
            'no_target'   => _x('No target', 'Campaign state when target is not set', 'leyka'),
            'is_reached'  => _x('Reached', 'Campaign state when target is reached', 'leyka'),
            'in_progress' => _x('In progress', 'Campaign state when target is not reached yet', 'leyka'),
        ));
    }
    /** @deprecated */
    public function get_campaign_target_states() {
        return $this->getCampaignTargetStates();
    }

    /**
     * @return array Of Leyka_Gateway objects.
     */
    public function getGateways() {
        return $this->_gateways;
    }
    /**
     * @deprecated
     * @return array Of Leyka_Gateway objects.
     */
    public function get_gateways() {
        return $this->getGateways();
    }

    /**
     * @param Leyka_Gateway $gateway
     * @return bool
     */
    public function addGateway(Leyka_Gateway $gateway) {

        if(empty($this->_gateways[$gateway->id])) {

            $this->_gateways[$gateway->id] = $gateway;
            return true;

        } else {
            return false;
        }

    }
    /**
     * @deprecated
     * @param Leyka_Gateway $gateway
     * @return bool
     */
    public function add_gateway(Leyka_Gateway $gateway) {
        return $this->addGateway($gateway);
    }

    public function removeGateway($gateway_id) {
        if( !empty($this->_gateways[$gateway_id]) ) {
            unset($this->_gateways[$gateway_id]);
        }
    }
    /** @deprecated */
    public function remove_gateway($gateway_id) {
        $this->removeGateway($gateway_id);
    }

    /** Fired when the plugin is activated or when an update is needed. */
    public static function activate() {

        $leyka_last_ver = get_option('leyka_last_ver');

        if($leyka_last_ver && $leyka_last_ver == LEYKA_VERSION) { // Already at last version
            return;
        }

        if( !$leyka_last_ver || $leyka_last_ver < '2.1' ) {

            /** Upgrade options structure in the DB */
            if(get_option('leyka_modules')) {
                delete_option('leyka_modules');
            }

            if(get_option('leyka_options_installed')) {
                delete_option('leyka_options_installed');
            }

            foreach(leyka_options()->get_options_names() as $name) {

                $option = get_option("leyka_$name");
                if(is_array($option) && isset($option['type']) && isset($option['title'])) { // Update option data
                    update_option("leyka_$name", $option['value']);
                }

            }

            /** Upgrade gateway and PM options structure in the DB */
            foreach(leyka_get_gateways() as $gateway) {

                /** @var $gateway Leyka_Gateway */
                delete_option("leyka_{$gateway->id}_payment_methods");

                foreach($gateway->get_options_names() as $name) {

                    $option = get_option("leyka_$name");

                    if(is_array($option) && isset($option['type']) && isset($option['title'])) { // Update option data
                        update_option("leyka_$name", $option['value']);
                    }

                }

                foreach($gateway->get_payment_methods() as $pm) {

                    /** @var $pm Leyka_Payment_Method */
                    foreach($pm->get_pm_options_names() as $name) {

                        $option = get_option("leyka_$name");
                        if(is_array($option) && isset($option['type']) && isset($option['title'])) // Update option data
                            update_option("leyka_$name", $option['value']);
                    }

                }

            }

        }

        if( !$leyka_last_ver || $leyka_last_ver <= '2.2.5' ) {

            // Initialize pm_order option if needed:
            if( !get_option('leyka_pm_order') ) {

                $pm_order = array();
                foreach((array)get_option('leyka_pm_available') as $pm_full_id) {
                    if($pm_full_id) {
                        $pm_order[] = "pm_order[]={$pm_full_id}";
                    }
                }

                update_option('leyka_pm_order', implode('&', $pm_order));

            }

            /** @todo Check if this code is needed! */
            // Remove unneeded scripts for settings pages:
            $settings_pages_dir = dir(LEYKA_PLUGIN_DIR.'inc/settings-pages/');
            while(false !== ($script = $settings_pages_dir->read())) {

                if(
                    $script !== '.' && $script !== '..' &&
                    !in_array($script, array(
                        'leyka-settings-common.php',
                        'leyka-settings-payment.php',
//                        'leyka-settings-payment-old.php',
                        'leyka-settings-payment-gateway.php',
                        'leyka-settings-payment-gateways-list.php',
                        'leyka-settings-payment-pm-order.php',
                    ))
                ) {
                    unlink(LEYKA_PLUGIN_DIR.'inc/settings-pages/'.$script);
                }
            }
            $settings_pages_dir->close();

            // Remove an obsolete plugin options:
            $options = array(
                array('old' => 'chronopay_card_description', 'new' => 'chronopay-chronopay_card_description'),
                array('old' => 'chronopay_card_rebill_description', 'new' => 'chronopay-chronopay_card_rebill_description'),
                array('old' => 'bank_order_description', 'new' => 'quittance-bank_order_description'),
                array('old' => 'bankcard_description', 'new' => 'rbk-bankcard_description'),
                array('old' => 'rbkmoney_description', 'new' => 'rbk-rbkmoney_description'),
                array('old' => 'rbk_all_description', 'new' => 'rbk-rbk_all_description'),
                array('old' => 'robokassa_card_description', 'new' => 'robokassa-BANKOCEAN2_description'),
                array('old' => 'robokassa_yandex_money_description', 'new' => 'robokassa-YandexMerchantOcean_description'),
                array('old' => 'robokassa_webmoney_description', 'new' => 'robokassa-WMR_description'),
                array('old' => 'robokassa_qiwi_description', 'new' => 'robokassa-Qiwi30Ocean_description'),
                array('old' => 'robokassa_all_description', 'new' => 'robokassa-Other_description'),
                array('old' => 'text_box_description', 'new' => 'text-text_box_description'),
                array('old' => 'yandex_card_description', 'new' => 'yandex-yandex_card_description'),
                array('old' => 'yandex_money_description', 'new' => 'yandex-yandex_money_description'),
                array('old' => 'yandex_wm_description', 'new' => 'yandex-yandex_wm_description'),
                array('old' => 'yandex_phyz_card_description', 'new' => 'yandex_phyz-yandex_phyz_card_description'),
                array('old' => 'yandex_phyz_money_description', 'new' => 'yandex_phyz-yandex_phyz_money_description'),
            );
            foreach($options as $option) {

                $old_value = get_option("leyka_{$option['old']}");
                $new_value = get_option("leyka_{$option['new']}");

                if($old_value && $old_value != $new_value) {
                    update_option("leyka_{$option['new']}", $old_value);
                }

                delete_option("leyka_{$option['old']}");
            }

        }

        /**
         * Fix the bug when total_funded amount of campaign was calculated incorrectly
         * if there were correctional donations for that campaign.
         */
        if($leyka_last_ver && $leyka_last_ver >= '2.2.5' && $leyka_last_ver <= '2.2.7.2') {

            function leyka_update_campaigns_total_funded() {

                set_time_limit(3600);
                wp_suspend_cache_addition(true);

                $campaigns = get_posts(array(
                    'post_type' => Leyka_Campaign_Management::$post_type,
                    'nopaging' => true,
                    'post_status' => 'any'
                ));
                foreach($campaigns as $campaign) {

                    $campaign = new Leyka_Campaign($campaign);
                    $campaign->updateTotalFundedAmount();
                }

                wp_suspend_cache_addition(false);

            }
            add_action('init', 'leyka_update_campaigns_total_funded', 100);

        }

        /** Fix the typo in one option's name */
        if($leyka_last_ver && $leyka_last_ver <= '2.2.7.2') {

            update_option('leyka_agree_to_terms_needed', get_option('leyka_argee_to_terms_needed'));
            delete_option('leyka_argee_to_terms_needed');

        }

        /** Fix the CloudPayments callbacks' IPs */
        if($leyka_last_ver && $leyka_last_ver <= '2.2.10') {
            update_option('leyka_cp_ip', '130.193.70.192,185.98.85.109');
        }

        if($leyka_last_ver && $leyka_last_ver <= '2.2.12.2') {
            delete_option('agree_to_terms_text'); // From now on, "agree to Terms" text field is separated in two new settings
        }

        if($leyka_last_ver && $leyka_last_ver <= '2.2.14') {
            if(in_array('chronopay-chronopay_card_rebill', (array)get_option('leyka_pm_available'))) {

                $pm_order_parts = explode('&', get_option('leyka_pm_order'));
                $key = array_search('chronopay-chronopay_card_rebill', $pm_order_parts);

                if($key !== false) {

                    unset($pm_order_parts[$key]);
                    update_option('leyka_pm_order', implode('&', $pm_order_parts));

                }

            }
        }

        if( !$leyka_last_ver || $leyka_last_ver < '3.0' ) {
            
            if(defined('KND_VERSION') && class_exists( 'TGM_Plugin_Activation' )) {
              update_option('leyka_init_wizard_redirect', false);
            }
            else {
               update_option('leyka_init_wizard_redirect', !$leyka_last_ver);
            }
            
            update_option('leyka_receiver_country', 'ru');
            update_option('leyka_receiver_legal_type', 'legal');

        }

        /** Set a flag to flush permalinks (needs to be done a bit later, than this activation itself): */
        update_option('leyka_permalinks_flushed', 0);

        update_option('leyka_last_ver', LEYKA_VERSION);

    }

    public static function deactivate() {
        delete_option('leyka_permalinks_flushed');
    }

    public function applyContentFormatting() {

        add_filter('leyka_the_content', 'wptexturize');
        add_filter('leyka_the_content', 'convert_smilies');
        add_filter('leyka_the_content', 'convert_chars');
        add_filter('leyka_the_content', 'wpautop');

    }
    /** @deprecated */
    public function apply_formatting_filters() {
        $this->applyContentFormatting();
    }

    /** Register and enqueue public-facing style sheet. */
    public function enqueue_styles() {

        if(stristr($_SERVER['REQUEST_URI'], 'leyka-process-donation') !== FALSE) { // Leyka service URL

            wp_enqueue_style(
                $this->_plugin_slug.'-redirect-styles',
                LEYKA_PLUGIN_BASE_URL.'css/gateway-redirect-page.css',
                array(),
                LEYKA_VERSION
            );
            return;

        }

        // Revo template or success/failure widgets styles:
        if(leyka_modern_template_displayed() || leyka_success_widget_displayed() || leyka_failure_widget_displayed()) {
            wp_enqueue_style(
                $this->_plugin_slug.'-revo-plugin-styles',
                LEYKA_PLUGIN_BASE_URL.'assets/css/public.css',
                array(),
                LEYKA_VERSION
            );
        }

        if( !leyka_form_is_screening() ) {
            return;
        }

        // Enqueue the normal Leyka CSS just in case some other plugin elements exist on page:
        wp_enqueue_style(
            $this->_plugin_slug.'-plugin-styles',
            LEYKA_PLUGIN_BASE_URL.'css/public.css',
            array(),
            LEYKA_VERSION
        );

    }

    /** Register and enqueue public-facing JavaScript files. */
    public function enqueue_scripts() {

        // Revo template or success/failure widgets JS:
        if(leyka_modern_template_displayed() || leyka_success_widget_displayed() || leyka_failure_widget_displayed()) {
            wp_enqueue_script(
                $this->_plugin_slug.'-revo-public',
                LEYKA_PLUGIN_BASE_URL.'assets/js/public.js',
                array('jquery',),
                LEYKA_VERSION,
                true
            );
        }
        
        if( !leyka_form_is_screening() ) {
            return;
        }
        
        // Enqueue the normal Leyka scripts just in case some other plugin elements exist on page:
        wp_enqueue_script(
            $this->_plugin_slug.'-modal',
            LEYKA_PLUGIN_BASE_URL.'js/jquery.easyModal.min.js',
            array('jquery'),
            LEYKA_VERSION,
            true
        );

        wp_enqueue_script(
            $this->_plugin_slug.'-public',
            LEYKA_PLUGIN_BASE_URL.'js/public.js',
            array('jquery', $this->_plugin_slug.'-modal'),
            LEYKA_VERSION,
            true
        );

        do_action('leyka_enqueue_scripts'); // Allow the gateways to add their own scripts

    }

    public function localizeScripts() {

        $js_data = apply_filters('leyka_js_localized_strings', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'homeurl' => home_url('/'),
            'correct_donation_amount_required' => __('Donation amount must be specified to submit the form', 'leyka'),
            'donation_amount_too_great' => __('Donation amount you entered is too great (maximum %s allowed)', 'leyka'),
            'donation_amount_too_small' => __('Donation amount you entered is too small (minimum %s allowed)', 'leyka'),
            'amount_incorrect' => __('Set an amount from %s to %s <span class="curr-mark">%s</span>', 'leyka'),
            'donor_name_required' => __('Enter your name', 'leyka'),
            'oferta_agreement_required' => __('You have to agree with the terms of the donation service', 'leyka'),

            'checkbox_check_required' => __('This checkbox must be checked to submit the form', 'leyka'),
            'text_required' => __('This field must be filled to submit the form', 'leyka'),
            'email_required' => __('Email must be filled to submit the form', 'leyka'),
            'email_invalid' => __('Enter an email in the some@email.com format', 'leyka'),
            'must_not_be_email' => __("You shouldn't enter an email here", 'leyka'),
            'value_too_long' => __('Entered value is too long', 'leyka'),
        ));

        $leyka_js_handle = wp_script_is($this->_plugin_slug.'-public') ?
            $this->_plugin_slug.'-public' :
            (wp_script_is($this->_plugin_slug.'-revo-public') ? $this->_plugin_slug.'-revo-public' : '');

        wp_localize_script(apply_filters('leyka_js_localized_script_id', $leyka_js_handle), 'leyka', $js_data);

    }
    /** @deprecated */
    public function localize_scripts() {
        $this->localizeScripts();
    }

    /** Register leyka user roles and caps. */
    public function registerUserCapabilities() {

        $role = get_role('administrator'); // Just in case. There were some exotic cases..
        if( !$role ) {
            return;
        }

        // Create all roles and capabilities:
        $caps = array(
            'read' => true, 'edit_#base#' => true, 'read_#base#' => true, 'delete_#base#' => true,
            'edit_#base#s' => true, 'edit_others_#base#s' => true, 'publish_#base#s' => true,
            'read_private_#base#s' => true, 'delete_#base#s' => true, 'delete_private_#base#s' => true,
            'delete_published_#base#s' => true, 'delete_others_#base#s' => true,
            'edit_private_#base#s' => true, 'edit_published_#base#s' => true,
            'upload_files' => true, 'unfiltered_html' => true, 'leyka_manage_donations' => true,
        );

        foreach($caps as $cap => $true) {

            $cap_donation = str_replace('#base#', 'donation', $cap);

            if(empty($role->capabilities[$cap_donation])) {
                $role->add_cap($cap_donation, true);
            }

            $caps[$cap_donation] = true;

            $cap_campaign = str_replace('#base#', 'campaign', $cap);

            if(empty($role->capabilities[$cap_campaign])) {
                $role->add_cap($cap_campaign, true);
            }

            $caps[$cap_campaign] = true;

            if(stristr($cap, '#base#') !== false) {
                unset($caps[$cap]);
            }

        }

        if(empty($role->capabilities['leyka_manage_options'])) {
            $role->add_cap('leyka_manage_options', true);
        }

        if( !get_role('donations_manager') ) {
            add_role('donations_manager', __('Donations Manager', 'leyka'), $caps);
        }
        if( !get_role('donations_administrator') ) {
            add_role(
                'donations_administrator',
                __('Donations Administrator', 'leyka'),
                array_merge($caps, array('leyka_manage_options' => true,))
            );
        }

        if(leyka_options()->opt('donor_accounts_available')) { // Donor role
            if( !get_role('donor') ) {
                add_role('donor', __('Donor', 'leyka'), array('access_donor_account_desktop'));
            }
        }

    }
    /** @deprecated */
    public function register_user_capabilities() {
        $this->registerUserCapabilities();
    }

    /**
     * Register leyka post types.
     */
    public function registerPostTypes(){

        // Donations:
        $args = array(
            'label' => __('Donations', 'leyka'),
            'labels' => array(
                'name'          => __('Donations', 'leyka'),
                'singular_name' => __('Donation', 'leyka'),
                'menu_name'     => __('Donations', 'leyka'),
                'all_items'     => __('Donations', 'leyka'),
                'add_new'       => __('Add correctional donation', 'leyka'),
                'add_new_item'  => __('Add correctional donation', 'leyka'),
                'edit_item'     => __('Donation profile', 'leyka'),
                'new_item'      => __('New donation', 'leyka'),
                'view_item'     => __('View donation', 'leyka'),
                'search_items'  => __('Search donation', 'leyka'),
                'not_found'     => __('Donations not found', 'leyka'),
                'not_found_in_trash' => __('Donations not found in Trash', 'leyka')
            ),
            'exclude_from_search' => true,
            'public' => true,
            'show_ui' => true,
            'show_in_nav_menus' => false,
            'show_in_menu' => false,
            'show_in_admin_bar' => false,
            'supports' => false,
            'taxonomies' => array(),
            'has_archive' => 'donations',
            'capability_type' => array('donation', 'donations'),
            'map_meta_cap' => true,
            'rewrite' => array('slug' => 'donation', 'with_front' => false),
            'show_in_rest' => false, // True to use Gutenberg editor, false otherwise
        );

        register_post_type(Leyka_Donation_Management::$post_type, $args);

        // Donation editing messages:
        add_filter('post_updated_messages', array(Leyka_Donation_Management::get_instance(), 'set_admin_messages'));

        // Campaigns:
        $args = array(
            'labels' => array(
                'name'          => __('Campaigns', 'leyka'),
                'singular_name' => _x('Campaign', 'In genitive case', 'leyka'),
                'menu_name'     => __('Campaigns', 'leyka'),
                'all_items'     => __('All Campaigns', 'leyka'),
                'add_new'       => __('New campaign', 'leyka'),
                'add_new_item'  => __('Add new campaign', 'leyka'),
                'edit_item'     => __('Edit campaign', 'leyka'),
                'new_item'      => __('New campaign', 'leyka'),
                'view_item'     => __('View campaign', 'leyka'),
                'search_items'  => __('Search campaigns', 'leyka'),
                'not_found'     => __('Campaigns not found', 'leyka'),
                'not_found_in_trash' => __('Campaigns not found in Trash', 'leyka')
            ),
            'exclude_from_search' => false,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_menu' => false,
            'show_in_admin_bar' => true,
            'supports' => array('title', 'editor', 'thumbnail', 'revisions',),
            'taxonomies' => array(),
            'has_archive' => true,
            'capability_type' => array('campaign', 'campaigns'),
            'map_meta_cap' => true,
            'rewrite' => array('slug' => 'campaign', 'with_front' => false),
            'show_in_rest' => false, // True to use Gutenberg editor, false otherwise
        );

        register_post_type(Leyka_Campaign_Management::$post_type, $args);

        /** Campaign editing messages */
        add_filter('post_updated_messages', array(Leyka_Campaign_Management::getInstance(), 'setAdminMessages'));

        register_post_status('submitted', array(
            'label'                     => _x('Submitted', '«Submitted» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Submitted <span class="count">(%s)</span>', 'Submitted <span class="count">(%s)</span>', 'leyka'),
        ));

        register_post_status('funded', array(
            'label'                     => _x('Funded', '«Completed» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Funded <span class="count">(%s)</span>', 'Funded <span class="count">(%s)</span>', 'leyka'),
        ));

        register_post_status('refunded', array(
            'label'                     => _x('Refunded', '«Refunded» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Refunded <span class="count">(%s)</span>', 'Refunded <span class="count">(%s)</span>', 'leyka'),
        ));

        register_post_status('failed', array(
            'label'                     => _x('Failed', '«Failed» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'leyka'),
        ));

        do_action('leyka_cpt_registered');

    }
    /** @deprecated */
    public function register_post_types() {
        $this->registerPostTypes();
    }

    /**
     * Add the plugin's rules themselves.
     * @var $rules array
     * @return array
     */
    public function insertRewriteRules(array $rules) {
        return array(
            'campaign/([^/]+)/donations/?$' => 'index.php?post_type='.Leyka_Donation_Management::$post_type.'&leyka_campaign_filter=$matches[1]',
            'campaign/([^/]+)/donations/page/([1-9]{1,})/?$' =>
                'index.php?post_type='.Leyka_Donation_Management::$post_type.'&leyka_campaign_filter=$matches[1]&paged=$matches[2]',
            'campaign/([^/]+)/cabinet/my-donations/?$' => 'index.php?post_type='.Leyka_Donation_Management::$post_type.'&leyka_campaign_filter=$matches[1]&leyka-screen=history',
        ) + $rules; // The rules' order is important
    }
    /**
     * @deprecated
     * @param array $rules
     * @return array
     */
    public function insert_rewrite_rules(array $rules) {
        return $this->insertRewriteRules($rules);
    }

    /**
     * Add the special query var to indicate a donations archive filtering by particular campaign.
     * @var $vars array
     * @return array
     */
    public function insertRewriteQueryVars(array $vars) {

        $vars[] = 'leyka_campaign_filter';
        $vars[] = 'leyka-screen';
        return $vars;

    }
    /**
     * @deprecated
     * @var $vars array
     * @return array
     */
    public function insert_rewrite_query_vars(array $vars) {
        return $this->insertRewriteQueryVars($vars);
    }

    /**
     * Payment form submissions.
     */
    public function gateway_redirect_page() {

        if(stristr($_SERVER['REQUEST_URI'], 'leyka-process-donation')) {

            if(empty($_POST)) {

                wp_redirect(site_url());
                exit();

            }

			if(is_admin_bar_showing()) { // Hide adminbar (toolbar) if needed
				add_filter('show_admin_bar', '__return_false');
			}

            add_filter('document_title_parts', 'leyka_remove_gateway_redirect_title', 10);
            function leyka_remove_gateway_redirect_title($title_parts){

                $title_parts['title'] = __('Redirecting to the gateway payment page', 'leyka');
                return $title_parts;

            }

            do_action('leyka_init_gateway_redirect_page');

            $this->_handlePaymentFormSubmit();

            if($this->paymentFormHasErrors() || !$this->_payment_url) {

                $this->_addSessionErrors(); // Error handling

                $referer = wp_get_referer();
                if(strstr($referer, '#') !== false) {
                    $referer = reset(explode('#', $referer));
                }

                wp_redirect($referer.'#leyka-submit-errors');

            } else {

                header('HTTP/1.1 200 OK');

                require_once(LEYKA_PLUGIN_DIR.'templates/service/leyka-gateway-redirect-page.php');
                exit();

            }

        }

    }

    public function _handlePaymentFormSubmit() {

        $this->clearSessionErrors(); // Clear all previous submits errors, if there are some

        $form_errors = Leyka_Payment_Form::is_form_fields_valid();

        if(is_array($form_errors) && count($form_errors) > 0) {

            foreach($form_errors as $error) { /** @var WP_Error $error */
                $this->addPaymentFormError($error);
            }

            return;

        }

        $donation_id = $this->log_submission();

        if(is_wp_error($donation_id)) { /** @var WP_Error $donation_id */

            $this->addPaymentFormError($donation_id);
            return;

        } else if( !$donation_id ) {

            $error = new WP_Error('unknown_donation_submit_error', __('The donation was not created due to error.', 'leyka'));
            $this->addPaymentFormError($error);
            return;

        }

        leyka_remember_donation_data(array('donation_id' => $donation_id));

        $pm = leyka_pf_get_payment_method_value();

        do_action(
            'leyka_payment_form_submission-'.$pm['gateway_id'],
            $pm['gateway_id'], $pm['payment_method_id'], $donation_id, $_POST
        );

        $this->_submitted_donation_id = $donation_id;

        $this->_payment_vars = apply_filters(
            'leyka_submission_form_data-'.$pm['gateway_id'],
            $this->_payment_vars, $pm['payment_method_id'], $donation_id
        );

        $this->_payment_url = apply_filters(
            'leyka_submission_redirect_url-'.$pm['gateway_id'],
            $this->_payment_url, $pm['payment_method_id']
        );

        $this->_submission_redirect_type = apply_filters(
            'leyka_submission_redirect_type-'.$pm['gateway_id'],
            'auto', $pm['payment_method_id'], $donation_id
        );

    }
    /** @deprecated */
    public function _do_payment_form_submission() {
        return $this->_handlePaymentFormSubmit();
    }

    /** Save the basic donation data and return new donation ID, so gateway can add it's specific data to the logs. */
    public function log_submission() {

        if(empty($_POST['leyka_campaign_id']) || (int)$_POST['leyka_campaign_id'] <= 0) {
            return false;
        }

        $campaign = new Leyka_Campaign((int)$_POST['leyka_campaign_id']);
        $pm_data = leyka_pf_get_payment_method_value();

        $donation_id = Leyka_Donation::add(apply_filters('leyka_new_donation_data', array(
            'purpose_text' => $campaign->payment_title,
            'gateway_id' => $pm_data['gateway_id'],
        )));

        if(is_wp_error($donation_id)) {
            return $donation_id;
        } else {

            $campaign->increaseSubmitsCounter();

            do_action('leyka_log_donation', $pm_data['gateway_id'], $pm_data['payment_method_id'], $donation_id);
            do_action('leyka_log_donation-'.$pm_data['gateway_id'], $donation_id);

            return $donation_id;

        }

    }

    /**
     * Templates manipulations.
     *
     * @param $is_service boolean True if templates is of service group, false otherwise.
     * @return array Template files.
     **/
    public function getTemplates($is_service = false) {

        if( !$this->templates ) {
            $this->templates = array();
        }

        if( !!$is_service ) {
            $this->templates = glob(LEYKA_PLUGIN_DIR.'templates/service/leyka-template-*.php');
        } else {

            $custom_templates = glob(STYLESHEETPATH.'/leyka-template-*.php');
            $custom_templates = $custom_templates ? $custom_templates : array();

            $this->templates = apply_filters(
                'leyka_templates_list',
                array_merge($custom_templates, glob(LEYKA_PLUGIN_DIR.'templates/leyka-template-*.php'))
            );

        }

        if( !$this->templates ) {
            $this->templates = array();
        }

        $this->templates = array_map(array($this, 'getTemplateData'), $this->templates);

        // Templates ordering:
        $ordered_templates = array();

        foreach($this->_templates_order as $ordered_template) {
            foreach($this->templates as $template_data) {
                if($template_data['id'] == $ordered_template) {
                    $ordered_templates[] = $template_data;
                }
            }
        }

        foreach($this->templates as $template_data) {
            if( !in_array($template_data['id'], $this->_templates_order) ) {
                $ordered_templates[] = $template_data;
            }
        }
        $this->templates = $ordered_templates;

        return (array)$this->templates;

    }
    /**
     * @deprecated
     * @param $is_service boolean
     * @return array
     */
    public function get_templates($is_service = false) {
        return $this->getTemplates($is_service);
    }


    public function getTemplateData($file) {

        $data = get_file_data($file, array(
            'name' => 'Leyka Template',
            'description' => 'Description',
            'debug_only' => 'Debug only',
        ));

        $data['file'] = $file;
        $data['basename'] = basename($file);

        $id = explode('-', str_replace('.php', '', $data['basename']));
        $data['id'] = end($id); // Otherwise error appears in php 5.4.x

        if(empty($data['name'])) {
            $data['name'] = $data['basename'];
        }

        return $data;

    }
    /**
     * @deprecated
     * @param $file string
     * @return array
     */
    public function get_template_data($file) {
        return $this->getTemplateData($file);
    }

    public function getTemplate($basename, $is_service = false) {

        $templates = $this->getTemplates($is_service);
        if( !$templates ) {
            return false;
        }

        $active = '';
        foreach($templates as $template) {

            $cur_basename = explode('-', str_replace('.php', '', $template['basename']));
            $cur_basename = end($cur_basename); // Otherwise error appears in PHP 5.4.x
            if($cur_basename == $basename) {

                $active = $template;
                break;

            }
        }

        return $active;

    }
    /**
     * @deprecated
     * @param $basename string
     * @param $is_service boolean
     * @return string
     */
    public function get_template($basename, $is_service = false) {
        return $this->getTemplate($basename, $is_service);
    }

}

/**
 * @return Leyka Core object
 */
function leyka() {
    return Leyka::getInstance();
}

/** Orphan strings to localize */
__('Radios', 'leyka');
__('Radio options for each payment method', 'leyka');
__('Toggles', 'leyka');
__('Toggled options for each payment method', 'leyka');
__('Neo', 'leyka');
__('An updated version of "Toggles" form template', 'leyka');
__('Revo', 'leyka');
__('A modern and lightweight step-by-step form template', 'leyka');
__('single', 'leyka');
__('rebill', 'leyka');
__('correction', 'leyka');
__('The donations management system for your WP site', 'leyka');
__('Teplitsa of Social Technologies', 'leyka');
__('Star', 'leyka');
__('A modern and lightweight form template', 'leyka');
