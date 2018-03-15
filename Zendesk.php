<?php

namespace common\components;

use Yii;
use yii\base\Component;
use Zendesk\API\HttpClient as ZendeskAPI;
use Firebase\JWT\JWT;

/**
 * Чтобы пользоваться компонентом согласно поставленным требованиям, необходимо выполнить соответствующие настройки.
 * Управление настройками службы поддержки осуществляется от имени администратора. Необходимы следующие настройки:
 * 1) токен API. Чтобы срабатывало подключение к Zendesk с возможностью создания тикета, необходимо настроить
 * токен API - параметр, участвующий в авторизации текущего пользователя.
 * Настраивается по адресу: {subdomain}.zendesk.com/agent/admin/api/settings - выбрать "Доступ по токену"
 *
 * 2) сквозная или единая авторизация. Необходима, чтобы избежать ввода паролей при входе пользователей в службу поддержки.
 * Настраивается по адресу: {subdomain}.zendesk.com/agent/admin/security - выбрать "Конечные пользователи",
 * поставить галку "Сквозная авторизация", затем галку "Веб-токен JSON".
 * Заполняются графы "URL удаленного входа" и "Разделенный секрет" (ниже параметр $ssoKey).
 *
 * 3) отправка тикетов. Чтобы все конечные пользователи могли отправлять тикеты,
 * необходимо проверить соответствующую настройку по адресу:  {subdomain}.zendesk.com/agent/admin/customers,
 * в графе "Все могут отправлять тикеты" поставить галку.
 *
 * 4) данные профиля конечного пользователя. Пользователь не должен иметь возможность изменить свой email, так же как и в проектах FF.
 * Настраивается по адресу: {subdomain}.zendesk.com/agent/admin/customers,
 * в графе "Разрешить пользователям просматривать и изменять данные профиля" снять галку.
 *
 * 5) пароль конечного пользователя. Поскольку выбрана сквозная авторизация, пароль конечного пользователя не должен подлежать изменению.
 * Настраивается по адресу: {subdomain}.zendesk.com/agent/admin/customers,
 * в графе "Разрешить пользователям менять свой пароль" снять галку.
 *
 * Class Zendesk
 * @package common\components
 */
class Zendesk extends Component
{
    /**
     * Строка, указываемая при регистрации домена в Zendesk
     * @var string
     */
    public $subdomain;

    /**
     * e-mail пользователя, который зарегистрирован как агент или администратор - действующее лицо, выполняющее тикеты
     * @var string
     */
    public $username;

    /**
     * Токен api для проверки подлинности
     * @var string
     */
    public $token;

    /**
     * Ключ для единой (сквозной) авторизации.
     * С его помощью генерируется ссылка для перехода в личный кабинет Zendesk без необходимости вводить логин и пароль
     * @var string
     */
    public $ssoKey;

    /**
     * Страница, куда перенаправляется пользователь, если его токен более недействителен
     * @var string
     */
    public $redirectPage;

    /**
     * Настраиваемые поля для тикета. В данном случае, название приложения. Возможно, в дальнейшем этих полей окажется больше
     * @var array
     */
    public $customFieldsId = [
        'appName' => null
    ];

    /**
     * @var ZendeskAPI
     */
    public $client;

    public $userSearch;

    public $userTickets;

    public $userId;

    /**
     * Русская локаль клиента отправляющего запрос
     * @var integer
     */
    const CLIENT_LOCALE_RU = 27;

    /**
     * Английская локаль клиента отправляющего запрос
     * @var integer
     */
    const CLIENT_LOCALE_EN = 1;

    /**
     * Zendesk constructor.
     * @param integer $userId
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->connect();
    }

    /**
     * Подключение к Zendesk и авторизация текущего пользователя
     */
    public function connect()
    {
        if (!$this->client) {
            $this->client = new ZendeskAPI($this->subdomain, $this->username);
            $this->client->setAuth('basic', ['username' => $this->username, 'token' => $this->token]);
        }
    }

    /**
     * Генерация ссылки для сквозного перехода в личный кабинет (без ввода логина и пароля)
     *
     * @param $name
     * @param $email
     * @return null|string
     */
    public function getSupportLink($name, $email)
    {
        if ($name && $email) {
            $now = time();
            $token = array(
                "jti" => md5($now . rand()),
                "iat" => $now,
                "name" => $name,
                "email" => $email,
            );
            $jwt = JWT::encode($token, $this->ssoKey);

            $location = $this->getDomain() . "/access/jwt?jwt=" . $jwt;
            return $location;
        }

        return null;
    }

    /**
     * Получение полного адреса страницы технической поддержки
     *
     * @return string
     */
    public function getDomain()
    {
        return "https://" . $this->subdomain . ".zendesk.com";
    }

    public function getExternalId($userId) {
        return $externalId = $userId ? 'user_' . $userId : null;
    }

    public function setUserSearch($userId) {
        $externalId = $this->getExternalId($userId);
        $this->userSearch = $this->client->users()->search(['external_id' => $externalId])->users;
    }

    /**
     * Создание заявки в Zendesk
     *
     * @param int|null $userId
     * @param string $name
     * @param string $email
     * @param string $message
     * @param string $subject
     * @param integer $localeId
     * @param string $appName
     * @param integer $brandId
     * @param array $uploads
     * @return mixed
     */
    public function createTicket($userId, $name, $email, $message, $subject, $localeId, $appName, $brandId, $uploads = [])
    {
        // get requester
        $params = [
            'email' => $email,
            'name' => $name,
            'locale_id' => $localeId
        ];

        $externalId = $this->getExternalId($userId);
        if ($externalId) {
            if (!$this->userSearch) {
                $params['external_id'] = $externalId;
            }
        }
        $zUser = $this->client->users()->createOrUpdate($params);

        // get current user
        $me = $this->getCurrentUser();

        $ticketParams = [
            'subject'  => $subject,
            'external_id' => $externalId,
            'requester_id' => $zUser->user->id,
            'submitter_id' => $zUser->user->id,
            'assignee_id' => $me->user->id,
            'comment'  => [
                'body' => $message,
                'uploads' => $uploads
            ],
            'priority' => 'normal',
            'custom_fields' => [
                [
                    'id' => $this->customFieldsId['appName'],
                    'value' => $appName
                ]
            ],
            'brand_id' => $brandId,
        ];

        // create ticket
        $result = $this->client->tickets()->create($ticketParams);
        return $result;
    }

    /**
     * @return null|\stdClass
     */
    public function getCurrentUser ()
    {
        return $this->client->users()->me(['email' => $this->username]);
    }

    /**
     * @param array $searchParams
     * @param array $searchParamsType
     * @param array $paginationParams
     * @param array $excludeSearchParams
     * @param array $conditions
     * @param bool $asArray
     * @return array
     */
    public function getUserTickets (
        $asArray = true,
        array $searchParams = [],
        array $searchParamsType = [],
        array $paginationParams = [],
        array $excludeSearchParams = [],
        array $conditions = []
    )
    {
        if (!isset($paginationParams['page'])) {
            $paginationParams['page'] = 1;
        }

        $query = $this->getQueryString('ticket', $searchParams, $searchParamsType, $conditions, $excludeSearchParams);

        if($this->userSearch) {
            foreach ($this->userSearch as $user) {
                $this->userTickets = $this->client->search()->find($query . 'requester_id:' . $user->id . '', $paginationParams);
            }
        } elseif ($this->userSearch === null) {
            $this->userTickets = $this->client->search()->find($query, $paginationParams);
        }

        if ($this->userTickets) {
            $tickets = $this->joinParamsToTickets($this->userTickets->results, [
                'closed_at' => ['method' => 'getTicketClosedAt', 'setToTicket' => true],
                'app' => ['method' => 'getTicketApp', 'setToTicket' => true],
                'userId' => ['method' => 'getSystemUserId', 'setToTicket' => true],
//            'zendeskUserEmail' => ['method' => 'getZendeskUserEmail', 'setToTicket' => true],
//            'lastCommentAuthorName' => 'getLastCommentAuthorName'
            ]);

            if ($asArray) {
                return $this->ticketsAsArray($tickets);
            }

            return $tickets;
        }
        return [];
    }

    /**
     * @param $type
     * @param array $params
     * @param array $searchParamsType
     * @param array $conditions
     * @param array $excludeParams
     * @return string
     */
    public function getQueryString($type, array $params, array $searchParamsType, array $conditions, array $excludeParams)
    {
        foreach ($excludeParams as $excludeParam) {
            unset($params[$excludeParam]);
        }

        $query = '';

        $types = [
            'ticket' => 'ticket',
            'user' => 'user',
            'organization' => 'organization',
            'group' => 'group'
        ];

        if (in_array($type, $types)) {
            $query = 'type:' . $types[$type] . ',';
            if (is_array($params)) {
                foreach ($params as $key => $value) {
                    if ($value) {
                        $condition = isset($conditions[$key]) ? $conditions[$key] : ':';
                        if (isset($searchParamsType[$key])) {
                            $query .= $searchParamsType[$key] . $condition . '"' .$value. '"' . ',';
                        } else {
                            $query .=  $key . $condition . '"' . $value . '"' . ',';
                        }
                    }
                }
            }
        }

        return $query;
    }


    /**
     * @param string $status
     * @return mixed
     */
    public function getUserTicketsCount ($status = '')
    {
        $count = 0;

        if ($this->userTickets) {
            if ($status) {
                foreach ($this->userTickets->results as $userTicket) {
                    if ($userTicket->status == $status) {
                        $count++;
                    }
                }
                return $count;
            }
            return $this->userTickets->count;
        }
        return $count;
    }

    /**
     * @param $ticketId
     * @return mixed
     */
    public function getCurrentTicket ($ticketId)
    {
        if ($ticketId) {
            $this->userTickets = $this->client->tickets()->find($ticketId)->ticket;
        }
        return $this->userTickets;
    }

    /**
     * @param $tickets
     * @return array
     */
    public function ticketsAsArray ($tickets)
    {
        $ticketsArr = [];
        foreach ($tickets as $ticket) {
            $ticketsArr[] = (array)$ticket;
        }
        return $ticketsArr;
    }

    /**
     * @param array $tickets
     * @param array $params
     * @return array
     */
    public function joinParamsToTickets(array $tickets, array $params = [])
    {
        $ticketsArr = [];
        foreach ($tickets as $ticket) {
            foreach ($params as $propertyName => $param) {
                if (is_array($param) && $param['setToTicket']) {
                    $ticket->{$propertyName} = $this->{$param['method']}($ticket);
                } else {
                    $ticket->{$propertyName} = $this->{$param}($ticket->id);
                }
            }
            $ticketsArr[] = $ticket;
        }
        return $ticketsArr;
    }

    /**
     * @param $ticketId
     * @return null|\stdClass
     */
    public function getLastComment ($ticketId)
    {
        $comments = $this->client->tickets()
            ->comments()
            ->findAll(['ticket_id' => $ticketId, 'sort_order' => 'desc', 'order_by' => 'created_at', 'per_page' => 1])
            ->comments;
        return array_shift($comments);
    }

    /**
     * @param $ticketId
     * @param bool $result
     * @return null|\stdClass
     */
    public function getTicketCommentsByTicketId ($ticketId, $result = false)
    {
        $comments = $this->client->tickets()->comments()->findAll(['ticket_id' => $ticketId]);
        return $result ? $comments->comments : $comments;
    }

    /**
     * @param $ticketId
     * @return integer
     */
    public function getLastCommentAuthorName($ticketId)
    {
        $authorId = $this->getLastComment($ticketId)->author_id;
        return $this->getZendeskUserData($authorId, 'name');
    }

    public function getZendeskUserEmail ($ticket)
    {
        return $this->getZendeskUserData($ticket->requester_id, 'email');
    }

    /**
     * @param $ticket
     * @return null
     */
    public function getTicketClosedAt ($ticket) {
        return $ticket->status == 'closed' ? $ticket->updated_at : null;
    }

    public function getSystemUserId ($ticket) {
        return $ticket->external_id ? explode('_', $ticket->external_id)[1] : null;
    }

    /**
     * @param $ticket
     * @return mixed
     */
    public function getTicketApp ($ticket) {
        if ($ticket->custom_fields) {
            foreach ($ticket->custom_fields as $custom_field) {
                if ($custom_field->id == $this->customFieldsId['appName']) {
                    return $custom_field->value;
                }
            }
        }
        return null;
    }

    /**
     * @param $userId
     * @param $fieldName
     * @return mixed
     */
    public function getZendeskUserData($userId, $fieldName)
    {
        $user = array_shift($this->client->search()->find('type:user,user:' . $userId . '')->results);
        return $fieldName ? $user->{$fieldName} : $user;
    }

    /**
     * @param $date
     * @return mixed
     */
    public static function formatDate ($date)
    {
        return str_replace(['T', 'Z'], ' ', $date);
    }

    /**
     * Управление прикреплёнными файлами
     *
     * @param string $file
     * @param string $type
     * @param string $name
     * @return mixed
     */
    public function uploadFile($file, $type, $name)
    {
        $this->connect();
        $attachment = $this->client->attachments()->upload(compact('file', 'type', 'name'));
        return $attachment->upload->token;
    }
}