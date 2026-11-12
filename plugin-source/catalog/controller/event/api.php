<?php
namespace Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Event;
/**
 * Class Api
 *
 * Route alias for the eco API contract. Eco always calls
 * index.php?route=extension/<CONTRACT_CODE>/api/<name>, whatever the
 * brand. OpenCart fires controller/<route>/before with &$route before it
 * loads the controller, so this listener rewrites that prefix to this
 * extension's own api/ routes.
 *   trigger: catalog/controller/extension/<CONTRACT_CODE>/api/<name>/before
 *
 * It only acts when this extension's code differs from CONTRACT_CODE and
 * the contract extension itself is not installed. The own route
 * extension/PLUGIN_SLUG/api/<name> keeps working either way.
 *
 * @package Opencart\Catalog\Controller\Extension\PLUGIN_NAMESPACE\Event
 */
class Api extends \Opencart\System\Engine\Controller {
	private const CONTRACT_CODE = 'skydropx';

	/**
	 * @param string       $route
	 * @param array<mixed> $args
	 */
	public function alias(string &$route, array &$args): void {
		$prefix = 'extension/' . self::CONTRACT_CODE . '/api/';

		if ('PLUGIN_SLUG' === self::CONTRACT_CODE || strpos($route, $prefix) !== 0 || is_dir(DIR_EXTENSION . self::CONTRACT_CODE . '/')) {
			return;
		}

		$route = 'extension/PLUGIN_SLUG/api/' . substr($route, strlen($prefix));
	}
}
