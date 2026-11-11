<?php
namespace Opencart\Admin\Model\Extension\Skydropx\Module;
/**
 * Class Skydropx
 *
 * Schema and settings for the Skydropx extension (admin side).
 *
 * @package Opencart\Admin\Model\Extension\Skydropx\Module
 */
class Skydropx extends \Opencart\System\Engine\Model {
	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "skydropx_shipment` (
			`shipment_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`tracking_number` VARCHAR(128) NOT NULL,
			`carrier` VARCHAR(64) NOT NULL DEFAULT '',
			`tracking_url` VARCHAR(512) NOT NULL DEFAULT '',
			`status` VARCHAR(32) NOT NULL DEFAULT 'CREATED',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`shipment_id`),
			UNIQUE KEY `order_tracking` (`order_id`, `tracking_number`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "skydropx_shipment_item` (
			`shipment_item_id` INT(11) NOT NULL AUTO_INCREMENT,
			`shipment_id` INT(11) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`order_product_id` INT(11) NOT NULL,
			`quantity` INT(11) NOT NULL,
			PRIMARY KEY (`shipment_item_id`),
			KEY `shipment_id` (`shipment_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "skydropx_tracking_event` (
			`tracking_event_id` INT(11) NOT NULL AUTO_INCREMENT,
			`shipment_id` INT(11) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`tracking_number` VARCHAR(128) NOT NULL,
			`status` VARCHAR(32) NOT NULL,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`tracking_event_id`),
			UNIQUE KEY `order_tracking_status` (`order_id`, `tracking_number`, `status`),
			KEY `shipment_id` (`shipment_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	public function uninstall(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "skydropx_tracking_event`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "skydropx_shipment_item`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "skydropx_shipment`");
	}

	/**
	 * Settings of one code on the default store, decoded.
	 */
	public function getSettings(string $code): array {
		$data = [];

		$query = $this->db->query("SELECT `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . $this->db->escape($code) . "'");

		foreach ($query->rows as $row) {
			$data[$row['key']] = $row['serialized'] ? json_decode($row['value'], true) : $row['value'];
		}

		return $data;
	}

	/**
	 * Writes only the given keys (core editSetting() would wipe every other
	 * key of the code, e.g. the values Skydropx stores through the API).
	 *
	 * @param array<string, mixed> $values
	 */
	public function editValues(string $code, array $values): void {
		foreach ($values as $key => $value) {
			$serialized = is_array($value);

			$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "'");
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . $this->db->escape($code) . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($serialized ? json_encode($value) : (string)$value) . "', `serialized` = '" . (int)$serialized . "'");
		}
	}

	public function deleteSettings(string $code): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . $this->db->escape($code) . "'");
	}

	public function generateApiKey(): string {
		return bin2hex(random_bytes(32));
	}
}
