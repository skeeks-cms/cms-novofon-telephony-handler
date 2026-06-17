<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\telephony\novofon;

use skeeks\cms\helpers\StringHelper;
use skeeks\cms\models\CmsTelephonyCall;
use skeeks\cms\models\CmsTelephonyUser;
use skeeks\cms\telephony\TelephonyHandler;
use skeeks\yii2\form\fields\FieldSet;
use yii\helpers\ArrayHelper;

/**
 *
 * @see https://smsimple.ru/api-http/
 *
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class NovofonTelephonyHandler extends TelephonyHandler
{
    public $webhookSecret = '';
    public $apiKey = '';
    /**
     * @return array
     */
    static public function descriptorConfig()
    {
        return array_merge(parent::descriptorConfig(), [
            'name' => \Yii::t('skeeks/shop/app', 'Novofon'),
            'description' => "Телефония Novofon",
        ]);
    }

    public function buildSipConfig(CmsTelephonyUser $user): array
    {
        return [
            'provider'    => 'sipuni',
            'uri'         => $user->sip_uri,
            'password'    => $user->sip_password,
            'ws'          => $user->ws_url,
            'displayName' => $user->display_name ?: $user->user->shortName,
            'iceServers'  => $user->getIceServers(),
        ];
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [

            [['webhookSecret'], 'string'],
            [['apiKey'], 'string'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'webhookSecret' => "Webhook secret",
            'apiKey'        => "Api ключ",
        ]);
    }

    /**
     * @return array
     */
    public function getConfigFormFields()
    {
        return [
            'main' => [
                'class'  => FieldSet::class,
                'name'   => 'Основные',
                'fields' => [
                    'webhookSecret',
                    'apiKey',
                ],
            ],
        ];
    }


    /**
     * Нормализация webhook Novofon
     */
    public function normalizeWebhook(array $payload): array
    {
        /**
         * 1. ID звонка у провайдера
         */
        $providerCallId = $payload['pbx_call_id'] ?? null;

        /**
         * 2. Направление звонка
         */
        $event = $payload['event'] ?? '';
        $direction = str_contains($event, 'OUT')
            ? 'out'
            : 'in';

        /**
         * 3. Номера
         */
        $phoneFrom = $payload['caller_id'] ?? null;
        $phoneTo   = $payload['destination'] ?? null;

        /**
         * ===== СТАТУС =====
         */
        switch ($event) {

            case 'NOTIFY_OUT_START':
            case 'NOTIFY_INTERNAL':
            case 'NOTIFY_START':
                $status = CmsTelephonyCall::STATUS_RINGING;
                break;

            case 'NOTIFY_ANSWER':
                $status = CmsTelephonyCall::STATUS_ANSWER;
                break;

            case 'NOTIFY_OUT_END':
                if (($payload['disposition'] ?? '') === 'answered') {
                    $status = STATUS_ENDED;
                    $endReason = null;
                } else {
                    $status = STATUS_FAILED;
                    $endReason = CmsTelephonyCall::END_REASON_NOANSWER;
                }
                break;

            default:
                $status = CmsTelephonyCall::STATUS_RINGING;
        }

        /**
         * 5. Время начала
         */
        $startedAt = null;
        if (!empty($payload['call_start'])) {
            $startedAt = strtotime($payload['call_start']);
        }

        /**
         * 6. Время окончания
         */
        $endedAt = null;
        if (str_ends_with($event, '_END')) {
            $endedAt = time();
        }

        /**
         * 7. Длительность
         */
        $duration = isset($payload['duration'])
            ? (int)$payload['duration']
            : 0;

        /**
         * 8. Запись разговора
         */
        $recordUrl = null;
        if (!empty($payload['call_id_with_rec'])) {
            $recordUrl = $this->buildRecordUrl($payload['call_id_with_rec']);
        }

        /**
         * 9. Внутренний номер сотрудника
         */
        $workerExt = $payload['internal'] ?? null;

        /**
         * 10. Финальный массив
         */
        return [
            'provider_call_id' => $providerCallId,

            'direction'   => $direction,
            'phone_from'  => $phoneFrom,
            'phone_to'    => $phoneTo,

            'status'      => $status,
            'duration'    => $duration,

            'started_at'  => $startedAt,
            'ended_at'    => $endedAt,

            'provider_user_num' => $workerExt,

            'record_url'  => $recordUrl,

            'provider_data' => $payload,
        ];
    }


}