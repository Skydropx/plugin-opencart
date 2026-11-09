<?php
namespace Opencart\Catalog\Controller\Extension\Skydropx\Api;
/**
 * Class Orders
 *
 * GET index.php?route=extension/skydropx/api/orders&page=1&limit=50&payment_status=paid,pending&date_from=2026-11-01
 * Manual sync, page-number pagination.
 *
 * @package Opencart\Catalog\Controller\Extension\Skydropx\Api
 */
class Orders extends \Opencart\System\Engine\Controller {
	private const MAX_LIMIT = 100;
	private const DEFAULT_LIMIT = 50;

	public function index(): void {
		$this->load->model('extension/skydropx/module/skydropx');

		$skydropx = $this->model_extension_skydropx_module_skydropx;

		if (!$skydropx->authorize()) {
			return;
		}

		$get = $this->request->get;

		$page = $this->positiveInt($get['page'] ?? '1');
		$limit = $this->positiveInt($get['limit'] ?? (string)self::DEFAULT_LIMIT);

		if ($page === null || $limit === null) {
			$skydropx->error(422, 'VALIDATION_ERROR', 'page and limit must be positive integers.');

			return;
		}

		$limit = min($limit, self::MAX_LIMIT);

		$payment_status = [];

		if (isset($get['payment_status']) && trim((string)$get['payment_status']) !== '') {
			foreach (explode(',', (string)$get['payment_status']) as $value) {
				$value = strtolower(trim($value));

				if (!in_array($value, ['paid', 'pending', 'cancelled'], true)) {
					$skydropx->error(422, 'VALIDATION_ERROR', 'payment_status accepts a comma list of paid, pending, cancelled.');

					return;
				}

				$payment_status[] = $value;
			}
		}

		if (isset($get['date_from']) && trim((string)$get['date_from']) !== '') {
			$date_from = $skydropx->parseIsoDate(trim((string)$get['date_from']));

			if ($date_from === '') {
				$skydropx->error(422, 'VALIDATION_ERROR', 'date_from must be an ISO 8601 date.');

				return;
			}
		} else {
			$date_from = date('Y-m-d H:i:s', strtotime('-30 days'));
		}

		$result = $skydropx->getOrders([
			'store_id'       => (int)$this->config->get('config_store_id'),
			'page'           => $page,
			'limit'          => $limit,
			'payment_status' => array_values(array_unique($payment_status)),
			'date_from'      => $date_from
		]);

		$skydropx->json(200, [
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
