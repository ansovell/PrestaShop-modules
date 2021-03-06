<?php

/*
 * 2007-2013 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author PrestaShop SA <contact@prestashop.com>
 *  @author Quadra Informatique <modules@quadra-informatique.fr>
 *  @copyright  2007-2013 PrestaShop SA / 1997-2013 Quadra Informatique
 *  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_'))
	exit;

class Socolissimo extends CarrierModule {

	private $_html = '';
	private $_postErrors = array();
	public $url = '';
	public $_errors = array();
	private $api_num_version = '4.0';
	public $initialCost = 0;
	
	private $_config = array(
		'name' => 'La Poste - So Colissimo',
		'id_tax_rules_group' => 0,
		'url' => 'http://www.colissimo.fr/portail_colissimo/suivreResultat.do?parcelnumber=@',
		'active' => true,
		'deleted' => 0,
		'shipping_handling' => false,
		'range_behavior' => 0,
		'is_module' => true,
		'delay' => array('fr' => 'Avec La Poste, Faites-vous livrer là ou vous le souhaitez en France Métropolitaine.',
			'en' => 'Do you deliver wherever you want in France.'),
		'id_zone' => 1,
		'shipping_external' => true,
		'external_module_name' => 'socolissimo',
		'need_range' => true
	);
	
	public $personal_data_phone_error = false;
	public $personal_data_zip_code_error = false;

	function __construct()
	{
		$this->name = 'socolissimo';
		$this->tab = 'shipping_logistics';
		$this->version = '2.8.1';
		$this->author = 'PrestaShop';
		$this->limited_countries = array('fr');
		$this->module_key = 'faa857ecf7579947c8eee2d9b3d1fb04';

		parent::__construct();

		$this->page = basename(__FILE__, '.php');
		$this->displayName = $this->l('So Colissimo');
		$this->description = $this->l('Offer your customer 5 different delivery methods with LaPoste.');
		$this->url = Tools::getProtocol() . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'modules/' . $this->name . '/validation.php';

		/** Backward compatibility */
		require(_PS_MODULE_DIR_.$this->name.'/backward_compatibility/backward.php');

		if (self::isInstalled($this->name))
		{
			$warning = array();
			$soCarrier = new Carrier(Configuration::get('SOCOLISSIMO_CARRIER_ID'));
			if (Validate::isLoadedObject($soCarrier))
			{
				if (!$this->checkZone((int)($soCarrier->id)))
					$warning[] .= $this->l('\'Carrier Zone(s)\'').' ';
				if (!$this->checkGroup((int)($soCarrier->id)))
					$warning[] .= $this->l('\'Carrier Group\'').' ';
				if (!$this->checkRange((int)($soCarrier->id)))
					$warning[] .= $this->l('\'Carrier Range(s)\'').' ';
				if (!$this->checkDelivery((int)($soCarrier->id)))
					$warning[] .= $this->l('\'Carrier price delivery\'').' ';
			}

			//Check config and display warning
			if (!Configuration::get('SOCOLISSIMO_ID'))
				$warning[] .= $this->l('\'Id FO\'').' ';
			if (!Configuration::get('SOCOLISSIMO_KEY'))
				$warning[] .= $this->l('\'Key\'').' ';
			if (!Configuration::get('SOCOLISSIMO_URL'))
				$warning[] .= $this->l('\'Url So\'').' ';

			if (count($warning))
				$this->warning .= implode(' , ',$warning).$this->l('must be configured to use this module correctly').' ';
			if (Configuration::get('SOCOLISSIMO_VERSION') != $this->version)
				$this->runUpgrades(true);
		}
	}

	public function install()
	{
		if (!parent::install() || !Configuration::updateValue('SOCOLISSIMO_ID', NULL) || !Configuration::updateValue('SOCOLISSIMO_KEY', NULL) || !Configuration::updateValue('SOCOLISSIMO_VERSION', '2.8.0') ||
			!Configuration::updateValue('SOCOLISSIMO_URL', 'http://ws.colissimo.fr/pudo-fo-frame/storeCall.do') || !Configuration::updateValue('SOCOLISSIMO_URL_MOBILE', 'http://ws.colissimo.fr/') ||
			!Configuration::updateValue('SOCOLISSIMO_PREPARATION_TIME', 1) || !Configuration::updateValue('SOCOLISSIMO_EXP_BEL', true) || !Configuration::updateValue('SOCOLISSIMO_COST_SELLER', 0) ||
			!Configuration::updateValue('SOCOLISSIMO_OVERCOST', 3.6) || !$this->registerHook('extraCarrier') || !$this->registerHook('AdminOrder') || !$this->registerHook('updateCarrier') ||
			!$this->registerHook('newOrder') || !$this->registerHook('paymentTop') || !$this->registerHook('backOfficeHeader') || !Configuration::updateValue('SOCOLISSIMO_SUP_URL', 'http://ws.colissimo.fr/supervision-pudo-frame/supervision.jsp') ||
			!Configuration::updateValue('SOCOLISSIMO_SUP_BELG', true) || !Configuration::updateValue('SOCOLISSIMO_SUP', true) || !Configuration::updateValue('SOCOLISSIMO_USE_FANCYBOX', false) || !Configuration::updateValue('SOCOLISSIMO_USE_IFRAME', true))
			return false;

		//creat config table in database
		$sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'socolissimo_delivery_info` (
				  `id_cart` int(10) NOT NULL,
				  `id_customer` int(10) NOT NULL,
				  `delivery_mode` varchar(3) NOT NULL,
				  `prid` text(10) NOT NULL,
				  `prname` varchar(64) NOT NULL,
				  `prfirstname` varchar(64) NOT NULL,
				  `prcompladress` text NOT NULL,
				  `pradress1` text NOT NULL,
				  `pradress2` text NOT NULL,
				  `pradress3` text NOT NULL,
				  `pradress4` text NOT NULL,
				  `przipcode` text(10) NOT NULL,
				  `prtown` varchar(64) NOT NULL,
				  `cecountry` varchar(10) NOT NULL,
				  `cephonenumber` varchar(10) NOT NULL,
				  `ceemail` varchar(64) NOT NULL,
				  `cecompanyname` varchar(64) NOT NULL,
				  `cedeliveryinformation` text NOT NULL,
				  `cedoorcode1` varchar(10) NOT NULL,
				  `cedoorcode2` varchar(10) NOT NULL,
				  PRIMARY KEY  (`id_cart`,`id_customer`)
				) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

		if (!Db::getInstance()->execute($sql))
			return false;

		// Add carrier in back office
		if (!$this->createSoColissimoCarrier($this->_config))
			return false;

		return true;
	}

	public function uninstall()
	{
		$so_id = (int) Configuration::get('SOCOLISSIMO_CARRIER_ID');

		Configuration::deleteByName('SOCOLISSIMO_ID');
		Configuration::deleteByName('SOCOLISSIMO_VERSION');
		Configuration::deleteByName('SOCOLISSIMO_USE_FANCYBOX');
		Configuration::deleteByName('SOCOLISSIMO_USE_IFRAME');
		Configuration::deleteByName('SOCOLISSIMO_KEY');
		Configuration::deleteByName('SOCOLISSIMO_URL');
		Configuration::deleteByName('SOCOLISSIMO_URL_MOBILE');
		Configuration::deleteByName('SOCOLISSIMO_OVERCOST');
		Configuration::deleteByName('SOCOLISSIMO_COST_SELLER');
		Configuration::deleteByName('SOCOLISSIMO_UPG_COUNTRY');
		Configuration::deleteByName('SOCOLISSIMO_PREPARATION_TIME');
		Configuration::deleteByName('SOCOLISSIMO_CARRIER_ID');
		Configuration::deleteByName('SOCOLISSIMO_SUP');
		Configuration::deleteByName('SOCOLISSIMO_EXP_BEL');
		Configuration::deleteByName('SOCOLISSIMO_SUP_BELG');
		Configuration::deleteByName('SOCOLISSIMO_SUP_URL');
		Configuration::deleteByName('SOCOLISSIMO_OVERCOST_TAX');

		if (!parent::uninstall() ||
			!Db::getInstance()->execute('DROP TABLE IF EXISTS`' . _DB_PREFIX_ . 'socolissimo_delivery_info`') ||
			!$this->unregisterHook('extraCarrier') ||
			!$this->unregisterHook('payment') ||
			!$this->unregisterHook('AdminOrder') ||
			!$this->unregisterHook('newOrder') ||
			!$this->unregisterHook('updateCarrier') ||
			!$this->unregisterHook('paymentTop') ||
			!$this->unregisterHook('backOfficeHeader'))
			return false;

		// Delete So Carrier
		$soCarrier = new Carrier($so_id);
		// If socolissimo carrier is default set other one as default
		if (Configuration::get('PS_CARRIER_DEFAULT') == (int) ($soCarrier->id))
		{
			$carriersD = Carrier::getCarriers($this->context->language->id);
			foreach ($carriersD as $carrierD)
				if ($carrierD['active'] and !$carrierD['deleted'] and ($carrierD['name'] != $this->_config['name']))
					Configuration::updateValue('PS_CARRIER_DEFAULT', $carrierD['id_carrier']);
		}
		// Save old carrier id
		Configuration::updateValue('SOCOLISSIMO_CARRIER_ID_HIST', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST') . '|' . (int) ($soCarrier->id));
		$soCarrier->deleted = 1;

		if (!$soCarrier->update())
			return false;
		return true;
	}

	public function hookBackOfficeHeader()
	{
		if (!Configuration::get('SOCOLISSIMO_PERSONAL_DATA'))
		{
			if (_PS_VERSION_ < '1.5' || !method_exists($this->context->controller, 'addJQuery'))
			{
				return '<script type="text/javascript" src="' . __PS_BASE_URI__ . 'js/jquery/jquery-1.4.4.min.js"></script>'
					. '<script type="text/javascript" src="' . __PS_BASE_URI__ . 'js/jquery/jquery.fancybox-1.3.4.js"></script>'
					. '<link type="text/css" rel="stylesheet" href="' . __PS_BASE_URI__ . 'css/jquery.fancybox-1.3.4.css" />';
			}
			else
			{
				$this->context->controller->addJQuery();
				$this->context->controller->addJQueryPlugin('fancybox');
			}
		}
	}

	public function getContent()
	{
		$this->_html .= '<h2>' . $this->l('So Colissimo') . ' Version ' . Configuration::get('SOCOLISSIMO_VERSION') . '</h2>';

		if (!empty($_POST) && (Tools::isSubmit('submitPersonalSave') || Tools::isSubmit('submitPersonalCancel')))
			$validation = $this->_postPersonalProcess();
		else
			$validation = true;

		if (!empty($_POST) && Tools::isSubmit('submitSave'))
		{
			$this->_postValidation();
			if (!sizeof($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= '<div class="alert error"><img src="' . _PS_IMG_ . 'admin/forbbiden.gif" alt="nok" />&nbsp;' . $err . '</div>';
		}

		if (!Configuration::get('SOCOLISSIMO_PERSONAL_DATA'))
			$this->displayPersonalDataForm($validation);

		/* var to report */
		$moduleDir = _MODULE_DIR_ . $this->name;
		$id_user = Tools::safeOutput(Tools::getValue('id_user', Configuration::get('SOCOLISSIMO_ID')));
		$key = Tools::safeOutput(Tools::getValue('key', Configuration::get('SOCOLISSIMO_KEY')));
		$dypreparationtime = (int) (Tools::getValue('dypreparationtime', Configuration::get('SOCOLISSIMO_PREPARATION_TIME')));
		$costseller = (float) (Tools::getValue('costseller', Configuration::get('SOCOLISSIMO_COST_SELLER')));
		$exp_bel_activ = Tools::getValue('exp_bel_activ', Configuration::get('SOCOLISSIMO_EXP_BEL'));
		$supcostbelg = (float) (Tools::getValue('supcostbelg', Configuration::get('SOCOLISSIMO_SUP_BELG')));
		$overcost = (float) (Tools::getValue('overcost', number_format(Configuration::get('SOCOLISSIMO_OVERCOST'), 2, '.', '')));
		$url_so = htmlentities(Tools::getValue('url_so', Configuration::get('SOCOLISSIMO_URL')), ENT_NOQUOTES, 'UTF-8');
		$url_so_mobile = htmlentities(Tools::getValue('url_so_mobile', Configuration::get('SOCOLISSIMO_URL_MOBILE')), ENT_NOQUOTES, 'UTF-8');
		if (!Configuration::get('SOCOLISSIMO_USE_FANCYBOX') && !Configuration::get('SOCOLISSIMO_USE_IFRAME'))
			$display_type = 0;
		elseif (Configuration::get('SOCOLISSIMO_USE_FANCYBOX'))
			$display_type = 1;
		elseif (Configuration::get('SOCOLISSIMO_USE_IFRAME'))
			$display_type = 2;
		$sup_active = Tools::getValue('sup_active', Configuration::get('SOCOLISSIMO_SUP'));
		$url_sup = htmlentities(Tools::getValue('url_sup', Configuration::get('SOCOLISSIMO_SUP_URL')), ENT_NOQUOTES, 'UTF-8');
		$validation_url = htmlentities($this->url, ENT_NOQUOTES, 'UTF-8');
		$return_url = htmlentities($this->url, ENT_NOQUOTES, 'UTF-8');
		$this->context->smarty->assign(array(
				'moduleDir' => $moduleDir,
				'id_user' => $id_user,
				'key' => $key,
				'dypreparationtime' => $dypreparationtime,
				'costseller' => $costseller,
				'exp_bel_activ' => $exp_bel_activ,
				'supcostbelg' => $supcostbelg,
				'overcost' => $overcost,
				'url_so' => $url_so,
				'url_so_mobile' => $url_so_mobile,
				'display_type' => $display_type,
				'sup_active' => $sup_active,
				'url_sup' => $url_sup,
				'validation_url' => $validation_url,
				'return_url' => $return_url
			));

		return $this->_html.= $this->fetchTemplate('back_office.tpl');
	}


	protected function displayPersonalDataForm($validation = false)
	{
		$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false;

		if ((!$referer || ($referer && strpos($referer, 'configure'))) && ($validation == true))
			return false;

		$phone = Tools::getValue('SOCOLISSIMO_PERSONAL_PHONE');
		$zip_code = Tools::getValue('SOCOLISSIMO_PERSONAL_ZIP_CODE');
		$shop_zip_code = Configuration::get('PS_SHOP_CODE');
		$shop_phone = Configuration::get('PS_SHOP_PHONE');
		$parcels = Tools::getValue('SOCOLISSIMO_PERSONAL_QUANTITIES');
		$siret = Tools::getValue('SOCOLISSIMO_PERSONAL_SIRET');

		$moduleDir = _MODULE_DIR_ . $this->name;
		$this->context->smarty->assign(array(
				'moduleDir' => $moduleDir,
				'siret' => $siret,
				'parcels' => $parcels,
				'phone' => $phone,
				'zip_code' => $zip_code,
				'shop_zip_code' => $shop_zip_code,
				'shop_phone' => $shop_phone,
				'personal_data_phone_error' => $this->personal_data_phone_error,
				'personal_data_zip_code_error' => $this->personal_data_zip_code_error
			));
		return $this->_html.=$this->fetchTemplate('personnal_data.tpl');
	}

	protected function savePreactivationRequest()
	{
		if (_PS_VERSION_ < '1.5')
			return $this->savePreactivationRequest14();
		return $this->savePreactivationRequest15();
	}

	protected function savePreactivationRequest14()
	{
		$employee = new Employee((int) Context::getContext()->cookie->id_employee);

		$data = array(
			'version' => '1.0',
			'partner' => $this->name,
			'country_iso_code' => strtoupper(Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'))),
			'security' => md5($employee->email . _COOKIE_IV_),
			'partner' => $this->name,
			'email' => $employee->email,
			'firstName' => $employee->firstname,
			'lastName' => $employee->lastname,
			'shop' => Configuration::get('PS_SHOP_NAME'),
			'host' => $_SERVER['HTTP_HOST'],
			'phoneNumber' => Configuration::get('SOCOLISSIMO_PERSONAL_PHONE'),
			'postalCode' => Configuration::get('SOCOLISSIMO_PERSONAL_ZIP_CODE'),
			'businessType' => Configuration::get('SOCOLISSIMO_PERSONAL_QUANTITIES'),
			'siret' => Configuration::get('SOCOLISSIMO_PERSONAL_SIRET'),
		);

		$query = http_build_query($data);

		return @Tools::file_get_contents('http://api.prestashop.com/partner/preactivation/actions.php?' . $query);
	}

	protected function savePreactivationRequest15()
	{
		$employee = new Employee((int) Context::getContext()->cookie->id_employee);

		$data = array(
			'iso_lang' => strtolower($this->context->language->iso_code),
			'iso_country' => strtoupper($this->context->country->iso_code),
			'host' => $_SERVER['HTTP_HOST'],
			'ps_version' => _PS_VERSION_,
			'ps_creation' => _PS_CREATION_DATE_,
			'partner' => $this->name,
			'firstname' => $employee->firstname,
			'lastname' => $employee->lastname,
			'email' => $employee->email,
			'shop' => Configuration::get('PS_SHOP_NAME'),
			'type' => 'home',
			'phone' => Configuration::get('SOCOLISSIMO_PERSONAL_PHONE'),
			'zipcode' => Configuration::get('SOCOLISSIMO_PERSONAL_ZIP_CODE'),
			'fields' => serialize(
				array(
					'quantities' => Configuration::get('SOCOLISSIMO_PERSONAL_QUANTITIES'),
					'siret' => Configuration::get('SOCOLISSIMO_PERSONAL_SIRET'),
				)
			),
		);

		$query = http_build_query($data);

		return @Tools::file_get_contents('http://api.prestashop.com/partner/premium/set_request.php?' . $query);
	}


	private function _postValidation()
	{
		if (Tools::getValue('id_user') == NULL)
			$this->_postErrors[] = $this->l('ID SO not specified');

		if (Tools::getValue('key') == NULL)
			$this->_postErrors[] = $this->l('Key SO not specified');

		if (Tools::getValue('dypreparationtime') == NULL)
			$this->_postErrors[] = $this->l('Preparation time not specified');
		elseif (!Validate::isInt(Tools::getValue('dypreparationtime')))
			$this->_postErrors[] = $this->l('Invalid preparation time');

		if (Tools::getValue('overcost') == NULL)
			$this->_postErrors[] = $this->l('Additional cost not specified');
		elseif (!Validate::isFloat(Tools::getValue('overcost')))
			$this->_postErrors[] = $this->l('Invalid additional cost');
	}


	protected function _postPersonalProcess()
	{
		if (Tools::isSubmit('submitPersonalSave'))
		{
			$result = true;
			$phone = Tools::getValue('SOCOLISSIMO_PERSONAL_PHONE');
			$zip_code = Tools::getValue('SOCOLISSIMO_PERSONAL_ZIP_CODE');
			$quantities = Tools::getValue('SOCOLISSIMO_PERSONAL_QUANTITIES');
			$siret = Tools::getValue('SOCOLISSIMO_PERSONAL_SIRET');
			$this->personal_data_phone_error = false;
			$this->personal_data_zip_code_error = false;

			if (!(bool) preg_match('#^(([\d]{2})([\s]){0,1}){5}$#', $phone))
			{
				$this->personal_data_phone_error = true;
				$result = false;
			}
			if (!(bool) preg_match('#^(([0-8][0-9])|(9[0-5]))[0-9]{3}$#', $zip_code))
			{
				$this->personal_data_zip_code_error = true;
				$result = false;
			}

			if ($result == false)
				return false;

			Configuration::updateValue('SOCOLISSIMO_PERSONAL_PHONE', $phone);
			Configuration::updateValue('SOCOLISSIMO_PERSONAL_ZIP_CODE', $zip_code);
			Configuration::updateValue('SOCOLISSIMO_PERSONAL_QUANTITIES', $quantities);
			Configuration::updateValue('SOCOLISSIMO_PERSONAL_SIRET', $siret);
			$this->savePreactivationRequest();
		}

		if (Tools::isSubmit('submitPersonalSave') || Tools::isSubmit('submitPersonalCancel'))
			Configuration::updateValue('SOCOLISSIMO_PERSONAL_DATA', true);

		return true;
	}


	private function _postProcess()
	{
		if (Tools::getValue('display_type') == 1)
		{
			Configuration::updateValue('SOCOLISSIMO_USE_FANCYBOX', true);
			Configuration::updateValue('SOCOLISSIMO_USE_IFRAME', false);
		}
		if (Tools::getValue('display_type') == 2)
		{
			Configuration::updateValue('SOCOLISSIMO_USE_IFRAME', true);
			Configuration::updateValue('SOCOLISSIMO_USE_FANCYBOX', false);
		}
		if (Tools::getValue('display_type') == 0)
		{
			Configuration::updateValue('SOCOLISSIMO_USE_IFRAME', false);
			Configuration::updateValue('SOCOLISSIMO_USE_FANCYBOX', false);
		}
		if (Configuration::updateValue('SOCOLISSIMO_ID', Tools::getValue('id_user')) &&
			Configuration::updateValue('SOCOLISSIMO_KEY', Tools::getValue('key')) &&
			Configuration::updateValue('SOCOLISSIMO_URL', pSQL(Tools::getValue('url_so'))) &&
			Configuration::updateValue('SOCOLISSIMO_URL_MOBILE', pSQL(Tools::getValue('url_so_mobile'))) &&
			Configuration::updateValue('SOCOLISSIMO_COST_SELLER', (float) (Tools::getValue('costseller'))) &&
			Configuration::updateValue('SOCOLISSIMO_EXP_BEL', (Tools::getValue('exp_bel_active'))) &&
			Configuration::updateValue('SOCOLISSIMO_SUP_BELG', (float) (Tools::getValue('supcostbelg'))) &&
			Configuration::updateValue('SOCOLISSIMO_PREPARATION_TIME', (int) (Tools::getValue('dypreparationtime'))) &&
			Configuration::updateValue('SOCOLISSIMO_OVERCOST', (float) (Tools::getValue('overcost'))) &&
			Configuration::updateValue('SOCOLISSIMO_SUP_URL', Tools::getValue('url_sup')) &&
			Configuration::updateValue('SOCOLISSIMO_OVERCOST_TAX', Tools::getValue('id_tax_rules_group')) &&
			Configuration::updateValue('SOCOLISSIMO_SUP', (int) (Tools::getValue('sup_active'))))
		{
			//save old carrier id if change
			if (!in_array((int) (Tools::getValue('carrier')), explode('|', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST'))))
				Configuration::updateValue('SOCOLISSIMO_CARRIER_ID_HIST', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST') . '|' . (int) (Tools::getValue('carrier')));

			$dataSync = (($so_login = Configuration::get('SOCOLISSIMO_ID')) ? '<img src="http://api.prestashop.com/modules/socolissimo.png?ps_id=' . urlencode($so_login) . '" style="float:right" />' : '');
			$this->_html .= $this->displayConfirmation($this->l('Configuration updated') . $dataSync);
		}
		else
			$this->_html .= '<div class="alert error"><img src="' . _PS_IMG_ . 'admin/forbbiden.gif" alt="nok" /> ' . $this->l('Cannot save settings') . '</div>';
	}


	public function hookExtraCarrier($params)
	{
		$carrierSo = new Carrier((int) (Configuration::get('SOCOLISSIMO_CARRIER_ID')));

		if (!isset($carrierSo) || !$carrierSo->active)
			return '';

		$country = new Country((int) ($params['address']->id_country));
		$carriers = Carrier::getCarriers($this->context->language->id, true, false, false, null, (defined('ALL_CARRIERS') ? ALL_CARRIERS : Carrier::ALL_CARRIERS));

		// Backward compatibility 1.5
		$id_carrier = $carrierSo->id;

		// For now works only with single shipping !
		if (method_exists($params['cart'], 'carrierIsSelected'))
			if ($params['cart']->carrierIsSelected((int) $carrierSo->id, $params['address']->id))
				$id_carrier = (int) $carrierSo->id;
			$customer = new Customer($params['address']->id_customer);
		
		$gender = array('1' => 'MR', '2' => 'MME', '3' => 'MLE');
		
		if (in_array(intval($customer->id_gender), array(1, 2)))
			$cecivility = $gender[intval($customer->id_gender)];
		else
			$cecivility = 'MR';

		$forwardingCharge = (float) $this->initialCost;

		// Keep this fields order (see doc.)
		$inputs = array(
			'pudoFOId' => Configuration::get('SOCOLISSIMO_ID'),
			'ceName' => $this->replaceAccentedChars(substr($params['address']->lastname, 0, 34)),
			'dyPreparationTime' => (int) Configuration::Get('SOCOLISSIMO_PREPARATION_TIME'),
			'dyForwardingCharges' => (float) $forwardingCharge,
			'dyForwardingChargesCMT' => (float) Configuration::Get('SOCOLISSIMO_COST_SELLER'),
			'trClientNumber' => (int) $params['address']->id_customer,
			'orderId' => $this->formatOrderId((int) $params['address']->id),
			'numVersion' => $this->getNumVersion(),
			'ceCivility' => $cecivility,
			'ceFirstName' => $this->replaceAccentedChars(substr($params['address']->firstname, 0, 29)),
			'ceCompanyName' => $this->replaceAccentedChars(substr($params['address']->company, 0, 38)),
			'ceAdress3' => $this->replaceAccentedChars(substr($params['address']->address1, 0, 38)),
			'ceAdress4' => $this->replaceAccentedChars(substr($params['address']->address2, 0, 38)),
			'ceZipCode' => $this->replaceAccentedChars($params['address']->postcode),
			'ceTown' => $this->replaceAccentedChars(substr($params['address']->city, 0, 32)),
			'ceEmail' => $this->replaceAccentedChars($params['cookie']->email),
			'cePhoneNumber' => $this->replaceAccentedChars(str_replace(array(' ', '.', '-', ',', ';', '+', '/', '\\', '+', '(', ')'), '', $params['address']->phone_mobile)),
			'dyWeight' => (float) ($params['cart']->getTotalWeight()) * 1000,
			'trParamPlus' => $carrierSo->id,
			'trReturnUrlKo' => htmlentities($this->url, ENT_NOQUOTES, 'UTF-8'),
			'trReturnUrlOk' => htmlentities($this->url, ENT_NOQUOTES, 'UTF-8'),
			'CHARSET' => 'UTF-8',
			'cePays' => $country->iso_code,
			'trInter' => Configuration::get('SOCOLISSIMO_EXP_BEL'),
			'ceLang' => 'FR'
		);
		if (!$inputs['dyForwardingChargesCMT'])
			unset($inputs['dyForwardingChargesCMT']);

		// set params for Api 3.0 if needed
		$inputs = $this->setInputParams($inputs);
		// generate key for API
		$inputs['signature'] = $this->generateKey($inputs);

		$this->context->smarty->assign(array(
				'select_label' => $this->l('Select delivery mode'),
				'edit_label' => $this->l('Edit delivery mode'),
				'token' => sha1('socolissimo' . _COOKIE_KEY_ . Context::getContext()->cookie->id_cart),
				'urlSo' => Configuration::get('SOCOLISSIMO_URL') . '?trReturnUrlKo=' . htmlentities($this->url, ENT_NOQUOTES, 'UTF-8'),
				'urlSoMobile' => Configuration::get('SOCOLISSIMO_URL_MOBILE') . '?trReturnUrlKo=' . htmlentities($this->url, ENT_NOQUOTES, 'UTF-8'),
				'id_carrier' => $id_carrier,
				'SOBWD_C' => (_PS_VERSION_ < '1.5') ? false : true, // Backward compatibility for js process in tpl
				'inputs' => $inputs,
				'initialCost' => $this->l('From') . ' ' . $forwardingCharge . ' €', // to change label for price in tpl
				'finishProcess' => $this->l('To choose SoColissimo, click on a delivery method')
			));

		$ids = array();
		foreach ($carriers as $carrier)
			$ids[] = $carrier['id_carrier'];


		if ($params['cart']->id_carrier == Configuration::Get('SOCOLISSIMO_CARRIER_ID') && $this->getDeliveryInfos($this->context->cart->id, $this->context->customer->id))
			$this->context->smarty->assign('already_select_delivery', true);
		else
			$this->context->smarty->assign('already_select_delivery', false);

		if (($country->iso_code == 'FR' || ($country->iso_code == 'BE' && Configuration::get('SOCOLISSIMO_EXP_BEL'))) && (Configuration::Get('SOCOLISSIMO_ID') != NULL) &&
			(Configuration::get('SOCOLISSIMO_KEY') != NULL) && $this->checkAvailibility() &&
			$this->checkSoCarrierAvailable((int) (Configuration::get('SOCOLISSIMO_CARRIER_ID'))) &&
			in_array((int) (Configuration::get('SOCOLISSIMO_CARRIER_ID')), $ids))
		{
			// if mobile or iPad
			if (_PS_VERSION_ < '1.5')
			{ // 1.4
				if (_THEME_NAME_ == 'prestashop_mobile' || $this->isIpad())
					if ($country->iso_code != 'FR')
					{
						$tabIdSoco = explode('|', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST'));
						$tabIdSoco[] = $id_carrier;
						$this->context->smarty->assign('ids', $tabIdSoco);
						return $this->fetchTemplate('socolissimo_error_mobile_opc.tpl');
					}
				else
					return $this->fetchTemplate('socolissimo_redirect_mobile.tpl');
			}
			else // 1.5
				if (Context::getContext()->getMobileDevice() || _THEME_NAME_ == 'prestashop_mobile' || $this->isIpad())
					if ($country->iso_code != 'FR' || Configuration::get('PS_ORDER_PROCESS_TYPE'))
					{
						$tabIdSoco = explode('|', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST'));
						$tabIdSoco[] = $id_carrier;
						$this->context->smarty->assign('ids', $tabIdSoco);
						return $this->fetchTemplate('socolissimo_error_mobile_opc.tpl');
					}
				else
					return $this->fetchTemplate('socolissimo_redirect_mobile.tpl');

				// route display mode
				if (Configuration::get('PS_ORDER_PROCESS_TYPE') || Configuration::get('SOCOLISSIMO_USE_FANCYBOX'))
					return $this->fetchTemplate('socolissimo_fancybox.tpl');
				if (Configuration::get('SOCOLISSIMO_USE_IFRAME'))
					return $this->fetchTemplate('socolissimo_iframe.tpl');
				return $this->fetchTemplate('socolissimo_redirect.tpl');
		}
		else
		{
			$tabIdSoco = explode('|', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST'));
			$tabIdSoco[] = $id_carrier;
			$this->context->smarty->assign('ids', $tabIdSoco);
			return $this->fetchTemplate('socolissimo_error.tpl');
		}
	}


	public function hookNewOrder($params)
	{
		if ($params['order']->id_carrier != Configuration::get('SOCOLISSIMO_CARRIER_ID'))
			return;

		$order = $params['order'];
		$order->id_address_delivery = $this->isSameAddress((int) ($order->id_address_delivery), (int) ($order->id_cart), (int) ($order->id_customer));
		$order->update();
	}


	public function hookAdminOrder($params)
	{
		require_once _PS_MODULE_DIR_ . 'socolissimo/classes/SCFields.php';

		$deliveryMode = array('DOM' => 'Livraison à domicile', 'BPR' => 'Livraison en Bureau de Poste',
			'A2P' => 'Livraison Commerce de proximité', 'MRL' => 'Livraison Commerce de proximité', 'CMT' => 'Livraison Commerce',
			'CIT' => 'Livraison en Cityssimo', 'ACP' => 'Agence ColiPoste', 'CDI' => 'Centre de distribution',
			'RDV' => 'Livraison sur Rendez-vous');

		$order = new Order($params['id_order']);
		$addressDelivery = new Address((int) ($order->id_address_delivery), (int) ($params['cookie']->id_lang));

		$soCarrier = new Carrier((int) (Configuration::get('SOCOLISSIMO_CARRIER_ID')));
		$deliveryInfos = $this->getDeliveryInfos((int) ($order->id_cart), (int) ($order->id_customer));
		// in 2.8.0 country is mandatory
		$sql = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'country c
										  LEFT JOIN ' . _DB_PREFIX_ . 'country_lang cl ON cl.id_lang = ' . (int) $params['cookie']->id_lang . '
										  AND cl.id_country = c.id_country WHERE iso_code = "' . pSQL($deliveryInfos ['cecountry']) . '"');
		$nameCountry = $sql['name'];

		if (((int) ($order->id_carrier) == (int) ($soCarrier->id) or in_array((int) ($order->id_carrier), explode('|', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST')))) and !empty($deliveryInfos))
		{
			$html = '<br><br><fieldset style="width:400px;"><legend><img src="' . $this->_path . 'logo.gif" alt="" /> ' . $this->l('So Colissimo') . '</legend>';
			$html .= '<b>' . $this->l('Delivery mode') . ' : </b>';

			$sc_fields = new SCFields($deliveryInfos['delivery_mode']);


			switch ($sc_fields->delivery_mode)
			{
				case SCFields::HOME_DELIVERY:
					$html .= $deliveryMode[$deliveryInfos['delivery_mode']] . '<br /><br />';
					$html .='<b>' . $this->l('Customer') . ' : </b>' . Tools::htmlentitiesUTF8($addressDelivery->firstname) . ' ' . Tools::htmlentitiesUTF8($addressDelivery->lastname) . '<br />' .
						(!empty($deliveryInfos['cecompanyname']) ? '<b>' . $this->l('Company') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['cecompanyname']) . '<br/>' : '' ) .
						(!empty($deliveryInfos['ceemail']) ? '<b>' . $this->l('E-mail address') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['ceemail']) . '<br/>' : '' ) .
						(!empty($deliveryInfos['cephonenumber']) ? '<b>' . $this->l('Phone') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['cephonenumber']) . '<br/><br/>' : '' ) .
						'<b>' . $this->l('Customer address') . ' : </b><br/>'
						. (Tools::htmlentitiesUTF8($addressDelivery->address1) ? Tools::htmlentitiesUTF8($addressDelivery->address1) . '<br />' : '')
						. (!empty($addressDelivery->address2) ? Tools::htmlentitiesUTF8($addressDelivery->address2) . '<br />' : '')
						. (!empty($addressDelivery->postcode) ? Tools::htmlentitiesUTF8($addressDelivery->postcode) . '<br />' : '')
						. (!empty($addressDelivery->city) ? Tools::htmlentitiesUTF8($addressDelivery->city) . '<br />' : '')
						. (!empty($addressDelivery->country) ? Tools::htmlentitiesUTF8($addressDelivery->country) . '<br />' : '')
						. (!empty($addressDelivery->other) ? '<hr><b>' . $this->l('Other') . ' : </b>' . Tools::htmlentitiesUTF8($addressDelivery->other) . '<br /><br />' : '')
						. (!empty($deliveryInfos['cedoorcode1']) ? '<b>' . $this->l('Door code') . ' 1 : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['cedoorcode1']) . '<br/>' : '' )
						. (!empty($deliveryInfos['cedoorcode2']) ? '<b>' . $this->l('Door code') . ' 2 : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['cedoorcode2']) . '<br/>' : '' )
						. (!empty($deliveryInfos['cedeliveryinformation']) ? '<b>' . $this->l('Delivery information') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['cedeliveryinformation']) . '<br/><br/>' : '' );
					break;
				case SCFields::RELAY_POINT:
					$html .= str_replace('+', ' ', $deliveryMode[$deliveryInfos['delivery_mode']]) . '<br/>'
						. (!empty($deliveryInfos['prid']) ? '<b>' . $this->l('Pick up point ID') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['prid']) . '<br/>' : '' )
						. (!empty($deliveryInfos['prname']) ? '<b>' . $this->l('Pick up point') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['prname']) . '<br/>' : '' )
						. '<b>' . $this->l('Pick up point address') . ' : </b><br/>'
						. (!empty($deliveryInfos['pradress1']) ? Tools::htmlentitiesUTF8($deliveryInfos['pradress1']) . '<br/>' : '' )
						. (!empty($deliveryInfos['pradress2']) ? Tools::htmlentitiesUTF8($deliveryInfos['pradress2']) . '<br/>' : '' )
						. (!empty($deliveryInfos['pradress3']) ? Tools::htmlentitiesUTF8($deliveryInfos['pradress3']) . '<br/>' : '' )
						. (!empty($deliveryInfos['pradress4']) ? Tools::htmlentitiesUTF8($deliveryInfos['pradress4']) . '<br/>' : '' )
						. (!empty($deliveryInfos['przipcode']) ? Tools::htmlentitiesUTF8($deliveryInfos['przipcode']) . '<br/>' : '' )
						. (!empty($deliveryInfos['prtown']) ? Tools::htmlentitiesUTF8($deliveryInfos['prtown']) . '<br/>' : '' )
						. (!empty($nameCountry) ? Tools::htmlentitiesUTF8($nameCountry) . '<br/>' : '' )
						. (!empty($deliveryInfos['ceemail']) ? '<b>' . $this->l('Email') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['ceemail']) . '<br/>' : '' )
						. (!empty($deliveryInfos['cephonenumber']) ? '<b>' . $this->l('Phone') . ' : </b>' . Tools::htmlentitiesUTF8($deliveryInfos['cephonenumber']) . '<br/><br/>' : '' );
	
					break;
			}
			$html .= '</fieldset>';
			return $html;
		}
	}


	public function hookUpdateCarrier($params)
	{
		if ((int) ($params['id_carrier']) == (int) (Configuration::get('SOCOLISSIMO_CARRIER_ID')))
		{
			Configuration::updateValue('SOCOLISSIMO_CARRIER_ID', (int) ($params['carrier']->id));
			Configuration::updateValue('SOCOLISSIMO_CARRIER_ID_HIST', Configuration::get('SOCOLISSIMO_CARRIER_ID_HIST') . '|' . (int) ($params['carrier']->id));
		}
	}


	public function hookPaymentTop($params)
	{
		if ($params['cart']->id_carrier == Configuration::get('SOCOLISSIMO_CARRIER_ID') and !$this->getDeliveryInfos((int) $params['cookie']->id_cart, (int) $params['cookie']->id_customer))
		{
			$params['cart']->id_carrier = 0;
			if (method_exists($params['cart'], 'setDeliveryOption'))
			{
				// TODO : 1.5 > find a way to block properly the paiement in OPC
				//$params['cart']->delivery_option = serialize(array($params['cart']->id_address_delivery => 0));
				//$params['cart']->setDeliveryOption(array($params['cart']->id_address_delivery, 0));
			}
		}
	}


	/**
	 * Generate the signed key
	 *
	 * @static
	 * @param $params
	 * @return string
	 */
	public function generateKey($params)
	{
		$str = '';

		foreach ($params as $key => $value)
			if (!in_array(strtoupper($key), array('SIGNATURE')))
				$str .= utf8_decode($value);

		return sha1($str . strtolower(Configuration::get('SOCOLISSIMO_KEY')));
	}


	public static function createSoColissimoCarrier($config)
	{
		$carrier = new Carrier();
		$carrier->name = $config['name'];
		$carrier->id_tax_rules_group = $config['id_tax_rules_group'];
		$carrier->id_zone = $config['id_zone'];
		$carrier->url = $config['url'];
		$carrier->active = $config['active'];
		$carrier->deleted = $config['deleted'];
		$carrier->delay = $config['delay'];
		$carrier->shipping_handling = $config['shipping_handling'];
		$carrier->range_behavior = $config['range_behavior'];
		$carrier->is_module = $config['is_module'];
		$carrier->shipping_external = $config['shipping_external'];
		$carrier->external_module_name = $config['external_module_name'];
		$carrier->need_range = $config['need_range'];

		$languages = Language::getLanguages(true);
		foreach ($languages as $language)
		{
			if ($language['iso_code'] == 'fr')
				$carrier->delay[$language['id_lang']] = $config['delay'][$language['iso_code']];
			if ($language['iso_code'] == 'en')
				$carrier->delay[$language['id_lang']] = $config['delay'][$language['iso_code']];
		}

		if ($carrier->add())
		{
			Configuration::updateValue('SOCOLISSIMO_CARRIER_ID', (int) ($carrier->id));
			$groups = Group::getgroups(true);

			foreach ($groups as $group)
				Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'carrier_group VALUE (\'' . (int) ($carrier->id) . '\',\'' . (int) ($group['id_group']) . '\')');

			$rangePrice = new RangePrice();
			$rangePrice->id_carrier = $carrier->id;
			$rangePrice->delimiter1 = '0';
			$rangePrice->delimiter2 = '10000';
			$rangePrice->add();

			$rangeWeight = new RangeWeight();
			$rangeWeight->id_carrier = $carrier->id;
			$rangeWeight->delimiter1 = '0';
			$rangeWeight->delimiter2 = '10000';
			$rangeWeight->add();


			//copy logo
			if (!copy(dirname(__FILE__) . '/img/socolissimo.jpg', _PS_SHIP_IMG_DIR_ . '/' . $carrier->id . '.jpg'))
				return false;
			return true;
		}
		return false;
	}


	public function getDeliveryInfos($idCart, $idCustomer)
	{
		return Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'socolissimo_delivery_info WHERE id_cart = ' . (int) ($idCart) . ' AND id_customer = ' . (int) ($idCustomer));
	}


	public function isSameAddress($idAddress, $idCart, $idCustomer)
	{
		$return = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'socolissimo_delivery_info WHERE id_cart =\'' . (int) ($idCart) . '\' AND id_customer =\'' . (int) ($idCustomer) . '\'');
		if (!$return)
			return $idAddress;

		$psAddress = new Address((int) ($idAddress));
		$newAddress = new Address();
		$sql = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'country c
										  LEFT JOIN ' . _DB_PREFIX_ . 'country_lang cl ON cl.id_lang = ' . (int) $this->context->language->id . '
										  AND cl.id_country = c.id_country WHERE iso_code = "' . pSQL($return ['cecountry']) . '"');
		$nameCountry = $sql['name'];
		$isoCode = $sql['id_country'];

		if ($this->upper($psAddress->lastname) != $this->upper($return['prname']) || $psAddress->id_country != $isoCode || 
			$this->upper($psAddress->firstname) != $this->upper($return['prfirstname']) || $this->upper($psAddress->address1) != $this->upper($return['pradress3']) || 
			$this->upper($psAddress->address2) != $this->upper($return['pradress2']) || $this->upper($psAddress->postcode) != $this->upper($return['przipcode']) || 
			$this->upper($psAddress->city) != $this->upper($return['prtown']) || str_replace(array(' ', '.', '-', ',', ';', '+', '/', '\\', '+', '(', ')'), '', $psAddress->phone_mobile) != $return['cephonenumber'])
		{
			$newAddress->id_customer = (int) ($idCustomer);
			$newAddress->lastname = substr($return['prname'], 0, 32);
			$newAddress->firstname = substr($return['prfirstname'], 0, 32);
			$newAddress->postcode = $return['przipcode'];
			$newAddress->city = $return['prtown'];
			$newAddress->id_country = $isoCode;
			$newAddress->alias = 'So Colissimo - ' . date('d-m-Y');

			if (!in_array($return['delivery_mode'], array('DOM', 'RDV')))
			{
				$newAddress->active = 1;
				$newAddress->deleted = 1;
				$newAddress->address1 = $return['pradress1'];
				$newAddress->add();
			}
			else
			{
				$newAddress->address1 = $return['pradress3'];
				((isset($return['pradress2'])) ? $newAddress->address2 = $return['pradress2'] : $newAddress->address2 = '');
				((isset($return['pradress1'])) ? $newAddress->other .= $return['pradress1'] : $newAddress->other = '');
				((isset($return['pradress4'])) ? $newAddress->other .= ' | ' . $return['pradress4'] : $newAddress->other = '');
				$newAddress->postcode = $return['przipcode'];
				$newAddress->city = $return['prtown'];
				$newAddress->id_country = $isoCode;
				$newAddress->alias = 'So Colissimo - ' . date('d-m-Y');
				$newAddress->add();
			}
			return (int)$newAddress->id;
		}
		return (int)$psAddress->id;
	}


	public function checkZone($id_carrier)
	{
		return (bool) Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'carrier_zone WHERE id_carrier = ' . (int) ($id_carrier));
	}


	public function checkGroup($id_carrier)
	{
		return (bool) Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'carrier_group WHERE id_carrier = ' . (int) ($id_carrier));
	}


	public function checkRange($id_carrier)
	{
		switch (Configuration::get('PS_SHIPPING_METHOD'))
		{
			case '0' :
				$sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'range_price WHERE id_carrier = ' . (int) ($id_carrier);
				break;
			case '1' :
				$sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'range_weight WHERE id_carrier = ' . (int) ($id_carrier);
				break;
		}
		return (bool) Db::getInstance()->getRow($sql);
	}


	public function checkDelivery($id_carrier)
	{
		return (bool) Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'delivery WHERE id_carrier = ' . (int) ($id_carrier));
	}


	public function upper($strIn)
	{
		return strtoupper(str_replace('-', ' ', Tools::link_rewrite($strIn)));
	}


	public function lower($strIn)
	{
		return strtolower(str_replace('-', ' ', Tools::link_rewrite($strIn)));
	}


	/**
	 * Generate good order id format.
	 *
	 * @param $id
	 * @return string
	 */
	public function formatOrderId($id)
	{
		while (strlen($id) < 5)
			$id = '0' . $id;
		return $id;
	}


	public function checkAvailibility()
	{
		if (Configuration::get('SOCOLISSIMO_SUP'))
		{
			$ctx = @stream_context_create(array('http' => array('timeout' => 1)));
			$return = @Tools::file_get_contents(Configuration::get('SOCOLISSIMO_SUP_URL'), 0, $ctx);

			if (ini_get('allow_url_fopen') == 0)
				return true;
			else
			{
				if (!empty($return))
				{
					preg_match('[OK]', $return, $matches);
					if ($matches[0] == 'OK')
						return true;
					return false;
				}
			}
		}
		return true;
	}


	private function checkSoCarrierAvailable($id_carrier)
	{
		$carrier = new Carrier((int) ($id_carrier));
		$address = new Address((int) ($this->context->cart->id_address_delivery));
		$id_zone = Address::getZoneById((int) ($address->id));

		// Get only carriers that are compliant with shipping method
		if ((Configuration::get('PS_SHIPPING_METHOD') && $carrier->getMaxDeliveryPriceByWeight($id_zone) === false) ||
			(!Configuration::get('PS_SHIPPING_METHOD') && $carrier->getMaxDeliveryPriceByPrice($id_zone) === false))
			return false;

		// If out-of-range behavior carrier is set on "Desactivate carrier"
		if ($carrier->range_behavior)
		{
			// Get id zone
			$id_zone = (int) $this->context->country->id_zone;
			if (isset($this->context->cart->id_address_delivery) and $this->context->cart->id_address_delivery)
				$id_zone = Address::getZoneById((int) ($this->context->cart->id_address_delivery));

			// Get only carriers that have a range compatible with cart
			if ((Configuration::get('PS_SHIPPING_METHOD') && (!Carrier::checkDeliveryPriceByWeight((int) ($carrier->id), $this->context->cart->getTotalWeight(), $id_zone))) ||
				(!Configuration::get('PS_SHIPPING_METHOD') && (!Carrier::checkDeliveryPriceByPrice((int) ($carrier->id), $this->context->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $id_zone, $this->context->cart->id_currency))))
				return false;
		}
		return true;
	}


	public function getOrderShippingCost($params, $shipping_cost)
	{
		// for label in tpl
		$this->initialCost = $shipping_cost;

		$deliveryInfo = $this->getDeliveryInfos($this->context->cart->id, $this->context->cart->id_customer);
		// apply overcost if needed
		if (!empty($deliveryInfo))
		{
			if ($deliveryInfo['delivery_mode'] == 'RDV')
				$shipping_cost += (float) (Configuration::get('SOCOLISSIMO_OVERCOST'));
			if ($deliveryInfo['cecountry'] == 'BE')
				$shipping_cost += (float) (Configuration::get('SOCOLISSIMO_SUP_BELG'));
			if ($deliveryInfo['delivery_mode'] == 'A2P' && Configuration::get('SOCOLISSIMO_COST_SELLER'))
				$shipping_cost = (float) (Configuration::get('SOCOLISSIMO_COST_SELLER'));
			return $shipping_cost;
		}
		return $shipping_cost;
	}


	public function getOrderShippingCostExternal($params)
	{

	}


	public function getNumVersion()
	{
		return $this->api_num_version;
	}


	/**
	 * Return the cecivility customer
	 *
	 * @return string
	 */
	public function getTitle(Customer $customer)
	{
		$title = 'MR';
		if (_PS_VERSION_ < '1.5')
		{
			$titles = array('1' => 'MR', '2' => 'MME');
			if (isset($titles[$customer->id_gender]))
				return $titles[$customer->id_gender];
		}
		else
		{
			$gender = new Gender($customer->id_gender, $this->context->language->id);
			return $gender->name;
		}
		return $title;
	}


	/**
	 * @param $str
	 * @return mixed
	 */
	public function replaceAccentedChars($str)
	{
		$str = preg_replace(
			array(
				/* Lowercase */
				'/[\x{0105}\x{00E0}\x{00E1}\x{00E2}\x{00E3}\x{00E4}\x{00E5}]/u',
				'/[\x{00E7}\x{010D}\x{0107}]/u',
				'/[\x{010F}]/u',
				'/[\x{00E8}\x{00E9}\x{00EA}\x{00EB}\x{011B}\x{0119}]/u',
				'/[\x{00EC}\x{00ED}\x{00EE}\x{00EF}]/u',
				'/[\x{0142}\x{013E}\x{013A}]/u',
				'/[\x{00F1}\x{0148}]/u',
				'/[\x{00F2}\x{00F3}\x{00F4}\x{00F5}\x{00F6}\x{00F8}]/u',
				'/[\x{0159}\x{0155}]/u',
				'/[\x{015B}\x{0161}]/u',
				'/[\x{00DF}]/u',
				'/[\x{0165}]/u',
				'/[\x{00F9}\x{00FA}\x{00FB}\x{00FC}\x{016F}]/u',
				'/[\x{00FD}\x{00FF}]/u',
				'/[\x{017C}\x{017A}\x{017E}]/u',
				'/[\x{00E6}]/u',
				'/[\x{0153}]/u',
				/* Uppercase */
				'/[\x{0104}\x{00C0}\x{00C1}\x{00C2}\x{00C3}\x{00C4}\x{00C5}]/u',
				'/[\x{00C7}\x{010C}\x{0106}]/u',
				'/[\x{010E}]/u',
				'/[\x{00C8}\x{00C9}\x{00CA}\x{00CB}\x{011A}\x{0118}]/u',
				'/[\x{0141}\x{013D}\x{0139}]/u',
				'/[\x{00D1}\x{0147}]/u',
				'/[\x{00D3}]/u',
				'/[\x{0158}\x{0154}]/u',
				'/[\x{015A}\x{0160}]/u',
				'/[\x{0164}]/u',
				'/[\x{00D9}\x{00DA}\x{00DB}\x{00DC}\x{016E}]/u',
				'/[\x{017B}\x{0179}\x{017D}]/u',
				'/[\x{00C6}]/u',
				'/[\x{0152}]/u',
			), array(
				'a', 'c', 'd', 'e', 'i', 'l', 'n', 'o', 'r', 's', 'ss', 't', 'u', 'y', 'z', 'ae', 'oe',
				'A', 'C', 'D', 'E', 'L', 'N', 'O', 'R', 'S', 'T', 'U', 'Z', 'AE', 'OE'
			), $str);
		$arrayUnauthorisedApi = array(';', '€', '~', '#', '{', '(', '[', '|', '\\', '^', ')', ']', '=', '}', '$', '¤', '£', '%', 'μ', '*', '§', '!', '°', '²', '"');
		foreach ($arrayUnauthorisedApi as $key => $value)
			$str = str_replace($value, '', $str);
		return $str;
	}


	/**
	 * @param array
	 * @return array
	 */
	public function setInputParams($inputs)
	{
		// set api params for 3.0 and mobile
		if (_PS_VERSION_ < '1.5')
			{ // 1.4
			if ((_THEME_NAME_ == 'prestashop_mobile' || $this->isIpad()) && $inputs['cePays'] == 'FR')
			{
				unset($inputs['CHARSET']);
				unset($inputs['cePays']);
				unset($inputs['trInter']);
				unset($inputs['ceLang']);
				$inputs['numVersion'] = '3.0';
			}
		}
		else // 1.5
			if ((Context::getContext()->getMobileDevice() || $this->isIpad()) && $inputs['cePays'] == 'FR')
			{
				unset($inputs['CHARSET']);
				unset($inputs['cePays']);
				unset($inputs['trInter']);
				unset($inputs['ceLang']);
				$inputs['numVersion'] = '3.0';
			}
		return $inputs;
	}


	/**
	 * Check if agent user is iPad(for so_mobile)
	 * @return bool
	 */
	public function isIpad()
	{
		return (bool) strpos($_SERVER['HTTP_USER_AGENT'], 'iPad');
	}


	public function fetchTemplate($name)
	{
		if (_PS_VERSION_ < '1.4')
			$this->context->smarty->currentTemplate = $name;
		else
		{
			$views = 'views/templates/';
			if (@filemtime(dirname(__FILE__) . '/' . $views . 'hook/' . $name))
				return $this->display(__FILE__, $views . 'hook/' . $name);
			elseif (@filemtime(dirname(__FILE__) . '/' . $views . 'front/' . $name))
				return $this->display(__FILE__, $views . 'front/' . $name);
			elseif (@filemtime(dirname(__FILE__) . '/' . $views . 'back/' . $name))
				return $this->display(__FILE__, $views . 'back/' . $name);
		}
	}


	/**
	 * Launch upgrade process
	 */
	public function runUpgrades($install = false)
	{
		if (Configuration::get('SOCOLISSIMO_VERSION') != $this->version)
			foreach (array('2.8.0') as $version)
			{
				$file = dirname(__FILE__) . '/upgrade/install-' . $version . '.php';
				if (Configuration::get('SOCOLISSIMO_VERSION') < $version && file_exists($file))
				{
					include_once $file;
					call_user_func('upgrade_module_' . str_replace('.', '_', $version), $this, $install);
				}
			}
	}


}
