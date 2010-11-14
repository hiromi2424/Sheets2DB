<?php

class AppController extends Controller {
	var $gdata_login = array();
	var $gdata_client = null;
	var $configured = true;
	var $auth_failed = false;

	function beforeFilter() {
		$this->disableCache();

		if (!$this->_loadConfig()) {
			$this->configured = false;
			return;
		}
		$this->gdata_login = Configure::read('Gdata.login');

		ini_set('include_path', get_include_path() . PATH_SEPARATOR . VENDORS . 'zend_framework' . DS . 'library' . PATH_SEPARATOR . APP . DS . 'vendors' . DS . 'zend_framework' . DS . 'library');
		require_once('Zend/Loader.php');
		Zend_Loader::loadClass('Zend_Gdata');
		Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
		Zend_Loader::loadClass('Zend_Gdata_Spreadsheets');
		Zend_Loader::loadClass('Zend_Gdata_App_AuthException');
		Zend_Loader::loadClass('Zend_Http_Client');
		try {
			$client = Zend_Gdata_ClientLogin::getHttpClient($this->gdata_login['email'], $this->gdata_login['password'], Zend_Gdata_Spreadsheets::AUTH_SERVICE_NAME);
			$this->gdata_client = $client;
		} catch (Zend_Gdata_App_CaptchaRequiredException $cre) {
			$this->gdata_capthaUrl = $cre->getCaptchaUrl();
			$this->gdata_token = $cre->getCaptchaToken();
			var_dump($this->gdata_capthaUrl, $this->gdata_token);
		} catch (Zend_Gdata_App_AuthException $ae) {
			$this->Session->setFlash(__('Failed Authentication', true) . ':' . $ae->exception());
			$this->auth_failed = true;
		}
	}

	function _storeConfig($type, $data) {
		$content = "<?php\n";

		foreach ($data as $key => $value) {
			$content .= "\$config['Gdata']['$type']['$key'] = " . var_export($value, true) . ";\n";
		}

		file_put_contents(TMP . 'gdata_config.php', $content);
	}

	function _loadConfig() {
		if (!file_exists(TMP . 'gdata_config.php')) {
			return false;
		}
		include_once(TMP . 'gdata_config.php');
		Configure::write($config);
		return true;
	}
}