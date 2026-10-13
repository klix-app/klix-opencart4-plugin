<?php
namespace Opencart\Admin\Model\Extension\Klix\Payment;
class Klix extends \Opencart\System\Engine\Model {
			
	public function install(): void {
	}

	public function uninstall(): void {
	}

	public function refund(int $order_id,int $amount): array|bool {
		$order = $this->getOrder($order_id);
		$klix_info=json_decode($order['payment_custom_field']);
		$klix_purchase_id=$klix_info->purchase_id;
		$refundable_amount=$this->getPurchase($klix_info->purchase_id)['refundable_amount'];
		
		
		if ($refundable_amount>=$amount && $klix_info->refund_availability!='none') {
			$refund_data=[
				'amount'=>$amount,
				'client_name'=>$order['firstname'].' '.$order['lastname']
			];
			
			$response_data = $this->sendCurl('POST',"purchases/{$klix_purchase_id}/refund/", $refund_data);
			return $response_data;
		} else {
			return false;
		}
	}

	public function getOrder(int $order_id): array|bool {
		$qry = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		if ($qry->num_rows) {
			$order = $qry->row;
			return $order;
		} else {
			return false;
		}
	}

	public function getPurchase(string $klix_purchase_id)
	{
		return $this->sendCurl('GET',"purchases/{$klix_purchase_id}/");
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
		curl_setopt($curl, CURLOPT_FAILONERROR, false);

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
