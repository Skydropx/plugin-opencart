<?php
namespace Opencart\Admin\Controller\Extension\PLUGIN_NAMESPACE\Event;
/**
 * Class Menu
 *
 * Adds a PLUGIN_NAME entry to the admin left menu, right after Extensions, that opens
 * the module page (connect button and settings), so the merchant does not have to go
 * through Extensions > Extensions > Modules.
 *   trigger: admin/view/common/column_left/before
 *
 * Registered by the module install and removed by its uninstall.
 *
 * @package Opencart\Admin\Controller\Extension\PLUGIN_NAMESPACE\Event
 */
class Menu extends \Opencart\System\Engine\Controller {
	private const MODULE_ROUTE = 'extension/PLUGIN_SLUG/module/PLUGIN_SLUG';

	/**
	 * @param string       $route
	 * @param array<mixed> $data  column_left view data
	 */
	public function add(string &$route, array &$data): void {
		if (!isset($this->session->data['user_token']) || !$this->user->hasPermission('access', self::MODULE_ROUTE)) {
			return;
		}

		$menus = (array)($data['menus'] ?? []);

		// Right after Extensions, or last when the user cannot see Extensions.
		$position = count($menus);

		foreach (array_values($menus) as $index => $menu) {
			if (($menu['id'] ?? '') === 'menu-extension') {
				$position = $index + 1;

				break;
			}
		}

		array_splice($menus, $position, 0, [[
			'id'       => 'menu-PLUGIN_SLUG',
			'icon'     => 'fa-solid fa-truck-fast',
			'name'     => 'PLUGIN_NAME',
			'href'     => $this->url->link(self::MODULE_ROUTE, 'user_token=' . $this->session->data['user_token']),
			'children' => []
		]]);

		$data['menus'] = $menus;
	}
}
