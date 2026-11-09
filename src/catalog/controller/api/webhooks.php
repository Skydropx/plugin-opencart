<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Api;
/**
 * Class Webhooks
 *
 * POST index.php?route=extension/skydropx/api/webhooks
 * Body: {"status":true,"url":".../new_order","update_url":".../order_status_change"}
 *
 * Turns the order webhooks on/off. The order history event is registered at
 * install time and only posts while this toggle is on.
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Api
 */
class Webhooks extends \Opencart\System\Engine\Controller {
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
		$url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
		$update_url = isset($body['update_url']) && is_string($body['update_url']) ? trim($body['update_url']) : '';

		if ($status === null) {
			$skydropx->error(422, 'VALIDATION_ERROR', 'status must be a boolean.');

			return;
		}

		foreach (['url' => $url, 'update_url' => $update_url] as $name => $value) {
			if (($status || $value !== '') && !$skydropx->isValidUrl($value)) {
				$skydropx->error(422, 'VALIDATION_ERROR', $name . ' must be an http(s) URL.');

				return;
			}
		}

		if ($url !== '') {
			$skydropx->setSetting('skydropx', 'skydropx_webhook_url', $url);
		}

		if ($update_url !== '') {
			$skydropx->setSetting('skydropx', 'skydropx_webhook_update_url', $update_url);
		}

		if ($status) {
			// Webhooks are sent for the store this connection was made through.
			$skydropx->setSetting('skydropx', 'skydropx_webhook_store_id', (string)(int)$this->config->get('config_store_id'));
			$skydropx->setSetting('skydropx', 'skydropx_webhook_store_url', $skydropx->getStoreUrl());
		}

		$skydropx->setSetting('skydropx', 'skydropx_webhooks_status', $status ? '1' : '0');

		$skydropx->json(200, [
			'status'     => $status,
			'url'        => (string)$skydropx->getSetting('skydropx_webhook_url', ''),
			'update_url' => (string)$skydropx->getSetting('skydropx_webhook_update_url', '')
		]);
	}
}
