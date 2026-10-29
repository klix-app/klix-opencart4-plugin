<?php
namespace Opencart\Admin\Controller\Extension\Klix\Payment;
class Klix extends \Opencart\System\Engine\Controller {
	private $error = [];
	private $separator = '';
	
	public function __construct($registry) {
        parent::__construct($registry);

		if (VERSION >= '4.0.2.0') {
			$this->separator = '.';
		} else {
			$this->separator = '|';
		}
    }
	
	public function index(): void {
		$this->load->language('extension/klix/payment/klix');
				
		$this->document->setTitle($this->language->get('heading_title'));
			
		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extensions'),
			'href' => $this->url->link('marketplace/opencart/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/klix/payment/klix', 'user_token=' . $this->session->data['user_token'])
		];
		
		$data['save'] = $this->url->link('extension/klix/payment/klix' . $this->separator . 'save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');	


		$_config = new \Opencart\System\Engine\Config();
		$_config->addPath(DIR_EXTENSION . 'klix/system/config/');
		$_config->load('klix');

		$data['brand_id'] = $this->config->get('payment_klix_brand_id');
		$data['secret_key'] = $this->config->get('payment_klix_secret_key');

		$data['entry_brand_id']=$this->language->get('entry_brand_id');
		$data['entry_secret_key']=$this->language->get('entry_secret_key');
		$data['entry_status']=$this->language->get('entry_status');
		$data['entry_pay_later_widget']=$this->language->get('entry_pay_later_widget');
		$data['entry_minimum_amount_pay_later_widget']=$this->language->get('entry_minimum_amount_pay_later_widget');
		
		$this->load->model('localisation/order_status');

		$data['klix_status'] = $this->config->get('payment_klix_status');
		$data['klix_pay_later_widget'] = $this->config->get('payment_klix_pay_later_widget');
		$data['klix_minimum_amount_pay_later_widget'] = $this->config->get('payment_klix_minimum_amount_pay_later_widget');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		
		if (isset($this->request->post['payment_klix_processing_status_id'])) {
			$data['processing_status_id'] = (int)$this->request->post['payment_klix_processing_status_id'];
		} else {
			$data['processing_status_id'] = (int)$this->config->get('payment_klix_processing_status_id');
		}

		if (isset($this->request->post['payment_klix_processed_status_id'])) {
			$data['processed_status_id'] = (int)$this->request->post['payment_klix_processed_status_id'];
		} else {
			$data['processed_status_id'] = (int)$this->config->get('payment_klix_processed_status_id');
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/klix/payment/klix', $data));
	}
	
	public function save(): void {
		$this->load->language('extension/klix/payment/klix');

		$json = [];

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			$this->load->model('setting/setting');
			if (!isset($this->request->post['payment_klix_status'])) {
				$this->request->post['payment_klix_status'] = 0;
			}
			if (!isset($this->request->post['payment_klix_pay_later_widget'])) {
				$this->request->post['payment_klix_pay_later_widget'] = 0;
			}

			$this->model_setting_setting->editSetting('payment_klix', $this->request->post);

			$json['success'] = $this->language->get('success_save');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
			
	public function install(): void {
		$this->load->model('extension/klix/payment/klix');

		$this->model_extension_klix_payment_klix->install();

		$this->load->model('setting/setting');
		$settings = $this->model_setting_setting->getSetting('payment_klix');
		$settings['payment_klix_processing_status_id'] = $settings['payment_klix_processing_status_id'] ?? 2;
		$settings['payment_klix_processed_status_id']  = $settings['payment_klix_processed_status_id']  ?? 2;
		$this->model_setting_setting->editSetting('payment_klix', $settings);

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('klix_paylater');
		$this->model_setting_event->addEvent([
			'code'        => 'klix_paylater',
			'description' => 'Klix Pay Later widget on product page',
			'trigger'     => 'catalog/view/product/product/before',
			'action'      => 'extension/klix/payment/klix.widget',
			'status'      => 1,
			'sort_order'  => 0
		]);
	}
	
	public function uninstall(): void {
		$this->load->model('extension/klix/payment/klix');
		
		$this->model_extension_klix_payment_klix->uninstall();
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('klix_paylater');
	}
					
	public function order() {
		$this->load->model('extension/klix/payment/klix');

		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		if (!$order_id) {
			return '';
		}

		$order = $this->model_extension_klix_payment_klix->getOrder($order_id);
		if (!$order) {
			return '';
		}

		$custom = $order['payment_custom_field'] ?? '';
		$klix_info = is_string($custom) ? json_decode($custom, true) : $custom;

		if (empty($klix_info) || empty($klix_info['purchase_id'])) {
			return '';
		}

		$purchase = $this->model_extension_klix_payment_klix->getPurchase($klix_info['purchase_id']);

		$data['order_id']               = $order_id;
		$data['klix_purchase_id']       = $klix_info['purchase_id'];
		$data['klix_payment_method']    = $klix_info['payment_method'] ?? '';
		$data['klix_refund_availability']= $klix_info['refund_availability'] ?? '';

		$refundable = (is_array($purchase) && isset($purchase['refundable_amount']))
			? $purchase['refundable_amount'] / 100
			: 0;
		$data['klix_refundable_amount'] = $refundable;

		$data['user_token'] = $this->session->data['user_token'];
		$data['separator']  = $this->separator;

		return $this->load->view('extension/klix/payment/order', $data);
	}

	public function refund() {
		$this->load->language('extension/klix/payment/klix');
		
		$json = array();

		if (!empty($this->request->post['order_id']) and !empty($this->request->post['amount'])) {
			$this->load->model('extension/klix/payment/klix');
			$amount = floor($this->request->post['amount']);
			$response = $this->model_extension_klix_payment_klix->refund($this->request->post['order_id'],$amount);
			print_r($response);
			if(isset($response['status']) and  $response['status']=='success')
			{
				$json['error'] = false;
				$json['msg'] = 'Refund successful';
			} else {
				print_r($response);
				$json['error'] = false;
				$json['msg'] = 'Refund failed';
			}
		} 
		else {
			$json['error'] = true;
			$json['msg'] = 'Missing data';
		}

		$this->response->addHeader('Content-Type: application/json');
		echo json_encode($json);
	}
}