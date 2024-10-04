<?php

GFForms::include_feed_addon_framework();

class GFKlaviyoAPI extends GFFeedAddOn {

	protected $_version = GF_KLAVIYO_API_VERSION;
	protected $_min_gravityforms_version = '2.4';
	protected $_slug = 'klaviyoaddon';
	protected $_path = 'klaviyoaddon/klaviyoaddon.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms Klaviyo Feed Add-On';
	protected $_short_title = 'Klaviyo';

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GFKlaviyoAPI
	 */
	public static function get_instance() {
		if (self::$_instance == null) {
			self::$_instance = new GFKlaviyoAPI();
		}

		return self::$_instance;
	}



	// # FEED PROCESSING -----------------------------------------------------------------------------------------------

	/**
	 * Process the feed e.g. subscribe the user to a list.
	 *
	 * @param array $feed The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form The form object currently being processed.
	 *
	 * @return bool|void
	 */
	public function process_feed($feed, $entry, $form) {
		$list_id = rgars($feed, 'meta/list_id');
		$contactStandard = $this->get_field_map_fields($feed, 'contactStandardFields');
		$contactStandard_value = $this->get_all_field_standard_value($form, $entry, $contactStandard);

		$merge_vars = array_merge($contactStandard_value);
		$args = array(
			'list_id'	=> $list_id,
			'contactStandard'	=> $contactStandard,
			'contactStandard_value' 	=> $contactStandard_value,
			'merge_vars'	=> $merge_vars,
		);

		$this->log_debug(__METHOD__ . '(): Data Feed =>' . print_r($args, true));

		if (empty($merge_vars['email'])) {
			$this->log_debug(__METHOD__ . '(): Fail! Email are required.');
			return;
		}

		$get_profiles = $this->check_email_profile($merge_vars['email']);
		if ($get_profiles === false) {
			$this->log_debug(__METHOD__ . '(): An error occurred while querying the data, ending the data sending process.');
			return;
		}

		if (!empty($get_profiles)) {
			$profile = $get_profiles[0];
			$profile_id = $profile['id'];
			$this->update_profile($profile_id, $args);
			$this->update_profile_to_list($profile_id, $args);
			$this->update_consent($profile_id, $args);
		} else {
			$profile = $this->create_profile($args);
			if (!empty($profile)) {
				$profile_id = $profile['id'];
				$this->update_profile_to_list($profile_id, $args);
				$this->update_consent($profile_id, $args);
			}
		}
	}


	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------


	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => esc_html__('Insert your Klaviyo API keys below to connect. You can find them on your Klaviyo account page.', 'klaviyoaddon'),
				'fields' => array(
					array(
						'name'    => 'private_api_key',
						'label'   => esc_html__('Private API Key', 'klaviyoaddon'),
						'type'    => 'text',
						'class'   => 'medium',
						'input_type'	=> 'password',
					),
				),
			),
		);
	}

	/**
	 * Configures the settings which should be rendered on the feed edit page in the Form Settings > Klaviyo area.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$feed_info = array(
			'title'  => esc_html__('Feed Settings', 'klaviyoaddon'),
			'fields' => array(
				array(
					'label'   => esc_html__('Feed name', 'klaviyoaddon'),
					'type'    => 'text',
					'name'    => 'feedName',
					'class'   => 'small',
					'tooltip'  => '<h6>' . esc_html__('Name', 'klaviyoaddon') . '</h6>' . esc_html__('Enter a feed name to uniquely identify this setup.', 'klaviyoaddon')
				),
			),
		);

		$klaviyo_lists = array(
			'title'  => esc_html__('Add Klaviyo List', 'klaviyoaddon'),
			'fields' => array(
				array(
					'type'    => 'select',
					'name'    => 'list_id',
					// 'label'    => esc_html__('Add Klaviyo List', 'klaviyoaddon'),
					'required'   => true,
					'choices' => $this->get_lists_klaviyo(),
				),
			),
			'tooltip'  => '<h6>' . esc_html__('Klaviyo List', 'klaviyoaddon') . '</h6>' . esc_html__('Select which Klaviyo list this feed will add contacts to.', 'klaviyoaddon')
		);

		$klaviyo_standard_field = array(
			'title'  => esc_html__('Standard Fields', 'klaviyoaddon'),
			'fields' => array(
				array(
					'name'      => 'contactStandardFields',
					// 'label'     => esc_html__('Contact Standard', 'klaviyoaddon'),
					'type'      => 'field_map',
					'field_map' => array(
						array(
							'name'       => 'email',
							'label'      => esc_html__('Email', 'klaviyoaddon'),
							'required'   => true,
							'field_type' => array('email', 'hidden'),
						),
						array(
							'name'     => 'first_name',
							'label'    => esc_html__('First Name', 'klaviyoaddon'),
							'required' => false
						),
						array(
							'name'     => 'last_name',
							'label'    => esc_html__('Last Name', 'klaviyoaddon'),
							'required' => false
						),
						array(
							'name'      => 'email_consent',
							'label'     => esc_html__('Email Consent (True/False)', 'klaviyoaddon'),
							'tooltip'	=> esc_html__('Default True. The green check mark consent status is only updated when Opt-in Process sets the option to Single opt-in in List Settings.', 'klaviyoaddon'),
						),

					),
				),
			),
		);

		$condition = array(
			'title'  => esc_html__('Condition', 'klaviyoaddon'),
			'fields' => array(
				array(
					'name'           => 'condition',
					// 'label'          => esc_html__('Condition', 'klaviyoaddon'),
					'type'           => 'feed_condition',
					'checkbox_label' => esc_html__('Enable Condition', 'klaviyoaddon'),
					'instructions'   => esc_html__('Process this feed if', 'klaviyoaddon'),
				),
			)
		);

		return array(
			$feed_info,
			$klaviyo_lists,
			$klaviyo_standard_field,
			$condition,
		);
	}

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @since 2.5
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return file_get_contents($this->get_base_path() . '/assets/images/klaviyo.svg');
	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'  => esc_html__('Feed Name', 'klaviyoaddon'),
			'list_id' => esc_html__('Klaviyo List', 'klaviyoaddon'),
		);
	}

	/**
	 * Get value pairs for all fields mapped.
	 *
	 * @return array
	 */
	public function get_all_field_standard_value($form, $entry, $list_id) {
		$data = array();
		foreach ($list_id as $name => $field_id) {
			$field = RGFormsModel::get_field($form, $field_id);
			if (isset($field) && isset($field['type'])) {
				if ($field['type'] == 'consent') {
					if ($name == 'email_consent' && $entry[$field_id] != "") {
						$data[$name] = '1';
					} else {
						$data[$name] = '';
					}
				} else {
					$data[$name] = $this->get_field_value($form, $entry, $field_id);
				}
			} else {
				$data[$name] = '';
			}
		}
		return $data;
	}

	/**
	 * Custom feed colum value.
	 *
	 * @return string
	 */
	public function get_column_value_list_id($feed) {
		$list_id = rgars($feed, 'meta/list_id');
		$validate_api = $this->validate_api();
		if (!$validate_api) return '';
		$lists = $this->get_lists_klaviyo();
		$list_label = 'No list is selected.';
		$list_key = array_search($list_id, array_column($lists, 'value'));
		if ($list_key !== false) {
			$list_label = $lists[$list_key]['label'];
		}
		return $list_label;
	}

	/**
	 * Override this function to allow the feed to being duplicated.
	 *
	 * @access public
	 * @param int|array $id The ID of the feed to be duplicated or the feed object when duplicating a form.
	 * @return boolean|true
	 */
	public function can_duplicate_feed($id) {
		return true;
	}

	/**
	 * Prevent feeds being listed or created if an api key isn't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		if (!$this->validate_api()) return false;
		return true;
	}

	/**
	 * Notify on feed if api key is invalid.
	 *
	 * @return bool|string
	 */
	public function feed_list_message() {
		$settings_label = sprintf(__('%s Settings', 'klaviyoaddon'), $this->get_short_title());
		$settings_link  = sprintf('<a href="%s">%s</a>', esc_url($this->get_plugin_settings_url()), $settings_label);
		if (!$this->validate_api()) {
			return sprintf(__('Private API Key is not correct, please configure your %s.', 'klaviyoaddon'), $settings_link);
		}
		return parent::feed_list_message();
	}

	/**
	 * Validate API.
	 *
	 * @return bool
	 */
	public function validate_api() {
		$api_endpoint = 'https://a.klaviyo.com/api/lists/';
		$responses = $this->request_api($api_endpoint, 'GET');
		$responses = json_decode(wp_remote_retrieve_body($responses), true);
		if (!empty($responses['errors'])) {
			return false;
		}
		return true;
	}

	/**
	 * Get all Lists in Klaviyo.
	 *
	 * @return array
	 */
	public function get_lists_klaviyo() {
		$api_endpoint = 'https://a.klaviyo.com/api/lists/';
		$lists = $this->get_list_klaviyo($api_endpoint);
		$sort = array_column($lists, 'label');
		array_multisort($lists, SORT_ASC, $sort, SORT_STRING);
		return $lists;
	}

	/**
	 * Get List recursive in Klaviyo.
	 *
	 * @return array
	 */
	public function get_list_klaviyo($api_endpoint) {
		$lists = array();
		$responses = $this->request_api($api_endpoint, 'GET');
		$responses = json_decode(wp_remote_retrieve_body($responses), true);
		if (!empty($responses['errors'])) {
			$this->log_debug(__METHOD__ . '(): Response data get list error =>' . print_r($responses['errors'], true));
			return $lists;
		}
		if (!empty($responses['data'])) {
			foreach ($responses['data'] as $list) {
				$lists[] = array(
					'label' => $list['attributes']['name'],
					'value' => $list['id'],
				);
			}
		}
		$next = rgars($responses, 'links/next');
		if (!empty($next)) {
			$list_page = $this->get_list_klaviyo($next);
			$lists = array_merge($lists, $list_page);
		}
		return $lists;
	}

	/**
	 * Check email profile.
	 *
	 * @return string|array
	 */
	public function check_email_profile($email) {
		$api_endpoint = "https://a.klaviyo.com/api/profiles/?filter=equals(email,'{$email}')";
		$this->log_debug(__METHOD__ . '(): Start check email in profile.');
		$responses = $this->request_api($api_endpoint, 'GET');
		$responses = json_decode(wp_remote_retrieve_body($responses), true);
		$this->log_debug(__METHOD__ . '(): Response data check email =>' . print_r($responses, true));
		if (!empty($responses['errors'])) {
			return false;
		}
		return $responses['data'];
	}

	/**
	 * Create Profile.
	 *
	 * @return array
	 */
	public function create_profile($args) {
		extract($args);
		$attributes = array(
			'email' => $merge_vars['email'],
			'first_name' => $merge_vars['first_name'],
		);
		$attributes['last_name'] = (!empty($merge_vars['last_name'])) ? $merge_vars['last_name'] : null;
		$api_endpoint = 'https://a.klaviyo.com/api/profiles/';
		$body = array(
			'data' => array(
				'type' => 'profile',
				'attributes' => $attributes,
			),
		);
		$this->log_debug(__METHOD__ . '(): Start create profile.');
		$this->log_debug(__METHOD__ . '(): Data create profile =>' . print_r($body, true));
		$profile = $this->request_api($api_endpoint, 'POST', $body);
		$profile = json_decode(wp_remote_retrieve_body($profile), true);
		$this->log_debug(__METHOD__ . '(): Response data create profile =>' . print_r($profile, true));
		if (!empty($profile['errors'])) {
			return false;
		}
		return $profile['data'];
	}

	/**
	 * Update Profile.
	 *
	 * @return array
	 */
	public function update_profile($profile_id, $args) {
		extract($args);
		$attributes = array(
			'first_name' => $merge_vars['first_name'],
		);
		$attributes['last_name'] = (!empty($merge_vars['last_name'])) ? $merge_vars['last_name'] : null;
		$api_endpoint = 'https://a.klaviyo.com/api/profiles/' . $profile_id;
		$body = array(
			'data' => array(
				'type' => 'profile',
				'id'	=> $profile_id,
				'attributes' => $attributes,
			),
		);
		$this->log_debug(__METHOD__ . '(): Start update profile.');
		$this->log_debug(__METHOD__ . '(): Data update profile =>' . print_r($body, true));
		$profile = $this->request_api($api_endpoint, 'PATCH', $body);
		$profile = json_decode(wp_remote_retrieve_body($profile), true);
		$this->log_debug(__METHOD__ . '(): Response data update profile =>' . print_r($profile, true));
		if (!empty($profile['errors'])) {
			return false;
		}
		return $profile['data'];
	}

	/**
	 * Update Profile To List.
	 *
	 */
	public function update_profile_to_list($profile_id, $args) {
		extract($args);
		if (empty($list_id)) {
			$this->log_debug(__METHOD__ . '(): An error occurred while querying the data, ending the data sending process. List Id = NULL.');
			return;
		}
		$api_endpoint = "https://a.klaviyo.com/api/lists/{$list_id}/relationships/profiles/";
		$body = array(
			'data' => array(
				array(
					'type' => 'profile',
					'id' => $profile_id,
				)
			)
		);
		$this->log_debug(__METHOD__ . '(): Start add profile to list "' . $list_id . '".');
		$this->log_debug(__METHOD__ . '(): Data add profile to list "' . $list_id . '" =>' . print_r($body, true));
		$responses = $this->request_api($api_endpoint, 'POST', $body);
		$responses = json_decode(wp_remote_retrieve_body($responses), true);
		if (!empty($responses['errors'])) {
			$this->log_debug(__METHOD__ . '(): Response data add profile to list "' . $list_id . '" fail" =>' . print_r($responses, true));
		}
		$this->log_debug(__METHOD__ . '(): Add profile to list "' . $list_id . '" success.');
	}

	/**
	 * Update consent.
	 *
	 */
	public function update_consent($profile_id, $args) {
		extract($args);
		$email_consent = !empty($contactStandard['email_consent']) ? $merge_vars['email_consent'] : true;
		$consent_enable = array('true', 'True', 'Yes', 'yes', 'Checked', 'checked', 'Selected', 'selected', 1);
		$consent_enable = apply_filters('klaviyoaddon_consent_value_default', $consent_enable);
		if (empty($list_id)) {
			$this->log_debug(__METHOD__ . '(): An error occurred while querying the data, ending the data sending process. List Id = NULL.');
			return;
		}

		$sub_attributes = array();

		if (in_array($email_consent, $consent_enable)) {
			$sub_attributes['email'] = $merge_vars['email'];
			$sub_attributes['subscriptions']['email'] = array('marketing' => array('consent' => 'SUBSCRIBED'));
		}

		$sub_body = array(
			'data' => array(
				'type' => 'profile-subscription-bulk-create-job',
				'attributes' => array(
					'custom_source' => 'Marketing Event',
					'profiles' => array(
						'data' => array(
							array(
								'type' => 'profile',
								'id' => $profile_id,
								'attributes' => $sub_attributes,
							)
						)
					)
				),
				'relationships' => array(
					'list' => array(
						'data' => array(
							'type' => 'list',
							'id' => $list_id
						)
					)
				)
			),
		);

		if (!empty($sub_attributes['email'])) {
			$this->subscribe_profiles($sub_body, $list_id);
		}
	}

	/**
	 * Subscribe Profiles.
	 *
	 * @return array
	 */
	public function subscribe_profiles($body, $list_id) {
		$api_endpoint = 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/';
		$this->log_debug(__METHOD__ . '(): Start subscribe profile in list "' . $list_id . '".');
		$this->log_debug(__METHOD__ . '(): Data subscribe profile in list "' . $list_id . '" =>' . print_r($body, true));
		$responses = $this->request_api($api_endpoint, 'POST', $body);
		$result = array();
		$result['response'] = $responses['response'];
		$result['body'] = $responses['body'];
		$this->log_debug(__METHOD__ . '(): Response on subscribe profile request in list "' . $list_id . '" => ' . print_r($result, true));
		return $responses;
	}

	/**
	 * Send request to API.
	 *
	 * @return array
	 */
	public function request_api($url, $method, $body = array()) {
		if (empty($url) || empty($method)) return '';
		$request = array();
		$request['method'] = $method;
		$request['headers'] = array(
			'Authorization' => 'Klaviyo-API-Key ' . $this->get_plugin_setting('private_api_key'),
			'accept' => 'application/json',
			'content-type' => 'application/json',
			'revision' => '2024-07-15',
		);
		$request['timeout'] = 300;
		if (!empty($body)) $request['body'] = json_encode($body);
		$response = wp_safe_remote_post($url, $request);
		return $response;
	}
}
