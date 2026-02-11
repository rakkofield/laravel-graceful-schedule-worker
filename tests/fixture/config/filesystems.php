<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => sys_get_temp_dir(),
        ],
    ],
];
