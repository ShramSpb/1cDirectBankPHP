<?php
/**
 * @doc: https://github.com/1C-Company/DirectBank/
 * @author Aleksey Petrishchev
 * @company Angels IT
 * @url https://angels-it.ru
 */

namespace AngelsIt;

class ExceptionDirectBankError extends \Exception { }

class DirectBank1C {

	var $setting_file = 'settings.xml'; // Файл с настройками

	// Блок заполнится из настроек
	var $api_url_base = '';
	var $api_client_id = '';
	var $api_customer_id = '';
	var $api_sid = '213d2d71-e58d-4179-92be-522cd9aa96a2';
	var $api_bank_data = [];
	var $api_client_data = [];

	var $api_version = '2.3.1';
	var $api_user_agent = 'Angels IT DirectBank1C Lib';

	var $allow_docKinds = [];

	var $debug = false; // Включить дебаг
	var $debug_data = ['request' => '',  'response' => '',  'http_code' => ''];

	var $doLog = false; // Включить логирование
	var $logfile = 'apilogs/DirectBank';

	var $docKinds = ['01' => 'Извещение о состоянии обработки транспортного контейнера', 
					 '02' => 'Извещение о состоянии электронного документа', '03' => 'Запрос о состоянии электронного документа',
	 				 '04' => 'Запрос об отзыве электронного документа', '05' => 'Запрос-зонд', '06' => 'Настройки обмена с банком',
	  				 '10' => 'Платежное поручение', '11' => 'Платежное требование', '12' => 'Инкассовое поручение', 
	  				 '13' => 'Внутренний банковский документ', '14' => 'Запрос выписки банка', '15' => 'Выписка банка', '16' => 'Мемориальный ордер',
	   				 '17' => 'Платежный ордер', '18' => 'Банковский ордер', '19' => 'Список на открытие счетов по зарплатному проекту', 
	   				 '20' => 'Подтверждение открытия счетов по зарплатному проекту', '21' => 'Список на зачисление денежных средств на счета сотрудников',
	    			 '22' => 'Подтверждение зачисления денежных средств на счета сотрудников', '23' => 'Список уволенных сотрудников', 
	    			 '24' => 'Объявление на взнос наличными', '25' => 'Денежный чек', '30' => 'Поручение на перевод валюты', '35' => 'Выписка по валютному счету', 
	    			 '40' => 'Письмо'];

	/**
	 * Конструктор объекта
	 * @param string $setting_file файл с настройками
	 */
	public function __construct($setting_file = "") {
		if ($setting_file)	{
			$this->setting_file = $setting_file;
		}
		$this->loadSettings();
	}


	/**
	 * Получает список подготовленных к получение пакетов
	 * @param  string $date после какой даты
	 * @return array        список задач, готовых к передачи
	 */
	public function getPackList($date='') {
		if ($date =='') {
			$date = '01.01.1970 00:00:00'; // Некоторые банки требуют чтобы DATE был всегда заполнен
		}

		$dateStr  = '?date=' . urlencode($date);
		
		$data = $this->doRequest('GET', 'GetPackList' . $dateStr);
		if (isset($data->Success->GetPacketListResponse->PacketID)) {
			return (array)$data->Success->GetPacketListResponse->PacketID;
		}
		return [];
	}

	/**
	 * Получает содержимое пакета из банка
	 * @param  string $id ID задачи
	 * @return string     ответ банка
	 */
	public function getPackData($id) {
		$this->checkSessionStart();

		$data = $this->doRequest('GET', 'GetPack?id=' . $id);

		if (isset($data->Success)) {
			$xml = base64_decode((string)$data->Success->GetPacketResponse->Document->Data);
			return $xml;
		} elseif (isset($data->Error)) {
			// Ошибка пакета
			throw new ExceptionDirectBankError($data->Error->Description, $data->Error->Code);
		} else {
			throw new \Exception("Unknown error", 999);
		}


	}


	public function doStatusRequest($id)	{
		$this->checkSessionStart();

		$xml = $this->createBaseXml('StatementRequest');
		$xml->addChild('ExtID', $id);


		$xmlString = $xml->asXML();
		
		// Собираем транспортный пакет
		$xmlPack = $this->createTransportPacket('14', $xmlString); // 14 - Запрос выписки
		$data = $this->doRequest('POST', 'SendPack', $xmlPack);
	}

	/**
	 * Делам запрос на выписку из банка
	 * в случае успеха вернёт ID запроса
	 * в случае неудачи кинет исключение
	 * На вход принимает массив с параметрами
	 *  StatementType - тип выписки
	 *  	 0	Окончательная выписка
	 *		 1	Промежуточная выписка
	 *		 2	Текущий остаток на счете
	 *  Account - номер счета по которому нужно получить вписки
	 *  DateFrom и DateTo - период выписки в формате Y-m-d\TH:i:s
	 * 
	 * @param  array  $data 
	 * @return string       ID запроса выписки
	 */
	public function doStatementRequest($data = []) {

		$this->checkSessionStart();

		$xml = $this->createBaseXml('StatementRequest');
		
		// Заполняем данные по умолчанию
		$defaultData = [
						'StatementType' => 0, 
						'DateFrom' => (new \DateTime())->sub(new \DateInterval('P1D'))->format('Y-m-d\T00:00:00'),
						];

		$this->setDefaults($data, $defaultData);
		$this->checkRequired($data, ['StatementType', 'Account', 'DateFrom']);

		// Заполняем данные для выписки
		$payload = $xml->addChild('Data');
		foreach($data as $k => $v) {
			$payload->addChild($k, $v);
		}
		$bank = $payload->addChild('Bank');
		$bank->addChild('BIC', $this->api_bank_data['bic']);
		$bank->addChild('Name', $this->api_bank_data['name']);

		$xmlString = $xml->asXML();
		
		// Собираем транспортный пакет
		$xmlPack = $this->createTransportPacket('14', $xmlString); // 14 - Запрос выписки
		$data = $this->doRequest('POST', 'SendPack', $xmlPack);

		
		if (isset($data->Success)) {
			return (string)$data->Success->SendPacketResponse->ID;
		} elseif (isset($data->Error)) {
			// Ошибка пакета
			throw new ExceptionDirectBankError($data->Error->Description, $data->Error->Code);
		} else {
			throw new \Exception("Unknown error", 999);
		}

	}


	private function createTransportPacket($dockind, $xmlString) {

		$xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><Packet></Packet>');

		// Header
		$xml->addAttribute('xmlns', 'http://directbank.1c.ru/XMLSchema');
		$xml->addAttribute('xmlns:xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
		$xml->addAttribute('xmlns:xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
		$xml->addAttribute('id', $this->genUUID());
		$xml->addAttribute('formatVersion', $this->api_version);
		$xml->addAttribute('creationDate', date('Y-m-d\TH:i:s'));
		$xml->addAttribute('userAgent', $this->api_user_agent);

		// Sender
		$senderRoot = $xml->addChild('Sender');
		$sender = $senderRoot->addChild('Customer');
		$sender->addAttribute('id', $this->api_client_data['id']);
		$sender->addAttribute('name', $this->api_client_data['name']);
		$sender->addAttribute('inn', $this->api_client_data['inn']);
		$sender->addAttribute('kpp', $this->api_client_data['kpp']);

		//Recipient
		$recipientRoot  = $xml->addChild('Recipient');
		$recipient = $recipientRoot->addChild('Bank');
		$recipient->addAttribute('bic', $this->api_bank_data['bic']);
		$recipient->addAttribute('name', $this->api_bank_data['name']);

		$doc = $xml->addChild('Document');
		$doc->addAttribute('id', $this->genUUID());
		$doc->addAttribute('dockind', $dockind);
		$doc->addAttribute('formatVersion', $this->api_version);
		$doc->addChild('Data', base64_encode($xmlString));
		return $xml->asXML();
	}


	/**
	 * Создает базовую XML. Заполняет шапку
	 * @param  string $rootName имя корневого элемента XML
	 * @return SimpleXMLObject  объект SimpleXML
	 */
	private function createBaseXml($rootName = '') 	{
		$xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><'.$rootName.'></'.$rootName.'>');

		// Header
		$xml->addAttribute('xmlns', 'http://directbank.1c.ru/XMLSchema');
		$xml->addAttribute('xmlns:xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
		$xml->addAttribute('xmlns:xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
		$xml->addAttribute('id', $this->genUUID());
		$xml->addAttribute('formatVersion', $this->api_version);
		$xml->addAttribute('creationDate', date('Y-m-d\TH:i:s'));
		$xml->addAttribute('userAgent', $this->api_user_agent);

		// Sender
		$sender = $xml->addChild('Sender');
		$sender->addAttribute('id', $this->api_client_data['id']);
		$sender->addAttribute('name', $this->api_client_data['name']);
		$sender->addAttribute('inn', $this->api_client_data['inn']);
		$sender->addAttribute('kpp', $this->api_client_data['kpp']);

		//Recipient
		$recipient  = $xml->addChild('Recipient');
		$recipient->addAttribute('bic', $this->api_bank_data['bic']);
		$recipient->addAttribute('name', $this->api_bank_data['name']);

		return $xml;
	}


	/**
	 * Список доступных для обмена с банком документов
	 * @return array список доступных документов
	 */
	public function getAllowDocKinds() {
		$resp = [];
		foreach( $this->allow_docKinds as $k ) {
			$resp[$k] = $this->docKinds[$k];
		}
		return $resp;
	}

	/**
	 * Загружаем настройки DirectBank
	 */
	private function loadSettings() {
		if (!file_exists($this->setting_file)) {
			throw new \Exception("Config File Not Found", 1);
		}

		$xml = simplexml_load_file($this->setting_file);
		
		$this->api_url_base = (string)$xml->Data->BankServerAddress . '/';
		$this->api_customer_id = (string)$xml->Data->CustomerID;
		$this->api_client_id = (string)$xml->Data->Logon->Login->User;
		if (isset($xml->Data->Document)) {
			foreach($xml->Data->Document as $doc) {
				$this->allow_docKinds[] = (string)$doc->Attributes()->docKind;
			}
		}

		$this->api_bank_data['bic'] = (string)$xml->Sender->Attributes()->bic;
		$this->api_bank_data['name'] = (string)$xml->Sender->Attributes()->name;

		$this->api_client_data['id'] = (string)$xml->Recipient->Attributes()->id;
		$this->api_client_data['name'] = (string)$xml->Recipient->Attributes()->name;
		$this->api_client_data['inn'] = (string)$xml->Recipient->Attributes()->inn;
		$this->api_client_data['kpp'] = (string)$xml->Recipient->Attributes()->kpp;

	}

	/**
	 * Авторизация в банке по паролю
	 * В случае успешной авторизации вернёт true
	 * В случае краха кинет исключение 
	 * 		ExceptionDirectBankError - если проблема с данными для авторизации (ошибка банка)
	 *   	Exception - в случае других ошибок
	 * @param  string $password [description]
	 * @return boolean	true в случае удачной авторизации
	 */
	public function logon($password = "") {
		$headers = [];
		$headers[] = 'Authorization: Basic ' . base64_encode($this->api_client_id . ":" . $password);
		$data = $this->doRequest('POST', 'Logon', '', $headers);
		
		if (isset($data->Success)) {
			$this->api_sid = (string)$data->Success->LogonResponse->SID;
		} elseif (isset($data->Error)) {
			// Ошибка авторизации
			throw new ExceptionDirectBankError($data->Error->Description, $data->Error->Code);
		} else {
			throw new \Exception("Unknown error", 999);
		}
		return true;
	}


	/**
	 * Запрос к серверу банка
	 * @param  string  $type   тип запроса: GET/POST/PUT etc
	 * @param  string  $method хвост
	 * @param  array   $data   
	 * @param  boolean $raw    [description]
	 * @return xml          ответ банка
	 */
	private function doRequest($type = "GET", $method = '', $dataXML = '', $headers = []) {
	    
	    $ch = curl_init();
	  	
	  	if ($this->debug) {
	  		curl_setopt($ch, CURLOPT_VERBOSE, true);
	  	}

	    switch ($type) {
	    	case 'POST':
			    curl_setopt($ch, CURLOPT_POST, 1);
			    curl_setopt($ch, CURLOPT_POSTFIELDS, $dataXML);
	    	break;
	    	case 'PUT':
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			    curl_setopt($ch, CURLOPT_POSTFIELDS, $dataXML);
    		break;
	    }
	    curl_setopt($ch, CURLOPT_URL, $this->api_url_base . $method );
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	    // Добавляем заголовки
		
		$headers[] = 'CustomerID: '.$this->api_customer_id;
		$headers[] = 'Content-Type: application/xml; charset=utf-8';
	    $headers[] = 'Accept-Language: ru-RU';
	    $headers[] = 'APIVersion: ' . $this->api_version;
	    $headers[] = 'AvailableAPIVersion: ' . $this->api_version;

	    if ($this->api_sid) {
	    	$headers[] = 'SID: ' . $this->api_sid;
		}

		
	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	    // Запускаем запрос
	    
	    $output = curl_exec ($ch);
		// Обрабатываем ответ
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	    $this->debug_data['request'] = $dataXML;
	    $this->debug_data['response'] = $output;
	    $this->debug_data['http_code'] = $http_code;

	    try {
			$output_xml = simplexml_load_string($output);
		    return $output_xml;
	    } catch (\Exception $e) {
			throw new \Exception('Answer parse error: ' . $e->getMessage(), 10);
	    }
	}

	/**
	 * Генерирует уникальный ID для запроса
	 * @return string UUID
	 */
	function genUUID() {
	     $uuid = array(
	      'time_low'  => 0,
	      'time_mid'  => 0,
	      'time_hi'  => 0,
	      'clock_seq_hi' => 0,
	      'clock_seq_low' => 0,
	      'node'   => array()
	     );

	     $uuid['time_low'] = mt_rand(0, 0xffff) + (mt_rand(0, 0xffff) << 16);
	     $uuid['time_mid'] = mt_rand(0, 0xffff);
	     $uuid['time_hi'] = (4 << 12) | (mt_rand(0, 0x1000));
	     $uuid['clock_seq_hi'] = (1 << 7) | (mt_rand(0, 128));
	     $uuid['clock_seq_low'] = mt_rand(0, 255);

	     for ($i = 0; $i < 6; $i++) {
	      $uuid['node'][$i] = mt_rand(0, 255);
	     }

	     $uuid = sprintf('%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
	      $uuid['time_low'],
	      $uuid['time_mid'],
	      $uuid['time_hi'],
	      $uuid['clock_seq_hi'],
	      $uuid['clock_seq_low'],
	      $uuid['node'][0],
	      $uuid['node'][1],
	      $uuid['node'][2],
	      $uuid['node'][3],
	      $uuid['node'][4],
	      $uuid['node'][5]
	     );
	     return $uuid;
	}

	/**
	 * Заменяет данные в оригинальном массиве на данные по умолчанию,
	 * если в оригинальном массиве их нет
	 * @param array &$data       оригинальные данные
	 * @param array $defaultData данные по умолчанию
	 */
	private function setDefaults(&$data, $defaultData = []) {
		foreach ($defaultData as $k=>$v) {
			if (!isset($data[$k])) {
				$data[$k] = $v;
			}
		}
	}

	/**
	 * Проверка обязательных данных
	 */
	private function checkRequired($data = [], $keys = []) {
		$errors = [];
		foreach($keys as $k) {
			if (!isset($data[$k]) ) {
				$errors[] = $k;
			}
		}

		if (!empty($errors)) {
			throw new \Exception('Не хватает обязательных параметров запроса: ' . implode(', ', $errors), 20);
		}
	}

	/**
	 * Добавляет в лог информацию
	 * @param string $str  текст
	 * @param string $file файл
	 */
	function addLog($str, $file = "log" ) {
		   $filename = $file.'_'.date('Y-m').'.log';

		   if(!file_exists(dirname($filename))) {
		        mkdir(dirname($filename), 0777, true);
		        chmod(dirname($filename), 0777);
		   }

		   if (!file_exists($filename)) {
		        file_put_contents($filename,"");
		        chmod($filename, 0777);
		   }

		   file_put_contents($filename, '['.date('d.m.Y H:i:s')."]\n". $_SERVER['REMOTE_ADDR'] ."\n" . $str ."\n\n", FILE_APPEND );
	}

	/**
	 * Проверяем, что у нас есть активная сессия с банком.
	 * Если нет - кидаем исключения
	 * @return bool сессия есть
	 */
	function checkSessionStart() {
		if ($this->api_sid == "") {
			throw new \Exception("Bank session not started. Please use \"logon()\" before.", 5);
			
		}
		return true;
	}
}


function pr($arg) {
	   $is_cli = false;
	   if (php_sapi_name() == 'cli') {
	      $is_cli = true;
	   }

	   if ($is_cli) echo PHP_EOL; else echo "<xmp>";
	   if (!isset($arg)) echo "pr(): value not set";
	   elseif (is_object($arg)) echo print_r($arg);
	   elseif (is_bool($arg)) if($arg) echo "true"; else echo "false";
	   elseif (!is_array($arg)) echo "pr() string: '$arg'";
	   elseif (!count($arg)) echo "pr(): array empty";
	   else print_r($arg);
	   if ($is_cli) echo PHP_EOL; else echo "</xmp>";

}



?>