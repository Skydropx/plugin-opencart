<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Api;
/**
 * Class Fulfillment
 *
 * POST index.php?route=extension/skydropx/api/fulfillment
 * Body: {"order_id":42,"tracking_number":"123","carrier":"DHL","tracking_url":"https://...",
 *        "items":[{"order_product_id":7,"quantity":2}],"notify":true}
 *
 * Idempotent on (order_id, tracking_number).
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Api
 */
class Fulfillment extends \Opencart\System\Engine\Controller {
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

		$order_id = $skydropx->parseId($body['order_id'] ?? null);
		$tracking_number = is_scalar($body['tracking_number'] ?? null) ? trim((string)$body['tracking_number']) : '';
		$carrier = is_scalar($body['carrier'] ?? null) ? trim((string)$body['carrier']) : '';
		$tracking_url = is_scalar($body['tracking_url'] ?? null) ? trim((string)$body['tracking_url']) : '';
		$notify = array_key_exists('notify', $body) ? $skydropx->parseBool($body['notify']) : false;
		$items = $body['items'] ?? [];

		$errors = [];

		if ($order_id === null) {
			$errors[] = 'order_id must be a positive integer';
		}

		if ($tracking_number === '' || mb_strlen($tracking_number) > 128) {
			$errors[] = 'tracking_number is required (max 128 characters)';
		}

		if (mb_strlen($carrier) > 64) {
			$errors[] = 'carrier must be at most 64 characters';
		}

		if ($tracking_url !== '' && (strlen($tracking_url) > 512 || !$skydropx->isValidUrl($tracking_url))) {
			$errors[] = 'tracking_url must be an http(s) URL (max 512 characters)';
		}

		if ($notify === null) {
			$errors[] = 'notify must be a boolean';
		}

		if ($items !== null && !is_array($items)) {
			$errors[] = 'items must be an array';
		}

		$requested = [];

		if (!$errors && $items) {
			foreach ($items as $item) {
				$order_product_id = is_array($item) ? $skydropx->parseId($item['order_product_id'] ?? null) : null;
				$quantity = is_array($item) ? $skydropx->parseId($item['quantity'] ?? null) : null;

				if ($order_product_id === null || $quantity === null) {
					$errors[] = 'each item needs a positive order_product_id and quantity';

					break;
				}

				$requested[$order_product_id] = ($requested[$order_product_id] ?? 0) + $quantity;
			}
		}

		if ($errors) {
			$skydropx->error(422, 'VALIDATION_ERROR', implode('; ', $errors) . '.');

			return;
		}

		$store_id = (int)$this->config->get('config_store_id');

		if (!$skydropx->getOrderRow($order_id, $store_id)) {
			$skydropx->error(404, 'ORDER_NOT_FOUND', 'Order not found.');

			return;
		}

		$existing = $skydropx->getShipment($order_id, $tracking_number);

		if ($existing) {
			$this->replay($order_id, (int)$existing['shipment_id'], $tracking_number);

			return;
		}

		$lines = $skydropx->getShippableLines($order_id);

		// items empty/omitted = every remaining quantity.
		$to_ship = [];

		if (!$requested) {
			foreach ($lines as $order_product_id => $line) {
				if ($line['quantity_to_ship'] > 0) {
					$to_ship[$order_product_id] = $line['quantity_to_ship'];
				}
			}
		} else {
			foreach ($requested as $order_product_id => $quantity) {
				if (!isset($lines[$order_product_id])) {
					$skydropx->error(422, 'VALIDATION_ERROR', 'order_product_id ' . $order_product_id . ' is not a shippable line of this order.');

					return;
				}

				if ($quantity > $lines[$order_product_id]['quantity_to_ship']) {
					$skydropx->error(422, 'VALIDATION_ERROR', 'quantity for order_product_id ' . $order_product_id . ' exceeds the quantity left to ship (' . $lines[$order_product_id]['quantity_to_ship'] . ').');

					return;
				}

				$to_ship[$order_product_id] = $quantity;
			}
		}

		$shipment_id = $skydropx->addShipment($order_id, $tracking_number, $carrier, $tracking_url);

		if (!$shipment_id) {
			// A concurrent retry created it first.
			$existing = $skydropx->getShipment($order_id, $tracking_number);

			$this->replay($order_id, (int)($existing['shipment_id'] ?? 0), $tracking_number);

			return;
		}

		foreach ($to_ship as $order_product_id => $quantity) {
			$skydropx->addShipmentItem($shipment_id, $order_id, $order_product_id, $quantity);
		}

		$remaining = 0;

		foreach ($lines as $order_product_id => $line) {
			$remaining += $line['quantity_to_ship'] - ($to_ship[$order_product_id] ?? 0);
		}

		$current_status_id = $skydropx->getOrderStatusId($order_id);
		$status_id = $remaining <= 0 ? $skydropx->getShippedStatusId() : $current_status_id;

		$comment = trim('Shipped with ' . ($carrier !== '' ? $carrier : 'Skydropx') . '. Tracking: ' . $tracking_number . ' ' . $tracking_url);

		try {
			$this->load->model('checkout/order');

			$this->model_checkout_order->addHistory($order_id, $status_id, $comment, (bool)$notify);
		} catch (\Throwable $e) {
			// The shipment is stored; a failing side effect (e.g. mail transport) must not lose it.
			$this->log->write('Skydropx: order history for order ' . $order_id . ' failed after fulfillment (' . get_class($e) . ').');
		}

		$skydropx->json(201, [
			'created'         => true,
			'order_id'        => $order_id,
			'shipment_id'     => $shipment_id,
			'tracking_number' => $tracking_number,
			'order_status_id' => $skydropx->getOrderStatusId($order_id)
		]);
	}

	private function replay(int $order_id, int $shipment_id, string $tracking_number): void {
		$skydropx = $this->model_extension_skydropx_module_skydropx;

		$skydropx->json(200, [
			'created'         => false,
			'order_id'        => $order_id,
			'shipment_id'     => $shipment_id,
			'tracking_number' => $tracking_number,
			'order_status_id' => $skydropx->getOrderStatusId($order_id)
		]);
	}
}
