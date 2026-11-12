<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api;
/**
 * Class Orders
 *
 * GET index.php?route=extension/PLUGIN_SLUG/api/orders&page=1&limit=50&payment_status=paid,pending&date_from=2026-11-01
 * Manual sync, page-number pagination. Cancelled orders are never listed.
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Api
 */
class Orders extends \Opencart\System\Engine\Controller {
	private const MAX_LIMIT = 100;
	private const DEFAULT_LIMIT = 50;

	public function index(): void {
		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$PLUGIN_SLUG = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG;

		if (!$PLUGIN_SLUG->authorize()) {
			return;
		}

		$get = $this->request->get;

		$page = $this->positiveInt($get['page'] ?? '1');
		$limit = $this->positiveInt($get['limit'] ?? (string)self::DEFAULT_LIMIT);

		if ($page === null || $limit === null) {
			$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'page and limit must be positive integers.');

			return;
		}

		$limit = min($limit, self::MAX_LIMIT);

		$payment_status = [];

		if (isset($get['payment_status']) && trim((string)$get['payment_status']) !== '') {
			foreach (explode(',', (string)$get['payment_status']) as $value) {
				$value = strtolower(trim($value));

				if (!in_array($value, ['paid', 'pending'], true)) {
					$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'payment_status accepts a comma list of paid, pending.');

					return;
				}

				$payment_status[] = $value;
			}
		}

		// Empty or absent = paid,pending. Cancelled orders are never listed.
		if (!$payment_status) {
			$payment_status = ['paid', 'pending'];
		}

		if (isset($get['date_from']) && trim((string)$get['date_from']) !== '') {
			$date_from = $PLUGIN_SLUG->parseIsoDate(trim((string)$get['date_from']));

			if ($date_from === '') {
				$PLUGIN_SLUG->error(422, 'VALIDATION_ERROR', 'date_from must be an ISO 8601 date.');

				return;
			}
		} else {
			$date_from = date('Y-m-d H:i:s', strtotime('-30 days'));
		}

		$result = $PLUGIN_SLUG->getOrders([
			'store_id'       => (int)$this->config->get('config_store_id'),
			'page'           => $page,
			'limit'          => $limit,
			'payment_status' => array_values(array_unique($payment_status)),
			'date_from'      => $date_from
		]);

		$PLUGIN_SLUG->json(200, [
			'total'  => $result['total'],
			'page'   => $page,
			'limit'  => $limit,
			'orders' => $result['orders']
		]);
	}

	private function positiveInt(mixed $value): ?int {
		$value = trim((string)$value);

		if (!preg_match('/^\d{1,9}$/', $value) || (int)$value < 1) {
			return null;
		}

		return (int)$value;
	}
}
