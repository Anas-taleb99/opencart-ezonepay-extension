<?php
namespace Opencart\Admin\Controller\Extension\Ezonepay\Payment;

class Ezonepay extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/ezonepay/payment/ezonepay');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/ezonepay/payment/ezonepay', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/ezonepay/payment/ezonepay.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$this->load->model('localisation/order_status');
		$this->load->model('localisation/geo_zone');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$defaults = [
			'payment_ezonepay_api_mode' => 'dev',
			'payment_ezonepay_dev_api_key' => '',
			'payment_ezonepay_production_api_key' => '',
			'payment_ezonepay_pending_status_id' => $this->defaultPendingStatusId(),
			'payment_ezonepay_paid_status_id' => $this->defaultPaidStatusId(),
			'payment_ezonepay_geo_zone_id' => 0,
			'payment_ezonepay_debug' => 0,
			'payment_ezonepay_status' => 0,
			'payment_ezonepay_sort_order' => 0
		];

		foreach ($defaults as $key => $default) {
			$value = $this->config->get($key);
			$data[$key] = $value !== null ? $value : $default;
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/ezonepay/payment/ezonepay', $data));
	}

	public function save(): void {
		$this->load->language('extension/ezonepay/payment/ezonepay');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/ezonepay/payment/ezonepay')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$api_mode = $this->request->post['payment_ezonepay_api_mode'] ?? 'dev';

		if (!in_array($api_mode, ['dev', 'production'], true)) {
			$api_mode = 'dev';
		}

		if ($api_mode === 'dev' && empty($this->request->post['payment_ezonepay_dev_api_key']) && !getenv('EZONEPAY_DEV_API_KEY')) {
			$json['error']['dev_api_key'] = $this->language->get('error_dev_api_key');
		}

		if ($api_mode === 'production' && empty($this->request->post['payment_ezonepay_production_api_key']) && !getenv('EZONEPAY_PRODUCTION_API_KEY')) {
			$json['error']['production_api_key'] = $this->language->get('error_production_api_key');
		}

		$pending_status_id = (int)($this->request->post['payment_ezonepay_pending_status_id'] ?? $this->defaultPendingStatusId());
		$paid_status_id = (int)($this->request->post['payment_ezonepay_paid_status_id'] ?? $this->defaultPaidStatusId());

		if ($pending_status_id === $paid_status_id) {
			$json['error']['warning'] = $this->language->get('error_statuses');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('payment_ezonepay', [
				'payment_ezonepay_api_mode' => $api_mode,
				'payment_ezonepay_dev_api_key' => (string)($this->request->post['payment_ezonepay_dev_api_key'] ?? ''),
				'payment_ezonepay_production_api_key' => (string)($this->request->post['payment_ezonepay_production_api_key'] ?? ''),
				'payment_ezonepay_pending_status_id' => $pending_status_id,
				'payment_ezonepay_paid_status_id' => $paid_status_id,
				'payment_ezonepay_geo_zone_id' => (int)($this->request->post['payment_ezonepay_geo_zone_id'] ?? 0),
				'payment_ezonepay_debug' => !empty($this->request->post['payment_ezonepay_debug']) ? 1 : 0,
				'payment_ezonepay_status' => !empty($this->request->post['payment_ezonepay_status']) ? 1 : 0,
				'payment_ezonepay_sort_order' => (int)($this->request->post['payment_ezonepay_sort_order'] ?? 0)
			]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ezonepay_payment` (
			`ezonepay_payment_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`order_reference` VARCHAR(128) NOT NULL,
			`payment_link_id` VARCHAR(128) NOT NULL DEFAULT '',
			`payment_link` TEXT NOT NULL,
			`amount` DECIMAL(15,4) NOT NULL,
			`currency_code` VARCHAR(3) NOT NULL,
			`state` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`confirmed_by` VARCHAR(64) NOT NULL DEFAULT '',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`ezonepay_payment_id`),
			UNIQUE KEY `order_id` (`order_id`),
			KEY `order_reference` (`order_reference`),
			KEY `payment_link_id` (`payment_link_id`),
			KEY `state` (`state`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->ensureUniqueOrderIndex();

		$this->load->model('setting/setting');

		$this->model_setting_setting->editSetting('payment_ezonepay', [
			'payment_ezonepay_api_mode' => 'dev',
			'payment_ezonepay_pending_status_id' => $this->defaultPendingStatusId(),
			'payment_ezonepay_paid_status_id' => $this->defaultPaidStatusId(),
			'payment_ezonepay_geo_zone_id' => 0,
			'payment_ezonepay_debug' => 0,
			'payment_ezonepay_status' => 0,
			'payment_ezonepay_sort_order' => 0
		]);
	}

	public function uninstall(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "ezonepay_payment`");
	}

	private function defaultPendingStatusId(): int {
		$status_id = (int)$this->config->get('config_pending_status_id');

		return $status_id ?: 1;
	}

	private function defaultPaidStatusId(): int {
		$status_id = (int)$this->config->get('config_complete_status_id');

		return $status_id ?: 5;
	}

	private function ensureUniqueOrderIndex(): void {
		$table = DB_PREFIX . 'ezonepay_payment';
		$duplicates = $this->db->query("SELECT COUNT(*) AS `total` FROM (SELECT `order_id` FROM `" . $table . "` GROUP BY `order_id` HAVING COUNT(*) > 1) duplicate_orders");

		if ((int)$duplicates->row['total'] > 0) {
			return;
		}

		$index = $this->db->query("SHOW INDEX FROM `" . $table . "` WHERE `Key_name` = 'order_id'");

		if ($index->row && (int)$index->row['Non_unique'] === 0) {
			return;
		}

		try {
			if ($index->row) {
				$this->db->query("ALTER TABLE `" . $table . "` DROP INDEX `order_id`");
			}

			$this->db->query("ALTER TABLE `" . $table . "` ADD UNIQUE KEY `order_id` (`order_id`)");
		} catch (\Throwable $e) {
			$this->log->write('Ezone Pay install: unable to add unique order index: ' . $e->getMessage());
		}
	}
}
