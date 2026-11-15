<?php

function databasePath(): string
{
    return __DIR__ . '/../storage/app.sqlite';
}

function loadApplicationConfig(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $configurationPath = dirname(__DIR__) . '/storage/config.php';
    if (is_file($configurationPath)) {
        require_once $configurationPath;
    }

    $loaded = true;
}

function databaseConfiguration(): array
{
    loadApplicationConfig();

    $configurationPath = dirname(__DIR__) . '/storage/database.config.php';
    $configuration = is_file($configurationPath) ? require $configurationPath : [];
    if (!is_array($configuration)) {
        throw new RuntimeException('Database configuration must return an array.');
    }

    if (defined('DB_HOST') || defined('DB_USER') || defined('DB_NAME')) {
        $configuration = array_merge($configuration, [
            'driver' => 'mysql',
            'host' => defined('DB_HOST') ? DB_HOST : '',
            'port' => defined('DB_PORT') ? DB_PORT : 3306,
            'database' => defined('DB_NAME') ? DB_NAME : '',
            'username' => defined('DB_USER') ? DB_USER : '',
            'password' => defined('DB_PASS') ? DB_PASS : '',
            'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
        ]);
    }

    foreach ([
        'driver' => 'DB_CONNECTION',
        'host' => 'DB_HOST',
        'port' => 'DB_PORT',
        'database' => 'DB_DATABASE',
        'username' => 'DB_USERNAME',
        'password' => 'DB_PASSWORD',
        'charset' => 'DB_CHARSET',
    ] as $key => $environmentVariable) {
        $environmentValue = getenv($environmentVariable);
        if ($environmentValue !== false && $environmentValue !== '') {
            $configuration[$key] = $environmentValue;
        }
    }

    return $configuration;
}

function subscriptionPaymentSetting(string $key): string
{
    loadApplicationConfig();

    static $fileConfiguration = null;
    if ($fileConfiguration === null) {
        $configurationPath = dirname(__DIR__) . '/storage/payment.config.php';
        $fileConfiguration = is_file($configurationPath) ? require $configurationPath : [];
        if (!is_array($fileConfiguration)) {
            throw new RuntimeException('Payment configuration must return an array.');
        }
    }

    $environmentValue = getenv($key);
    if ($environmentValue !== false && $environmentValue !== '') {
        return trim($environmentValue);
    }

    if ($key === 'APP_BASE_URL' && defined('BASE_URL')
        && trim((string) BASE_URL) !== ''
        && trim((string) BASE_URL) !== 'https://your-domain.example') {
        return trim((string) BASE_URL);
    }

    $value = $fileConfiguration[$key] ?? '';
    return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
}

function databaseDriver(PDO $pdo): string
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function databaseMonthExpression(string $driver, string $column): string
{
    if (!in_array($column, ['p.payment_date', 'expense_date'], true)) {
        throw new InvalidArgumentException('Unsupported date column for monthly grouping.');
    }

    return match ($driver) {
        'mysql' => "DATE_FORMAT({$column}, '%Y-%m')",
        'sqlite' => "strftime('%Y-%m', {$column})",
        default => throw new InvalidArgumentException('Unsupported database driver for monthly grouping.'),
    };
}

function openDatabase(): PDO
{
    $configuration = databaseConfiguration();
    $driver = strtolower((string) ($configuration['driver'] ?? 'sqlite'));

    try {
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . databasePath());
        } elseif ($driver === 'mysql') {
            foreach (['host', 'database', 'username', 'password'] as $requiredSetting) {
                if (!array_key_exists($requiredSetting, $configuration)
                    || ($requiredSetting !== 'password' && $configuration[$requiredSetting] === '')) {
                    throw new RuntimeException('MySQL database configuration is incomplete. Set the host, database, username and password.');
                }
            }
            $host = (string) $configuration['host'];
            $port = (int) ($configuration['port'] ?? 3306);
            $database = (string) $configuration['database'];
            $charset = (string) ($configuration['charset'] ?? 'utf8mb4');
            if ($port < 1 || $port > 65535
                || !preg_match('/^[a-zA-Z0-9.:-]+$/', $host)
                || !preg_match('/^[a-zA-Z0-9_$-]+$/', $database)
                || !preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
                throw new RuntimeException('MySQL database configuration contains an invalid port or character set.');
            }
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $pdo = new PDO($dsn, (string) $configuration['username'], (string) $configuration['password']);
            $pdo->exec("SET time_zone = '+00:00'");
        } else {
            throw new RuntimeException('Unsupported database driver. Use mysql or sqlite.');
        }
    } catch (PDOException $exception) {
        error_log('Database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('Database connection failed. Check the database settings and ensure the database server is available.', 0, $exception);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if (databaseDriver($pdo) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }

    return $pdo;
}

function initializeDatabase(PDO $pdo, bool $seedDemo = true, bool $provisionSubscriptions = true): void
{
    if (databaseDriver($pdo) === 'mysql') {
        initializeMysqlDatabase($pdo);
        if ($provisionSubscriptions) {
            initializeSubscriptionData($pdo);
        }
        return;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at TEXT
        );

        CREATE TABLE IF NOT EXISTS businesses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            email TEXT,
            phone TEXT,
            address TEXT,
            city TEXT,
            country TEXT,
            currency TEXT NOT NULL DEFAULT 'USD',
            tax_id TEXT,
            logo_path TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            company TEXT,
            email TEXT,
            phone TEXT,
            address TEXT,
            country TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            sku TEXT,
            unit TEXT,
            price REAL NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'USD',
            tax_rate REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            customer_id INTEGER,
            invoice_number TEXT NOT NULL,
            issue_date TEXT NOT NULL,
            due_date TEXT,
            template TEXT NOT NULL DEFAULT 'classic',
            currency TEXT NOT NULL DEFAULT 'USD',
            status TEXT NOT NULL DEFAULT 'Draft',
            issuer_name TEXT,
            issuer_email TEXT,
            issuer_phone TEXT,
            issuer_address TEXT,
            issuer_city TEXT,
            issuer_country TEXT,
            issuer_tax_id TEXT,
            recipient_company TEXT,
            recipient_address TEXT,
            recipient_email TEXT,
            recipient_phone TEXT,
            document_label TEXT,
            document_title TEXT,
            logo_path TEXT,
            subtotal REAL NOT NULL DEFAULT 0,
            discount REAL NOT NULL DEFAULT 0,
            tax REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            notes TEXT,
            terms TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            description TEXT NOT NULL,
            quantity REAL NOT NULL DEFAULT 1,
            unit_price REAL NOT NULL DEFAULT 0,
            discount REAL NOT NULL DEFAULT 0,
            tax_rate REAL NOT NULL DEFAULT 0,
            line_total REAL NOT NULL DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS invoice_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            amount REAL NOT NULL CHECK (amount > 0),
            payment_date TEXT NOT NULL,
            method TEXT NOT NULL,
            reference TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_invoice_payments_invoice_date
            ON invoice_payments (invoice_id, payment_date, id);

        CREATE TABLE IF NOT EXISTS invoice_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            user_id INTEGER,
            event_type TEXT NOT NULL,
            from_status TEXT,
            to_status TEXT,
            details_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_invoice_events_invoice_date
            ON invoice_events (invoice_id, created_at, id);

        CREATE TABLE IF NOT EXISTS quotations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            customer_id INTEGER,
            quotation_number TEXT NOT NULL,
            issue_date TEXT NOT NULL,
            expiry_date TEXT NOT NULL,
            currency TEXT NOT NULL DEFAULT 'USD',
            status TEXT NOT NULL DEFAULT 'Draft',
            notes TEXT,
            terms TEXT,
            subtotal REAL NOT NULL DEFAULT 0,
            discount REAL NOT NULL DEFAULT 0,
            tax REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            converted_invoice_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (converted_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS quotation_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quotation_id INTEGER NOT NULL,
            description TEXT NOT NULL,
            quantity REAL NOT NULL DEFAULT 1,
            unit_price REAL NOT NULL DEFAULT 0,
            discount REAL NOT NULL DEFAULT 0,
            tax_rate REAL NOT NULL DEFAULT 0,
            line_total REAL NOT NULL DEFAULT 0,
            FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS quotation_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quotation_id INTEGER NOT NULL,
            user_id INTEGER,
            event_type TEXT NOT NULL,
            details_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS customer_portal_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL UNIQUE,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at TEXT,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS quotation_portal_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quotation_id INTEGER NOT NULL UNIQUE,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at TEXT,
            FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            expense_date TEXT NOT NULL,
            category TEXT NOT NULL,
            description TEXT NOT NULL,
            amount REAL NOT NULL CHECK (amount > 0),
            currency TEXT NOT NULL,
            receipt_path TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_hash TEXT NOT NULL,
            attempted_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS ai_usage (
            user_id INTEGER NOT NULL,
            usage_date TEXT NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, usage_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS promo_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            discount_type TEXT NOT NULL,
            discount_value REAL NOT NULL,
            currency TEXT NOT NULL,
            max_redemptions INTEGER,
            expires_at TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            starts_at TEXT,
            ends_at TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            monthly_price REAL,
            yearly_price REAL,
            monthly_price_xaf REAL,
            yearly_price_xaf REAL,
            currency TEXT,
            features_json TEXT,
            is_available INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            plan_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            current_period_start TEXT,
            current_period_end TEXT,
            cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
            provider TEXT,
            provider_customer_id TEXT,
            provider_subscription_id TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (plan_id) REFERENCES plans(id)
        );

        CREATE TABLE IF NOT EXISTS subscription_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            previous_plan_id INTEGER,
            new_plan_id INTEGER,
            details_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (previous_plan_id) REFERENCES plans(id) ON DELETE SET NULL,
            FOREIGN KEY (new_plan_id) REFERENCES plans(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS subscription_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            plan_id INTEGER,
            billing_cycle TEXT NOT NULL DEFAULT 'monthly',
            provider TEXT,
            provider_transaction_id TEXT,
            checkout_url TEXT,
            amount REAL NOT NULL CHECK (amount > 0),
            currency TEXT NOT NULL,
            status TEXT NOT NULL,
            description TEXT,
            promo_code_id INTEGER,
            promo_code TEXT,
            discount_amount REAL NOT NULL DEFAULT 0,
            paid_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (provider, provider_transaction_id),
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (plan_id) REFERENCES plans(id),
            FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS subscription_usage (
            user_id INTEGER NOT NULL,
            feature TEXT NOT NULL,
            usage_month TEXT NOT NULL,
            usage_count INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, feature, usage_month),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_login_attempts_email_time
            ON login_attempts (email_hash, attempted_at);
SQL);

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_business_number_ci ON invoices (business_id, invoice_number COLLATE NOCASE)');
    $pdo->exec('DROP INDEX IF EXISTS idx_invoices_business_number');

    $invoiceColumns = $pdo->query('PRAGMA table_info(invoices)')->fetchAll();
    $existingInvoiceColumns = array_column($invoiceColumns, 'name');
    $invoiceMigrations = [
        'template' => "TEXT NOT NULL DEFAULT 'classic'",
        'document_kind' => "TEXT NOT NULL DEFAULT 'invoice'",
        'issuer_name' => 'TEXT',
        'issuer_email' => 'TEXT',
        'issuer_phone' => 'TEXT',
        'issuer_address' => 'TEXT',
        'issuer_city' => 'TEXT',
        'issuer_country' => 'TEXT',
        'issuer_tax_id' => 'TEXT',
        'recipient_company' => 'TEXT',
        'recipient_address' => 'TEXT',
        'recipient_email' => 'TEXT',
        'recipient_phone' => 'TEXT',
        'document_label' => 'TEXT',
        'document_title' => 'TEXT',
        'logo_path' => 'TEXT',
    ];

    foreach ($invoiceMigrations as $column => $definition) {
        if (!in_array($column, $existingInvoiceColumns, true)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN {$column} {$definition}");
        }
    }

    $businessColumns = $pdo->query('PRAGMA table_info(businesses)')->fetchAll();
    $existingBusinessColumns = array_column($businessColumns, 'name');
    if (!in_array('logo_path', $existingBusinessColumns, true)) {
        $pdo->exec('ALTER TABLE businesses ADD COLUMN logo_path TEXT');
    }

    $userColumns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $existingUserColumns = array_column($userColumns, 'name');
    if (!in_array('role', $existingUserColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    }
    if (!in_array('last_activity_at', $existingUserColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_activity_at TEXT');
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_last_activity ON users (last_activity_at)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_quotations_business_number_ci ON quotations (business_id, quotation_number COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quotations_business_status ON quotations (business_id, status, expiry_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quotation_events_quote_date ON quotation_events (quotation_id, created_at, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_expenses_business_date ON expenses (business_id, expense_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscription_events_user_date ON subscription_events (user_id, created_at, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscription_transactions_user_date ON subscription_transactions (user_id, created_at, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscription_usage_user_month ON subscription_usage (user_id, usage_month)');
    $transactionColumns = $pdo->query('PRAGMA table_info(subscription_transactions)')->fetchAll();
    $existingTransactionColumns = array_column($transactionColumns, 'name');
    foreach (['plan_id' => 'INTEGER', 'billing_cycle' => "TEXT NOT NULL DEFAULT 'monthly'", 'checkout_url' => 'TEXT', 'promo_code_id' => 'INTEGER', 'promo_code' => 'TEXT', 'discount_amount' => 'REAL NOT NULL DEFAULT 0'] as $column => $definition) {
        if (!in_array($column, $existingTransactionColumns, true)) {
            $pdo->exec("ALTER TABLE subscription_transactions ADD COLUMN {$column} {$definition}");
        }
    }
    $planColumns = $pdo->query('PRAGMA table_info(plans)')->fetchAll();
    $existingPlanColumns = array_column($planColumns, 'name');
    foreach (['monthly_price_xaf' => 'REAL', 'yearly_price_xaf' => 'REAL'] as $column => $definition) {
        if (!in_array($column, $existingPlanColumns, true)) {
            $pdo->exec("ALTER TABLE plans ADD COLUMN {$column} {$definition}");
        }
    }
    if (!in_array('admin_customized', $existingPlanColumns, true)) {
        $pdo->exec('ALTER TABLE plans ADD COLUMN admin_customized INTEGER NOT NULL DEFAULT 0');
    }

    if ($seedDemo) {
        seedDemoData($pdo);
    }
    if ($provisionSubscriptions) {
        initializeSubscriptionData($pdo);
    }
}

function initializeMysqlDatabase(PDO $pdo): void
{
    $schemaPath = dirname(__DIR__) . '/storage/mysql_schema.sql';
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException('The MySQL schema file could not be read.');
    }

    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        $pdo->exec($statement);
    }
    $userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('last_activity_at', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_activity_at DATETIME NULL');
    }
    $activityIndex = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_last_activity'")->fetch();
    if (!$activityIndex) {
        $pdo->exec('CREATE INDEX idx_users_last_activity ON users (last_activity_at)');
    }
    $planColumns = $pdo->query('SHOW COLUMNS FROM plans')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['monthly_price_xaf' => 'DECIMAL(15,2) NULL', 'yearly_price_xaf' => 'DECIMAL(15,2) NULL', 'admin_customized' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $column => $definition) {
        if (!in_array($column, $planColumns, true)) {
            $pdo->exec("ALTER TABLE plans ADD COLUMN {$column} {$definition}");
        }
    }
    $transactionColumns = $pdo->query('SHOW COLUMNS FROM subscription_transactions')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['plan_id' => 'BIGINT UNSIGNED NULL', 'billing_cycle' => "VARCHAR(20) NOT NULL DEFAULT 'monthly'", 'checkout_url' => 'VARCHAR(1000) NULL', 'promo_code_id' => 'BIGINT UNSIGNED NULL', 'promo_code' => 'VARCHAR(40) NULL', 'discount_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0'] as $column => $definition) {
        if (!in_array($column, $transactionColumns, true)) {
            $pdo->exec("ALTER TABLE subscription_transactions ADD COLUMN {$column} {$definition}");
        }
    }
    $promoCurrencyColumn = $pdo->query("SHOW COLUMNS FROM promo_codes LIKE 'currency'")->fetch();
    if (!$promoCurrencyColumn) {
        throw new RuntimeException('The promo-code currency column is missing from the database.');
    }
    if (strtolower((string) $promoCurrencyColumn['Type']) !== 'varchar(4)') {
        $pdo->exec('ALTER TABLE promo_codes MODIFY currency VARCHAR(4) NOT NULL');
    }
}

function recordUserActivity(PDO $pdo, int $userId): void
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required to record activity.');
    }

    $statement = $pdo->prepare('UPDATE users SET last_activity_at = :last_activity_at WHERE id = :user_id');
    $statement->execute([
        'last_activity_at' => gmdate('Y-m-d H:i:s'),
        'user_id' => $userId,
    ]);
}

function initializeSubscriptionData(PDO $pdo): void
{
    seedSubscriptionPlans($pdo);
    $users = $pdo->query('SELECT u.id FROM users u LEFT JOIN subscriptions s ON s.user_id = u.id WHERE s.id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($users as $userId) {
        provisionFreeSubscription($pdo, (int) $userId);
    }
}

function seedSubscriptionPlans(PDO $pdo): void
{
    $catalog = subscriptionPlanCatalog();
    $plans = [
        ['free', 0, null, 0, null],
        ['pro', 3, null, 1200, null],
        ['business', null, 9, null, 5000],
    ];
    $driver = databaseDriver($pdo);
    $sql = $driver === 'mysql'
        ? 'INSERT INTO plans (code, name, monthly_price, yearly_price, monthly_price_xaf, yearly_price_xaf, currency, features_json, is_available) VALUES (:code, :name, :monthly_price, :yearly_price, :monthly_price_xaf, :yearly_price_xaf, :currency, :features_json, :is_available) ON DUPLICATE KEY UPDATE name = IF(admin_customized = 1, name, VALUES(name)), monthly_price = IF(admin_customized = 1, monthly_price, VALUES(monthly_price)), yearly_price = IF(admin_customized = 1, yearly_price, VALUES(yearly_price)), monthly_price_xaf = IF(admin_customized = 1, monthly_price_xaf, VALUES(monthly_price_xaf)), yearly_price_xaf = IF(admin_customized = 1, yearly_price_xaf, VALUES(yearly_price_xaf)), currency = VALUES(currency), features_json = VALUES(features_json), is_available = IF(admin_customized = 1, is_available, VALUES(is_available))'
        : 'INSERT INTO plans (code, name, monthly_price, yearly_price, monthly_price_xaf, yearly_price_xaf, currency, features_json, is_available) VALUES (:code, :name, :monthly_price, :yearly_price, :monthly_price_xaf, :yearly_price_xaf, :currency, :features_json, :is_available) ON CONFLICT(code) DO UPDATE SET name = CASE WHEN admin_customized = 1 THEN name ELSE excluded.name END, monthly_price = CASE WHEN admin_customized = 1 THEN monthly_price ELSE excluded.monthly_price END, yearly_price = CASE WHEN admin_customized = 1 THEN yearly_price ELSE excluded.yearly_price END, monthly_price_xaf = CASE WHEN admin_customized = 1 THEN monthly_price_xaf ELSE excluded.monthly_price_xaf END, yearly_price_xaf = CASE WHEN admin_customized = 1 THEN yearly_price_xaf ELSE excluded.yearly_price_xaf END, currency = excluded.currency, features_json = excluded.features_json, is_available = CASE WHEN admin_customized = 1 THEN is_available ELSE excluded.is_available END';
    $statement = $pdo->prepare($sql);
    foreach ($plans as [$code, $monthlyPrice, $yearlyPrice, $monthlyPriceXaf, $yearlyPriceXaf]) {
        $statement->execute([
            'code' => $code,
            'name' => $catalog[$code]['name'],
            'monthly_price' => $monthlyPrice,
            'yearly_price' => $yearlyPrice,
            'monthly_price_xaf' => $monthlyPriceXaf,
            'yearly_price_xaf' => $yearlyPriceXaf,
            'currency' => 'USD',
            'features_json' => json_encode($catalog[$code], JSON_THROW_ON_ERROR),
            'is_available' => 1,
        ]);
    }
}

function provisionFreeSubscription(PDO $pdo, int $userId): void
{
    seedSubscriptionPlans($pdo);
    $freePlanId = getFreePlanId($pdo);
    $sql = databaseDriver($pdo) === 'mysql'
        ? 'INSERT IGNORE INTO subscriptions (user_id, plan_id, status, current_period_start) VALUES (:user_id, :plan_id, \'active\', :period_start)'
        : 'INSERT OR IGNORE INTO subscriptions (user_id, plan_id, status, current_period_start) VALUES (:user_id, :plan_id, \'active\', :period_start)';
    $statement = $pdo->prepare($sql);
    $statement->execute([
        'user_id' => $userId,
        'plan_id' => $freePlanId,
        'period_start' => gmdate('Y-m-d H:i:s'),
    ]);
    if ($statement->rowCount() === 1) {
        $subscriptionId = $pdo->lastInsertId();
        $event = $pdo->prepare("INSERT INTO subscription_events (subscription_id, user_id, event_type, new_plan_id) VALUES (:subscription_id, :user_id, 'subscription_created', :plan_id)");
        $event->execute([
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
            'plan_id' => $freePlanId,
        ]);
    }
}

function getFreePlanId(PDO $pdo): int
{
    $statement = $pdo->query("SELECT id FROM plans WHERE code = 'free' LIMIT 1");
    $planId = $statement->fetchColumn();
    if ($planId === false) {
        throw new RuntimeException('The Free subscription plan is missing.');
    }

    return (int) $planId;
}

function getUserSubscription(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.monthly_price, p.yearly_price, p.monthly_price_xaf, p.yearly_price_xaf, p.currency AS plan_currency FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = :user_id LIMIT 1');
    $statement->execute(['user_id' => $userId]);
    $subscription = $statement->fetch() ?: null;
    if ($subscription && $subscription['plan_code'] !== 'free'
        && !empty($subscription['current_period_end'])
        && $subscription['current_period_end'] <= gmdate('Y-m-d H:i:s')) {
        $freePlanId = getFreePlanId($pdo);
        $now = gmdate('Y-m-d H:i:s');
        $expire = $pdo->prepare('UPDATE subscriptions SET plan_id = :plan_id, current_period_start = :period_start, current_period_end = NULL, cancel_at_period_end = 0, provider = NULL, provider_customer_id = NULL, provider_subscription_id = NULL, updated_at = :updated_at WHERE id = :id AND plan_id = :previous_plan_id AND current_period_end <= :period_end');
        $expire->execute([
            'plan_id' => $freePlanId,
            'period_start' => $now,
            'updated_at' => $now,
            'id' => $subscription['id'],
            'previous_plan_id' => $subscription['plan_id'],
            'period_end' => $now,
        ]);
        if ($expire->rowCount() === 1) {
            $pdo->prepare("INSERT INTO subscription_events (subscription_id, user_id, event_type, previous_plan_id, new_plan_id) VALUES (:subscription_id, :user_id, 'subscription_expired', :previous_plan_id, :new_plan_id)")
                ->execute([
                    'subscription_id' => $subscription['id'],
                    'user_id' => $userId,
                    'previous_plan_id' => $subscription['plan_id'],
                    'new_plan_id' => $freePlanId,
                ]);
        }
        $statement->execute(['user_id' => $userId]);
        $subscription = $statement->fetch() ?: null;
    }

    return $subscription;
}

function subscriptionPlanCatalog(?PDO $pdo = null): array
{
    $catalog = [
        'free' => [
            'name' => 'Free',
            'invoice_limit' => 10,
            'customer_limit' => 25,
            'product_limit' => 25,
            'ai_limit' => 10,
            'quote_access' => false,
            'analytics' => false,
            'templates' => ['classic', 'minimal'],
            'price_xaf_monthly' => 0,
            'price_usd_monthly' => 0,
            'price_xaf_yearly' => null,
            'price_usd_yearly' => null,
        ],
        'pro' => [
            'name' => 'Pro',
            'invoice_limit' => 100,
            'customer_limit' => 500,
            'product_limit' => 500,
            'ai_limit' => 100,
            'quote_access' => true,
            'analytics' => true,
            'templates' => ['classic', 'modern', 'minimal', 'executive', 'creative', 'editorial', 'ledger', 'atelier', 'horizon', 'studio', 'cobalt', 'terra', 'noir', 'botanical', 'aurora', 'blueprint', 'sunrise', 'monochrome', 'coastal', 'gilded'],
            'price_xaf_monthly' => 1200,
            'price_usd_monthly' => 3,
            'price_xaf_yearly' => null,
            'price_usd_yearly' => null,
        ],
        'business' => [
            'name' => 'Business',
            'invoice_limit' => 500,
            'customer_limit' => 2500,
            'product_limit' => 2500,
            'ai_limit' => 500,
            'quote_access' => true,
            'analytics' => true,
            'templates' => ['classic', 'modern', 'minimal', 'executive', 'creative', 'editorial', 'ledger', 'atelier', 'horizon', 'studio', 'cobalt', 'terra', 'noir', 'botanical', 'aurora', 'blueprint', 'sunrise', 'monochrome', 'coastal', 'gilded'],
            'price_xaf_monthly' => null,
            'price_usd_monthly' => null,
            'price_xaf_yearly' => 5000,
            'price_usd_yearly' => 9,
        ],
    ];

    if ($pdo === null) {
        return $catalog;
    }

    $plans = $pdo->query('SELECT code, name, monthly_price, yearly_price, monthly_price_xaf, yearly_price_xaf, is_available FROM plans')->fetchAll();
    foreach ($plans as $plan) {
        $code = (string) $plan['code'];
        if (!isset($catalog[$code])) {
            continue;
        }
        $catalog[$code]['name'] = (string) $plan['name'];
        $catalog[$code]['price_usd_monthly'] = $plan['monthly_price'] === null ? null : (float) $plan['monthly_price'];
        $catalog[$code]['price_usd_yearly'] = $plan['yearly_price'] === null ? null : (float) $plan['yearly_price'];
        $catalog[$code]['price_xaf_monthly'] = $plan['monthly_price_xaf'] === null ? null : (float) $plan['monthly_price_xaf'];
        $catalog[$code]['price_xaf_yearly'] = $plan['yearly_price_xaf'] === null ? null : (float) $plan['yearly_price_xaf'];
        $catalog[$code]['is_available'] = (bool) $plan['is_available'];
    }

    return $catalog;
}

function isCameroonCountry(?string $country): bool
{
    $normalized = strtolower(trim((string) $country));

    return in_array($normalized, ['cameroon', 'cameroun', 'cm'], true);
}

function subscriptionCurrencyForCountry(?string $country): string
{
    return isCameroonCountry($country) ? 'XAF' : 'USD';
}

function subscriptionPrice(array $plan, string $cycle, string $currency): ?float
{
    if (!in_array($cycle, ['monthly', 'yearly'], true)) {
        return null;
    }
    $currency = strtoupper($currency);
    if (!in_array($currency, ['XAF', 'USD'], true)) {
        return null;
    }

    $key = 'price_' . strtolower($currency) . '_' . $cycle;
    $price = $plan[$key] ?? null;

    return $price === null ? null : (float) $price;
}

function subscriptionPaymentProviderConfigured(string $currency): bool
{
    $baseUrl = subscriptionPaymentSetting('APP_BASE_URL');
    $baseUrlParts = parse_url($baseUrl);
    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
        || $baseUrlParts === false || !isset($baseUrlParts['scheme'], $baseUrlParts['host'])
        || (strtolower($baseUrlParts['scheme']) !== 'https'
            && !in_array(strtolower($baseUrlParts['host']), ['localhost', '127.0.0.1'], true))) {
        return false;
    }
    if ($currency === 'XAF') {
        return in_array(strtolower(subscriptionPaymentSetting('FAPSHI_ENV')), ['sandbox', 'live', 'production'], true)
            && subscriptionPaymentSetting('FAPSHI_API_USER') !== ''
            && subscriptionPaymentSetting('FAPSHI_API_KEY') !== ''
            && subscriptionPaymentSetting('FAPSHI_WEBHOOK_SECRET') !== '';
    }
    if ($currency === 'USD') {
        return subscriptionPaymentSetting('NOWPAYMENTS_API_KEY') !== ''
            && subscriptionPaymentSetting('NOWPAYMENTS_IPN_SECRET') !== '';
    }

    return false;
}

function getPlanEntitlements(PDO $pdo, int $userId): array
{
    $subscription = getUserSubscription($pdo, $userId);
    $code = $subscription['plan_code'] ?? 'free';
    $catalog = subscriptionPlanCatalog($pdo);
    if (!isset($catalog[$code])) {
        throw new RuntimeException('The assigned subscription plan is not recognized.');
    }

    return ['subscription' => $subscription, 'plan' => $catalog[$code], 'plan_code' => $code];
}

function getPlanFeatureUsage(PDO $pdo, int $userId, int $businessId, string $feature): int
{
    $queries = [
        'invoices' => ['SELECT COUNT(*) FROM invoices i JOIN businesses b ON b.id = i.business_id WHERE b.user_id = :user_id AND i.created_at >= :period_start AND i.created_at < :period_end', true],
        'customers' => ['SELECT COUNT(*) FROM customers WHERE business_id = :business_id', false],
        'products' => ['SELECT COUNT(*) FROM products WHERE business_id = :business_id', false],
        'ai_requests' => ['SELECT usage_count FROM subscription_usage WHERE user_id = :user_id AND feature = :feature AND usage_month = :usage_month', false],
    ];
    if (!isset($queries[$feature])) {
        throw new InvalidArgumentException('The requested subscription feature is not supported.');
    }

    [$sql, $monthlyInvoices] = $queries[$feature];
    $statement = $pdo->prepare($sql);
    if ($monthlyInvoices) {
        $periodStart = gmdate('Y-m-01 00:00:00');
        $statement->execute([
            'user_id' => $userId,
            'period_start' => $periodStart,
            'period_end' => gmdate('Y-m-d H:i:s', strtotime($periodStart . ' +1 month')),
        ]);
    } elseif ($feature === 'customers' || $feature === 'products') {
        $statement->execute(['business_id' => $businessId]);
    } else {
        $statement->execute(['user_id' => $userId, 'feature' => $feature, 'usage_month' => gmdate('Y-m')]);
    }

    return (int) ($statement->fetchColumn() ?: 0);
}

function assertPlanLimit(PDO $pdo, int $userId, int $businessId, string $feature): void
{
    $entitlements = getPlanEntitlements($pdo, $userId);
    $limitKey = match ($feature) {
        'invoices' => 'invoice_limit',
        'customers' => 'customer_limit',
        'products' => 'product_limit',
        'ai_requests' => 'ai_limit',
        default => throw new InvalidArgumentException('The requested subscription feature is not supported.'),
    };
    $limit = (int) $entitlements['plan'][$limitKey];
    if (getPlanFeatureUsage($pdo, $userId, $businessId, $feature) >= $limit) {
        throw new InvalidArgumentException('You have reached the ' . number_format($limit) . ' ' . str_replace('_', ' ', $feature) . ' limit for the ' . $entitlements['plan']['name'] . ' plan. Upgrade to continue.');
    }
}

function assertPlanAllowsTemplate(PDO $pdo, int $userId, string $template): void
{
    $entitlements = getPlanEntitlements($pdo, $userId);
    if (!in_array($template, $entitlements['plan']['templates'], true)) {
        throw new InvalidArgumentException('That invoice design is available on Pro and Business plans. Upgrade to use all templates.');
    }
}

function assertPlanAllowsQuotations(PDO $pdo, int $userId): void
{
    $entitlements = getPlanEntitlements($pdo, $userId);
    if (!$entitlements['plan']['quote_access']) {
        throw new InvalidArgumentException('Quotations are available on Pro and Business plans. Upgrade to create quotations.');
    }
}

function getMonthlySubscriptionUsage(PDO $pdo, int $userId, string $feature, ?string $usageMonth = null): int
{
    if (!in_array($feature, ['ai_requests'], true)) {
        throw new InvalidArgumentException('The requested subscription usage type is not supported.');
    }
    $usageMonth = $usageMonth ?? gmdate('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $usageMonth)) {
        throw new InvalidArgumentException('A valid subscription usage month is required.');
    }

    $statement = $pdo->prepare('SELECT usage_count FROM subscription_usage WHERE user_id = :user_id AND feature = :feature AND usage_month = :usage_month');
    $statement->execute(['user_id' => $userId, 'feature' => $feature, 'usage_month' => $usageMonth]);

    return (int) ($statement->fetchColumn() ?: 0);
}

function consumeMonthlySubscriptionUsage(PDO $pdo, int $userId, string $feature, int $limit, ?string $usageMonth = null): bool
{
    if ($userId < 1 || $limit < 1 || !in_array($feature, ['ai_requests'], true)) {
        throw new InvalidArgumentException('A valid user, feature and usage limit are required.');
    }
    $usageMonth = $usageMonth ?? gmdate('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $usageMonth)) {
        throw new InvalidArgumentException('A valid subscription usage month is required.');
    }

    $sql = databaseDriver($pdo) === 'mysql'
        ? 'INSERT INTO subscription_usage (user_id, feature, usage_month, usage_count) VALUES (:user_id, :feature, :usage_month, 1) ON DUPLICATE KEY UPDATE updated_at = IF(usage_count < :update_limit, CURRENT_TIMESTAMP, updated_at), usage_count = IF(usage_count < :count_limit, usage_count + 1, usage_count)'
        : 'INSERT INTO subscription_usage (user_id, feature, usage_month, usage_count) VALUES (:user_id, :feature, :usage_month, 1) ON CONFLICT(user_id, feature, usage_month) DO UPDATE SET usage_count = usage_count + 1, updated_at = CURRENT_TIMESTAMP WHERE usage_count < :count_limit';
    $statement = $pdo->prepare($sql);
    $parameters = ['user_id' => $userId, 'feature' => $feature, 'usage_month' => $usageMonth, 'count_limit' => $limit];
    if (databaseDriver($pdo) === 'mysql') {
        $parameters['update_limit'] = $limit;
    }
    $statement->execute($parameters);

    return databaseDriver($pdo) === 'mysql' ? $statement->rowCount() > 0 : $statement->rowCount() === 1;
}

function countMonthlyUserInvoices(PDO $pdo, int $userId, ?string $monthStart = null): int
{
    $monthStart = $monthStart ?? gmdate('Y-m-01 00:00:00');
    $nextMonthStart = gmdate('Y-m-d H:i:s', strtotime($monthStart . ' +1 month'));
    $statement = $pdo->prepare('SELECT COUNT(*) FROM invoices i JOIN businesses b ON b.id = i.business_id WHERE b.user_id = :user_id AND i.created_at >= :month_start AND i.created_at < :next_month_start');
    $statement->execute(['user_id' => $userId, 'month_start' => $monthStart, 'next_month_start' => $nextMonthStart]);

    return (int) $statement->fetchColumn();
}

function seedDemoData(PDO $pdo): void
{
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount > 0) {
        return;
    }

    $passwordHash = password_hash('demo123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (name, email, password_hash) VALUES ('Demo User', 'demo@invoice.local', '{$passwordHash}');");

    $userId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO businesses (user_id, name, email, phone, address, city, country, currency, tax_id) VALUES ({$userId}, 'Northstar Studio', 'hello@northstarstudio.com', '+1 415 000 1000', '124 Market Street', 'San Francisco', 'United States', 'USD', 'US-456-982');");

    $businessId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO customers (business_id, name, company, email, phone, address, country, notes)
        VALUES ({$businessId}, 'Alicia Morgan', 'Bright Path Studio', 'alicia@brightpathstudio.com', '+44 203 000 4455', '28 Regent Street, London', 'United Kingdom', 'Prefers net 14');");

    $pdo->exec("INSERT INTO customers (business_id, name, company, email, phone, address, country, notes)
        VALUES ({$businessId}, 'Samuel Kofi', 'Kofi & Co. Agency', 'samuel@kofiagency.com', '+233 20 000 8976', '21 Accra High Street', 'Ghana', 'Recurring monthly retainer');");

    $pdo->exec("INSERT INTO products (business_id, name, description, sku, unit, price, currency, tax_rate)
        VALUES ({$businessId}, 'Website Design', 'Custom branded landing page design', 'WEB-001', 'hour', 75, 'USD', 10);");

    $pdo->exec("INSERT INTO products (business_id, name, description, sku, unit, price, currency, tax_rate)
        VALUES ({$businessId}, 'Brand Identity Kit', 'Logo, color system, and branding guidance', 'BRAND-01', 'project', 900, 'USD', 10);");

    $customerId = (int) $pdo->query("SELECT id FROM customers WHERE business_id = {$businessId} ORDER BY id ASC LIMIT 1")->fetchColumn();
    $issueDate = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime('+14 days'));

    $invoiceNumber = 'INV-1001';
    $pdo->exec("INSERT INTO invoices (business_id, customer_id, invoice_number, issue_date, due_date, currency, status, subtotal, discount, tax, total, notes, terms)
        VALUES ({$businessId}, {$customerId}, '{$invoiceNumber}', '{$issueDate}', '{$dueDate}', 'USD', 'Issued', 1425, 0, 142.5, 1567.5, 'Thank you for your partnership.', 'Payment due within 14 days.');");

    $invoiceId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount, tax_rate, line_total)
        VALUES ({$invoiceId}, 'Website Design', 3, 250, 0, 10, 825);");
    $pdo->exec("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount, tax_rate, line_total)
        VALUES ({$invoiceId}, 'SEO Copy Review', 1, 600, 0, 10, 600);");
}
