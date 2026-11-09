<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Api;
/**
 * Class Store
 *
 * GET index.php?route=extension/skydropx/api/store
 * Connect validation and origin location.
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Api
 */
class Store extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('extension/skydropx/module/skydropx');

		$skydropx = $this->model_extension_skydropx_module_skydropx;

		if (!$skydropx->authorize()) {
			return;
		}

		$skydropx->json(200, [
			'name'              => $skydropx->text($this->config->get('config_name')),
			'url'               => $skydropx->getStoreUrl(),
			'opencart_version'  => VERSION,
			'extension_version' => $skydropx->getExtensionVersion(),
			'currency'          => (string)$this->config->get('config_currency'),
			'weight_unit'       => 'kg',
			'length_unit'       => 'cm',
			'location'          => $skydropx->getOrigin(),
			'quotation'         => [
				'enabled'            => (bool)$skydropx->getSetting('shipping_skydropx_status', false),
				'quotation_base_url' => (string)$skydropx->getSetting('skydropx_quotation_base_url', '')
			],
			'webhooks'          => [
				'enabled' => (bool)$skydropx->getSetting('skydropx_webhooks_status', false),
				'url'     => (string)$skydropx->getSetting('skydropx_webhook_url', '')
			]
		]);
	}
}
