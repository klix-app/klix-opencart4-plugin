<?php
namespace Opencart\Catalog\Model\Extension\Klix\Payment;
class Klix extends \Opencart\System\Engine\Model {

	public function getGroupedPaymentMethods(array $payment_methods): array
	{
		$grouped=[];
		$groups=['bank_transfer','klix_card','klix_pay_later'];

		foreach ($payment_methods['payment_method_groups'] as $payment_method_group) {
			if (isset($payment_method_group['name']) and in_array($payment_method_group['name'],$groups)) {
				foreach ($payment_methods['by_country'] as $key => $value) {
					foreach ($payment_method_group['methods'] as $method) {
						if (in_array($method, $payment_methods['by_country'][$key]) or ('any' != $key and in_array($method, $payment_methods['by_country']['any']))) {
							$grouped[$key][$payment_method_group['name']][] = $method;
						}
					}
				}
			}
		}

		$sorted_payment_methods=[];
		foreach($grouped as $key=>$group)
		{
			ksort($group);
			$sorted_payment_methods[$key]=$group;
		}

		return $sorted_payment_methods;
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


	public function getPaymentMethodsFromAPI(): array
	{
		$this->load->model('localisation/language');
		$currentLanguageCode = $this->getLanguageCode();

		$URI='payment_methods/';
		$brand_id=$this->config->get('payment_klix_brand_id');
		
		$URI.='?brand_id='.$brand_id;
		$URI.='&currency='.$this->session->data['currency'];
		$URI.='&language='.$currentLanguageCode;

		$payment_methods=$this->sendCurl('GET',$URI);

		foreach($payment_methods['payment_method_groups'] as $group) {
			$payment_methods['payment_method_groups'][$group['name']]=$group['label'];
		}

		return $payment_methods;
	}

	public function createPurchase($purchaseBody): mixed
	{
		$response=$this->sendCurl('POST','purchases/',$purchaseBody);
		return $response;
	}

	public function getMethod(array $address): array {
		$this->load->language('extension/klix/payment/klix');

		$method_data = [
			'code'       => 'klix',
			'title'      => $this->language->get('text_title'),
			'sort_order' => 1
		];

		return $method_data;
	}

	public function getMethods(array $address = []): array {
		$this->load->language('extension/klix/payment/klix');

		$option_data['klix'] = [
			'code' => 'klix.klix',
			'name' => $this->language->get('text_title')
		];

		$method_data = [
			'code'       => 'klix',
			'name'       => $this->language->get('text_title'),
			'option'     => $option_data,
			'sort_order' => 1
		];

		return $method_data;
	}
	
	public function updateOrderPaymentInfo($order_id,$custom_payment_field)
	{
		$payment_method=json_encode(['code'=>'klix','name'=>'Klix']);
		$this->db->query("UPDATE ".DB_PREFIX."order SET `payment_custom_field` = '{$custom_payment_field}',`payment_method`='{$payment_method}' WHERE `order_id` = '" . $order_id . "'");
	}

	public function getPurchase($purchase_id)
	{
		$purchase=$this->sendCurl('GET',"purchases/{$purchase_id}/");
		
		if($purchase!=null)
		{
			return $purchase;
		}
		return '';
	}

	public function sendCurl(string $method,string $url, ?array $payment_data=null): array {
		$endpoint='https://portal.klix.app/api/v1/';

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $endpoint.$url);
		curl_setopt($curl, CURLOPT_PORT, 443);
		curl_setopt($curl, CURLOPT_HEADER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

		if ($method == 'POST') {
            curl_setopt($curl, CURLOPT_POST, 1);
        }
        if ($method == 'PUT') {
            curl_setopt($curl, CURLOPT_PUT, 1);
        }
        if ($method == 'PUT' or $method == 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payment_data));
        }

		$headers=[
			'Content-type: application/json',
			'Authorization: ' . "Bearer " . $this->config->get('payment_klix_secret_key'),
		];
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		

		$response = curl_exec($curl);

		curl_close($curl);
		
		return json_decode($response,true);
	}

	public function log(string $title, array|string $data): void {
		$_config = new \Opencart\System\Engine\Config();
		$_config->addPath(DIR_EXTENSION . 'klix/system/config/');
		$_config->load('klix');
			
		$config_setting = $_config->get('klix_setting');
		
		$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_klix_setting'));
		
		if ($setting['general']['debug']) {
			$log = new \Opencart\System\Library\Log('klix.log');
			
			$log->write($title . ': ' . print_r($data, 1));
		}
	}
}