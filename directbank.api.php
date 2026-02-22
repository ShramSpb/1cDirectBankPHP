<?php
/**
 * @doc: https://github.com/1C-Company/DirectBank/
 * @author Aleksey Petrishchev
 * @company Angels IT
 * @url https://angels-it.ru
 */

namespace AngelsIt;

class ExceptionDirectBankError extends \Exception { }

class DirectBank1CBase {

    /** @var string Файл с настройками */
    public $setting_file = 'settings.xml';

    // Блок заполнится из настроек
    public $api_url_base    = '';
    public $api_client_id   = '';
    public $api_customer_id = '';

    /** @var string|null SID сессии; null до вызова logon() */
    public $api_sid = null;

    public $api_bank_data   = [];
    public $api_client_data = [];

    public $api_version    = '2.3.2';
    public $api_user_agent = 'Angels IT DirectBank1C Lib';

    public $allow_docKinds = [];

    public $debug      = false;
    public $debug_data = ['request' => '', 'response' => '', 'http_code' => ''];

    public $doLog    = false;
    public $logfile  = 'apilogs/DirectBank';

    public $docKinds = [
        '01' => 'Извещение о состоянии обработки транспортного контейнера',
        '02' => 'Извещение о состоянии электронного документа',
        '03' => 'Запрос о состоянии электронного документа',
        '04' => 'Запрос об отзыве электронного документа',
        '05' => 'Запрос-зонд',
        '06' => 'Настройки обмена с банком',
        '10' => 'Платежное поручение',
        '11' => 'Платежное требование',
        '12' => 'Инкассовое поручение',
        '13' => 'Внутренний банковский документ',
        '14' => 'Запрос выписки банка',
        '15' => 'Выписка банка',
        '16' => 'Мемориальный ордер',
        '17' => 'Платежный ордер',
        '18' => 'Банковский ордер',
        '19' => 'Список на открытие счетов по зарплатному проекту',
        '20' => 'Подтверждение открытия счетов по зарплатному проекту',
        '21' => 'Список на зачисление денежных средств на счета сотрудников',
        '22' => 'Подтверждение зачисления денежных средств на счета сотрудников',
        '23' => 'Список уволенных сотрудников',
        '24' => 'Объявление на взнос наличными',
        '25' => 'Денежный чек',
        '30' => 'Поручение на перевод валюты',
        '35' => 'Выписка по валютному счету',
        '40' => 'Письмо',
    ];

    /**
     * Конструктор объекта
     * @param string $setting_file файл с настройками
     */
    public function __construct($setting_file = '') {
        if ($setting_file) {
            $this->setting_file = $setting_file;
        }
        $this->loadSettings();
    }

    /**
     * Получает настройки обмена с банком
     * @param array $data массив с Account, Inn, Bic
     * @return \SimpleXMLElement ответ банка
     */
    public function getSettings($data) {
        $headers   = [];
        $headers[] = 'Account: '    . $data['Account'];
        $headers[] = 'CustomerID: 0';
        $headers[] = 'Inn: '        . $data['Inn'];
        $headers[] = 'Bic: '        . $data['Bic'];

        return $this->doRequest('POST', 'GetSettings', [], $headers);
    }

    /**
     * Получает список подготовленных к получению пакетов
     * @param  string $date после какой даты
     * @return array        список задач, готовых к передаче
     */
    public function getPackList($date = '') {
        if ($date === '') {
            $date = '01.01.1970 00:00:00'; // Некоторые банки требуют чтобы DATE был всегда заполнен
        }

        $dateStr = '?date=' . urlencode($date);
        $data    = $this->doRequest('GET', 'GetPackList' . $dateStr);

        if (isset($data->Success->GetPacketListResponse->PacketID)) {
            return (array)$data->Success->GetPacketListResponse->PacketID;
        }
        return [];
    }

    /**
     * Получает содержимое пакета из банка
     * @param  string $id ID задачи
     * @return string     ответ банка в виде XML-строки
     * @throws ExceptionDirectBankError
     * @throws \Exception
     */
    public function getPackData($id) {
        $this->checkSessionStart();

        $data = $this->doRequest('GET', 'GetPack?id=' . $id);

        if (isset($data->Success)) {
            return base64_decode((string)$data->Success->GetPacketResponse->Document->Data);
        } elseif (isset($data->Error)) {
            throw new ExceptionDirectBankError(
                (string)$data->Error->Description,
                (int)$data->Error->Code
            );
        } else {
            throw new \Exception('Unknown error', 999);
        }
    }

    /**
     * Запрос статуса выписки по ID
     * @param  string $id ID запроса
     * @return \SimpleXMLElement ответ банка
     */
    public function doStatusRequest($id) {
        $this->checkSessionStart();

        $xml = $this->createBaseXml('StatementRequest');
        $xml->addChild('ExtID', $id);

        $xmlString = $xml->asXML();
        $xmlPack   = $this->createTransportPacket('14', $xmlString); // 14 — Запрос выписки

        return $this->doRequest('POST', 'SendPack', $xmlPack);
    }

    /**
     * Делает запрос на выписку из банка.
     * В случае успеха вернёт ID запроса.
     * В случае неудачи кинет исключение.
     *
     * Параметры $data:
     *   StatementType — тип выписки:
     *       0  Окончательная выписка
     *       1  Промежуточная выписка
     *       2  Текущий остаток на счёте
     *   Account  — номер счёта
     *   DateFrom — начало периода в формате Y-m-d\TH:i:s
     *   DateTo   — конец  периода в формате Y-m-d\TH:i:s
     *
     * @param  array  $data
     * @return string ID запроса выписки
     * @throws ExceptionDirectBankError
     * @throws \Exception
     */
    public function doStatementRequest($data = []) {
        $this->checkSessionStart();

        $xml = $this->createBaseXml('StatementRequest');

        $defaultData = [
            'StatementType' => 0,
            'DateFrom'      => (new \DateTime())->sub(new \DateInterval('P1D'))->format('Y-m-d\T00:00:00'),
            'DateTo'        => (new \DateTime())->format('Y-m-d\T23:59:59'),
        ];

        $this->setDefaults($data, $defaultData);
        $this->checkRequired($data, ['StatementType', 'Account', 'DateFrom']);
        $this->setOrder($data, ['StatementType', 'DateFrom', 'DateTo', 'Account']);

        $payload = $xml->addChild('Data');
        foreach ($data as $k => $v) {
            $payload->addChild($k, $v);
        }
        $bank = $payload->addChild('Bank');
        $bank->addChild('BIC',  $this->api_bank_data['bic']);
        $bank->addChild('Name', $this->api_bank_data['name']);

        $xmlString = $xml->asXML();
        $xmlPack   = $this->createTransportPacket('14', $xmlString); // 14 — Запрос выписки
        $response  = $this->doRequest('POST', 'SendPack', $xmlPack);

        if (isset($response->Success)) {
            return (string)$response->Success->SendPacketResponse->ID;
        } elseif (isset($response->Error)) {
            throw new ExceptionDirectBankError(
                (string)$response->Error->Description,
                (int)$response->Error->Code
            );
        } else {
            throw new \Exception('Unknown error', 999);
        }
    }

    /**
     * Создаёт транспортный пакет для отправки в банк
     * @param  string $dockind   тип документа
     * @param  string $xmlString содержимое XML
     * @return string            XML транспортного пакета
     */
    protected function createTransportPacket($dockind, $xmlString) {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><Packet></Packet>');

        $xml->addAttribute('xmlns',           'http://directbank.1c.ru/XMLSchema');
        $xml->addAttribute('xmlns:xmlns:xs',  'http://www.w3.org/2001/XMLSchema');
        $xml->addAttribute('xmlns:xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->addAttribute('id',              $this->genUUID());
        $xml->addAttribute('formatVersion',   $this->api_version);
        $xml->addAttribute('creationDate',    date('Y-m-d\TH:i:s'));
        $xml->addAttribute('userAgent',       $this->api_user_agent);

        $senderRoot = $xml->addChild('Sender');
        $sender     = $senderRoot->addChild('Customer');
        $sender->addAttribute('id',   $this->api_client_data['id']);
        $sender->addAttribute('name', $this->api_client_data['name']);
        $sender->addAttribute('inn',  $this->api_client_data['inn']);
        if (!empty($this->api_client_data['kpp'])) {
            $sender->addAttribute('kpp', $this->api_client_data['kpp']);
        }

        $recipientRoot = $xml->addChild('Recipient');
        $recipient     = $recipientRoot->addChild('Bank');
        $recipient->addAttribute('bic',  $this->api_bank_data['bic']);
        $recipient->addAttribute('name', $this->api_bank_data['name']);

        $doc = $xml->addChild('Document');
        $doc->addAttribute('id',            $this->genUUID());
        $doc->addAttribute('dockind',       $dockind);
        $doc->addAttribute('formatVersion', $this->api_version);
        $doc->addChild('Data', base64_encode($xmlString));

        return $xml->asXML();
    }

    /**
     * Создаёт базовую XML-структуру с заголовками
     * @param  string $rootName имя корневого элемента XML
     * @return \SimpleXMLElement объект SimpleXML
     */
    protected function createBaseXml($rootName = '') {
        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="utf-8"?><' . $rootName . '></' . $rootName . '>'
        );

        $xml->addAttribute('xmlns',           'http://directbank.1c.ru/XMLSchema');
        $xml->addAttribute('xmlns:xmlns:xs',  'http://www.w3.org/2001/XMLSchema');
        $xml->addAttribute('xmlns:xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->addAttribute('id',              $this->genUUID());
        $xml->addAttribute('formatVersion',   $this->api_version);
        $xml->addAttribute('creationDate',    date('Y-m-d\TH:i:s'));
        $xml->addAttribute('userAgent',       $this->api_user_agent);

        $sender = $xml->addChild('Sender');
        $sender->addAttribute('id',   $this->api_client_data['id']);
        $sender->addAttribute('name', $this->api_client_data['name']);
        $sender->addAttribute('inn',  $this->api_client_data['inn']);
        if (!empty($this->api_client_data['kpp'])) {
            $sender->addAttribute('kpp', $this->api_client_data['kpp']);
        }

        $recipient = $xml->addChild('Recipient');
        $recipient->addAttribute('bic',  $this->api_bank_data['bic']);
        $recipient->addAttribute('name', $this->api_bank_data['name']);

        return $xml;
    }

    /**
     * Список доступных для обмена с банком документов
     * @return array список доступных документов
     */
    public function getAllowDocKinds() {
        $resp = [];
        foreach ($this->allow_docKinds as $k) {
            $resp[$k] = $this->docKinds[$k];
        }
        return $resp;
    }

    /**
     * Загружает настройки DirectBank из XML-файла
     * @throws \Exception
     */
    protected function loadSettings() {
        if (!file_exists($this->setting_file)) {
            throw new \Exception('Config File Not Found', 1);
        }

        $xml = simplexml_load_file($this->setting_file);
        if ($xml === false) {
            throw new \Exception('Config File Parse Error', 2);
        }

        $this->api_url_base    = rtrim((string)$xml->Data->BankServerAddress, '/') . '/';
        $this->api_customer_id = (string)$xml->Data->CustomerID;
        $this->api_client_id   = (string)$xml->Data->Logon->Login->User;

        if (isset($xml->Data->Document)) {
            foreach ($xml->Data->Document as $doc) {
                $this->allow_docKinds[] = (string)$doc->Attributes()->docKind;
            }
        }

        $this->api_bank_data['bic']  = (string)$xml->Sender->Attributes()->bic;
        $this->api_bank_data['name'] = (string)$xml->Sender->Attributes()->name;

        $this->api_client_data['id']   = (string)$xml->Recipient->Attributes()->id;
        $this->api_client_data['name'] = (string)$xml->Recipient->Attributes()->name;
        $this->api_client_data['inn']  = (string)$xml->Recipient->Attributes()->inn;
        $this->api_client_data['kpp']  = (string)$xml->Recipient->Attributes()->kpp; // Может быть пустым у ИП

        if ($this->api_version !== (string)$xml->Attributes()->formatVersion) {
            throw new \Exception('Версия сервера и версия клиента не совпадают');
        }
    }

    /**
     * Авторизация в банке по паролю.
     * В случае успешной авторизации вернёт true.
     * В случае краха кинет исключение:
     *   ExceptionDirectBankError — ошибка банка
     *   \Exception               — прочие ошибки
     *
     * @param  string $password пароль
     * @return bool   true при успешной авторизации
     * @throws ExceptionDirectBankError
     * @throws \Exception
     */
    public function logon($password = '') {
        $headers   = [];
        $headers[] = 'Authorization: Basic ' . base64_encode($this->api_client_id . ':' . $password);
        $data      = $this->doRequest('POST', 'Logon', '', $headers);

        if (isset($data->Success)) {
            $this->api_sid = (string)$data->Success->LogonResponse->SID;
        } elseif (isset($data->Error)) {
            throw new ExceptionDirectBankError(
                (string)$data->Error->Description,
                (int)$data->Error->Code
            );
        } else {
            throw new \Exception('Unknown error', 999);
        }
        return true;
    }

    /**
     * Выполняет HTTP-запрос к серверу банка
     * @param  string $type    тип запроса: GET, POST, PUT
     * @param  string $method  путь метода API
     * @param  string $dataXML тело запроса (XML)
     * @param  array  $headers дополнительные заголовки
     * @return \SimpleXMLElement ответ банка
     * @throws \Exception
     */
    protected function doRequest($type = 'GET', $method = '', $dataXML = '', $headers = []) {
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

        curl_setopt($ch, CURLOPT_URL,            $this->api_url_base . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);

        $headers[] = 'CustomerID: '         . $this->api_customer_id;
        $headers[] = 'Content-Type: application/xml; charset=utf-8';
        $headers[] = 'Accept-Language: ru-RU';
        $headers[] = 'APIVersion: '          . $this->api_version;
        $headers[] = 'AvailableAPIVersion: ' . $this->api_version;

        if ($this->api_sid !== null) {
            $headers[] = 'SID: ' . $this->api_sid;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $output     = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $this->debug_data['request']   = $dataXML;
        $this->debug_data['response']  = $output;
        $this->debug_data['http_code'] = $http_code;

        if ($output === false) {
            throw new \Exception('CURL request failed: ' . $curl_error, 11);
        }

        libxml_use_internal_errors(true);
        $output_xml = simplexml_load_string($output);
        if ($output_xml === false) {
            $errors   = libxml_get_errors();
            libxml_clear_errors();
            $errorMsg = !empty($errors) ? trim($errors[0]->message) : 'Unknown XML error';
            throw new \Exception('Answer parse error: ' . $errorMsg, 10);
        }

        return $output_xml;
    }

    /**
     * Генерирует UUID v4
     * @return string UUID
     */
    protected function genUUID() {
        return sprintf(
            '%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
            mt_rand(0, 0xffff) | (mt_rand(0, 0xffff) << 16), // time_low  (32 бита)
            mt_rand(0, 0xffff),                               // time_mid  (16 бит)
            (4 << 12) | mt_rand(0, 0x0fff),                  // time_hi   (версия 4)
            (1 << 7)  | mt_rand(0, 0x3f),                    // clock_seq_hi (вариант RFC 4122)
            mt_rand(0, 0xff),                                 // clock_seq_low
            mt_rand(0, 0xff), mt_rand(0, 0xff),              // node (6 байт)
            mt_rand(0, 0xff), mt_rand(0, 0xff),
            mt_rand(0, 0xff), mt_rand(0, 0xff)
        );
    }

    /**
     * Подставляет значения по умолчанию в массив данных
     * @param array &$data       оригинальные данные
     * @param array $defaultData данные по умолчанию
     */
    protected function setDefaults(&$data, $defaultData = []) {
        foreach ($defaultData as $k => $v) {
            if (!isset($data[$k])) {
                $data[$k] = $v;
            }
        }
    }

    /**
     * Проверяет наличие обязательных параметров
     * @param array $data входные данные
     * @param array $keys список обязательных ключей
     * @throws \Exception
     */
    protected function checkRequired($data = [], $keys = []) {
        $errors = [];
        foreach ($keys as $k) {
            if (!isset($data[$k])) {
                $errors[] = $k;
            }
        }
        if (!empty($errors)) {
            throw new \Exception(
                'Не хватает обязательных параметров запроса: ' . implode(', ', $errors),
                20
            );
        }
    }

    /**
     * Упорядочивает элементы массива согласно заданному порядку ключей.
     * Ключи, не включённые в $orderArr, добавляются в конец.
     * @param array &$data    исходный массив
     * @param array $orderArr нужный порядок ключей
     */
    protected function setOrder(&$data, $orderArr) {
        $ordered = [];
        foreach ($orderArr as $k) {
            if (array_key_exists($k, $data)) {
                $ordered[$k] = $data[$k];
            }
        }
        foreach ($data as $k => $v) {
            if (!array_key_exists($k, $ordered)) {
                $ordered[$k] = $v;
            }
        }
        $data = $ordered;
    }

    /**
     * Записывает сообщение в лог-файл
     * @param string $str  текст для записи
     * @param string $file базовое имя лог-файла (без даты и расширения)
     */
    public function addLog($str, $file = 'log') {
        $filename = $file . '_' . date('Y-m') . '.log';
        $dir      = dirname($filename);

        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!file_exists($filename)) {
            file_put_contents($filename, '');
            chmod($filename, 0644);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        file_put_contents(
            $filename,
            '[' . date('d.m.Y H:i:s') . "]\n" . $ip . "\n" . $str . "\n\n",
            FILE_APPEND
        );
    }

    /**
     * Проверяет наличие активной сессии с банком
     * @return bool
     * @throws \Exception
     */
    public function checkSessionStart() {
        if ($this->api_sid === null) {
            throw new \Exception('Bank session not started. Please use "logon()" before.', 5);
        }
        return true;
    }
}


/**
 * Отладочный вывод значения в читаемом виде (CLI и веб)
 * @param mixed $arg значение для вывода
 */
function pr($arg = null) {
    $is_cli = (php_sapi_name() === 'cli');

    echo $is_cli ? PHP_EOL : '<xmp>';

    if (is_null($arg)) {
        echo 'pr(): value is null';
    } elseif (is_object($arg) || is_array($arg)) {
        print_r($arg);
    } elseif (is_bool($arg)) {
        echo $arg ? 'true' : 'false';
    } else {
        echo "pr() string: '$arg'";
    }

    echo $is_cli ? PHP_EOL : '</xmp>';
}
