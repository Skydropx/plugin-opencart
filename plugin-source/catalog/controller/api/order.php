<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api;
/**
 * Class Order
 *
 * GET index.php?route=extension/PLUGIN_SLUG/api/order&order_id=42
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api
 */
class Order extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$PLUGIN_SLUG = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG;

		if (!$PLUGIN_SLUG->authorize()) {
			return;
		}

		$order_id = trim((string)($this->request->get['order_id'] ?? ''));

		if (!preg_match('/^\d{1,10}$/', $order_id) || (int)$order_id < 1) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'order_id must be a positive integer.');

			return;
		}

		$order = $PLUGIN_SLUG->getOrder((int)$order_id, (int)$this->config->get('config_store_id'));

		if (!$order) {
			$PLUGIN_SLUG->error(404, 'ORDER_NOT_FOUND', 'Order not found.');

			return;
		}

		$PLUGIN_SLUG->json(200, ['order' => $order]);
	}
}
