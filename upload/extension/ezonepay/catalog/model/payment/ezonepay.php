<?php
namespace Opencart\Catalog\Model\Extension\Ezonepay\Payment;

class Ezonepay extends \Opencart\System\Engine\Model {
	public function getMethods(array $address = []): array {
		$this->load->language('extension/ezonepay/payment/ezonepay');

		if ($this->cart->hasSubscription()) {
			$status = false;
		} elseif (!$this->config->get('config_checkout_payment_address')) {
			$status = true;
		} elseif (!$this->config->get('payment_ezonepay_geo_zone_id')) {
			$status = true;
		} else {
			$this->load->model('localisation/geo_zone');

			$results = $this->model_localisation_geo_zone->getGeoZone((int)$this->config->get('payment_ezonepay_geo_zone_id'), (int)$address['country_id'], (int)$address['zone_id']);

			$status = (bool)$results;
		}

		$method_data = [];

		if ($status) {
			$option_data['ezonepay'] = [
				'code' => 'ezonepay.ezonepay',
				'name' => $this->language->get('text_title')
			];

			$method_data = [
				'code'       => 'ezonepay',
				'name'       => $this->language->get('text_title'),
				'option'     => $option_data,
				'sort_order' => $this->config->get('payment_ezonepay_sort_order')
			];
		}

		return $method_data;
	}
}
