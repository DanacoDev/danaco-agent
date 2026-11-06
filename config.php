<?php

return [
    'app_name' => 'Agent Danaco',
    'db_path' => __DIR__ . '/data/app.sqlite',
    'data_dir' => __DIR__ . '/data',
    'session_name' => 'agent_session',
    'port' => 8000,
    'ollama_url' => 'http://127.0.0.1:11434',
    'local_model' => 'qwen3.6:35b',
    'cpu_failsafe_model' => 'qwen2.5:0.5b',
    'cloud_base_url' => 'https://api.openai.com/v1',
    'cloud_model' => 'gpt-4o-mini',
    'max_turns' => 1000,
    'cloud_max_turns' => 50,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
];
