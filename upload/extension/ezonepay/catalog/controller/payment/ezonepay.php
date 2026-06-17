<?php
namespace Opencart\Catalog\Controller\Extension\Ezonepay\Payment;

class Ezonepay extends \Opencart\System\Engine\Controller {
	private const DEV_BASE_URL = 'https://test.ezonepay.ly';
	private const PRODUCTION_BASE_URL = 'https://api.ezonepay.ly';
	private const DEFAULT_CUSTOMER = [
		'FirstName' => 'OpenCart',
		'LastName' => 'Customer',
		'PhoneNumber' => '0910000000'
	];

	public function index(): string {
		$this->load->language('extension/ezonepay/payment/ezonepay');

		$data['create'] = $this->url->link('extension/ezonepay/payment/ezonepay.create', 'language=' . $this->config->get('config_language'), true);
		$data['status'] = $this->url->link('extension/ezonepay/payment/ezonepay.status', 'language=' . $this->config->get('config_language'), true);
		$data['qr_base'] = '';
		$data['token'] = $this->csrfToken();

		return $this->load->view('extension/ezonepay/payment/ezonepay', $data);
	}

	public function create(): void {
		$this->load->language('extension/ezonepay/payment/ezonepay');

		$json = [];

		$order_info = $this->getValidatedOrder($json);

		if (!$json && $order_info) {
			$lock_name = $this->orderLockName((int)$order_info['order_id']);

			if (!$this->acquireOrderLock($lock_name)) {
				$json['error'] = $this->language->get('error_payment');
			} else {
				try {
					$existing = $this->getLatestPaymentForOrder((int)$order_info['order_id']);

					if ($existing && $existing['state'] === 'pending' && $existing['payment_link']) {
						$json = $this->paymentResponse($existing);
					} elseif ($existing && in_array($existing['state'], ['paid', 'used', 'completing'], true)) {
						$json = $this->currentPaymentStateResponse($order_info, (int)$existing['ezonepay_payment_id']);
					} else {
						try {
							$amount = $this->orderAmount($order_info);

							if ($amount <= 0) {
								$json['error'] = $this->language->get('error_amount');
								$this->sendJson($json);
								return;
							}

							$order_reference = $this->generateReference((int)$order_info['order_id']);

							$body = [
								'Title' => 'OpenCart order payment',
								'OrderReference' => $order_reference,
								'InternalReference' => $order_reference,
								'IsUniqueOrderReference' => true,
								'Amount' => $amount,
								'MaxUsageCount' => 1,
								'Note' => 'OpenCart order #' . (int)$order_info['order_id'] . ' ' . $order_reference,
								'Customer' => $this->customerPayload($order_info)
							];

							$data = $this->apiRequest('post', '/payment-link/new', ['json' => $body]);
							$link = $data['link'] ?? $data['Link'] ?? '';

							if (!$link) {
								$json['error'] = $this->language->get('error_link');
							} else {
								$payment_link_id = (string)($data['id'] ?? $data['Id'] ?? '');
								$payment_id = $this->addPayment($order_info, $order_reference, $payment_link_id, $link, $amount);
								$payment = $this->getPayment($payment_id);

								if (!$payment || (int)$payment['order_id'] !== (int)$order_info['order_id']) {
									$json['error'] = $this->language->get('error_payment');
								} elseif ($payment['state'] === 'pending') {
									if ($payment['order_reference'] === $order_reference) {
										$this->addPendingHistory($order_info, $order_reference);
									}

									$json = $this->paymentResponse($payment);
								} else {
									$json = $this->currentPaymentStateResponse($order_info, (int)$payment['ezonepay_payment_id']);
								}
							}
						} catch (\Throwable $e) {
							$this->logApiError($e->getMessage());
							$json['error'] = $this->language->get('error_api');
						}
					}
				} finally {
					$this->releaseOrderLock($lock_name);
				}
			}
		}

		$this->sendJson($json);
	}

	public function status(): void {
		$this->load->language('extension/ezonepay/payment/ezonepay');

		$json = [];

		$order_info = $this->getValidatedOrder($json, false);
		$payment_id = (int)($this->request->post['ezonepay_payment_id'] ?? 0);

		if (!$json && !$payment_id) {
			$json['error'] = $this->language->get('error_payment');
		}

		if (!$json && $order_info) {
			$payment = $this->getPayment($payment_id);

			if (!$payment || (int)$payment['order_id'] !== (int)$order_info['order_id']) {
				$json['error'] = $this->language->get('error_payment');
			} elseif (in_array($payment['state'], ['paid', 'used'], true)) {
				$json = $this->completeOrder($order_info, $payment);
			} elseif ($payment['state'] === 'completing') {
				$json = ['status' => 'pending'];
			} else {
				try {
					$json = $this->verifyPayment($order_info, $payment);
				} catch (\Throwable $e) {
					$this->logApiError($e->getMessage());
					$json['error'] = $this->language->get('error_api');
				}
			}
		}

		$this->sendJson($json);
	}

	private function getValidatedOrder(array &$json, bool $require_enabled = true): array {
		if (!$this->hasValidCsrfToken()) {
			$json['error'] = $this->language->get('error_session');
			return [];
		}

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
			return [];
		}

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);

		if (!$order_info) {
			$json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);
			unset($this->session->data['order_id']);
			return [];
		}

		if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] !== 'ezonepay.ezonepay') {
			$json['error'] = $this->language->get('error_payment_method');
			return [];
		}

		if ($require_enabled && !$this->config->get('payment_ezonepay_status')) {
			$json['error'] = $this->language->get('error_config');
			return [];
		}

		if (!$this->apiKey()) {
			$json['error'] = $this->language->get('error_config');
			return [];
		}

		return $order_info;
	}

	private function csrfToken(): string {
		if (empty($this->session->data['ezonepay_token'])) {
			$this->session->data['ezonepay_token'] = bin2hex(random_bytes(16));
		}

		return (string)$this->session->data['ezonepay_token'];
	}

	private function hasValidCsrfToken(): bool {
		$expected = (string)($this->session->data['ezonepay_token'] ?? '');
		$actual = (string)($this->request->post['ezonepay_token'] ?? '');

		return $expected !== '' && $actual !== '' && hash_equals($expected, $actual);
	}

	private function getLatestPaymentForOrder(int $order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ezonepay_payment` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY `ezonepay_payment_id` DESC LIMIT 1");

		return $query->row;
	}

	private function getPayment(int $payment_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ezonepay_payment` WHERE `ezonepay_payment_id` = '" . (int)$payment_id . "'");

		return $query->row;
	}

	private function orderLockName(int $order_id): string {
		return 'ezonepay_order_' . $order_id;
	}

	private function acquireOrderLock(string $lock_name): bool {
		$query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lock_name) . "', 10) AS `locked`");

		return isset($query->row['locked']) && (int)$query->row['locked'] === 1;
	}

	private function releaseOrderLock(string $lock_name): void {
		$this->db->query("DO RELEASE_LOCK('" . $this->db->escape($lock_name) . "')");
	}

	private function addPayment(array $order_info, string $order_reference, string $payment_link_id, string $link, float $amount): int {
		try {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "ezonepay_payment` SET `order_id` = '" . (int)$order_info['order_id'] . "', `order_reference` = '" . $this->db->escape($order_reference) . "', `payment_link_id` = '" . $this->db->escape($payment_link_id) . "', `payment_link` = '" . $this->db->escape($link) . "', `amount` = '" . (float)$amount . "', `currency_code` = '" . $this->db->escape($order_info['currency_code']) . "', `state` = 'pending', `date_added` = NOW(), `date_modified` = NOW()");
		} catch (\Throwable $e) {
			$existing = $this->getLatestPaymentForOrder((int)$order_info['order_id']);

			if ($existing) {
				return (int)$existing['ezonepay_payment_id'];
			}

			throw $e;
		}

		return (int)$this->db->getLastId();
	}

	private function paymentResponse(array $payment): array {
		return [
			'ezonepay_payment_id' => (int)$payment['ezonepay_payment_id'],
			'order_reference' => $payment['order_reference'],
			'payment_link_id' => $payment['payment_link_id'],
			'link' => $payment['payment_link'],
			'amount' => $this->currency->format((float)$payment['amount'], $payment['currency_code'], 1),
			'status' => $payment['state']
		];
	}

	private function verifyPayment(array $order_info, array $payment): array {
		$payment_link_id = $payment['payment_link_id'];

		if (!$payment_link_id) {
			$payment_link_id = $this->findPaymentLinkId((float)$payment['amount'], $payment['order_reference']);
			if ($payment_link_id) {
				$this->db->query("UPDATE `" . DB_PREFIX . "ezonepay_payment` SET `payment_link_id` = '" . $this->db->escape($payment_link_id) . "', `date_modified` = NOW() WHERE `ezonepay_payment_id` = '" . (int)$payment['ezonepay_payment_id'] . "'");
				$payment['payment_link_id'] = $payment_link_id;
			}
		}

		if (!$payment_link_id) {
			return ['status' => 'pending'];
		}

		$detail = $this->apiRequest('get', '/payment-link/' . rawurlencode($payment_link_id));
		$reference = $this->getReference($detail);

		if ($reference && $reference !== $payment['order_reference']) {
			return ['error' => $this->language->get('error_reference')];
		}

		if (!$reference) {
			$expected_payment_link_id = $this->findPaymentLinkId((float)$payment['amount'], $payment['order_reference']);

			if ((string)$expected_payment_link_id !== (string)$payment_link_id) {
				return ['error' => $this->language->get('error_reference')];
			}
		}

		$paid_amount = $this->getPaidAmount($detail);
		$paid_by_status = $this->isSuccessfulPayment($detail) && $this->hasPaidAmount($detail) && $this->amountMatches($paid_amount, (float)$payment['amount']);

		if ($paid_by_status) {
			if (!$this->markPaymentPaid((int)$payment['ezonepay_payment_id'], 'payment-link-detail-polling')) {
				return $this->currentPaymentStateResponse($order_info, (int)$payment['ezonepay_payment_id']);
			}

			$payment['state'] = 'paid';
			$payment['confirmed_by'] = 'payment-link-detail-polling';

			return $this->completeOrder($order_info, $payment);
		}

		$transactions = $this->apiRequest('get', '/payment-link/' . rawurlencode($payment_link_id) . '/transactions', ['params' => ['PageNumber' => 1, 'PageSize' => 50]]);
		$transaction_paid_amount = 0.0;

		foreach ($this->extractItems($transactions) as $transaction) {
			if ($this->isSuccessfulTransaction($transaction)) {
				$transaction_paid_amount += $this->getTransactionAmount($transaction);
			}
		}

		if ($this->amountMatches($transaction_paid_amount, (float)$payment['amount'])) {
			if (!$this->markPaymentPaid((int)$payment['ezonepay_payment_id'], 'payment-link-transactions-polling')) {
				return $this->currentPaymentStateResponse($order_info, (int)$payment['ezonepay_payment_id']);
			}

			$payment['state'] = 'paid';
			$payment['confirmed_by'] = 'payment-link-transactions-polling';

			return $this->completeOrder($order_info, $payment);
		}

		return ['status' => 'pending', 'payment_link_id' => $payment_link_id];
	}

	private function completeOrder(array $order_info, array $payment): array {
		$this->load->model('checkout/order');

		if ($payment['state'] !== 'used') {
			if (!$this->reservePaymentCompletion((int)$payment['ezonepay_payment_id'])) {
				return $this->currentPaymentStateResponse($order_info, (int)$payment['ezonepay_payment_id']);
			}

			$comment = sprintf($this->language->get('text_order_comment'), $payment['order_reference']);

			if (!empty($payment['confirmed_by'])) {
				$comment .= "\nConfirmed by: " . $payment['confirmed_by'];
			}

			try {
				$this->model_checkout_order->addHistory((int)$order_info['order_id'], (int)$this->config->get('payment_ezonepay_paid_status_id'), $comment, false);
				$this->finishPaymentCompletion((int)$payment['ezonepay_payment_id']);
			} catch (\Throwable $e) {
				$this->rollbackPaymentCompletion((int)$payment['ezonepay_payment_id']);
				throw $e;
			}
		}

		return [
			'status' => 'paid',
			'redirect' => $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true)
		];
	}

	private function addPendingHistory(array $order_info, string $order_reference): void {
		if ((int)$order_info['order_status_id'] === (int)$this->config->get('payment_ezonepay_pending_status_id')) {
			return;
		}

		$this->load->model('checkout/order');

		$order_id = (int)$order_info['order_id'];
		$order_status_id = (int)$this->config->get('payment_ezonepay_pending_status_id');
		$comment = sprintf($this->language->get('text_order_comment'), $order_reference);

		// Keep the initial Ezone Pay pending marker local and quiet. The normal
		// addHistory event chain can invoke order email code for an unpaid order,
		// and in this customized storefront that currently throws on store url.
		$this->model_checkout_order->editOrderStatusId($order_id, $order_status_id);
		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = '" . $order_id . "', `order_status_id` = '" . $order_status_id . "', `notify` = '0', `comment` = '" . $this->db->escape($comment) . "', `date_added` = NOW()");
	}

	private function orderAmount(array $order_info): float {
		return (float)$this->currency->format((float)$order_info['total'], $order_info['currency_code'], (float)$order_info['currency_value'], false);
	}

	private function generateReference(int $order_id): string {
		return 'OC-' . $order_id . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
	}

	private function customerPayload(array $order_info): array {
		$telephone = $order_info['telephone'] ?? '';

		if (!$telephone) {
			return self::DEFAULT_CUSTOMER;
		}

		return [
			'FirstName' => $order_info['firstname'] ?: self::DEFAULT_CUSTOMER['FirstName'],
			'LastName' => $order_info['lastname'] ?: self::DEFAULT_CUSTOMER['LastName'],
			'PhoneNumber' => $telephone
		];
	}

	private function baseUrl(): string {
		return $this->config->get('payment_ezonepay_api_mode') === 'production' ? self::PRODUCTION_BASE_URL : self::DEV_BASE_URL;
	}

	private function apiKey(): string {
		$mode = $this->config->get('payment_ezonepay_api_mode') === 'production' ? 'production' : 'dev';
		$env = $mode === 'production' ? getenv('EZONEPAY_PRODUCTION_API_KEY') : getenv('EZONEPAY_DEV_API_KEY');

		if ($env) {
			return $env;
		}

		return (string)$this->config->get('payment_ezonepay_' . $mode . '_api_key');
	}

	private function apiRequest(string $method, string $path, array $options = []): array {
		$url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');

		if (!empty($options['params'])) {
			$url .= '?' . http_build_query($options['params']);
		}

		$curl = curl_init($url);

		$headers = [
			'Accept: application/json',
			'Content-Type: application/json',
			'X-API-Key: ' . $this->apiKey()
		];

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 20);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));

		if (isset($options['json'])) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($options['json']));
		}

		$response = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);

		curl_close($curl);

		if ($response === false) {
			throw new \RuntimeException($error ?: 'Ezone Pay request failed.');
		}

		$decoded = json_decode($response, true);

		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException('Ezone Pay HTTP ' . $status . ': ' . $response);
		}

		if (strtolower($method) === 'delete' || $status === 204) {
			return is_array($decoded) ? $decoded : [];
		}

		if (!is_array($decoded)) {
			throw new \RuntimeException('Ezone Pay returned invalid JSON.');
		}

		return $decoded['data'] ?? $decoded['Data'] ?? $decoded['result'] ?? $decoded['Result'] ?? $decoded;
	}

	private function findPaymentLinkId(float $amount, string $order_reference): string {
		$data = $this->apiRequest('get', '/payment-link/list', ['params' => ['PageNumber' => 1, 'PageSize' => 50, 'ExactAmount' => $amount]]);

		foreach ($this->extractItems($data) as $item) {
			$item_reference = $item['orderReference'] ?? $item['OrderReference'] ?? '';

			if ($item_reference === $order_reference) {
				return (string)($item['id'] ?? $item['Id'] ?? '');
			}
		}

		return '';
	}

	private function extractItems($data): array {
		if (is_array($data) && $this->isListArray($data)) {
			return $data;
		}

		if (!is_array($data)) {
			return [];
		}

		foreach (['items', 'Items', 'data', 'Data', 'records', 'Records'] as $key) {
			if (isset($data[$key]) && is_array($data[$key])) {
				return $data[$key];
			}
		}

		return [];
	}

	private function isListArray(array $data): bool {
		return !$data || array_keys($data) === range(0, count($data) - 1);
	}

	private function getReference($data): string {
		if (!is_array($data)) {
			return '';
		}

		return (string)($data['orderReference'] ?? $data['OrderReference'] ?? $data['internalReference'] ?? $data['InternalReference'] ?? '');
	}

	private function getPaidAmount($data): float {
		if (!is_array($data)) {
			return 0.0;
		}

		return $this->toFloat($data['totalAmountPaid'] ?? $data['TotalAmountPaid'] ?? $data['paidAmount'] ?? $data['PaidAmount'] ?? $data['amountPaid'] ?? $data['AmountPaid'] ?? 0);
	}

	private function hasPaidAmount($data): bool {
		if (!is_array($data)) {
			return false;
		}

		foreach (['totalAmountPaid', 'TotalAmountPaid', 'paidAmount', 'PaidAmount', 'amountPaid', 'AmountPaid'] as $key) {
			if (array_key_exists($key, $data)) {
				return true;
			}
		}

		return false;
	}

	private function getTransactionAmount($data): float {
		if (!is_array($data)) {
			return 0.0;
		}

		return $this->toFloat($data['amount'] ?? $data['Amount'] ?? $data['paidAmount'] ?? $data['PaidAmount'] ?? $data['totalAmountPaid'] ?? $data['TotalAmountPaid'] ?? 0);
	}

	private function isSuccessfulTransaction($transaction): bool {
		if (!is_array($transaction)) {
			return false;
		}

		$status = strtolower((string)($transaction['status'] ?? $transaction['Status'] ?? $transaction['paymentStatus'] ?? $transaction['PaymentStatus'] ?? ''));

		return in_array($status, ['paid', 'success', 'succeeded', 'completed', 'approved', 'captured'], true);
	}

	private function isSuccessfulPayment($payment): bool {
		if (!is_array($payment)) {
			return false;
		}

		$status = strtolower((string)($payment['status'] ?? $payment['Status'] ?? $payment['paymentStatus'] ?? $payment['PaymentStatus'] ?? $payment['state'] ?? $payment['State'] ?? ''));

		return in_array($status, ['paid', 'success', 'succeeded', 'completed', 'approved', 'captured'], true);
	}

	private function amountMatches(float $paid_amount, float $expected_amount): bool {
		return $paid_amount > 0 && $expected_amount > 0 && abs($paid_amount - $expected_amount) <= 0.01;
	}

	private function toFloat($value): float {
		return is_numeric($value) ? (float)$value : 0.0;
	}

	private function markPaymentPaid(int $payment_id, string $confirmed_by): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "ezonepay_payment` SET `state` = 'paid', `confirmed_by` = '" . $this->db->escape($confirmed_by) . "', `date_modified` = NOW() WHERE `ezonepay_payment_id` = '" . (int)$payment_id . "' AND `state` = 'pending'");

		return $this->db->countAffected() > 0;
	}

	private function reservePaymentCompletion(int $payment_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "ezonepay_payment` SET `state` = 'completing', `date_modified` = NOW() WHERE `ezonepay_payment_id` = '" . (int)$payment_id . "' AND `state` = 'paid'");

		return $this->db->countAffected() > 0;
	}

	private function finishPaymentCompletion(int $payment_id): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "ezonepay_payment` SET `state` = 'used', `date_modified` = NOW() WHERE `ezonepay_payment_id` = '" . (int)$payment_id . "' AND `state` = 'completing'");
	}

	private function rollbackPaymentCompletion(int $payment_id): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "ezonepay_payment` SET `state` = 'paid', `date_modified` = NOW() WHERE `ezonepay_payment_id` = '" . (int)$payment_id . "' AND `state` = 'completing'");
	}

	private function currentPaymentStateResponse(array $order_info, int $payment_id): array {
		$current = $this->getPayment($payment_id);

		if ($current && (int)$current['order_id'] === (int)$order_info['order_id']) {
			if ($current['state'] === 'paid') {
				return $this->completeOrder($order_info, $current);
			}

			if ($current['state'] === 'used') {
				return $this->paidResponse();
			}
		}

		return ['status' => 'pending'];
	}

	private function paidResponse(): array {
		return [
			'status' => 'paid',
			'redirect' => $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true)
		];
	}

	private function sendJson(array $json): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function logApiError(string $message): void {
		if ($this->config->get('payment_ezonepay_debug')) {
			$this->log->write('Ezone Pay: ' . $message);
		}
	}
}
