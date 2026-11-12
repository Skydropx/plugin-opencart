<?php
namespace Opencart\Admin\Controller\Extension\PLUGIN_NAMESPACE\Module;
/**
 * Class PLUGIN_NAMESPACE
 *
 * Settings page, API key management and install/uninstall of the PLUGIN_NAME
 * extension (tables, order event and the `PLUGIN_SLUG` shipping method).
 *
 * @package Opencart\Admin\Controller\Extension\PLUGIN_NAMESPACE\Module
 */
class PLUGIN_NAMESPACE extends \Opencart\System\Engine\Controller {
	private const EVENT_CODE = 'PLUGIN_SLUG_order_history';
	private const ALIAS_EVENT_CODE = 'PLUGIN_SLUG_api_alias';

	// Extension code the ecommerce service calls (`extension/<code>/api/...`).
	private const CONTRACT_CODE = 'skydropx';

	private const DEFAULT_SHIPPED_STATUS_ID = 3;
	private const DEFAULT_DELIVERED_STATUS_ID = 5;
	private const DEFAULT_CANCELLED_STATUS_IDS = [7, 8, 10, 11, 13, 14, 16];

	private const ORIGIN_FIELDS = ['name', 'owner', 'email', 'telephone', 'address', 'address_2', 'city', 'postcode'];

	public function index(): void {
		$this->load->language('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/PLUGIN_SLUG/module/PLUGIN_SLUG', $token)]
		];

		$data['save'] = $this->url->link('extension/PLUGIN_SLUG/module/PLUGIN_SLUG.save', $token);
		$data['regenerate'] = $this->url->link('extension/PLUGIN_SLUG/module/PLUGIN_SLUG.regenerate', $token, true);
		$data['connect'] = $this->url->link('extension/PLUGIN_SLUG/module/PLUGIN_SLUG.connect', $token, true);
		$data['zone_url'] = $this->url->link('localisation/country.country', $token, true);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=module');

		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$settings = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('PLUGIN_SLUG');
		$module = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('module_PLUGIN_SLUG');
		$shipping = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('shipping_PLUGIN_SLUG');

		$data['module_PLUGIN_SLUG_status'] = !empty($module['module_PLUGIN_SLUG_status']);
		$data['api_key'] = (string)($settings['PLUGIN_SLUG_api_key'] ?? '');

		foreach (self::ORIGIN_FIELDS as $field) {
			$data['origin_' . $field] = (string)($settings['PLUGIN_SLUG_origin_' . $field] ?? '');
		}

		$data['origin_country_id'] = (int)($settings['PLUGIN_SLUG_origin_country_id'] ?? 0);
		$data['origin_zone_id'] = (int)($settings['PLUGIN_SLUG_origin_zone_id'] ?? 0);

		$data['shipped_status_id'] = (int)($settings['PLUGIN_SLUG_shipped_status_id'] ?? self::DEFAULT_SHIPPED_STATUS_ID);
		$data['delivered_status_id'] = (int)($settings['PLUGIN_SLUG_delivered_status_id'] ?? self::DEFAULT_DELIVERED_STATUS_ID);
		$data['cancelled_status_ids'] = array_map('intval', (array)($settings['PLUGIN_SLUG_cancelled_status_ids'] ?? self::DEFAULT_CANCELLED_STATUS_IDS));

		// Read-only: written by PLUGIN_NAME through the API.
		$data['quotation_status'] = !empty($shipping['shipping_PLUGIN_SLUG_status']);
		$data['quotation_base_url'] = (string)($settings['PLUGIN_SLUG_quotation_base_url'] ?? '');
		$data['webhooks_status'] = !empty($settings['PLUGIN_SLUG_webhooks_status']);
		$data['webhook_url'] = (string)($settings['PLUGIN_SLUG_webhook_url'] ?? '');
		$data['webhook_update_url'] = (string)($settings['PLUGIN_SLUG_webhook_update_url'] ?? '');
		$data['api_base_url'] = HTTP_CATALOG . 'index.php?route=extension/PLUGIN_SLUG/api/';

		// PLUGIN_NAME turns the order webhooks on while it creates the shop, before the merchant signs in
		// and links it (and also when the link is then refused), so this only says that the service
		// reached the store, never that the store is linked.
		$data['contacted'] = $data['webhooks_status'];
		$data['origin_incomplete'] = $data['origin_address'] === '' || $data['origin_postcode'] === '' || !$data['origin_country_id'];

		// Left by connect() when the user cannot modify the extension.
		$data['error_warning'] = (string)($this->session->data['error_warning'] ?? '');

		unset($this->session->data['error_warning']);

		// Brand logo (optional in the package), app link and build info.
		$data['logo'] = is_file(DIR_EXTENSION . 'PLUGIN_SLUG/admin/view/image/logo.png') ? HTTP_CATALOG . 'extension/PLUGIN_SLUG/admin/view/image/logo.png' : '';

		$build = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getBuild();

		$data['app_url'] = rtrim($build['app_base'], '/') . '/es-MX/merchant_stores';
		$data['build_info'] = $build['environment'] . ' · ' . $build['ecommerce_base'];

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/country');

		$data['countries'] = $this->model_localisation_country->getCountries();

		$this->load->model('localisation/zone');

		$data['zones'] = $data['origin_country_id'] ? $this->model_localisation_zone->getZonesByCountryId($data['origin_country_id']) : [];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/PLUGIN_SLUG/module/PLUGIN_SLUG', $data));
	}

	public function save(): void {
		$this->load->language('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/PLUGIN_SLUG/module/PLUGIN_SLUG')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post = $this->request->post;

		$email = trim((string)($post['origin_email'] ?? ''));

		if ($email !== '' && !filter_var(html_entity_decode($email, ENT_QUOTES, 'UTF-8'), FILTER_VALIDATE_EMAIL)) {
			$json['error']['origin_email'] = $this->language->get('error_email');
		}

		foreach (['shipped_status_id', 'delivered_status_id'] as $field) {
			if ((int)($post[$field] ?? 0) < 1) {
				$json['error'][$field] = $this->language->get('error_status');
			}
		}

		if (isset($json['error']) && !isset($json['error']['warning'])) {
			$json['error']['warning'] = $this->language->get('error_warning');
		}

		if (!$json) {
			$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

			$values = [];

			foreach (self::ORIGIN_FIELDS as $field) {
				$values['PLUGIN_SLUG_origin_' . $field] = mb_substr(trim((string)($post['origin_' . $field] ?? '')), 0, 255);
			}

			$values['PLUGIN_SLUG_origin_country_id'] = (string)(int)($post['origin_country_id'] ?? 0);
			$values['PLUGIN_SLUG_origin_zone_id'] = (string)(int)($post['origin_zone_id'] ?? 0);
			$values['PLUGIN_SLUG_shipped_status_id'] = (string)(int)$post['shipped_status_id'];
			$values['PLUGIN_SLUG_delivered_status_id'] = (string)(int)$post['delivered_status_id'];
			$values['PLUGIN_SLUG_cancelled_status_ids'] = array_values(array_filter(array_map('intval', (array)($post['cancelled_status_ids'] ?? []))));

			$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('PLUGIN_SLUG', $values);
			$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('module_PLUGIN_SLUG', ['module_PLUGIN_SLUG_status' => !empty($post['module_PLUGIN_SLUG_status']) ? '1' : '0']);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Connect button: sends the admin to the ecommerce service with the API key and the store
	 * URL, so the merchant never copies the key by hand (WooCommerce plugin flow).
	 */
	public function connect(): void {
		$this->load->language('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		if (!$this->user->hasPermission('modify', 'extension/PLUGIN_SLUG/module/PLUGIN_SLUG')) {
			$this->session->data['error_warning'] = $this->language->get('error_permission');

			$this->response->redirect($this->url->link('extension/PLUGIN_SLUG/module/PLUGIN_SLUG', 'user_token=' . $this->session->data['user_token'], true));

			return;
		}

		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$settings = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('PLUGIN_SLUG');

		$this->response->redirect($this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getConnectUrl((string)($settings['PLUGIN_SLUG_api_key'] ?? ''), HTTP_CATALOG));
	}

	/**
	 * Replaces the API key. PLUGIN_NAME must be updated with the new key.
	 */
	public function regenerate(): void {
		$this->load->language('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/PLUGIN_SLUG/module/PLUGIN_SLUG')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

			$api_key = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->generateApiKey();

			$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('PLUGIN_SLUG', ['PLUGIN_SLUG_api_key' => $api_key]);

			$json['api_key'] = $api_key;
			$json['success'] = $this->language->get('text_regenerated');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Called by Extensions > Modules > Install.
	 */
	public function install(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->install();

		// Defaults (kept when the extension is reinstalled without uninstalling).
		$settings = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('PLUGIN_SLUG');

		$defaults = [
			'PLUGIN_SLUG_api_key'              => $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->generateApiKey(),
			'PLUGIN_SLUG_shipped_status_id'    => (string)self::DEFAULT_SHIPPED_STATUS_ID,
			'PLUGIN_SLUG_delivered_status_id'  => (string)self::DEFAULT_DELIVERED_STATUS_ID,
			'PLUGIN_SLUG_cancelled_status_ids' => self::DEFAULT_CANCELLED_STATUS_IDS,
			'PLUGIN_SLUG_webhooks_status'      => '0',
			// Origin address starts from the store settings (Settings > Store), so the merchant
			// only adds what OpenCart does not keep there: street, city and postcode.
			'PLUGIN_SLUG_origin_name'          => mb_substr((string)$this->config->get('config_name'), 0, 255),
			'PLUGIN_SLUG_origin_owner'         => mb_substr((string)$this->config->get('config_owner'), 0, 255),
			'PLUGIN_SLUG_origin_email'         => mb_substr((string)$this->config->get('config_email'), 0, 255),
			'PLUGIN_SLUG_origin_telephone'     => mb_substr((string)$this->config->get('config_telephone'), 0, 255),
			'PLUGIN_SLUG_origin_country_id'    => (string)(int)$this->config->get('config_country_id'),
			'PLUGIN_SLUG_origin_zone_id'       => (string)(int)$this->config->get('config_zone_id')
		];

		$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('PLUGIN_SLUG', array_diff_key($defaults, $settings));
		$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('module_PLUGIN_SLUG', ['module_PLUGIN_SLUG_status' => '1']);

		// Order history event: the model event name changed in 4.1.
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);

		$this->model_setting_event->addEvent([
			'code'        => self::EVENT_CODE,
			'description' => 'PLUGIN_NAME: posts order webhooks when an order history is added.',
			'trigger'     => $this->getOrderHistoryTrigger(),
			'action'      => 'extension/PLUGIN_SLUG/event/order.history',
			'status'      => 1,
			'sort_order'  => 0
		]);

		// API route alias: maps the contract API route to this extension's own code.
		$this->model_setting_event->deleteEventByCode(self::ALIAS_EVENT_CODE);

		if ('PLUGIN_SLUG' !== self::CONTRACT_CODE) {
			$this->model_setting_event->addEvent([
				'code'        => self::ALIAS_EVENT_CODE,
				'description' => 'PLUGIN_NAME: routes the ecommerce service API calls to this extension.',
				'trigger'     => 'catalog/controller/extension/' . self::CONTRACT_CODE . '/api/*/before',
				'action'      => 'extension/PLUGIN_SLUG/event/api.alias',
				'status'      => 1,
				'sort_order'  => 0
			]);
		}

		// Register the `PLUGIN_SLUG` shipping method (disabled until PLUGIN_NAME turns quotes on).
		$this->load->model('setting/extension');

		if (!$this->model_setting_extension->getExtensionByCode('shipping', 'PLUGIN_SLUG')) {
			$this->model_setting_extension->install('shipping', 'PLUGIN_SLUG', 'PLUGIN_SLUG');
		}

		$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('shipping_PLUGIN_SLUG', ['shipping_PLUGIN_SLUG_status' => '0', 'shipping_PLUGIN_SLUG_sort_order' => '0']);

		$this->load->model('user/user_group');

		foreach (['access', 'modify'] as $type) {
			$this->model_user_user_group->addPermission($this->user->getGroupId(), $type, 'extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG');
		}
	}

	/**
	 * Called by Extensions > Modules > Uninstall.
	 */
	public function uninstall(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
		$this->model_setting_event->deleteEventByCode(self::ALIAS_EVENT_CODE);

		$this->load->model('setting/extension');

		$this->model_setting_extension->uninstall('shipping', 'PLUGIN_SLUG');

		$this->load->model('user/user_group');

		foreach (['access', 'modify'] as $type) {
			$this->model_user_user_group->removePermission($this->user->getGroupId(), $type, 'extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG');
		}

		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		foreach (['PLUGIN_SLUG', 'module_PLUGIN_SLUG', 'shipping_PLUGIN_SLUG'] as $code) {
			$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->deleteSettings($code);
		}

		$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->uninstall();
	}

	/**
	 * 4.0.2.x fires model events as `model/<route>/<method>`;
	 * 4.1.x as `model/<route>.<method>`.
	 */
	private function getOrderHistoryTrigger(): string {
		if (version_compare(VERSION, '4.1.0.0', '>=')) {
			return 'catalog/model/checkout/order.addHistory/after';
		}

		return 'catalog/model/checkout/order/addHistory/after';
	}
}
