<?php
namespace Opencart\Admin\Controller\Extension\Skydropx\Module;
/**
 * Class Skydropx
 *
 * Settings page, API key management and install/uninstall of the Skydropx
 * extension (tables, order event and the `skydropx` shipping method).
 *
 * @package Opencart\Admin\Controller\Extension\Skydropx\Module
 */
class Skydropx extends \Opencart\System\Engine\Controller {
	private const EVENT_CODE = 'skydropx_order_history';

	private const DEFAULT_SHIPPED_STATUS_ID = 3;
	private const DEFAULT_DELIVERED_STATUS_ID = 5;
	private const DEFAULT_CANCELLED_STATUS_IDS = [7, 8, 10, 11, 13, 14, 16];

	private const ORIGIN_FIELDS = ['name', 'owner', 'email', 'telephone', 'address', 'address_2', 'city', 'postcode'];

	public function index(): void {
		$this->load->language('extension/skydropx/module/skydropx');

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/skydropx/module/skydropx', $token)]
		];

		$data['save'] = $this->url->link('extension/skydropx/module/skydropx.save', $token);
		$data['regenerate'] = $this->url->link('extension/skydropx/module/skydropx.regenerate', $token, true);
		$data['zone_url'] = $this->url->link('localisation/country.country', $token, true);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=module');

		$this->load->model('extension/skydropx/module/skydropx');

		$settings = $this->model_extension_skydropx_module_skydropx->getSettings('skydropx');
		$module = $this->model_extension_skydropx_module_skydropx->getSettings('module_skydropx');
		$shipping = $this->model_extension_skydropx_module_skydropx->getSettings('shipping_skydropx');

		$data['module_skydropx_status'] = !empty($module['module_skydropx_status']);
		$data['api_key'] = (string)($settings['skydropx_api_key'] ?? '');

		foreach (self::ORIGIN_FIELDS as $field) {
			$data['origin_' . $field] = (string)($settings['skydropx_origin_' . $field] ?? '');
		}

		$data['origin_country_id'] = (int)($settings['skydropx_origin_country_id'] ?? 0);
		$data['origin_zone_id'] = (int)($settings['skydropx_origin_zone_id'] ?? 0);

		$data['shipped_status_id'] = (int)($settings['skydropx_shipped_status_id'] ?? self::DEFAULT_SHIPPED_STATUS_ID);
		$data['delivered_status_id'] = (int)($settings['skydropx_delivered_status_id'] ?? self::DEFAULT_DELIVERED_STATUS_ID);
		$data['cancelled_status_ids'] = array_map('intval', (array)($settings['skydropx_cancelled_status_ids'] ?? self::DEFAULT_CANCELLED_STATUS_IDS));

		// Read-only: written by Skydropx through the API.
		$data['quotation_status'] = !empty($shipping['shipping_skydropx_status']);
		$data['quotation_base_url'] = (string)($settings['skydropx_quotation_base_url'] ?? '');
		$data['webhooks_status'] = !empty($settings['skydropx_webhooks_status']);
		$data['webhook_url'] = (string)($settings['skydropx_webhook_url'] ?? '');
		$data['webhook_update_url'] = (string)($settings['skydropx_webhook_update_url'] ?? '');
		$data['api_base_url'] = HTTP_CATALOG . 'index.php?route=extension/skydropx/api/';

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/country');

		$data['countries'] = $this->model_localisation_country->getCountries();

		$this->load->model('localisation/zone');

		$data['zones'] = $data['origin_country_id'] ? $this->model_localisation_zone->getZonesByCountryId($data['origin_country_id']) : [];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/skydropx/module/skydropx', $data));
	}

	public function save(): void {
		$this->load->language('extension/skydropx/module/skydropx');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/skydropx/module/skydropx')) {
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
			$this->load->model('extension/skydropx/module/skydropx');

			$values = [];

			foreach (self::ORIGIN_FIELDS as $field) {
				$values['skydropx_origin_' . $field] = mb_substr(trim((string)($post['origin_' . $field] ?? '')), 0, 255);
			}

			$values['skydropx_origin_country_id'] = (string)(int)($post['origin_country_id'] ?? 0);
			$values['skydropx_origin_zone_id'] = (string)(int)($post['origin_zone_id'] ?? 0);
			$values['skydropx_shipped_status_id'] = (string)(int)$post['shipped_status_id'];
			$values['skydropx_delivered_status_id'] = (string)(int)$post['delivered_status_id'];
			$values['skydropx_cancelled_status_ids'] = array_values(array_filter(array_map('intval', (array)($post['cancelled_status_ids'] ?? []))));

			$this->model_extension_skydropx_module_skydropx->editValues('skydropx', $values);
			$this->model_extension_skydropx_module_skydropx->editValues('module_skydropx', ['module_skydropx_status' => !empty($post['module_skydropx_status']) ? '1' : '0']);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Replaces the API key. Skydropx must be updated with the new key.
	 */
	public function regenerate(): void {
		$this->load->language('extension/skydropx/module/skydropx');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/skydropx/module/skydropx')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('extension/skydropx/module/skydropx');

			$api_key = $this->model_extension_skydropx_module_skydropx->generateApiKey();

			$this->model_extension_skydropx_module_skydropx->editValues('skydropx', ['skydropx_api_key' => $api_key]);

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

		$this->load->model('extension/skydropx/module/skydropx');

		$this->model_extension_skydropx_module_skydropx->install();

		// Defaults (kept when the extension is reinstalled without uninstalling).
		$settings = $this->model_extension_skydropx_module_skydropx->getSettings('skydropx');

		$defaults = [
			'skydropx_api_key'              => $this->model_extension_skydropx_module_skydropx->generateApiKey(),
			'skydropx_shipped_status_id'    => (string)self::DEFAULT_SHIPPED_STATUS_ID,
			'skydropx_delivered_status_id'  => (string)self::DEFAULT_DELIVERED_STATUS_ID,
			'skydropx_cancelled_status_ids' => self::DEFAULT_CANCELLED_STATUS_IDS,
			'skydropx_webhooks_status'      => '0'
		];

		$this->model_extension_skydropx_module_skydropx->editValues('skydropx', array_diff_key($defaults, $settings));
		$this->model_extension_skydropx_module_skydropx->editValues('module_skydropx', ['module_skydropx_status' => '1']);

		// Order history event: the model event name changed in 4.1.
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);

		$this->model_setting_event->addEvent([
			'code'        => self::EVENT_CODE,
			'description' => 'Skydropx: posts order webhooks when an order history is added.',
			'trigger'     => $this->getOrderHistoryTrigger(),
			'action'      => 'extension/skydropx/event/order.history',
			'status'      => 1,
			'sort_order'  => 0
		]);

		// Register the `skydropx` shipping method (disabled until Skydropx turns quotes on).
		$this->load->model('setting/extension');

		if (!$this->model_setting_extension->getExtensionByCode('shipping', 'skydropx')) {
			$this->model_setting_extension->install('shipping', 'skydropx', 'skydropx');
		}

		$this->model_extension_skydropx_module_skydropx->editValues('shipping_skydropx', ['shipping_skydropx_status' => '0', 'shipping_skydropx_sort_order' => '0']);

		$this->load->model('user/user_group');

		foreach (['access', 'modify'] as $type) {
			$this->model_user_user_group->addPermission($this->user->getGroupId(), $type, 'extension/skydropx/shipping/skydropx');
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

		$this->load->model('setting/extension');

		$this->model_setting_extension->uninstall('shipping', 'skydropx');

		$this->load->model('user/user_group');

		foreach (['access', 'modify'] as $type) {
			$this->model_user_user_group->removePermission($this->user->getGroupId(), $type, 'extension/skydropx/shipping/skydropx');
		}

		$this->load->model('extension/skydropx/module/skydropx');

		foreach (['skydropx', 'module_skydropx', 'shipping_skydropx'] as $code) {
			$this->model_extension_skydropx_module_skydropx->deleteSettings($code);
		}

		$this->model_extension_skydropx_module_skydropx->uninstall();
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
