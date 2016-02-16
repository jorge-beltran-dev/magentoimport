<?php
namespace App\Library;

class MagentoImporter {

	private $k = 0;

	private $websiteId;

	private $storeId;

	private	$customerDefaults = [
		'website' => 'base',
		'group_id' => '',
		'firstname' => '',
		'lastname' => '',
    		'email' => '',
		'taxvat' => '',
		'cust_codigo' => '',
		'cust_nombre_comercial' => '',
		'cust_representante' => '',
		'cust_idusuario' => '',
		'gender' => '0',
		'dob' => '',
		'is_subscribed' => '',
		'cust_lopd' => '',
		'cust_forma_pago' => '',
		'cust_dias_fijo1' => '',
		'cust_dias_fijo2' => '',
		'cust_portes' => '',
		'cust_iva' => '', //@TODO
		'cust_recargo' => '',
		'cust_aplica_cant_min' => '', //@TODO
		'cust_iban' => '',
		'cust_swift' => '',
		'cust_correspondencia' => '',
	];

	private $addressDefaults = [
		'firstname' => '',
		'lastname' => '',
		'street' => '',
		'city' => '',
		'region' => '',
		'region_id' => '',
		'postcode' => '',
		'country_id' => '',
		'telephone' => '',
    		'fax' => '',
		'cust_telefono2' => '',
		'cust_movil' => '',
	];	

	private $orderDefaults = [
		'session' => ['customer_id' => '', 'store_id' => ''],
		'payment' => ['method' => 'checkmo'],
		'add_products' => [], // price, qty
		'order' => [
			'currency' => 'EUR',
			'account' => ['group_id' => '1', 'email' => ''],
			'billing_address' => [
				'customer_address_id' => '',
				'prefix' => '',
				'firstname' => '',
				'middlename' => '',
				'lastname' => '',
				'suffix' => '',
				'company' => '',
				'street' => '',
				'city' => '',
				'country_id' => '',
				'region' => '',
				'region_id' => '',
				'postcode' => '',
				'telephone' => '',
				'fax' => '',
			],
			'shipping_address' => [
				'customer_address_id' => '',
				'prefix' => '',
				'firstname' => '',
				'middlename' => '',
				'lastname' => '',
				'suffix' => '',
				'company' => '',
				'street' => '',
				'city' => '',
				'country_id' => '',
				'region' => '',
				'region_id' => '',
				'postcode' => '',
				'telephone' => '',
				'fax' => '',
			],
			'shipping_method' => 'freeshipping_freeshipping',
			'comment' => ['customer_note' => 'Pedido importado automáticamente.'],
			'send_confirmation' => false,
		]
	];

	public function __construct($magentoPath) {
		require_once($magentoPath . 'app/Mage.php');
		\Mage::setIsDeveloperMode(true);
		$this->websiteId = \Mage::app()->getWebsite()->getId();
		$this->storeId = \Mage::app()->getStore()->getId();
		
		return $this;
	}

	public function getWebsiteId() {
		return $this->websiteId;
	}

	public function getStoreId() {
		return $this->storeId;
	}

	public function saveCustomer($customerData, $billingData, $shippingData, $mailingData) {
		$customerData = array_merge($this->customerDefaults, $customerData);
		$billingData = array_merge($this->addressDefaults, $billingData);
		$shippingData = array_merge($this->addressDefaults, $shippingData);
		if (is_array($mailingData)) {
			$mailingData = array_merge($this->addressDefaults, $mailingData);
		}
		
		$customer = $this->saveCustomerToMagento($customerData);

		if ($billingData == $shippingData) {
			$this->saveAddressToMagento($customer, $billingData, 'both');
		} else {
			$this->saveAddressToMagento($customer, $billingData, 'billing');
			$this->saveAddressToMagento($customer, $shippingData, 'shipping');
		}

		if (!empty($mailingData)) {
			$this->saveAddressToMagento($customer, $mailingData, 'mailing');
		}

	}

	public function saveOrder($code, $products) {
		$customer = $this->getCustomer($code);
		$orderData = $this->orderDefaults;
		
		if (!count($customer->getData())) {
			return false;
		}

		$orderData['session']['customer_id'] = $customer->getId();
		$orderData['session']['store_id'] = $this->getStoreId();	
		$session = $this->getSessionModel();
		$session->setCustomerId($customer->getId());
		$session->setStoreId($this->getStoreId());
		

		$orderData['order']['account']['email'] = $customer->getEmail();

	    	$billingAddressId = $customer->getDefaultBilling();
	    	$shippingAddressId = $customer->getDefaultShipping();

		$orderData['order']['billing_address'] = $this->getAddress($billingAddressId);
		$orderData['order']['shipping_address'] = $this->getAddress($shippingAddressId);	

		$orderData['add_products'] = $products;

		$order = $this->getOrderModel();
		
		\Mage::unregister('config');
		\Mage::reset();
		\Mage::app();
		if (count($order->getQuote()->getAllItems())) {
			$quote = $order->getQuote();
			$items = $quote->getAllItems();
			foreach ($items as $item)
			{	
				$quote->removeItem($item->getItemId());	
			}
			$quote->save();
			$order->setQuote($quote);
		}
		\Mage::unregister('config');
		\Mage::reset();
		\Mage::app();

		$order->importPostData($orderData['order']);
		\Mage::unregister('rule_data');
		$addedProducts = false;
		foreach ($orderData['add_products'] as $sku => $_data) {
			$price = $_data['price'];
			unset($_data['price']);
			$product = $this->getProductBySku($sku);
			if (!$product) {
				continue;
			}
			$order->getQuote()->addProduct($product, new \Varien_Object(['qty' => $_data['qty']]))
				->setOriginalCustomPrice($price);
			$addedProducts = true;
		}

		if (!$addedProducts)
		{
			return false;
		}

		$order->collectShippingRates();
		$order->getQuote()->getPayment()->addData($orderData['payment']);
		$order->initRuleData()->saveQuote();

		$order->setPaymentData($orderData['payment']);
		$order->getQuote()->getPayment()->addData($orderData['payment']);

		\Mage::app()->getStore()->setConfig(\Mage_Sales_Model_Order::XML_PATH_EMAIL_ENABLED, "0");
		$increment_id = false;
		try {	
			$increment_id = $order->importPostData($orderData['order'])->createOrder()->getIncrementId();
		} catch(Exception $e) {
			print_r($e);
		}

		
		\Mage::unregister('config');
		\Mage::reset();
		\Mage::app();
		if (count($order->getQuote()->getAllItems())) {
			$quote = $order->getQuote();
			$items = $quote->getAllItems();
			foreach ($items as $item)
			{	
				$quote->removeItem($item->getItemId());	
			}
			$quote->save();
			$order->setQuote($quote);
		}

		\Mage::unregister('config');
		\Mage::reset();
		\Mage::app();
		return $increment_id;		
	}

	private function getProduct($field, $value) {
		return \Mage::getModel('catalog/product')->getCollection()
			->addAttributeToFilter($field, $value)
			->addAttributeToSelect('*')
			->getFirstItem();
	}

	private function getProductBySku($sku) {
		$id = \Mage::getModel('catalog/product')->getResource()->getIdBySku($sku);
		if (!$id) {
			return false;
		}
		return \Mage::getModel('catalog/product')->load($id);
	}

	private function getCustomer($code) {
		return \Mage::getModel('customer/customer')
			->getCollection()
			->addAttributeToSelect('*')
			->addAttributeToFilter('cust_codigo', $code)->load()->getFirstItem();
	}

	private function getCustomerByEmail($email) {
		return \Mage::getModel('customer/customer')
			->getCollection()
			->addAttributeToSelect('*')
			->addAttributeToFilter('email', $email)->load()->getFirstItem();
	}

	private function getAddress($addressId) {
		$address = \Mage::getModel('customer/address')->load($addressId);

		return [
			'customer_address_id' => $addressId,
			'prefix' => '',
			'firstname' => $address->getFirstname(),
			'middlename' => '',
			'lastname' => $address->getLastname(),
			'suffix' => '',
			'company' => '',
			'street' => [current($address->getStreet()), ''],
			'city' => $address->getCity(),
			'country_id' => $address->getCountryId(),
			'region' => '',
			'region_id' => $address->getRegionId(),
			'postcode' => $address->getPostcode(),
			'telephone' => $address->getTelephone(),
			'fax' => '',
		];
	}

	private function saveCustomerToMagento($data) {
		$code = $data['cust_codigo'];
		$customer = $this->getCustomerByEmail($data['email']);
		$new = (count($customer->getData()) == 0);

		if ($new) {	
			$customer = \Mage::getModel('customer/customer');
		}
		else
		{
			unset($data['customer_activated']);
		}

		$customer->addData($data);
		if ($new) {
 			$customer->setPassword($customer->generatePassword(10));
			//@TODO
		        //$customer->sendNewAccountEmail();
		}

		try{
			$customer->save();
			$customer->setConfirmation(null); // confirm the account, AFTER it has been created
			$customer->setStatus(1); // enable the account, AFTER it has been created
			$customer->save();
		}
		catch (Exception $e) {
			Zend_Debug::dump($e->getMessage());
		}

		return $customer;
	}

	private function saveAddressToMagento($customer, $data, $type) {

		if ($type == 'billing' || $type == 'both') {
	    		$addressId = $customer->getDefaultBilling();
		} elseif ($type == 'shipping') {
			$addressId = $customer->getDefaultShipping();
		} 
		/*
		elseif ($type == 'mailing') {
			foreach ($customer->getAddresses() as $address) {
				//@TODO Obtener de Magento dirección del cliente que no sea ni de envío ni facturación
				//debug($address);
				return false;
			}
		} */

		if (!empty($addressId)) {
	    		$address = \Mage::getModel('customer/address')->load($addressId);
		} else {
	    		$address = \Mage::getModel('customer/address');
		}

		$address->addData($data)->setCustomerId($customer->getId());

		if ($type == 'billing' || $type == 'both') {
                         $address->setIsDefaultBilling('1');
		}
		if ($type == 'shipping' || $type == 'both') {
                         $address->setIsDefaultShipping('1');
		}
               	$address->setSaveInAddressBook('1');

		try{
			$address->save();
		}
		catch (Exception $e) {
			Zend_Debug::dump($e->getMessage());
		}
		return $address;
	}

	private function getOrderModel() {
		return \Mage::getModel('adminhtml/sales_order_create');
	}

	private function getSessionModel() {
		return \Mage::getModel('adminhtml/session_quote');
	}
}
