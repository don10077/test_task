<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        //убрал явное преобразование, что бы не было предупреждения
        $resellerId = $data['resellerId'] ?? null;
        $notificationType = $data['notificationType'] ?? null;
        $creatorId = $data['creatorId'] ?? null;
        $clientId = $data['clientId'] ?? null;
        $expertId = $data['expertId'] ?? null;

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        if (!$resellerId) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        if (!$notificationType) {
            throw new Exception('Empty notificationType', 400);
        }

        if (!$creatorId) {
            throw new Exception('Empty creatorId', 400);
        }

        if (!$expertId) {
            throw new Exception('Empty expertId', 400);
        }
        if (!$clientId) {
            throw new Exception('Empty clientId', 400);
        }

        //создается новая запись не вижу смысла проверять, если будет ошибка при создании выдать исключение раньше
        $reseller = Seller::getById((int)$resellerId);

        $client = Contractor::getById((int)$clientId);
        if ($client->type !== Contractor::TYPE_CUSTOMER || $clientId !== (int)$resellerId) {
            throw new Exception('client not found!', 400);
        }

        //создаются новая записи не вижу смысла проверять, если будет ошибка при создании выдавать исключение раньше, изменил на более понятные названия
        $creator = Employee::getById((int)$creatorId);
        $expert = Employee::getById((int)$expertId);

        $differences = ''; //Мне кажется тут больше было бы уместно название modifications
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif (
                  $notificationType === self::TYPE_CHANGE &&
                  !is_null($data['differences']['from'] ?? null) &&  //если оставить empty не сможем передать 0
                  !is_null($data['differences']['to'] ?? null)
                ) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID' => (int)($data['complaintId'] ?? 0),
            'COMPLAINT_NUMBER' => (string)($data['complaintNumber'] ?? ''),
            'CREATOR_ID' => (int)$creatorId,
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => (int)$expertId,
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => (int)$clientId,
            'CLIENT_NAME' => $client->getFullName(), //запрашивается полное имя состоящее из имени и id, в случае возврата пустого значения, запрашивалось только имя пользователя это нас не спасет(, убрал проверку и отпала необходимость в лишней переменной
            'CONSUMPTION_ID' => (int)($data['consumptionId'] ?? 0),
            'CONSUMPTION_NUMBER' => (string)($data['consumptionNumber'] ?? ''),
            'AGREEMENT_NUMBER' => (string)($data['agreementNumber'] ?? ''),
            'DATE' => (string)($data['date'] ?? ''),
            'DIFFERENCES' => $differences,
        ];

        $stringError = '';
        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {  //Тут надо знать какие варианты значений допустимы и возможно есть смысл сделать проверку на входе, но сейчас без некоторых данных все равно создаются учетки, может так надо
                $stringError .= "Template Data ($key) is empty!\r";
            }
        }
        //Я бы так сделал, что бы сразу было видно какие параметры не заданы, возможно это лишнее
        if (empty($stringError)) {
            throw new Exception($stringError, 400); // 500 будет вводить в заблуждение
        }

        $emailFrom = getResellerEmailFrom(); //метод не ждет никаких входных данных
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;

            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !is_null($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo' => $client->email,
                        'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $clientId, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']); //Судя по предыдущему вызову метода sendMessage, $resellerId тут лишний
                $result['notificationClientByEmail'] = true;
            }

            $error = 'Ой беда, беда'; //Инициализируем переменную
            if (!empty($client->mobile)) {
                $res = NotificationManager::send($resellerId, $clientId, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                } else if (!empty($error)) { //Не хватает кода, который будет генерить текст ошибки, возможно в методе send, если туда передать $error как ссылку. Если же так как сейчас, то проверка на пустое значение не имеет смысла
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }

    // $string и $null не очень информативные название, __ возможно обобщили разные методы, поэтому так назвали, т.к. вторым параметром передаются 2 разных массива и null, если это все таки один метод, то название должно описывать, что метод делает
    private function __(string $messageType, $templateData, int $resellerId)
    {
    }
}
