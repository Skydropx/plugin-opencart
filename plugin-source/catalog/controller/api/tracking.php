<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api;
/**
 * Class Tracking
 *
 * POST index.php?route=extension/PLUGIN_SLUG/api/tracking
 * Body: {"order_id":42,"tracking_number":"123","status":"IN_TRANSIT","carrier":"DHL","tracking_url":"https://..."}
 *
 * Idempotent on (order_id, tracking_number, status).
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api
 */
class Tracking extends \Opencart\System\Engine\Controller {
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

		$order_id = $PLUGIN_SLUG->parseId($body['order_id'] ?? null);
		$tracking_number = is_scalar($body['tracking_number'] ?? null) ? trim((string)$body['tracking_number']) : '';
		$status = is_scalar($body['status'] ?? null) ? strtoupper(trim((string)$body['status'])) : '';
		$carrier = is_scalar($body['carrier'] ?? null) ? trim((string)$body['carrier']) : '';
		$tracking_url = is_scalar($body['tracking_url'] ?? null) ? trim((string)$body['tracking_url']) : '';

		$errors = [];

		if ($order_id === null) {
			$errors[] = 'order_id must be a positive integer';
		}

		if ($tracking_number === '' || mb_strlen($tracking_number) > 128) {
			$errors[] = 'tracking_number is required (max 128 characters)';
		}

		if (!preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $status)) {
			$errors[] = 'status is required (e.g. IN_TRANSIT, DELIVERED, EXCEPTION)';
		}

		if (mb_strlen($carrier) > 64) {
			$errors[] = 'carrier must be at most 64 characters';
		}

		if ($tracking_url !== '' && (strlen($tracking_url) > 512 || !$PLUGIN_SLUG->isValidUrl($tracking_url))) {
			$errors[] = 'tracking_url must be an http(s) URL (max 512 characters)';
		}

		if ($errors) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', implode('; ', $errors) . '.');

			return;
		}

		if (!$PLUGIN_SLUG->getOrderRow($order_id, (int)$this->config->get('config_store_id'))) {
			$PLUGIN_SLUG->error(404, 'ORDER_NOT_FOUND', 'Order not found.');

			return;
		}

		$shipment = $PLUGIN_SLUG->getShipment($order_id, $tracking_number);

		if (!$shipment) {
			// Label created outside api/fulfillment: keep track of it anyway.
			$PLUGIN_SLUG->addShipment($order_id, $tracking_number, $carrier, $tracking_url);

			$shipment = $PLUGIN_SLUG->getShipment($order_id, $tracking_number);
		}

		$shipment_id = (int)($shipment['shipment_id'] ?? 0);

		if (!$PLUGIN_SLUG->addTrackingEvent($shipment_id, $order_id, $tracking_number, $status)) {
			$PLUGIN_SLUG->json(200, ['updated' => false, 'order_status_id' => $PLUGIN_SLUG->getOrderStatusId($order_id)]);

			return;
		}

		// A late non-final event never moves a delivered shipment backwards.
		$shipment_status = (($shipment['status'] ?? '') == 'DELIVERED') ? 'DELIVERED' : $status;

		$PLUGIN_SLUG->updateShipment($shipment_id, $shipment_status, $carrier, $tracking_url);

		$status_id = $PLUGIN_SLUG->getOrderStatusId($order_id);

		if ($status == 'DELIVERED' && $PLUGIN_SLUG->allShipmentsDelivered($order_id)) {
			$status_id = $PLUGIN_SLUG->getDeliveredStatusId();
		}

		$comment = trim('Tracking update: ' . $status . '. ' . ($carrier !== '' ? $carrier . ' ' : '') . $tracking_number . ' ' . $tracking_url);

		try {
			$this->load->model('checkout/order');

			// Tracking events are not buyer-visible by default.
			$this->model_checkout_order->addHistory($order_id, $status_id, $comment, false);
		} catch (\Throwable $e) {
			$this->log->write('PLUGIN_NAME: order history for order ' . $order_id . ' failed after tracking update (' . get_class($e) . ').');
		}

		$PLUGIN_SLUG->json(200, ['updated' => true, 'order_status_id' => $PLUGIN_SLUG->getOrderStatusId($order_id)]);
	}
}
