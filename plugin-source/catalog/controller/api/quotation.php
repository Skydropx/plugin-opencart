<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api;
/**
 * Class Quotation
 *
 * POST index.php?route=extension/PLUGIN_SLUG/api/quotation
 * Body: {"status":true,"quotation_base_url":"https://.../quotations"}
 *
 * Enables/disables the `PLUGIN_SLUG` shipping method (CARRIER_SERVICE_V2) and stores the URL.
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api
 */
class Quotation extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$PLUGIN_SLUG = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG;

		if (!$PLUGIN_SLUG->authorize()) {
			return;
		}

		$body = $PLUGIN_SLUG->readJsonBody();

		if ($body === null) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'The request body must be a JSON object.');

			return;
		}

		$status = $PLUGIN_SLUG->parseBool($body['status'] ?? null);
		$has_url = array_key_exists('quotation_base_url', $body) && $body['quotation_base_url'] !== null;
		$url = $has_url && is_string($body['quotation_base_url']) ? trim($body['quotation_base_url']) : '';

		if ($status === null) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'status must be a boolean.');

			return;
		}

		if (($status || $url !== '') && !$PLUGIN_SLUG->isValidUrl($url)) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'quotation_base_url must be an http(s) URL.');

			return;
		}

		if ($status) {
			$PLUGIN_SLUG->ensureShippingExtension();
		}

		if ($url !== '') {
			$PLUGIN_SLUG->setSetting('PLUGIN_SLUG', 'PLUGIN_SLUG_quotation_base_url', $url);
		}

		$PLUGIN_SLUG->setSetting('shipping_PLUGIN_SLUG', 'shipping_PLUGIN_SLUG_status', $status ? '1' : '0');

		$PLUGIN_SLUG->json(200, [
			'status'             => $status,
			'quotation_base_url' => (string)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_quotation_base_url', '')
		]);
	}
}
