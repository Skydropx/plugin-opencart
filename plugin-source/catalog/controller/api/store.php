<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api;
/**
 * Class Store
 *
 * GET index.php?route=extension/PLUGIN_SLUG/api/store
 * Connect validation and origin location.
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api
 */
class Store extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$PLUGIN_SLUG = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG;

		if (!$PLUGIN_SLUG->authorize()) {
			return;
		}

		$PLUGIN_SLUG->json(200, [
			'name'              => $PLUGIN_SLUG->text($this->config->get('config_name')),
			'url'               => $PLUGIN_SLUG->getStoreUrl(),
			'opencart_version'  => VERSION,
			'extension_version' => $PLUGIN_SLUG->getExtensionVersion(),
			'currency'          => (string)$this->config->get('config_currency'),
			'weight_unit'       => 'kg',
			'length_unit'       => 'cm',
			'location'          => $PLUGIN_SLUG->getOrigin(),
			'quotation'         => [
				'enabled'            => (bool)$PLUGIN_SLUG->getSetting('shipping_PLUGIN_SLUG_status', false),
				'quotation_base_url' => (string)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_quotation_base_url', '')
			],
			'webhooks'          => [
				'enabled' => (bool)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhooks_status', false),
				'url'     => (string)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhook_url', '')
			]
		]);
	}
}
