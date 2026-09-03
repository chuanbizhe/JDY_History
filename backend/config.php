<?php
/* 生产环境请优先通过环境变量覆盖这些配置。 */
return [
    'db_host' => getenv('HISTORY_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('HISTORY_DB_PORT') ?: '3306',
    'db_name' => getenv('HISTORY_DB_NAME') ?: 'jdy_dingdanduo_c',
    'db_user' => getenv('HISTORY_DB_USER') ?: 'jdy_dingdanduo_c',
    'db_pass' => getenv('HISTORY_DB_PASS') ?: '',
    'base_url' => rtrim(getenv('HISTORY_BASE_URL') ?: 'https://your-domain.example', '/'),
    'rollback_url' => rtrim(getenv('HISTORY_ROLLBACK_URL') ?: 'https://your-domain.example/rollback.php', '/'),
    'app_key' => getenv('HISTORY_APP_KEY') ?: '',
];
