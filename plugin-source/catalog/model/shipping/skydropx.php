<?php
namespace Opencart\Catalog\Model\Extension\Skydropx\Shipping;
/**
 * Class Skydropx
 *
 * Live Skydropx rates at checkout (CARRIER_SERVICE_V2). Calls the
 * quotation_base_url stored by api/quotation with a signed request.
 * Any failure returns no rates; checkout keeps working.
 *
 * @package Opencart\Catalog\Model\Extension\Skydropx\Shipping
 */
class Skydropx extends \Opencart\System\Engine\Model {
	private const TIMEOUT = 5;
	private const CACHE_TTL = 300;
	private const RATE_CURRENCY = 'MXN';

	/**
	 * @param array<string, mixed> $address
	 *
	 * @return array<string, mixed>
	 */
	public function getQuote(array $address): array {
		try {
			return $this->quote($address);
		} catch (\Throwable $e) {
			$this->log->write('PLUGIN_NAME: checkout quote skipped (' . get_class($e) . ').');

			return [];
		}
	}

	private function quote(array $address): array {
		$this->load->model('extension/skydropx/module/skydropx');

		$skydropx = $this->model_extension_skydropx_module_skydropx;

		$url = (string)$skydropx->getSetting('skydropx_quotation_base_url', '');

		if (!$skydropx->isEnabled() || !$skydropx->getSetting('shipping_skydropx_status', false) || !$skydropx->isValidUrl($url) || $skydropx->getApiKey() === '') {
			return [];
		}

		$items = [];
		$total_price = 0.0;

		foreach ($this->cart->getProducts() as $product) {
			if (empty($product['shipping'])) {
				continue;
			}

			$quantity = max(1, (int)$product['quantity']);

			// Cart line weight = (product + option weight) x quantity, in the product weight class.
			$unit_weight = (float)$product['weight'] / $quantity;

			$items[] = [
				'id'       => (int)$product['product_id'],
				'quantity' => $quantity,
				'weight'   => round($skydropx->toKg($unit_weight, (int)$product['weight_class_id']), 3),
				'width'    => round($skydropx->toCm((float)$product['width'], (int)$product['length_class_id']), 2),
				'height'   => round($skydropx->toCm((float)$product['height'], (int)$product['length_class_id']), 2),
				'length'   => round($skydropx->toCm((float)$product['length'], (int)$product['length_class_id']), 2)
			];

			$total_price += (float)$product['total'];
		}

		if (!$items) {
			return [];
		}

		$origin = $skydropx->getOrigin();

		$payload = [
			'address_from' => [
				'country'   => $origin['country_iso2'],
				'zip_code'  => $origin['postcode'],
				'state'     => $origin['zone_name'],
				'city'      => $origin['city'],
				'address'   => $origin['address'],
				'address_2' => $origin['address_2']
			],
			'address_to'   => [
				'country'   => (string)($address['iso_code_2'] ?? ''),
				'zip_code'  => (string)($address['postcode'] ?? ''),
				'state'     => (string)($address['zone'] ?? ''),
				'city'      => (string)($address['city'] ?? ''),
				'address'   => (string)($address['address_1'] ?? ''),
				'address_2' => (string)($address['address_2'] ?? '')
			],
			'items'        => $items,
			'total_price'  => round($total_price, 2)
		];

		$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		$rates = $this->fetchRates($skydropx, $url, $body);

		if (!$rates) {
			return [];
		}

		$this->load->language('extension/skydropx/shipping/skydropx');

		$base_currency = (string)$this->config->get('config_currency');
		$session_currency = (string)($this->session->data['currency'] ?? $base_currency);

		$quote_data = [];

		foreach ($rates as $rate) {
			if (!is_array($rate) || !isset($rate['total_price']) || !is_numeric($rate['total_price'])) {
				continue;
			}

			$service_code = $this->code((string)($rate['service_code'] ?? ''));

			if ($service_code === '') {
				continue;
			}

			if (isset($quote_data[$service_code])) {
				$service_code = $this->code((string)($rate['service_provider'] ?? '') . '_' . $service_code);
			}

			$cost = (float)$rate['total_price'];
			$rate_currency = strtoupper((string)($rate['currency'] ?? self::RATE_CURRENCY));

			// Quote cost must be in the store default currency.
			if ($rate_currency !== $base_currency) {
				if (!$this->currency->has($rate_currency) || !$this->currency->has($base_currency)) {
					continue;
				}

				$cost = $this->currency->convert($cost, $rate_currency, $base_currency);
			}

			$service_name = trim((string)($rate['service_name'] ?? ''));

			if ($service_name === '') {
				$service_name = trim((string)($rate['service_provider'] ?? '') . ' ' . $service_code);
			}

			$quote_data[$service_code] = [
				'code'         => 'skydropx.' . $service_code,
				'name'         => sprintf($this->language->get('text_rate_name'), $service_name),
				'cost'         => round($cost, 2),
				'tax_class_id' => 0,
				'text'         => $this->currency->format($cost, $session_currency)
			];
		}

		if (!$quote_data) {
			return [];
		}

		return [
			'code'       => 'skydropx',
			'name'       => $this->language->get('heading_title'),
			'quote'      => $quote_data,
			'sort_order' => (int)$skydropx->getSetting('shipping_skydropx_sort_order', 0),
			'error'      => false
		];
	}

	/**
	 * Signed POST to the quotation URL; responses are cached in the session
	 * per request body because checkout asks for quotes on several steps.
	 */
	private function fetchRates(object $skydropx, string $url, string $body): array {
		$key = hash('sha256', $url . "\n" . $body);
		$cache = $this->session->data['skydropx_quote'] ?? null;

		if (is_array($cache) && ($cache['key'] ?? '') === $key && ($cache['expires'] ?? 0) > time()) {
			return $cache['rates'];
		}

		$result = $skydropx->postSigned($url, $body, [], self::TIMEOUT);

		if ($result['status'] < 200 || $result['status'] >= 300) {
			$this->log->write('PLUGIN_NAME: quotation request failed (HTTP ' . $result['status'] . ($result['error'] !== '' ? ', ' . $result['error'] : '') . ').');

			return [];
		}

		$data = json_decode($result['body'], true);
		$rates = is_array($data) && isset($data['rates']) && is_array($data['rates']) ? array_values($data['rates']) : [];

		$this->session->data['skydropx_quote'] = ['key' => $key, 'expires' => time() + self::CACHE_TTL, 'rates' => $rates];

		return $rates;
	}

	private function code(string $value): string {
		return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)), '_');
	}
}
