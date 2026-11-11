<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Api;
/**
 * Class Quotation
 *
 * POST index.php?route=extension/skydropx/api/quotation
 * Body: {"status":true,"quotation_base_url":"https://.../quotations"}
 *
 * Enables/disables the `skydropx` shipping method (CARRIER_SERVICE_V2) and stores the URL.
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Api
 */
class Quotation extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('extension/skydropx/module/skydropx');

		$skydropx = $this->model_extension_skydropx_module_skydropx;

		if (!$skydropx->authorize()) {
			return;
		}

		$body = $skydropx->readJsonBody();

		if ($body === null) {
			$skydropx->error(422, 'VALIDATION_ERROR', 'The request body must be a JSON object.');

			return;
		}

		$status = $skydropx->parseBool($body['status'] ?? null);
		$has_url = array_key_exists('quotation_base_url', $body) && $body['quotation_base_url'] !== null;
		$url = $has_url && is_string($body['quotation_base_url']) ? trim($body['quotation_base_url']) : '';

		if ($status === null) {
			$skydropx->error(422, 'VALIDATION_ERROR', 'status must be a boolean.');

			return;
		}

		if (($status || $url !== '') && !$skydropx->isValidUrl($url)) {
			$skydropx->error(422, 'VALIDATION_ERROR', 'quotation_base_url must be an http(s) URL.');

			return;
		}

		if ($status) {
			$skydropx->ensureShippingExtension();
		}

		if ($url !== '') {
			$skydropx->setSetting('skydropx', 'skydropx_quotation_base_url', $url);
		}

		$skydropx->setSetting('shipping_skydropx', 'shipping_skydropx_status', $status ? '1' : '0');

		$skydropx->json(200, [
			'status'             => $status,
			'quotation_base_url' => (string)$skydropx->getSetting('skydropx_quotation_base_url', '')
		]);
	}
}
