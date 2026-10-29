<?php
namespace Opencart\Catalog\Controller\Extension\Klix\Payment;

use PurchaseObject;

require_once(DIR_EXTENSION . 'klix/system/library/PurchaseObject.php');

class Klix extends \Opencart\System\Engine\Controller {
	private $separator = '';
	private $version='1.2.0';
	
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
		$failure_url = html_entity_decode($this->url->link('extension/klix/payment/klix.cancel', 'order_id=' . $order_id, true));

		$purchaseObject->success_redirect = $success_url;
		$purchaseObject->success_callback = $success_url;
		$purchaseObject->cancel_redirect  = $cancel_url;
		$purchaseObject->failure_redirect = $failure_url;

		$purchaseObject->platform      = 'opencart';
		$purchaseObject->creator_agent = 'OpenCart module: v' . $this->version;

		$createdPurchase = $this->model_extension_klix_payment_klix->createPurchase((array)$purchaseObject);

		if (isset($createdPurchase['id'])) {
			$processing_status_id = (int)$this->config->get('payment_klix_processing_status_id');
			if ($processing_status_id) {
				$this->model_checkout_order->addHistory($this->session->data['order_id'], $processing_status_id);
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
			
	public function success(): void {
		$this->load->language('extension/klix/payment/klix');
		$this->load->model('checkout/order');
		$this->load->model('extension/klix/payment/klix');

		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;

		if (!$order_id) {
			$this->response->redirect($this->url->link('checkout/cart'));
			return;
		}

		$order = $this->model_checkout_order->getOrder($order_id);
		if (!$order) {
			$this->response->redirect($this->url->link('checkout/cart'));
			return;
		}

		$custom = $order['payment_custom_field'] ?? '';
		$klix_info = is_string($custom) ? json_decode($custom, true) : $custom;

		if (!empty($klix_info) && !empty($klix_info['purchase_id'])) {
			$purchase = $this->model_extension_klix_payment_klix->getPurchase($klix_info['purchase_id']);

			$processing_status_id = (int)$this->config->get('payment_klix_processing_status_id');
			$processed_status_id  = (int)$this->config->get('payment_klix_processed_status_id');

			if (is_array($purchase)
				&& ($purchase['status'] ?? '') == 'paid'
				&& $processed_status_id
				&& (int)$order['order_status_id'] == $processing_status_id
			) {
				$this->model_checkout_order->addHistory($order_id, $processed_status_id, '', true);

				$data = json_encode([
					'purchase_id'         => $purchase['id'] ?? '',
					'payment_method'      => $purchase['transaction_data']['payment_method'] ?? '',
					'refund_availability' => $purchase['refund_availability'] ?? ''
				]);
				$this->model_extension_klix_payment_klix->updateOrderPaymentInfo($order_id, $data);
			}
		}

		$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true));
	}

	public function cancel(): void {
		$this->load->language('extension/klix/payment/klix');
		$this->load->model('checkout/order');
		$this->load->model('extension/klix/payment/klix');

		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		if (!$order_id) {
			$this->response->redirect($this->url->link('checkout/cart'));
			return;
		}

		$order = $this->model_checkout_order->getOrder($order_id);
		if (!$order) {
			$this->response->redirect($this->url->link('checkout/cart'));
			return;
		}

		$custom = $order['payment_custom_field'] ?? '';
		$klix_info = is_string($custom) ? json_decode($custom, true) : $custom;

		$processing_status_id = (int)$this->config->get('payment_klix_processing_status_id');
		$processed_status_id  = (int)$this->config->get('payment_klix_processed_status_id');
		if (!empty($klix_info) && !empty($klix_info['purchase_id'])) {
			$purchase = $this->model_extension_klix_payment_klix->getPurchase($klix_info['purchase_id']);

			$status = is_array($purchase) ? ($purchase['status'] ?? '') : '';

			if (
				$status == 'paid'
				&& $processed_status_id
				&& (int)$order['order_status_id'] == $processing_status_id
			) {
				$this->model_checkout_order->addHistory($order_id, $processed_status_id, '', true);

				$data = json_encode([
					'purchase_id'         => $purchase['id'] ?? '',
					'payment_method'      => $purchase['transaction_data']['payment_method'] ?? '',
					'refund_availability' => $purchase['refund_availability'] ?? ''
				]);
				$this->model_extension_klix_payment_klix->updateOrderPaymentInfo($order_id, $data);
				$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true));
				return;
			}
		}

		$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language'), true));
	}
	
	public function widget(string &$route, array &$data, string &$template): void {

		$product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
		if (!$product_id) {
			return;
		}

		$this->load->model('setting/setting');
		$klix_settings = $this->model_setting_setting->getSetting('payment_klix');
		$brand_id = $this->config->get('payment_klix_brand_id');
		$status   = $this->config->get('payment_klix_status');
	    $klix_pay_later_widget = $this->config->get('payment_klix_pay_later_widget');

		if (!$status || !$brand_id || !$klix_pay_later_widget) {
			return;
		}
		
		$this->load->model('catalog/product');
		$product_info = $this->model_catalog_product->getProduct($product_id);
		if (!$product_info) {
			return;
		}
		
		$amount = (int)round((float)$product_info['price'] * 100);
		$raw = $this->config->get('payment_klix_minimum_amount_pay_later_widget');

		if ($raw === null || $raw === '') {
			$minimum_amount = 7000;
		} else {
			$minimum_amount = (int)round((float)$raw * 100);
		}

		if ($amount < $minimum_amount) {
			return;
		}

		$language = substr((string)$this->config->get('config_language'), 0, 2);
		if (!in_array($language, ['en', 'et', 'lt', 'lv', 'ru'])) {
			$language = 'en';
		}

		$widget  = '<script type="module" src="https://klix.blob.core.windows.net/public/pay-later-widget/build/klix-pay-later-widget.esm.js"></script>';
		$widget .= '<script nomodule src="https://klix.blob.core.windows.net/public/pay-later-widget/build/klix-pay-later-widget.js"></script>';
		$widget .= '<klix-pay-later amount="' . $amount . '" brand_id="' . htmlspecialchars($brand_id) . '" language="' . $language . '"></klix-pay-later>';

		if (!isset($data['price'])) {
			$data['price'] = '';
		}
		
		$data['price'] .= $widget;
	}
}