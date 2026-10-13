<?php
namespace Opencart\Catalog\Controller\Extension\Klix\Payment;

use PurchaseObject;

require_once(DIR_EXTENSION . 'klix/system/library/PurchaseObject.php');

class Klix extends \Opencart\System\Engine\Controller {
	private $separator = '';
	private $version='1.0.4';
	
	public function __construct($registry) {
        parent::__construct($registry);

		if (VERSION >= '4.0.2.0') {
			$this->separator = '.';
		} else {
			$this->separator = '|';
		}
    }
	
	public function index(): string {

		
		$this->load->model('extension/klix/payment/klix');
		$klix_settings = $this->model_setting_setting->getSetting('payment_klix');

		$klix_payment_methods=$this->model_extension_klix_payment_klix->getPaymentMethodsFromAPI();
		$grouped_payment_methods=$this->model_extension_klix_payment_klix->getGroupedPaymentMethods($klix_payment_methods);
		$data['klix_status']=$klix_settings['payment_klix_status'];
		$data['klix_payment_methods']=$klix_payment_methods;
		$data['klix_grouped_payment_methods']=$grouped_payment_methods;
		$data['selected_country']=$this->session->data['shipping_address']['iso_code_2'];
		$data['klix_payment_checkout_title']='Klix payments';

		$data['separator'] = $this->separator;
	
		return $this->load->view('extension/klix/payment/klix', $data);
		
		return '';
	}
	
	private function getLanguageCode(): string
	{
		$this->load->model('localisation/language');
		$language = $this->language;
		$currentLanguageCode = $language->get('code');
		//en, et, lt, lv, ru
		if(!in_array($currentLanguageCode, ['en', 'et', 'lt', 'lv', 'ru'])) {
			$currentLanguageCode = 'en'; // Default to English if unsupported language
		}
		return $currentLanguageCode;
	}

	public function confirm(): void {

		$this->load->language('extension/klix/payment/klix');
		$this->load->model('extension/klix/payment/klix');

		$this->load->model('checkout/order');
		$this->load->model('account/order');
		$this->load->model('localisation/language');
		$this->load->model('localisation/order_status');

		if (empty($this->session->data['order_id'])) {
			print_r("Klix: no order_id in session");
			return;
		}

		$order_id = (int)$this->session->data['order_id'];

		$order_info = $this->model_checkout_order->getOrder($order_id);

		$purchaseObject = new PurchaseObject();

		$purchaseObject->client['email']          = $order_info['email'];
		$purchaseObject->client['phone']          = $order_info['telephone'];
		$purchaseObject->client['full_name']      = $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'];
		$purchaseObject->client['street_address'] = $order_info['shipping_address_1'] . ' ' . $order_info['shipping_address_2'];

		$order_products = $this->model_account_order->getProducts($order_id);
		$notes = '';
		foreach ($order_products as $product) {
			$notes .= $product['name'] . " " . $product['model'] . " x " . $product['quantity'] . '; ';
		}
		$purchaseObject->purchase['notes']    = $notes;
		$purchaseObject->purchase['language'] = $this->getLanguageCode();

		$product            = [];
		$product['name']    = 'Payment';
		$product['price']   = (int)round($order_info['total'] * 100);
		$purchaseObject->purchase['products'][] = $product;

		$purchaseObject->brand_id  = $this->config->get('payment_klix_brand_id');
		$purchaseObject->reference = $order_id;

		$success_url = html_entity_decode($this->url->link('extension/klix/payment/klix.success', 'order_id=' . $order_id, true));
		$cancel_url  = html_entity_decode($this->url->link('extension/klix/payment/klix.cancel',  'order_id=' . $order_id, true));
		$failure_url = html_entity_decode($this->url->link('extension/klix/payment/klix.failure', 'order_id=' . $order_id, true));

		$purchaseObject->success_redirect = $success_url;
		$purchaseObject->success_callback = $success_url;
		$purchaseObject->cancel_redirect  = $cancel_url;
		$purchaseObject->failure_redirect = $failure_url;

		$purchaseObject->platform      = 'opencart';
		$purchaseObject->creator_agent = 'OpenCart module: v' . $this->version;

		$createdPurchase = $this->model_extension_klix_payment_klix->createPurchase((array)$purchaseObject);

		if (isset($createdPurchase['id'])) {
			$order_statuses        = $this->model_localisation_order_status->getOrderStatuses();
			$processing_status_id  = 0;
			foreach ($order_statuses as $order_status) {
				if ($order_status['name'] == 'Processing') {
					$processing_status_id = (int)$order_status['order_status_id'];
				}
			}

			if ($processing_status_id) {
				$this->model_checkout_order->addHistory($order_id, $processing_status_id);
			}

			$payment_method = $this->request->post['payment_method'] ?? '';

			$data = json_encode([
				'purchase_id'    => $createdPurchase['id'],
				'payment_method' => $payment_method
			]);

			$this->model_extension_klix_payment_klix->updateOrderPaymentInfo($order_id, $data);

			header('Location: ' . $createdPurchase['checkout_url'] . '?preferred=' . urlencode($payment_method), true, 302);
			return;
		}

		echo "There was an error with Klix plugin configuration";
		if (is_array($createdPurchase)) {
			error_log('KLIX_DEBUG createPurchase response: ' . json_encode($createdPurchase));
		}
	}
			
	public function success():void
	{
		$this->load->language('extension/klix/payment/klix');
		$this->load->model('checkout/order');
		$this->load->model('extension/klix/payment/klix');
		$this->load->model('localisation/order_status');

		$order=$this->model_checkout_order->getOrder($_GET['order_id']);

		$purchase=$this->model_extension_klix_payment_klix->getPurchase($order['payment_custom_field']['purchase_id']);
		
		if($purchase['status']=='paid' && $order['order_status_id']==2)
		{
			$order_statuses = $this->model_localisation_order_status->getOrderStatuses();
			$processed_status_id=null;
			foreach($order_statuses as $order_status)
			{
				if($order_status['name']=='Processed') {
					$processed_status_id=$order_status['order_status_id'];
				}
			}

			$this->model_checkout_order->addHistory($_GET['order_id'], $processed_status_id,'',true);
			$data=json_encode([
				'purchase_id'=>$purchase['id'],
				'payment_method'=>$purchase['transaction_data']['payment_method'],
				'refund_availability'=>$purchase['refund_availability']
			]);
			$this->model_extension_klix_payment_klix->updateOrderPaymentInfo($_GET['order_id'],$data);

		}
		Header("Location: ".$this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true));
	}
}