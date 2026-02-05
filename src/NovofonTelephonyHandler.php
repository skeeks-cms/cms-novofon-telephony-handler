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
     * Нормализация webhook Sipuni
     */
    public function normalizeWebhook(array $payload): array
    {
        /**
         * 1. ID звонка у провайдера
         */
        $providerCallId = $payload['call_id'] ?? null;

        /**
         * 2. Направление звонка
         * Sipuni присылает treeName: "Исходящая" / "Входящая"
         */
        $direction = ($payload['treeName'] ?? '') === 'Исходящая'
            ? 'out'
            : 'in';

        /**
         * 3. Определяем номера
         */
        if ($direction === 'out') {
            // исходящий
            $phoneFrom = $payload['short_src_num']
                ?? $payload['src_num']
                ?? null;

            $phoneTo = $payload['pbxdstnum']
                ?? $payload['dst_num']
                ?? null;
        } else {
            // входящий
            $phoneFrom = $payload['src_num']
                ?? null;

            $phoneTo = $payload['dst_num']
                ?? null;
        }

        /**
         * 4. Статус звонка
         */
        $status = match ($payload['status'] ?? null) {
            StringHelper::strtoupper(CmsTelephonyCall::STATUS_ANSWER) => CmsTelephonyCall::STATUS_ANSWER,
            StringHelper::strtoupper(CmsTelephonyCall::STATUS_BUSY) => CmsTelephonyCall::STATUS_BUSY,
            StringHelper::strtoupper(CmsTelephonyCall::STATUS_NOANSWER) => CmsTelephonyCall::STATUS_NOANSWER,
            StringHelper::strtoupper(CmsTelephonyCall::STATUS_CANCEL) => CmsTelephonyCall::STATUS_CANCEL,
            StringHelper::strtoupper(CmsTelephonyCall::STATUS_CONGESTION) => CmsTelephonyCall::STATUS_CONGESTION,
            StringHelper::strtoupper(CmsTelephonyCall::STATUS_CHANUNAVAIL) => CmsTelephonyCall::STATUS_CHANUNAVAIL,
            default    => CmsTelephonyCall::STATUS_RINGING,
        };

        /**
         * 5. Время начала / окончания
         */
        $startedAt = isset($payload['call_start_timestamp'])
            ? (int)$payload['call_start_timestamp']
            : null;

        $endedAt = isset($payload['timestamp'])
            ? (int)$payload['timestamp']
            : null;

        /**
         * 6. Длительность
         */
        $duration = isset($payload['billsec'])
            ? (int)$payload['billsec']
            : 0;

        /**
         * 7. Запись разговора
         */
        $recordUrl = $payload['call_record_link'] ?? null;

        /**
         * 8. Внутренний номер сотрудника (extension)
         *
         * - исходящий: short_src_num
         * - входящий: last_called
         */
        $workerExt = $payload['short_src_num']
            ?? $payload['last_called']
            ?? null;

        /**
         * 9. Финальный нормализованный массив
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

            'provider_user_num'  => $workerExt,

            'record_url'  => $recordUrl,

            // всегда сохраняем сырьё
            'provider_data' => $payload,
        ];
    }
}