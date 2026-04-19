<?php return array (
  'app' => 
  array (
    'name' => 'AllTrueAdmin',
    'env' => 'production',
    'debug' => false,
    'url' => 'http://localhost',
    'timezone' => 'Asia/Taipei',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => 'base64:U/QSesYDU8lkbZXkIXeP+jgqMPljRxyEyOI4kI60Osc=',
    'cipher' => 'AES-256-CBC',
    'providers' => 
    array (
      0 => 'App\\Providers\\AppServiceProvider',
      1 => 'Illuminate\\Auth\\AuthServiceProvider',
      2 => 'Illuminate\\Broadcasting\\BroadcastServiceProvider',
      3 => 'Illuminate\\Bus\\BusServiceProvider',
      4 => 'Illuminate\\Cache\\CacheServiceProvider',
      5 => 'Illuminate\\Cookie\\CookieServiceProvider',
      6 => 'Illuminate\\Database\\DatabaseServiceProvider',
      7 => 'Illuminate\\Encryption\\EncryptionServiceProvider',
      8 => 'Illuminate\\Filesystem\\FilesystemServiceProvider',
      9 => 'Illuminate\\Foundation\\Providers\\ConsoleSupportServiceProvider',
      10 => 'Illuminate\\Foundation\\Providers\\FoundationServiceProvider',
      11 => 'Illuminate\\Hashing\\HashServiceProvider',
      12 => 'Illuminate\\Pipeline\\PipelineServiceProvider',
      13 => 'Illuminate\\Queue\\QueueServiceProvider',
      14 => 'Illuminate\\Routing\\RoutingServiceProvider',
      15 => 'Illuminate\\Session\\SessionServiceProvider',
      16 => 'Illuminate\\Translation\\TranslationServiceProvider',
      17 => 'Illuminate\\Validation\\ValidationServiceProvider',
      18 => 'Illuminate\\View\\ViewServiceProvider',
      19 => 'Maatwebsite\\Excel\\ExcelServiceProvider',
    ),
    'aliases' => 
    array (
      'Excel' => 'Maatwebsite\\Excel\\Facades\\Excel',
    ),
  ),
  'broadcasting' => 
  array (
    'default' => 'pusher',
    'connections' => 
    array (
      'pusher' => 
      array (
        'driver' => 'pusher',
        'key' => 'alltrue-local-key',
        'secret' => 'alltrue-local-secret',
        'app_id' => 'alltrue-local',
        'options' => 
        array (
          'cluster' => 'mt1',
          'host' => '127.0.0.1',
          'port' => 6001,
          'scheme' => 'http',
          'encrypted' => false,
          'useTLS' => false,
        ),
      ),
      'null' => 
      array (
        'driver' => 'null',
      ),
    ),
  ),
  'cache' => 
  array (
    'default' => 'file',
    'stores' => 
    array (
      'array' => 
      array (
        'driver' => 'array',
        'serialize' => false,
      ),
      'file' => 
      array (
        'driver' => 'file',
        'path' => '/home/admin/backend/storage/framework/cache/data',
      ),
    ),
    'prefix' => 'alltrue_cache',
  ),
  'cors' => 
  array (
    'paths' => 
    array (
      0 => 'api/*',
    ),
    'allowed_methods' => 
    array (
      0 => '*',
    ),
    'allowed_origins' => 
    array (
      0 => '*',
    ),
    'allowed_headers' => 
    array (
      0 => '*',
    ),
    'exposed_headers' => 
    array (
    ),
    'max_age' => 0,
    'supports_credentials' => false,
  ),
  'database' => 
  array (
    'default' => 'mysql',
    'connections' => 
    array (
      'sqlite' => 
      array (
        'driver' => 'sqlite',
        'url' => NULL,
        'database' => 'AllTrue',
        'prefix' => '',
        'foreign_key_constraints' => true,
      ),
      'pgsql' => 
      array (
        'driver' => 'pgsql',
        'url' => NULL,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'AllTrue',
        'username' => 'admin',
        'password' => 'fLcLUu3Imi7RuFhT1jfwIH1',
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'sslmode' => 'prefer',
      ),
      'mysql' => 
      array (
        'driver' => 'mysql',
        'url' => NULL,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'AllTrue',
        'username' => 'admin',
        'password' => 'fLcLUu3Imi7RuFhT1jfwIH1',
        'unix_socket' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => NULL,
        'options' => 
        array (
        ),
        'read' => 
        array (
        ),
        'write' => 
        array (
        ),
        'sticky' => true,
      ),
    ),
    'migrations' => 'migrations',
    'redis' => 
    array (
      'client' => 'phpredis',
      'options' => 
      array (
        'cluster' => 'redis',
        'prefix' => 'alltrueadmin_database_',
      ),
    ),
  ),
  'filesystems' => 
  array (
    'default' => 'local',
    'disks' => 
    array (
      'local' => 
      array (
        'driver' => 'local',
        'root' => '/home/admin/backend/storage/app',
      ),
      'public' => 
      array (
        'driver' => 'local',
        'root' => '/home/admin/backend/storage/app/public',
        'url' => 'http://localhost/storage',
        'visibility' => 'public',
      ),
    ),
  ),
  'hashing' => 
  array (
    'driver' => 'bcrypt',
    'bcrypt' => 
    array (
      'rounds' => 10,
    ),
  ),
  'logging' => 
  array (
    'default' => 'stack',
    'channels' => 
    array (
      'stack' => 
      array (
        'driver' => 'stack',
        'channels' => 
        array (
          0 => 'daily',
        ),
      ),
      'daily' => 
      array (
        'driver' => 'daily',
        'path' => '/home/admin/backend/storage/logs/laravel.log',
        'level' => 'debug',
        'days' => 14,
        'permission' => 436,
      ),
      'perf' => 
      array (
        'driver' => 'daily',
        'path' => '/home/admin/backend/storage/logs/perf.log',
        'level' => 'info',
        'days' => 14,
        'permission' => 436,
      ),
    ),
  ),
  'payroll' => 
  array (
    'enabled' => true,
    'max_per_page' => 200,
    'max_export_rows' => 5000,
    'base_rates' => 
    array (
      'high' => 400,
      'junior' => 350,
      'elementary' => 300,
      'tutoring' => 200,
    ),
    'headcount_bonus' => 50,
    'concurrency_bonus_per_student' => 50,
    'level_weights' => 
    array (
      'high' => 4,
      'junior' => 3,
      'elementary' => 2,
      'tutoring' => 1,
    ),
    'grade_level_map' => 
    array (
      1 => 'elementary',
      2 => 'elementary',
      3 => 'elementary',
      4 => 'elementary',
      5 => 'elementary',
      6 => 'elementary',
      7 => 'junior',
      8 => 'junior',
      9 => 'junior',
      10 => 'high',
      11 => 'high',
      12 => 'high',
    ),
  ),
  'perfflags' => 
  array (
    'throttle_notification_sync' => true,
    'notification_sync_cooldown_seconds' => 300,
    'learning_records_default_per_page' => 50,
    'learning_records_max_per_page' => 200,
    'learning_records_default_window_days' => 90,
    'course_packages_enabled' => true,
    'log_session_count_mismatch' => false,
  ),
  'services' => 
  array (
    'line' => 
    array (
      'channel_access_token' => NULL,
      'channel_secret' => NULL,
      'liff_id' => NULL,
    ),
  ),
  'session' => 
  array (
    'driver' => 'cookie',
    'lifetime' => '120',
    'expire_on_close' => false,
    'encrypt' => true,
    'cookie' => 'alltrue_session',
    'path' => '/',
    'domain' => NULL,
    'secure' => true,
    'http_only' => true,
    'same_site' => 'lax',
  ),
  'view' => 
  array (
    'paths' => 
    array (
      0 => '/home/admin/backend/resources/views',
    ),
    'compiled' => '/home/admin/backend/storage/framework/views',
  ),
  'auth' => 
  array (
    'guards' => 
    array (
      'sanctum' => 
      array (
        'driver' => 'sanctum',
        'provider' => NULL,
      ),
    ),
  ),
  'sanctum' => 
  array (
    'stateful' => 
    array (
      0 => 'localhost',
      1 => '127.0.0.1',
    ),
    'guard' => 
    array (
      0 => 'web',
    ),
    'expiration' => NULL,
    'middleware' => 
    array (
      'verify_csrf_token' => 'App\\Http\\Middleware\\VerifyCsrfToken',
      'encrypt_cookies' => 'App\\Http\\Middleware\\EncryptCookies',
    ),
  ),
  'excel' => 
  array (
    'exports' => 
    array (
      'chunk_size' => 1000,
      'pre_calculate_formulas' => false,
      'strict_null_comparison' => false,
      'csv' => 
      array (
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '
',
        'use_bom' => false,
        'include_separator_line' => false,
        'excel_compatibility' => false,
        'output_encoding' => '',
        'test_auto_detect' => true,
      ),
      'properties' => 
      array (
        'creator' => '',
        'lastModifiedBy' => '',
        'title' => '',
        'description' => '',
        'subject' => '',
        'keywords' => '',
        'category' => '',
        'manager' => '',
        'company' => '',
      ),
    ),
    'imports' => 
    array (
      'read_only' => true,
      'ignore_empty' => false,
      'heading_row' => 
      array (
        'formatter' => 'slug',
      ),
      'csv' => 
      array (
        'delimiter' => NULL,
        'enclosure' => '"',
        'escape_character' => '\\',
        'contiguous' => false,
        'input_encoding' => 'guess',
      ),
      'properties' => 
      array (
        'creator' => '',
        'lastModifiedBy' => '',
        'title' => '',
        'description' => '',
        'subject' => '',
        'keywords' => '',
        'category' => '',
        'manager' => '',
        'company' => '',
      ),
      'cells' => 
      array (
        'middleware' => 
        array (
        ),
      ),
    ),
    'extension_detector' => 
    array (
      'xlsx' => 'Xlsx',
      'xlsm' => 'Xlsx',
      'xltx' => 'Xlsx',
      'xltm' => 'Xlsx',
      'xls' => 'Xls',
      'xlt' => 'Xls',
      'ods' => 'Ods',
      'ots' => 'Ods',
      'slk' => 'Slk',
      'xml' => 'Xml',
      'gnumeric' => 'Gnumeric',
      'htm' => 'Html',
      'html' => 'Html',
      'csv' => 'Csv',
      'tsv' => 'Csv',
      'pdf' => 'Dompdf',
    ),
    'value_binder' => 
    array (
      'default' => 'Maatwebsite\\Excel\\DefaultValueBinder',
    ),
    'cache' => 
    array (
      'driver' => 'memory',
      'batch' => 
      array (
        'memory_limit' => 60000,
      ),
      'illuminate' => 
      array (
        'store' => NULL,
      ),
      'default_ttl' => 10800,
    ),
    'transactions' => 
    array (
      'handler' => 'db',
      'db' => 
      array (
        'connection' => NULL,
      ),
    ),
    'temporary_files' => 
    array (
      'local_path' => '/home/admin/backend/storage/framework/cache/laravel-excel',
      'local_permissions' => 
      array (
      ),
      'remote_disk' => NULL,
      'remote_prefix' => NULL,
      'force_resync_remote' => NULL,
    ),
  ),
  'tinker' => 
  array (
    'commands' => 
    array (
    ),
    'alias' => 
    array (
    ),
    'dont_alias' => 
    array (
      0 => 'App\\Nova',
    ),
    'trust_project' => 'always',
  ),
);
