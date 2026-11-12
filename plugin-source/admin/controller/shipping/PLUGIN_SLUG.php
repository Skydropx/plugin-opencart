<?php
namespace Opencart\Admin\Controller\Extension\PLUGIN_NAMESPACE\Shipping;
/**
 * Class PLUGIN_NAMESPACE
 *
 * The `PLUGIN_SLUG` shipping method. It is switched on/off by PLUGIN_NAME through
 * api/quotation; the merchant only chooses its sort order here.
 *
 * @package Opencart\Admin\Controller\Extension\PLUGIN_NAMESPACE\Shipping
 */
class PLUGIN_NAMESPACE extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG');

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=shipping')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG', $token)]
		];

		$data['save'] = $this->url->link('extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG.save', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=shipping');
		$data['module'] = $this->url->link('extension/PLUGIN_SLUG/module/PLUGIN_SLUG', $token);

		$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

		$shipping = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('shipping_PLUGIN_SLUG');
		$settings = $this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->getSettings('PLUGIN_SLUG');

		$data['quotation_status'] = !empty($shipping['shipping_PLUGIN_SLUG_status']);
		$data['quotation_base_url'] = (string)($settings['PLUGIN_SLUG_quotation_base_url'] ?? '');
		$data['shipping_PLUGIN_SLUG_sort_order'] = (int)($shipping['shipping_PLUGIN_SLUG_sort_order'] ?? 0);

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG', $data));
	}

	public function save(): void {
		$this->load->language('extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/PLUGIN_SLUG/shipping/PLUGIN_SLUG')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('extension/PLUGIN_SLUG/module/PLUGIN_SLUG');

			// Only the sort order: the status belongs to the PLUGIN_NAME connection.
			$this->model_extension_PLUGIN_SLUG_module_PLUGIN_SLUG->editValues('shipping_PLUGIN_SLUG', [
				'shipping_PLUGIN_SLUG_sort_order' => (string)(int)($this->request->post['shipping_PLUGIN_SLUG_sort_order'] ?? 0)
			]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
