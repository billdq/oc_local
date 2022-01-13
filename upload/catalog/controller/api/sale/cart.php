<?php
namespace Opencart\Catalog\Controller\Api\Sale;
class Cart extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('api/sale/cart');

		$json = [];

		// Stock
		if (!$this->cart->hasStock() && (!$this->config->get('config_stock_checkout') || $this->config->get('config_stock_warning'))) {
			$json['error']['stock'] = $this->language->get('error_stock');
		}

		$totals = [];
		$taxes = $this->cart->getTaxes();
		$total = 0;

		$this->load->model('checkout/cart');

		($this->model_checkout_cart->getTotals)($totals, $taxes, $total);

		$frequencies = [
			'day'        => $this->language->get('text_day'),
			'week'       => $this->language->get('text_week'),
			'semi_month' => $this->language->get('text_semi_month'),
			'month'      => $this->language->get('text_month'),
			'year'       => $this->language->get('text_year')
		];

		$json['products'] = [];

		$products = $this->model_checkout_cart->getProducts();

		foreach ($products as $product) {
			$description = '';

			if ($product['subscription']) {
				if ($product['subscription']['trial_status']) {
					$description = sprintf($this->language->get('text_subscription_trial'), $this->currency->format($this->tax->calculate($product['subscription']['trial_price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['subscription']['trial_cycle'], $frequencies[$product['subscription']['trial_frequency']], $product['subscription']['trial_duration']) . ' ';
				}

				$price = $this->currency->format($this->tax->calculate($product['subscription']['price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);

				if ($product['subscription']['duration']) {
					$description .= sprintf($this->language->get('text_subscription_description'), $price, $product['subscription']['cycle'], $frequencies[$product['subscription']['frequency']], $product['subscription']['duration']);
				} else {
					$description .= sprintf($this->language->get('text_subscription_cancel'), $price, $product['subscription']['cycle'], $frequencies[$product['subscription']['frequency']]);
				}
			}

			$json['products'][] = [
				'cart_id'      => $product['cart_id'],
				'product_id'   => $product['product_id'],
				'name'         => $product['name'],
				'model'        => $product['model'],
				'option'       => $product['option'],
				'subscription' => $description,
				'quantity'     => $product['quantity'],
				'stock'        => $product['stock'],
				'minimum'      => $product['minimum'],
				'reward'       => $product['reward'],
				'price'        => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']),
				'total'        => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'], $this->session->data['currency']),
			];
		}

		$json['vouchers'] = [];

		$vouchers = $this->model_checkout_cart->getVouchers();

		foreach ($vouchers as $key => $voucher) {
			$json['vouchers'][] = [
				'key'         => $key,
				'description' => sprintf($this->language->get('text_for'), $this->currency->format($voucher['amount'], $this->session->data['currency']), $voucher['to_name']),
				'amount'      => $this->currency->format($voucher['amount'], $this->session->data['currency'])
			];
		}

		$json['totals'] = [];

		foreach ($totals as $total) {
			$json['totals'][] = [
				'title' => $total['title'],
				'text'  => $this->currency->format($total['value'], $this->session->data['currency'])
			];
		}

		$json['shipping_required'] = $this->cart->hasShipping();

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function add(): void {
		$this->load->language('api/sale/cart');

		$json = [];

		if (isset($this->request->post['product_id'])) {
			$product_id = (int)$this->request->post['product_id'];
		} else {
			$product_id = 0;
		}

		if (isset($this->request->post['quantity'])) {
			$quantity = (int)$this->request->post['quantity'];
		} else {
			$quantity = 1;
		}

		if (isset($this->request->post['option'])) {
			$option = array_filter($this->request->post['option']);
		} else {
			$option = [];
		}

		if (isset($this->request->post['subscription_plan_id'])) {
			$subscription_plan_id = (int)$this->request->post['subscription_plan_id'];
		} else {
			$subscription_plan_id = 0;
		}

		$this->load->model('catalog/product');

		$product_info = $this->model_catalog_product->getProduct($product_id);

		if ($product_info) {
			// If variant get master product
			if ($product_info['master_id']) {
				$product_id = $product_info['master_id'];
			}

			// Merge variant code with options
			foreach ($product_info['variant'] as $key => $value) {
				$option[$key] = $value;
			}

			// Validate options
			$product_options = $this->model_catalog_product->getOptions($product_id);

			foreach ($product_options as $product_option) {
				if ($product_option['required'] && empty($option[$product_option['product_option_id']])) {
					$json['error']['option_' . $product_option['product_option_id']] = sprintf($this->language->get('error_required'), $product_option['name']);
				}
			}

			// Validate Subscription plan
			$subscriptions = $this->model_catalog_product->getSubscriptions($product_id);

			if ($subscriptions) {
				$subscription_plan_ids = [];

				foreach ($subscriptions as $subscription) {
					$subscription_plan_ids[] = $subscription['subscription_plan_id'];
				}

				if (!in_array($subscription_plan_id, $subscription_plan_ids)) {
					$json['error']['warning'] = $this->language->get('error_subscription_plan');
				}
			}
		} else {
			$json['error']['warning'] = $this->language->get('error_product');
		}

		if (!$json) {
			$this->cart->add($product_id, $quantity, $option, $subscription_plan_id);

			$json['success'] = $this->language->get('text_success');

			unset($this->session->data['shipping_methods']);
			unset($this->session->data['payment_methods']);
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function edit(): void {
		$this->load->language('api/sale/cart');

		$json = [];

		if (isset($this->request->post['key'])) {
			$key = (int)$this->request->post['key'];
		} else {
			$key = 0;
		}

		if (isset($this->request->post['quantity'])) {
			$quantity = (int)$this->request->post['quantity'];
		} else {
			$quantity = 1;
		}

		$this->cart->update($key, $quantity);

		$json['success'] = $this->language->get('text_success');

		unset($this->session->data['shipping_methods']);
		unset($this->session->data['payment_methods']);
		unset($this->session->data['reward']);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function remove(): void {
		$this->load->language('api/sale/cart');

		$json = [];

		if (isset($this->request->post['key'])) {
			$key = (int)$this->request->post['key'];
		} else {
			$key = 0;
		}

		// Remove
		$this->cart->remove($key);

		$json['success'] = $this->language->get('text_success');

		unset($this->session->data['shipping_methods']);
		unset($this->session->data['payment_methods']);
		unset($this->session->data['reward']);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
