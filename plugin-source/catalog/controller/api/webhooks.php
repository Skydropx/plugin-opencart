<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api;
/**
 * Class Webhooks
 *
 * POST index.php?route=extension/PLUGIN_SLUG/api/webhooks
 * Body: {"status":true,"url":".../new_order","update_url":".../order_status_change"}
 *
 * Turns the order webhooks on/off. The order history event is registered at
 * install time and only posts while this toggle is on.
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api
 */
class Webhooks extends \Opencart\System\Engine\Controller {
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
		$url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
		$update_url = isset($body['update_url']) && is_string($body['update_url']) ? trim($body['update_url']) : '';

		if ($status === null) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'status must be a boolean.');

			return;
		}

		foreach (['url' => $url, 'update_url' => $update_url] as $name => $value) {
			if (($status || $value !== '') && !$PLUGIN_SLUG->isValidUrl($value)) {
				$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', $name . ' must be an http(s) URL.');

				return;
			}
		}

		if ($url !== '') {
			$PLUGIN_SLUG->setSetting('PLUGIN_SLUG', 'PLUGIN_SLUG_webhook_url', $url);
		}

		if ($update_url !== '') {
			$PLUGIN_SLUG->setSetting('PLUGIN_SLUG', 'PLUGIN_SLUG_webhook_update_url', $update_url);
		}

		if ($status) {
			// Webhooks are sent for the store this connection was made through.
			$PLUGIN_SLUG->setSetting('PLUGIN_SLUG', 'PLUGIN_SLUG_webhook_store_id', (string)(int)$this->config->get('config_store_id'));
			$PLUGIN_SLUG->setSetting('PLUGIN_SLUG', 'PLUGIN_SLUG_webhook_store_url', $PLUGIN_SLUG->getStoreUrl());
		}

		$PLUGIN_SLUG->setSetting('PLUGIN_SLUG', 'PLUGIN_SLUG_webhooks_status', $status ? '1' : '0');

		$PLUGIN_SLUG->json(200, [
			'status'     => $status,
			'url'        => (string)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhook_url', ''),
			'update_url' => (string)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhook_update_url', '')
		]);
	}
}
