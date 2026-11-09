<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Api;
/**
 * Class Order
 *
 * GET index.php?route=extension/skydropx/api/order&order_id=42
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Api
 */
class Order extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('extension/skydropx/module/skydropx');

		$skydropx = $this->model_extension_skydropx_module_skydropx;

		if (!$skydropx->authorize()) {
			return;
		}

		$order_id = trim((string)($this->request->get['order_id'] ?? ''));

		if (!preg_match('/^\d{1,10}$/', $order_id) || (int)$order_id < 1) {
			$skydropx->error(422, 'VALIDATION_ERROR', 'order_id must be a positive integer.');

			return;
		}

		$order = $skydropx->getOrder((int)$order_id, (int)$this->config->get('config_store_id'));

		if (!$order) {
			$skydropx->error(404, 'ORDER_NOT_FOUND', 'Order not found.');

			return;
		}

		$skydropx->json(200, ['order' => $order]);
	}
}
