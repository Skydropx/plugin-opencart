<?php
namespace Opencart\Admin\Controller\Extension\Skydropx\Shipping;
/**
 * Class Skydropx
 *
 * The `skydropx` shipping method. It is switched on/off by Skydropx through
 * api/quotation; the merchant only chooses its sort order here.
 *
 * @package Opencart\Admin\Controller\Extension\Skydropx\Shipping
 */
class Skydropx extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/skydropx/shipping/skydropx');

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=shipping')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/skydropx/shipping/skydropx', $token)]
		];

		$data['save'] = $this->url->link('extension/skydropx/shipping/skydropx.save', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=shipping');
		$data['module'] = $this->url->link('extension/skydropx/module/skydropx', $token);

		$this->load->model('extension/skydropx/module/skydropx');

		$shipping = $this->model_extension_skydropx_module_skydropx->getSettings('shipping_skydropx');
		$settings = $this->model_extension_skydropx_module_skydropx->getSettings('skydropx');

		$data['quotation_status'] = !empty($shipping['shipping_skydropx_status']);
		$data['quotation_base_url'] = (string)($settings['skydropx_quotation_base_url'] ?? '');
		$data['shipping_skydropx_sort_order'] = (int)($shipping['shipping_skydropx_sort_order'] ?? 0);

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/skydropx/shipping/skydropx', $data));
	}

	public function save(): void {
		$this->load->language('extension/skydropx/shipping/skydropx');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/skydropx/shipping/skydropx')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('extension/skydropx/module/skydropx');

			// Only the sort order: the status belongs to the Skydropx connection.
			$this->model_extension_skydropx_module_skydropx->editValues('shipping_skydropx', [
				'shipping_skydropx_sort_order' => (string)(int)($this->request->post['shipping_skydropx_sort_order'] ?? 0)
			]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
