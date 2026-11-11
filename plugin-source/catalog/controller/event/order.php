<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Event;
/**
 * Class Order
 *
 * Listens to the order history event and POSTs the order to PLUGIN_NAME.
 *   4.0.2.x trigger: catalog/model/checkout/order/addHistory/after
 *   4.1.x   trigger: catalog/model/checkout/order.addHistory/after
 * Admin status changes run the same catalog model (in-process store
 * instance), so this single listener covers them too.
 *
 * Best effort: 3 s timeout, failures are logged (no secrets) and never
 * interrupt the order flow. Manual sync (api/orders) is the safety net.
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Event
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

		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$PLUGIN_SLUG = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG;

		if (!$PLUGIN_SLUG->isEnabled() || !$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhooks_status', false) || $PLUGIN_SLUG->getApiKey() === '') {
			return;
		}

		// Only orders of the connected store; only shippable orders with status > 0.
		$order = $PLUGIN_SLUG->getOrder($order_id, (int)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhook_store_id', 0));

		if (!$order) {
			return;
		}

		// First history row = the order was just confirmed (status 0 -> > 0).
		$created = $PLUGIN_SLUG->getTotalOrderHistories($order_id) <= 1;

		$topic = $created ? 'order.created' : 'order.updated';
		$url = (string)$PLUGIN_SLUG->getSetting($created ? 'PLUGIN_SLUG_webhook_url' : 'PLUGIN_SLUG_webhook_update_url', '');

		if (!$PLUGIN_SLUG->isValidUrl($url)) {
			return;
		}

		$body = json_encode(['topic' => $topic, 'order' => $order], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		$result = $PLUGIN_SLUG->postSigned($url, $body, ['X-Skydropx-Topic' => $topic], self::TIMEOUT, (string)$PLUGIN_SLUG->getSetting('PLUGIN_SLUG_webhook_store_url', ''));

		if ($result['status'] < 200 || $result['status'] >= 300) {
			$this->log->write('PLUGIN_NAME: ' . $topic . ' webhook for order ' . $order_id . ' failed (HTTP ' . $result['status'] . ($result['error'] !== '' ? ', ' . $result['error'] : '') . ').');
		}
	}
}
