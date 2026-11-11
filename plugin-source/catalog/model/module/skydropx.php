<?php
namespace Opencart\Catalog\Model\Extension\Skydropx\Module;
/**
 * Class Skydropx
 *
 * Shared catalog logic for the Skydropx extension: settings, API key
 * authentication, JSON responses, order serialisation, shipments and
 * signed outbound HTTP calls.
 *
 * Load with $this->load->model('extension/skydropx/module/skydropx');
 *
 * @package Opencart\Catalog\Model\Extension\Skydropx\Module
 */
class Skydropx extends \Opencart\System\Engine\Model {
	public const EXTENSION_VERSION = 'PLUGIN_VERSION';

	public const DEFAULT_SHIPPED_STATUS_ID = 3;
	public const DEFAULT_DELIVERED_STATUS_ID = 5;
	public const DEFAULT_CANCELLED_STATUS_IDS = [7, 8, 10, 11, 13, 14, 16];

	/** Settings live on the default store (store_id 0). */
	private const SETTING_CODES = ['skydropx', 'module_skydropx', 'shipping_skydropx'];

	private ?array $settings = null;

	private array $unit_cache = [];

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	/**
	 * Reads the extension settings straight from the default store so the
	 * values are the same whichever store (host) handled the request.
	 */
	public function getSettings(): array {
		if ($this->settings === null) {
			$codes = array_map(fn ($code) => "'" . $this->db->escape($code) . "'", self::SETTING_CODES);

			$query = $this->db->query("SELECT `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` IN (" . implode(',', $codes) . ")");

			$this->settings = [];

			foreach ($query->rows as $row) {
				$this->settings[$row['key']] = $row['serialized'] ? json_decode($row['value'], true) : $row['value'];
			}
		}

		return $this->settings;
	}

	public function getSetting(string $key, mixed $default = null): mixed {
		$settings = $this->getSettings();

		return array_key_exists($key, $settings) ? $settings[$key] : $default;
	}

	public function setSetting(string $code, string $key, mixed $value): void {
		$serialized = is_array($value);
		$stored = $serialized ? json_encode($value) : (string)$value;

		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . $this->db->escape($code) . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($stored) . "', `serialized` = '" . (int)$serialized . "'");

		if ($this->settings !== null) {
			$this->settings[$key] = $value;
		}
	}

	/**
	 * OpenCart stores request input HTML-escaped (Request::clean); the API
	 * returns plain text.
	 */
	public function text(mixed $value): string {
		return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	public function getExtensionVersion(): string {
		return self::EXTENSION_VERSION;
	}

	public function isEnabled(): bool {
		return (bool)$this->getSetting('module_skydropx_status', false);
	}

	public function getApiKey(): string {
		return (string)$this->getSetting('skydropx_api_key', '');
	}

	/**
	 * Makes sure the `skydropx` shipping method is registered so checkout
	 * picks it up (the module installer normally does this already).
	 */
	public function ensureShippingExtension(): void {
		$query = $this->db->query("SELECT `extension_id` FROM `" . DB_PREFIX . "extension` WHERE `type` = 'shipping' AND `code` = 'skydropx'");

		if (!$query->num_rows) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "extension` SET `extension` = 'skydropx', `type` = 'shipping', `code` = 'skydropx'");
		}
	}

	// ---------------------------------------------------------------------
	// HTTP helpers for the API controllers
	// ---------------------------------------------------------------------

	/**
	 * Checks X-Skydropx-Api-Key and the enabled flag. Writes the error
	 * response and returns false when the request must stop.
	 */
	public function authorize(): bool {
		$provided = isset($this->request->server['HTTP_X_SKYDROPX_API_KEY']) ? (string)$this->request->server['HTTP_X_SKYDROPX_API_KEY'] : '';
		$key = $this->getApiKey();

		if ($key === '' || $provided === '' || !hash_equals($key, $provided)) {
			$this->error(401, 'INVALID_API_KEY', 'Missing or invalid X-Skydropx-Api-Key header.');

			return false;
		}

		if (!$this->isEnabled()) {
			$this->error(403, 'EXTENSION_DISABLED', 'The PLUGIN_NAME extension is disabled in this store.');

			return false;
		}

		return true;
	}

	public function json(int $status, array $data): void {
		http_response_code($status);

		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
	}

	public function error(int $status, string $code, string $message = ''): void {
		$error = ['code' => $code];

		if ($message !== '') {
			$error['message'] = $message;
		}

		$this->json($status, ['error' => $error]);
	}

	/**
	 * Decodes the raw JSON request body. Returns null when it is not a JSON object.
	 */
	public function readJsonBody(): ?array {
		$raw = file_get_contents('php://input');

		if ($raw === false || trim($raw) === '') {
			return null;
		}

		$data = json_decode($raw, true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Store home URL used to identify the shop in outbound calls.
	 */
	public function getStoreUrl(): string {
		return (string)$this->config->get('config_url');
	}

	/**
	 * Origin address from the extension settings. Empty strings when not
	 * configured (never placeholder data).
	 */
	public function getOrigin(): array {
		$country_iso2 = '';
		$zone_name = '';
		$zone_code = '';

		$country_id = (int)$this->getSetting('skydropx_origin_country_id', 0);
		$zone_id = (int)$this->getSetting('skydropx_origin_zone_id', 0);

		if ($country_id) {
			$query = $this->db->query("SELECT `iso_code_2` FROM `" . DB_PREFIX . "country` WHERE `country_id` = '" . (int)$country_id . "'");

			$country_iso2 = (string)($query->row['iso_code_2'] ?? '');
		}

		if ($zone_id) {
			if (version_compare(VERSION, '4.1.0.0', '>=')) {
				// 4.1 moved zone names to oc_zone_description.
				$query = $this->db->query("SELECT `zd`.`name`, `z`.`code` FROM `" . DB_PREFIX . "zone` `z` LEFT JOIN `" . DB_PREFIX . "zone_description` `zd` ON (`zd`.`zone_id` = `z`.`zone_id`) WHERE `z`.`zone_id` = '" . (int)$zone_id . "' ORDER BY (`zd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "') DESC LIMIT 1");
			} else {
				$query = $this->db->query("SELECT `name`, `code` FROM `" . DB_PREFIX . "zone` WHERE `zone_id` = '" . (int)$zone_id . "'");
			}

			$zone_name = $this->text($query->row['name'] ?? '');
			$zone_code = (string)($query->row['code'] ?? '');
		}

		return [
			'name'         => $this->text($this->getSetting('skydropx_origin_name', '')),
			'owner'        => $this->text($this->getSetting('skydropx_origin_owner', '')),
			'email'        => $this->text($this->getSetting('skydropx_origin_email', '')),
			'telephone'    => $this->text($this->getSetting('skydropx_origin_telephone', '')),
			'address'      => $this->text($this->getSetting('skydropx_origin_address', '')),
			'address_2'    => $this->text($this->getSetting('skydropx_origin_address_2', '')),
			'city'         => $this->text($this->getSetting('skydropx_origin_city', '')),
			'postcode'     => $this->text($this->getSetting('skydropx_origin_postcode', '')),
			'country_iso2' => $country_iso2,
			'zone_name'    => $zone_name,
			'zone_code'    => $zone_code
		];
	}

	// ---------------------------------------------------------------------
	// Outbound signed calls
	// ---------------------------------------------------------------------

	public function sign(string $body): string {
		return hash_hmac('sha256', $body, $this->getApiKey());
	}

	/**
	 * POSTs a signed JSON body. Never throws; returns status/body/error.
	 *
	 * @param array<string, string> $headers extra headers (name => value)
	 */
	public function postSigned(string $url, string $body, array $headers, int $timeout, string $store_url = ''): array {
		$result = ['status' => 0, 'body' => '', 'error' => ''];

		if (!function_exists('curl_init')) {
			$result['error'] = 'curl extension not available';

			return $result;
		}

		$lines = [
			'Content-Type: application/json',
			'Accept: application/json',
			'X-Skydropx-Store-Url: ' . ($store_url !== '' ? $store_url : $this->getStoreUrl()),
			'X-Skydropx-Signature: ' . $this->sign($body),
			'User-Agent: PLUGIN_SLUG-opencart/' . self::EXTENSION_VERSION
		];

		foreach ($headers as $name => $value) {
			$lines[] = $name . ': ' . str_replace(["\r", "\n"], '', (string)$value);
		}

		$curl = curl_init($url);

		curl_setopt_array($curl, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS
		]);

		$response = curl_exec($curl);

		if ($response === false) {
			$result['error'] = 'curl error ' . curl_errno($curl);
		} else {
			$result['body'] = (string)$response;
		}

		// No curl_close(): a no-op since PHP 8.0 (deprecated in 8.5); the handle is freed with $curl.
		$result['status'] = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

		return $result;
	}

	/**
	 * Strict boolean from JSON: true/false, 1/0, "true"/"false". Null when invalid.
	 */
	public function parseBool(mixed $value): ?bool {
		if (is_bool($value)) {
			return $value;
		}

		if ($value === 1 || $value === '1' || $value === 'true') {
			return true;
		}

		if ($value === 0 || $value === '0' || $value === 'false') {
			return false;
		}

		return null;
	}

	/**
	 * Positive integer from JSON (int or digit string). Null when invalid.
	 */
	public function parseId(mixed $value): ?int {
		if (is_int($value) && $value > 0) {
			return $value;
		}

		if (is_string($value) && preg_match('/^\d{1,10}$/', $value) && (int)$value > 0) {
			return (int)$value;
		}

		return null;
	}

	public function isValidUrl(string $url): bool {
		if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

		return in_array($scheme, ['http', 'https'], true);
	}

	// ---------------------------------------------------------------------
	// Status mapping
	// ---------------------------------------------------------------------

	/** paid = config_processing_status ∪ config_complete_status of the store. */
	public function getPaidStatusIds(): array {
		$ids = array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status'));

		return $this->intList($ids);
	}

	public function getCancelledStatusIds(): array {
		$ids = $this->getSetting('skydropx_cancelled_status_ids', null);

		if (!is_array($ids)) {
			$ids = self::DEFAULT_CANCELLED_STATUS_IDS;
		}

		return $this->intList($ids);
	}

	public function getShippedStatusId(): int {
		$id = (int)$this->getSetting('skydropx_shipped_status_id', 0);

		return $id > 0 ? $id : self::DEFAULT_SHIPPED_STATUS_ID;
	}

	public function getDeliveredStatusId(): int {
		$id = (int)$this->getSetting('skydropx_delivered_status_id', 0);

		return $id > 0 ? $id : self::DEFAULT_DELIVERED_STATUS_ID;
	}

	public function getPaymentStatus(int $order_status_id): string {
		if (in_array($order_status_id, $this->getPaidStatusIds(), true)) {
			return 'paid';
		}

		if (in_array($order_status_id, $this->getCancelledStatusIds(), true)) {
			return 'cancelled';
		}

		return 'pending';
	}

	private function intList(array $values): array {
		$ids = [];

		foreach ($values as $value) {
			if (is_numeric($value) && (int)$value > 0) {
				$ids[] = (int)$value;
			}
		}

		return array_values(array_unique($ids));
	}

	/** SQL list for IN (...); "0" when empty (no real status has id 0 among shippable orders). */
	private function sqlIn(array $ids): string {
		return $ids ? implode(',', array_map('intval', $ids)) : '0';
	}

	// ---------------------------------------------------------------------
	// Orders
	// ---------------------------------------------------------------------

	/**
	 * @param array{store_id:int, page:int, limit:int, payment_status:string[], date_from:string} $filter payment_status: paid and/or pending
	 */
	public function getOrders(array $filter): array {
		$where = $this->baseOrderWhere((int)$filter['store_id']);

		$where[] = "`o`.`date_added` >= '" . $this->db->escape($filter['date_from']) . "'";

		if (!empty($filter['payment_status'])) {
			$paid = $this->sqlIn($this->getPaidStatusIds());
			$cancelled = $this->sqlIn($this->getCancelledStatusIds());

			$or = [];

			foreach ($filter['payment_status'] as $payment_status) {
				if ($payment_status == 'paid') {
					$or[] = "`o`.`order_status_id` IN (" . $paid . ")";
				} elseif ($payment_status == 'pending') {
					$or[] = "(`o`.`order_status_id` NOT IN (" . $paid . ") AND `o`.`order_status_id` NOT IN (" . $cancelled . "))";
				}
			}

			if ($or) {
				$where[] = '(' . implode(' OR ', $or) . ')';
			}
		}

		$sql_where = " WHERE " . implode(' AND ', $where);

		$total = (int)$this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "order` `o`" . $sql_where)->row['total'];

		$start = ((int)$filter['page'] - 1) * (int)$filter['limit'];

		$query = $this->db->query($this->orderSelect() . $sql_where . " ORDER BY `o`.`date_added` DESC, `o`.`order_id` DESC LIMIT " . (int)$start . "," . (int)$filter['limit']);

		return ['total' => $total, 'orders' => $this->formatOrders($query->rows)];
	}

	/**
	 * One shippable order (order_status_id > 0 and a shipping method).
	 * $store_id null = any store.
	 */
	public function getOrder(int $order_id, ?int $store_id): array {
		$where = $this->baseOrderWhere($store_id);
		$where[] = "`o`.`order_id` = '" . (int)$order_id . "'";

		$query = $this->db->query($this->orderSelect() . " WHERE " . implode(' AND ', $where));

		if (!$query->num_rows) {
			return [];
		}

		return $this->formatOrders($query->rows)[0];
	}

	/** Raw order row (any status) for write endpoints. */
	public function getOrderRow(int $order_id, int $store_id): array {
		$query = $this->db->query("SELECT `order_id`, `store_id`, `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' AND `store_id` = '" . (int)$store_id . "' AND `order_status_id` > '0'");

		return $query->row;
	}

	public function getOrderStatusId(int $order_id): int {
		$query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

		return $query->num_rows ? (int)$query->row['order_status_id'] : 0;
	}

	public function getTotalOrderHistories(int $order_id): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = '" . (int)$order_id . "'");

		return (int)$query->row['total'];
	}

	private function baseOrderWhere(?int $store_id): array {
		$where = [
			"`o`.`order_status_id` > '0'",
			"COALESCE(`o`.`shipping_method`, '') <> ''"
		];

		if ($store_id !== null) {
			$where[] = "`o`.`store_id` = '" . (int)$store_id . "'";
		}

		return $where;
	}

	private function orderSelect(): string {
		return "SELECT `o`.`order_id`, `o`.`store_id`, `o`.`date_added`, `o`.`date_modified`, `o`.`order_status_id`, `o`.`total`,"
			. " `o`.`shipping_method`, `o`.`payment_method`, `o`.`firstname`, `o`.`lastname`, `o`.`email`, `o`.`telephone`,"
			. " `o`.`shipping_firstname`, `o`.`shipping_lastname`, `o`.`shipping_company`, `o`.`shipping_address_1`, `o`.`shipping_address_2`,"
			. " `o`.`shipping_city`, `o`.`shipping_postcode`, `o`.`shipping_zone`,"
			. " `os`.`name` AS `order_status`, `c`.`iso_code_2` AS `shipping_iso_code_2`, `z`.`code` AS `shipping_zone_code`"
			. " FROM `" . DB_PREFIX . "order` `o`"
			. " LEFT JOIN `" . DB_PREFIX . "order_status` `os` ON (`os`.`order_status_id` = `o`.`order_status_id` AND `os`.`language_id` = '" . (int)$this->config->get('config_language_id') . "')"
			. " LEFT JOIN `" . DB_PREFIX . "country` `c` ON (`c`.`country_id` = `o`.`shipping_country_id`)"
			. " LEFT JOIN `" . DB_PREFIX . "zone` `z` ON (`z`.`zone_id` = `o`.`shipping_zone_id`)";
	}

	private function formatOrders(array $rows): array {
		if (!$rows) {
			return [];
		}

		$order_ids = array_map(fn ($row) => (int)$row['order_id'], $rows);

		$products = $this->getOrderProducts($order_ids);
		$shipments = $this->getShipments($order_ids);
		$currency = (string)$this->config->get('config_currency');

		$orders = [];

		foreach ($rows as $row) {
			$order_id = (int)$row['order_id'];

			$shipping_method = $this->decodeMethod($row['shipping_method']);
			$payment_method = $this->decodeMethod($row['payment_method']);

			$lines = $products[$order_id] ?? [];

			$fulfilled = true;

			foreach ($lines as $line) {
				if ($line['quantity_to_ship'] > 0) {
					$fulfilled = false;
				}
			}

			$orders[] = [
				'order_id'           => $order_id,
				'order_number'       => (string)$order_id,
				'store_id'           => (int)$row['store_id'],
				'date_added'         => $this->isoDate($row['date_added']),
				'date_modified'      => $this->isoDate($row['date_modified']),
				'order_status_id'    => (int)$row['order_status_id'],
				'order_status'       => $this->text($row['order_status'] ?? ''),
				'payment_status'     => $this->getPaymentStatus((int)$row['order_status_id']),
				'fulfillment_status' => $fulfilled ? 'fulfilled' : 'unfulfilled',
				'currency'           => $currency,
				'total'              => round((float)$row['total'], 2),
				'is_pickup'          => str_starts_with($shipping_method['code'], 'pickup.'),
				'is_cod'             => str_starts_with($payment_method['code'], 'cod'),
				'shipping_method'    => [
					'name' => $this->text($shipping_method['name']),
					'code' => $shipping_method['code'],
					'cost' => round($shipping_method['cost'], 2)
				],
				'payment_method'     => [
					'name' => $this->text($payment_method['name']),
					'code' => $payment_method['code']
				],
				'customer'           => [
					'firstname' => $this->text($row['firstname']),
					'lastname'  => $this->text($row['lastname']),
					'email'     => $this->text($row['email']),
					'telephone' => $this->text($row['telephone'])
				],
				'shipping_address'   => [
					'firstname'    => $this->text($row['shipping_firstname']),
					'lastname'     => $this->text($row['shipping_lastname']),
					'company'      => $this->text($row['shipping_company']),
					'address_1'    => $this->text($row['shipping_address_1']),
					'address_2'    => $this->text($row['shipping_address_2']),
					'city'         => $this->text($row['shipping_city']),
					'postcode'     => $this->text($row['shipping_postcode']),
					'country_iso2' => (string)($row['shipping_iso_code_2'] ?? ''),
					'zone_name'    => $this->text($row['shipping_zone']),
					'zone_code'    => (string)($row['shipping_zone_code'] ?? '')
				],
				'products'           => $lines,
				'shipments'          => $shipments[$order_id] ?? []
			];
		}

		return $orders;
	}

	/**
	 * 4.x stores shipping_method / payment_method as JSON ({name, code, cost, ...}).
	 */
	private function decodeMethod(?string $value): array {
		$method = ['name' => '', 'code' => '', 'cost' => 0.0];

		if ($value === null || $value === '') {
			return $method;
		}

		$data = json_decode($value, true);

		if (is_array($data)) {
			$method['name'] = (string)($data['name'] ?? ($data['title'] ?? ''));
			$method['code'] = (string)($data['code'] ?? '');
			$method['cost'] = (float)($data['cost'] ?? 0);
		} else {
			// Defensive: plain-text method name.
			$method['name'] = $value;
		}

		return $method;
	}

	/**
	 * Lines that need shipping, with current product weight/dimensions per unit.
	 */
	private function getOrderProducts(array $order_ids): array {
		if (version_compare(VERSION, '4.1.0.0', '>=')) {
			// 4.1 moved SKU to oc_product_code (identifier "SKU").
			$sku = "(SELECT `pc`.`value` FROM `" . DB_PREFIX . "product_code` `pc` WHERE `pc`.`product_id` = `op`.`product_id` AND `pc`.`code` = 'SKU' AND `pc`.`value` <> '' LIMIT 1)";
		} else {
			$sku = "`p`.`sku`";
		}

		$query = $this->db->query("SELECT `op`.`order_product_id`, `op`.`order_id`, `op`.`product_id`, `op`.`name`, `op`.`model`, `op`.`quantity`, `op`.`price`,"
			. " `p`.`shipping`, `p`.`weight`, `p`.`weight_class_id`, `p`.`length`, `p`.`width`, `p`.`height`, `p`.`length_class_id`, " . $sku . " AS `sku`"
			. " FROM `" . DB_PREFIX . "order_product` `op`"
			. " LEFT JOIN `" . DB_PREFIX . "product` `p` ON (`p`.`product_id` = `op`.`product_id`)"
			. " WHERE `op`.`order_id` IN (" . $this->sqlIn($order_ids) . ") ORDER BY `op`.`order_id`, `op`.`order_product_id`");

		$shipped = $this->getShippedQuantities($order_ids);

		$products = [];

		foreach ($query->rows as $row) {
			// Products that do not require shipping (downloads, services) are not shippable lines.
			// A deleted product (NULL) is kept: it was shippable when ordered.
			if ($row['shipping'] !== null && !(int)$row['shipping']) {
				continue;
			}

			$order_product_id = (int)$row['order_product_id'];
			$quantity = (int)$row['quantity'];
			$to_ship = max(0, $quantity - ($shipped[$order_product_id] ?? 0));

			$sku = trim((string)($row['sku'] ?? ''));

			$products[(int)$row['order_id']][] = [
				'order_product_id' => $order_product_id,
				'product_id'       => (int)$row['product_id'],
				'name'             => $this->text($row['name']),
				'model'            => $this->text($row['model']),
				'sku'              => $this->text($sku !== '' ? $sku : $row['model']),
				'quantity'         => $quantity,
				'quantity_to_ship' => $to_ship,
				'price'            => round((float)$row['price'], 2),
				'weight_kg'        => round($this->toKg((float)$row['weight'], (int)$row['weight_class_id']), 3),
				'length_cm'        => round($this->toCm((float)$row['length'], (int)$row['length_class_id']), 2),
				'width_cm'         => round($this->toCm((float)$row['width'], (int)$row['length_class_id']), 2),
				'height_cm'        => round($this->toCm((float)$row['height'], (int)$row['length_class_id']), 2)
			];
		}

		return $products;
	}

	/**
	 * Shippable order lines of one order keyed by order_product_id.
	 */
	public function getShippableLines(int $order_id): array {
		$lines = [];

		foreach ($this->getOrderProducts([$order_id])[$order_id] ?? [] as $line) {
			$lines[$line['order_product_id']] = $line;
		}

		return $lines;
	}

	// ---------------------------------------------------------------------
	// Units (OpenCart weight/length classes -> kg/cm)
	// ---------------------------------------------------------------------

	public function toKg(float $value, int $weight_class_id): float {
		return $this->convertUnit('weight', $value, $weight_class_id);
	}

	public function toCm(float $value, int $length_class_id): float {
		return $this->convertUnit('length', $value, $length_class_id);
	}

	/**
	 * Uses the class `value` ratios (the same maths as OpenCart's Weight/Length
	 * libraries) against the class whose unit is kg/cm. Falls back to known
	 * unit factors when the store has no kg/cm class.
	 */
	private function convertUnit(string $type, float $value, int $class_id): float {
		if ($value == 0.0) {
			return 0.0;
		}

		$classes = $this->getUnitClasses($type);
		$target = $type == 'weight' ? 'kg' : 'cm';
		$factors = $type == 'weight'
			? ['kg' => 1.0, 'g' => 0.001, 'lb' => 0.45359237, 'oz' => 0.028349523125]
			: ['cm' => 1.0, 'mm' => 0.1, 'in' => 2.54, 'm' => 100.0];

		$from = $classes['by_id'][$class_id] ?? ($classes['by_id'][(int)$this->config->get('config_' . $type . '_class_id')] ?? null);

		if ($from === null) {
			return $value;
		}

		$to = $classes['by_unit'][$target] ?? null;

		if ($to !== null && $from['value'] > 0) {
			return $value * ($to['value'] / $from['value']);
		}

		return isset($factors[$from['unit']]) ? $value * $factors[$from['unit']] : $value;
	}

	private function getUnitClasses(string $type): array {
		if (!isset($this->unit_cache[$type])) {
			$table = $type == 'weight' ? 'weight_class' : 'length_class';
			$id = $table . '_id';

			$query = $this->db->query("SELECT `c`.`" . $id . "` AS `id`, `c`.`value`, LOWER(TRIM(`d`.`unit`)) AS `unit` FROM `" . DB_PREFIX . $table . "` `c` LEFT JOIN `" . DB_PREFIX . $table . "_description` `d` ON (`d`.`" . $id . "` = `c`.`" . $id . "`)");

			$classes = ['by_id' => [], 'by_unit' => []];

			foreach ($query->rows as $row) {
				$class = ['value' => (float)$row['value'], 'unit' => (string)$row['unit']];

				if (!isset($classes['by_id'][(int)$row['id']])) {
					$classes['by_id'][(int)$row['id']] = $class;
				}

				if (!isset($classes['by_unit'][$class['unit']])) {
					$classes['by_unit'][$class['unit']] = $class;
				}
			}

			$this->unit_cache[$type] = $classes;
		}

		return $this->unit_cache[$type];
	}

	// ---------------------------------------------------------------------
	// Shipments and tracking
	// ---------------------------------------------------------------------

	private function getShipments(array $order_ids): array {
		$query = $this->db->query("SELECT `order_id`, `tracking_number`, `carrier`, `tracking_url`, `date_added` FROM `" . DB_PREFIX . "skydropx_shipment` WHERE `order_id` IN (" . $this->sqlIn($order_ids) . ") ORDER BY `shipment_id`");

		$shipments = [];

		foreach ($query->rows as $row) {
			$shipments[(int)$row['order_id']][] = [
				'tracking_number' => (string)$row['tracking_number'],
				'carrier'         => (string)$row['carrier'],
				'tracking_url'    => (string)$row['tracking_url'],
				'date_added'      => $this->isoDate($row['date_added'])
			];
		}

		return $shipments;
	}

	private function getShippedQuantities(array $order_ids): array {
		$query = $this->db->query("SELECT `order_product_id`, SUM(`quantity`) AS `quantity` FROM `" . DB_PREFIX . "skydropx_shipment_item` WHERE `order_id` IN (" . $this->sqlIn($order_ids) . ") GROUP BY `order_product_id`");

		$shipped = [];

		foreach ($query->rows as $row) {
			$shipped[(int)$row['order_product_id']] = (int)$row['quantity'];
		}

		return $shipped;
	}

	public function getShipment(int $order_id, string $tracking_number): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "skydropx_shipment` WHERE `order_id` = '" . (int)$order_id . "' AND `tracking_number` = '" . $this->db->escape($tracking_number) . "'");

		return $query->row;
	}

	/**
	 * Inserts the shipment unless (order_id, tracking_number) already exists.
	 * Returns the new shipment_id, or 0 when it already existed.
	 */
	public function addShipment(int $order_id, string $tracking_number, string $carrier, string $tracking_url, string $status = 'CREATED'): int {
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "skydropx_shipment` SET `order_id` = '" . (int)$order_id . "', `tracking_number` = '" . $this->db->escape($tracking_number) . "', `carrier` = '" . $this->db->escape($carrier) . "', `tracking_url` = '" . $this->db->escape($tracking_url) . "', `status` = '" . $this->db->escape($status) . "', `date_added` = NOW(), `date_modified` = NOW()");

		if ($this->db->countAffected() < 1) {
			return 0;
		}

		return (int)$this->db->getLastId();
	}

	public function addShipmentItem(int $shipment_id, int $order_id, int $order_product_id, int $quantity): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "skydropx_shipment_item` SET `shipment_id` = '" . (int)$shipment_id . "', `order_id` = '" . (int)$order_id . "', `order_product_id` = '" . (int)$order_product_id . "', `quantity` = '" . (int)$quantity . "'");
	}

	public function updateShipment(int $shipment_id, string $status, string $carrier, string $tracking_url): void {
		$sql = "UPDATE `" . DB_PREFIX . "skydropx_shipment` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW()";

		if ($carrier !== '') {
			$sql .= ", `carrier` = '" . $this->db->escape($carrier) . "'";
		}

		if ($tracking_url !== '') {
			$sql .= ", `tracking_url` = '" . $this->db->escape($tracking_url) . "'";
		}

		$this->db->query($sql . " WHERE `shipment_id` = '" . (int)$shipment_id . "'");
	}

	/**
	 * Records a tracking event unless (order_id, tracking_number, status) exists.
	 */
	public function addTrackingEvent(int $shipment_id, int $order_id, string $tracking_number, string $status): bool {
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "skydropx_tracking_event` SET `shipment_id` = '" . (int)$shipment_id . "', `order_id` = '" . (int)$order_id . "', `tracking_number` = '" . $this->db->escape($tracking_number) . "', `status` = '" . $this->db->escape($status) . "', `date_added` = NOW()");

		return $this->db->countAffected() > 0;
	}

	public function allShipmentsDelivered(int $order_id): bool {
		$query = $this->db->query("SELECT COUNT(*) AS `total`, SUM(`status` = 'DELIVERED') AS `delivered` FROM `" . DB_PREFIX . "skydropx_shipment` WHERE `order_id` = '" . (int)$order_id . "'");

		return (int)$query->row['total'] > 0 && (int)$query->row['total'] == (int)$query->row['delivered'];
	}

	// ---------------------------------------------------------------------
	// Dates
	// ---------------------------------------------------------------------

	/**
	 * OpenCart writes NOW() in the store time zone (startup/setting aligns the
	 * PHP and MySQL session zones), so DB values are read in PHP's zone.
	 */
	public function isoDate(?string $value): string {
		if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
			return '';
		}

		try {
			$date = new \DateTime($value, new \DateTimeZone(date_default_timezone_get()));
			$date->setTimezone(new \DateTimeZone('UTC'));

			return $date->format('Y-m-d\TH:i:s\Z');
		} catch (\Exception $e) {
			return '';
		}
	}

	/**
	 * ISO 8601 date/date-time (UTC unless an offset is given) -> store-local
	 * "Y-m-d H:i:s" for SQL. Returns '' when invalid.
	 */
	public function parseIsoDate(string $value): string {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
			return '';
		}

		try {
			$date = new \DateTime($value, new \DateTimeZone('UTC'));
		} catch (\Exception $e) {
			return '';
		}

		$errors = \DateTime::getLastErrors();

		if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
			return '';
		}

		$date->setTimezone(new \DateTimeZone(date_default_timezone_get()));

		return $date->format('Y-m-d H:i:s');
	}
}
