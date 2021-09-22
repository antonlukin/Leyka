<?php if ( !defined('WPINC') ) die;

/**
 * Leyka Extension: Unisender extension
 * Version: 0.1
 * Author: Teplitsa of social technologies
 * Author URI: https://te-st.ru
 **/


class Leyka_Unisender_Extension extends Leyka_Extension {

    protected static $_instance;

    protected function _set_attributes() {

        $this->_id = 'unisender';
        $this->_title = __('Unisender', 'leyka');
        $this->_description = __('This extension provides an integration with the "Unisender" service.', 'leyka');
        $this->_full_description = __('This extension provides an automatic subscrition of the donors to the chosen "Unisender" mailing lists.', 'leyka');
        $this->_settings_description = __('This extension provides an automatic subscrition of the donors to the chosen "Unisender" mailing lists.', 'leyka');
        $this->_connection_description = __(
            '<h4>Short instruction:</h4>
            <div>
                <ol>
                    <li>Register the "Unisender" account</li>
                    <li>Create one or more mailing lists and save their IDs in extension settings</li>
                    <li>Copy API key from "Unisender" personal account to extension settings</li>
                    <li>Select needed donor fields</li>
                </ol>
            </div>'
        , 'leyka'); // TODO Заменить до релиза
        $this->_user_docs_link = 'https://www.unisender.com/ru/support/'; // TODO Заменить до релиза
        $this->_has_wizard = false;
        $this->_has_color_options = false;
        $this->_icon = LEYKA_PLUGIN_BASE_URL.'extensions/unisender/img/main_icon.jpeg';

    }

    protected function _set_options_defaults() {

        $this->_options = apply_filters('leyka_'.$this->_id.'_extension_options', [
            $this->_id.'_api_key' => [
                'type' => 'text',
                'title' => __('API key', 'leyka'),
                'comment' => __('"Unisender" API key', 'leyka'),
                'required' => true,
                'is_password' => true,
                'placeholder' => 'abcdefghijklmnopqrstuvwxyz1234567890',
            ],
            $this->_id.'_lists_ids' => [
                'type' => 'text',
                'title' => __('IDs of the "Unisender" lists to subscribe', 'leyka'),
                'comment' => __('IDs of the lists (in "Unisender") that holds donors contacts', 'leyka'),
                'required' => true,
                'placeholder' => '1,3,10',
                'description' => __('Comma-separated IDs list', 'leyka')
            ],
            $this->_id.'_donor_fields' => [
                'type' => 'multi_select',
                'title' => __('Donor fields', 'leyka'),
                'required' => true,
                'comment' => __('Donor fields which will be transferred to "Unisender"', 'leyka'),
                'list_entries' => $this->_get_donor_fields(),
                'default' => ['name'], // 'default' should be an array of values (even if it's single value there)
            ],
            $this->_id.'_donor_confirmation' => [
                'type' => 'checkbox',
                'default' => true,
                'title' => __('Donor subscription confirmation', 'leyka'),
                'comment' => __('If enabled donors will be asked by email for permission upon subscribing on the list', 'leyka'),
                'short_format' => true
            ]
        ]);

    }

    protected function _get_donor_fields() {

        $fields_library = leyka_options()->get_value('additional_donation_form_fields_library');
        $additional_fields = ['name' => __('Name', 'leyka')];

        foreach ($fields_library as $name => $data) {
            $additional_fields[$name] = __($data['title'], 'leyka');
        }

        return $additional_fields;

    }

    protected function _initialize_active() {
        add_action('leyka_donation_funded_status_changed', [$this, 'add_donor_to_unisender_list'],11,3);
    }

    public function add_donor_to_unisender_list($donation_id, $old_status, $new_status) {

        if($old_status !== 'funded' && $new_status === 'funded') {

            require_once LEYKA_PLUGIN_DIR.'extensions/unisender/lib/UnisenderApi.php';

            $apikey = leyka_options()->get_value($this->_id.'_api_key');
            $donation = Leyka_Donations::get_instance()->get($donation_id);
            $lists_ids = str_replace(' ','',
                stripslashes(leyka_options()->get_value($this->_id.'_lists_ids')));
            $double_optin = leyka_options()->get_value($this->_id.'_donor_confirmation') === '1' ? 4 : 3;
            $donor_fields = ['email' => $donation->get_meta('leyka_donor_email')];

            foreach (leyka_options()->opt($this->_id.'_donor_fields') as $field_name) {

                if ( !empty($donation->get_meta('leyka_donor_'.$field_name)) ) {
                    $donor_fields[$field_name] = $donation->get_meta('leyka_donor_'.$field_name);
                } else {

                    $donation_additional_fields = $donation->get_meta('leyka_additional_fields');
                    $donor_fields[$field_name] = $donation_additional_fields[$field_name];

                }

            };

            $uni = new \Unisender\ApiWrapper\UnisenderApi($apikey);
            $uni->subscribe(['list_ids' => $lists_ids, 'fields' =>  $donor_fields, 'double_optin' => $double_optin]);

        }

    }

}

function leyka_add_extension_unisender() {
    leyka()->add_extension(Leyka_Unisender_Extension::get_instance());
}
add_action('leyka_init_actions', 'leyka_add_extension_unisender');