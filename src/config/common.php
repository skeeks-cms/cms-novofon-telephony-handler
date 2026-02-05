<?php
return [
    'components' => [
        'cms' => [
            'telephonyHandlers' => [
                'novofon' => [
                    'class' => \skeeks\cms\telephony\novofon\NovofonTelephonyHandler::class,
                ],
            ],
        ],
    ],
];