<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Event;
/**
 * Class Order
 *
 * Listens to the order history event and POSTs the order to Skydropx.
 *   4.0.2.x trigger: catalog/model/checkout/order/addHistory/after
 *   4.1.x   trigger: catalog/model/checkout/order.addHistory/after
 * Admin status changes run the same catalog model (in-process store
 * instance), so this single listener covers them too.
 *
 * Best effort: 3 s timeout, failures are logged (no secrets) and never
 * interrupt the order flow. Manual sync (api/orders) is the safety net.
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Event
 */
class Order extends \Opencart\System\Engine\Controller {
	private const TIMEOUT = 3;

	/**
	 * @param string       $route
	 * @param array<mixed> $args   [order_id, order_status_id, comment, notify, override]
	 * @param mixed        $output
	 */
	public function history(string &$route, array &$args, &$output): void {
		try {
			$this->send((int)($args[0] ?? 0));
		} catch (\Throwable $e) {
			$this->log->write('PLUGIN_NAME: order webhook skipped (' . get_class($e) . ').');
		}
	}

	private function send(int $order_id): void {
		if ($order_id < 1) {
			return;
		}

		$this->load->model('extension/skydropx/module/skydropx');

		$skydropx = $this->model_extension_skydropx_module_skydropx;

		if (!$skydropx->isEnabled() || !$skydropx->getSetting('skydropx_webhooks_status', false) || $skydropx->getApiKey() === '') {
			return;
		}

		// Only orders of the connected store; only shippable orders with status > 0.
		$order = $skydropx->getOrder($order_id, (int)$skydropx->getSetting('skydropx_webhook_store_id', 0));

		if (!$order) {
			return;
		}

		// First history row = the order was just confirmed (status 0 -> > 0).
		$created = $skydropx->getTotalOrderHistories($order_id) <= 1;

		$topic = $created ? 'order.created' : 'order.updated';
		$url = (string)$skydropx->getSetting($created ? 'skydropx_webhook_url' : 'skydropx_webhook_update_url', '');

		if (!$skydropx->isValidUrl($url)) {
			return;
		}

		$body = json_encode(['topic' => $topic, 'order' => $order], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		$result = $skydropx->postSigned($url, $body, ['X-Skydropx-Topic' => $topic], self::TIMEOUT, (string)$skydropx->getSetting('skydropx_webhook_store_url', ''));

		if ($result['status'] < 200 || $result['status'] >= 300) {
			$this->log->write('PLUGIN_NAME: ' . $topic . ' webhook for order ' . $order_id . ' failed (HTTP ' . $result['status'] . ($result['error'] !== '' ? ', ' . $result['error'] : '') . ').');
		}
	}
}
