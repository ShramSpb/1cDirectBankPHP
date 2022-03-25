# 1cDirectBankPHP
Класс обмена данными с банком по протоколу 1С Direct Bank
На данный момент реализован только получение выписки без шифрования.
Для работы класса необходим файл *settings.xml*, который вы получите в банк-клиенте.
В файле конфигурация Вашей организации и endpoint для доступа к API банка.

## Примеры
### Получение выписки
    
    require_once 'directbank.api.php';
    
	$api = new AngelsIt\DirectBank1C();
	$data = [];
    $data['Account'] = '4XXXXXXXXXXXXXXXXXXXXXXXX6'; // Номер счет в банке
    $data['DateFrom']  = date('Y-m-d\T00:00:00');    // Сегодня с полуночи
    $data['DateTo']  = date('Y-m-d\TH:i:s');     // До текущего времени (не обязательно)
	
	$taskGUID = $api->doStatementRequest($data); // Передаем данные в банк, получем обратно ID задачи

    // Просматриваем готовые заданий
	$requestTime = new DateTime(); // С какого момента смотрим задания
	$requestTime->sub(new DateInterval('PT5M')); // Если вдруг часы банка и наши не синхронизированы
	$completedGUIDs = $api->getPackList( $requestTime->format('d.m.Y H:i:s') ); // Получаем список готовых заданий

    if (in_array($taskGUID, $$completedGUIDs) ) {
        $xmlString = $api->getPackData($taskGUID); // Получем XML с выпиской из банка
    }

