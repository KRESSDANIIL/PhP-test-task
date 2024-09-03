<?php

require 'vendor/autoload.php';

// Настройки базы данных
$host = '127.0.0.1';
$db = 'tenders';
$user = 'Admin';
$pass = 'Admin';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Подключение к базе данных
try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'");
    echo "Подключение успешно.\n";
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

// Создание таблицы, если её нет
$pdo->exec("
CREATE TABLE IF NOT EXISTS tenders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tender_number VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    organizer VARCHAR(255) NOT NULL,
    procedure_link TEXT NOT NULL,
    start_date DATE,
    documentation TEXT,
    INDEX idx_tender_number (tender_number)
);
");

// путь к cacert.pem, без него соединение не произведётся корректно
$cacertPath = 'C:\php-8.3.11-Win32-vs16-x64\cacert.pem';

$client = new GuzzleHttp\Client([
    'verify' => $cacertPath,
]);
//отправка get запроса для получения в ответ post от сайта 
$response = $client->request('GET', 'https://tender.rusal.ru/Tenders');
$csrfToken = $response->getHeader('X-CSRF-Token')[0] ?? null;

//фильтрация по условию, в данном случае ЖД, авиа, авто, контейнерные перевозки
$filterId = 'bef4c544-ba45-49b9-8e91-85d9483ff2f6';
//лимит загружаемых строк в БД, можно изменить начало отчёта через переменную $offset и количество строк с данными после начала отчёта в переменной &limit
$limit = 999999999;
$offset = 0;

//сортировка по условию
try {
    $response = $client->request('POST', 'https://tender.rusal.ru/Tenders/Load', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-CSRF-Token' => $csrfToken,
            'X-Requested-With' => 'XMLHttpRequest',
        ],
        'form_params' => [
            'limit' => $limit,
            'offset' => $offset,
            'sortAsc' => 'false',
            'sortColumn' => 'EntityNumber',
            'ClassifiersFieldData.SiteSectionType' => $filterId,
        ]
    ]);
    //перенос данных в более удобную форму, с которой будем работать далее
    $tenders = json_decode($response->getBody(), true);

    foreach ($tenders['Rows'] as $tender) {
        $tenderNumber = $tender['TenderNumber'];
        $organizerName = $tender['OrganizerName'];
        $entityViewUrl = $tender['EntityViewUrl'];
        $tenderViewUrl = $tender['TenderViewUrl'];
        $publishedDate = $tender['PublishedDate'];

        // Проверяем существующую запись по tender_number
        $stmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_number = :tender_number");
        $stmt->execute([':tender_number' => $tenderNumber]);
        // Проверяем переносим данные существующей записи (которую мы обнаружили с помощью $tenderNumber) в Array, для последующего сопоставления данных
        $existingTender = $stmt->fetch(PDO::FETCH_ASSOC);

        // Получение документации
        $url = 'https://tender.rusal.ru' . $tenderViewUrl;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CAINFO, 'C:\php-8.3.11-Win32-vs16-x64\cacert.pem'); 
        $response = curl_exec($ch);
        curl_close($ch);

        //очищаю переменную в цикле, что-бы лишние названия файлов и ссылки не попадали в БД
        $documentation = '';

        
        if (curl_errno($ch)) {
            echo 'Ошибка CURL: ' . curl_error($ch);
        } else {
            $response = mb_convert_encoding($response, 'HTML-ENTITIES', 'UTF-8');
            //создание дерева для последующего постка в нём данных через getAttribute
            $dom = new DOMDocument();
            @$dom->loadHTML($response);
            $xpath = new DOMXPath($dom);
            //возврат ссылок на документы
            $files = $xpath->query("//a[contains(@class, 'file-download-link')]");

            if ($files->length > 0) {
                foreach ($files as $file) {
                    if ($file instanceof DOMElement) {
                        //извлечене текстого контента из поля <a>, зачастую, как в нашем случае это название файла
                        $fileName = trim($file->textContent);
                        //извлечение ссылки
                        $href = $file->getAttribute('href');
                        //формирование полной ссылке по которой можно сразу перейти
                        $fileUrl = 'https://tender.rusal.ru' . $href;
                        //дописывание в переменную $documentation данных, это нучно если на сайте больше одного файла и переменная не перезаписывалась
                        $documentation .= "$fileName: $fileUrl, ";
                    }
                }
                $documentation = rtrim($documentation, ', ');
            } else {
                $documentation = "Документов нет";
            }
        }

        // Если запись существует, проверяем изменения
        if ($existingTender) {

            // Приведение существующей даты к формату Y-m-d
            $existingStartDate = date('Y-m-d', strtotime($existingTender['start_date']));

            // Приведение новой даты к формату Y-m-d
            $newStartDate = date('Y-m-d', strtotime($publishedDate));

            $updateNeeded = false;

            if ($existingTender['organizer'] !== $organizerName ||
                $existingTender['procedure_link'] !== 'https://tender.rusal.ru' . $tender['TenderViewUrl'] ||
                $existingStartDate !== $newStartDate ||
                $existingTender['documentation'] !== $documentation) {
                $updateNeeded = true;
                }

            if ($updateNeeded) {
                $stmt = $pdo->prepare("
                    UPDATE tenders SET
                        organizer = :organizer,
                        procedure_link = :procedure_link,
                        start_date = :start_date,
                        documentation = :documentation
                    WHERE tender_number = :tender_number
                ");

                    $stmt->execute([
                        ':tender_number' => $tenderNumber,
                        ':organizer' => $organizerName,
                        ':procedure_link' => 'https://tender.rusal.ru' . $tender['TenderViewUrl'],
                        ':start_date' => $publishedDate,
                        ':documentation' => $documentation,
                    ]);

                    echo "Обновлена запись для тендера #{$tenderNumber}.\n";
                } else {
                echo "Запись для тендера #{$tenderNumber} не требует обновления.\n";
            }
        } 
else {
        // Если записи нет, создаем новую
        $stmt = $pdo->prepare("
            INSERT INTO tenders (
                tender_number,
                organizer,
                procedure_link,
                start_date,
                documentation
            ) VALUES (
                :tender_number,
                :organizer,
                :procedure_link,
                :start_date,
                :documentation
            )
        ");

    $stmt->execute([
        ':tender_number' => $tenderNumber,
        ':organizer' => $organizerName,
        ':procedure_link' => 'https://tender.rusal.ru' . $tender['TenderViewUrl'],
        ':start_date' => $publishedDate,
        ':documentation' => $documentation,
    ]);

    echo "Вставлена новая запись для тендера #{$tenderNumber}.\n";
}
    }

} 
catch (GuzzleHttp\Exception\RequestException $e) {
    echo "Ошибка запроса: " . $e->getMessage() . "\n";
}

$stmt = $pdo->prepare("SELECT * FROM tenders");
$stmt->execute();

$tenders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Печать тендеров из базы данных
foreach ($tenders as $tender) {
    echo "Tender #{$tender['tender_number']}:\n";
    echo "  Organizer: {$tender['organizer']}\n";
    echo "  Procedure Link: {$tender['procedure_link']}\n";
    echo "  Start Date: {$tender['start_date']}\n";
    echo "  Documentation: {$tender['documentation']}\n";
    echo "\n";
}
?>