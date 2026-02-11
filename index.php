<?php

declare(strict_types=1);

session_start();

$config = [
    'enabled' => true,
    'default_host' => '127.0.0.1',
    'default_port' => 5236,
    'default_db' => 'DM8',
    'default_user' => 'SYSDBA',
    'default_password' => '',
    'default_schema' => '',
    'default_charset' => 'UTF-8',
    'data_charset' => '',
    'error_charset' => '',
    'output_charset' => 'UTF-8',
    'auto_schema' => true,
    'max_rows' => 200,
];

$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($configFile)) {
    $DM_CONFIG = [];
    require $configFile;
    if (is_array($DM_CONFIG)) {
        $config = array_merge($config, $DM_CONFIG);
    }
}
$GLOBALS['dm_charset_from'] = (string) ($config['data_charset'] ?? '');
$GLOBALS['dm_charset_to'] = (string) ($config['output_charset'] ?? 'UTF-8');

if (! $config['enabled']) {
    http_response_code(404);
    echo 'DM Admin is disabled.';
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dm_convert_string(string $value, string $from, string $to): string
{
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
        return $value;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, $to, $from);
    }
    if (function_exists('iconv')) {
        $converted = @iconv($from, $to . '//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }
    return $value;
}

function dm_detect_encoding(string $value, array $candidates): string
{
    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return 'UTF-8';
    }
    if (function_exists('mb_detect_encoding')) {
        $detected = mb_detect_encoding($value, $candidates, true);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }
    return '';
}

function dm_score_text(string $value): int
{
    $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $value, $matches);
    if ($cjk === false) {
        $cjk = 0;
    }
    $bad = substr_count($value, '�') + substr_count($value, '?');
    return (int) ($cjk * 2 - $bad);
}

function dm_convert_with_candidates(string $value, array $candidates, string $to): string
{
    $best = $value;
    $bestScore = dm_score_text($value);

    foreach ($candidates as $candidate) {
        if (strcasecmp($candidate, $to) === 0) {
            $converted = $value;
        } else {
            $converted = dm_convert_string($value, $candidate, $to);
        }
        $score = dm_score_text($converted);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $converted;
        }
    }

    return $best;
}

function dm_best_effort_convert(string $value, array $candidates, string $to): string
{
    $detected = dm_detect_encoding($value, $candidates);
    if ($detected !== '') {
        return dm_convert_string($value, $detected, $to);
    }
    foreach ($candidates as $candidate) {
        $converted = dm_convert_string($value, $candidate, $to);
        if ($converted !== '' && $converted !== $value) {
            return $converted;
        }
    }
    return $value;
}

function dm_normalize_rows(array $rows): array
{
    $from = (string) ($GLOBALS['dm_charset_from'] ?? '');
    $to = (string) ($GLOBALS['dm_charset_to'] ?? 'UTF-8');
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
        return $rows;
    }

    $convert = static function (&$value) use ($from, $to): void {
        if (is_string($value)) {
            $value = dm_convert_string($value, $from, $to);
        }
    };

    foreach ($rows as &$row) {
        if (is_array($row)) {
            array_walk_recursive($row, $convert);
        }
    }
    unset($row);

    return $rows;
}

function dm_prepare_input(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $from = (string) ($GLOBALS['dm_charset_to'] ?? 'UTF-8');
    $to = (string) ($GLOBALS['dm_charset_from'] ?? '');
    if ($to === '' || strcasecmp($from, $to) === 0) {
        return $value;
    }
    return dm_convert_string($value, $from, $to);
}

function dm_normalize_error(string $message): string
{
    if (function_exists('mb_check_encoding') && mb_check_encoding($message, 'UTF-8')) {
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $message) === 1) {
            return $message;
        }
    }

    $from = (string) ($GLOBALS['dm_error_charset'] ?? '');
    if ($from === '') {
        $from = (string) ($GLOBALS['dm_charset_from'] ?? '');
    }
    $to = (string) ($GLOBALS['dm_charset_to'] ?? 'UTF-8');
    $candidates = ['UTF-8', 'GB18030', 'GBK', 'GB2312', 'CP936', 'ISO-8859-1'];
    if ($from !== '' && ! in_array(strtoupper($from), $candidates, true)) {
        array_unshift($candidates, $from);
    }
    if ($from !== '' && $to !== '' && strcasecmp($from, $to) !== 0) {
        $primary = dm_convert_string($message, $from, $to);
        $best = dm_convert_with_candidates($message, $candidates, $to);
        return dm_score_text($primary) >= dm_score_text($best) ? $primary : $best;
    }
    return dm_convert_with_candidates($message, $candidates, $to);
}

function dm_apply_default_schema(string $sql, string $schema): array
{
    $schema = trim($schema);
    if ($schema === '') {
        return [$sql, false];
    }

    $schemaFormatted = strtoupper($schema);
    if (! preg_match('/^[A-Z0-9_$]+$/', $schemaFormatted)) {
        $schemaFormatted = '"' . str_replace('"', '""', $schema) . '"';
    }

    $patterns = [
        '/\b(FROM)\s+((?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+)(?:\.(?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+))?)/i',
        '/\b(UPDATE)\s+((?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+)(?:\.(?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+))?)/i',
        '/\b(INSERT\s+INTO)\s+((?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+)(?:\.(?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+))?)/i',
        '/\b(DELETE\s+FROM)\s+((?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+)(?:\.(?:`[^`]+`|"[^"]+"|[A-Z0-9_$]+))?)/i',
    ];

    foreach ($patterns as $pattern) {
        $replaced = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($schemaFormatted): string {
                $keyword = $matches[1];
                $table = $matches[2];
                if ($table === '' || $table[0] === '(') {
                    return $matches[0];
                }
                if (strpos($table, '.') !== false) {
                    return $matches[0];
                }
                if ($table[0] === '`') {
                    $table = '"' . str_replace('`', '""', trim($table, '`')) . '"';
                    $qualified = $schemaFormatted . '.' . $table;
                } elseif ($table[0] === '"') {
                    $qualified = $schemaFormatted . '.' . $table;
                } elseif (preg_match('/^[A-Z0-9_$]+$/i', $table)) {
                    $qualified = $schemaFormatted . '.' . strtoupper($table);
                } else {
                    $qualified = $schemaFormatted . '."' . str_replace('"', '""', $table) . '"';
                }
                return $keyword . ' ' . $qualified;
            },
            $sql,
            1,
            $count
        );
        if ($count > 0 && is_string($replaced) && $replaced !== $sql) {
            return [$replaced, true];
        }
    }

    return [$sql, false];
}

function dm_quote_identifier(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '""';
    }
    if ($trimmed[0] === '"' && substr($trimmed, -1) === '"') {
        return $trimmed;
    }
    if (preg_match('/^[A-Z0-9_$]+$/i', $trimmed)) {
        $trimmed = strtoupper($trimmed);
    }
    return '"' . str_replace('"', '""', $trimmed) . '"';
}

function dm_driver_available(): bool
{
    return in_array('dm', PDO::getAvailableDrivers(), true);
}

function dm_connect(array $conn): PDO
{
    $dsn = sprintf(
        'dm:host=%s;port=%d;dbname=%s',
        $conn['host'],
        (int) $conn['port'],
        $conn['db']
    );
    if (! empty($conn['charset'])) {
        $dsn .= ';charset=' . $conn['charset'];
    }

    $pdo = new PDO($dsn, $conn['user'], $conn['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function dm_current_schema(PDO $pdo): string
{
    try {
        $value = $pdo->query('SELECT USER FROM DUAL')->fetchColumn();
        if (is_string($value) && $value !== '') {
            return strtoupper($value);
        }
    } catch (Throwable $e) {
    }

    return '';
}

function dm_list_schemas(PDO $pdo): array
{
    try {
//        $rows = $pdo->query('SELECT USERNAME FROM ALL_USERS ORDER BY USERNAME')->fetchAll();
//        return array_map(static fn(array $row) => $row['USERNAME'], $rows);
        $rows = $pdo->query('SELECT DISTINCT OWNER FROM ALL_OBJECTS')->fetchAll();
        return array_map(static fn(array $row) => $row['OWNER'], $rows);
    } catch (Throwable $e) {
        $fallback = dm_current_schema($pdo);
        return $fallback !== '' ? [$fallback] : [];
    }
}

function dm_list_tables(PDO $pdo, string $schema): array
{
    try {
        $stmt = $pdo->prepare('SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = ? ORDER BY TABLE_NAME');
        $stmt->execute([strtoupper($schema)]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $row) => $row['TABLE_NAME'], $rows);
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query('SELECT TABLE_NAME FROM USER_TABLES ORDER BY TABLE_NAME')->fetchAll();
            return array_map(static fn(array $row) => $row['TABLE_NAME'], $rows);
        } catch (Throwable $e2) {
            return [];
        }
    }
}

function dm_table_primary_key(PDO $pdo, string $schema, string $table): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COL.COLUMN_NAME
         FROM ALL_CONSTRAINTS CON
         JOIN ALL_CONS_COLUMNS COL
           ON CON.OWNER = COL.OWNER
          AND CON.CONSTRAINT_NAME = COL.CONSTRAINT_NAME
         WHERE CON.CONSTRAINT_TYPE = \'P\'
           AND CON.OWNER = ?
           AND CON.TABLE_NAME = ?
         ORDER BY COL.POSITION'
    );
    $stmt->execute([strtoupper($schema),strtoupper($table)]);
    $row = $stmt->fetch();
    if (is_array($row) && isset($row['COLUMN_NAME'])) {
        return (string) $row['COLUMN_NAME'];
    }
    return null;
}

function dm_table_columns(PDO $pdo, string $schema, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE FROM ALL_TAB_COLUMNS
         WHERE OWNER = ? AND TABLE_NAME = ? ORDER BY COLUMN_ID'
    );
    $stmt->execute([strtoupper($schema), strtoupper($table)]);
    return $stmt->fetchAll();
}

function dm_table_rows(PDO $pdo, string $schema, string $table, int $limit, int $offset = 0): array
{
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $schema = strtoupper($schema);
    $table = strtoupper($table);

    if ($offset > 0) {
        $sql = sprintf(
            'SELECT * FROM "%s"."%s" OFFSET %d ROWS FETCH NEXT %d ROWS ONLY',
            $schema,
            $table,
            $offset,
            $limit
        );
    } else {
        $sql = sprintf('SELECT * FROM "%s"."%s" FETCH FIRST %d ROWS ONLY', $schema, $table, $limit);
    }
    try {
        $rows = $pdo->query($sql)->fetchAll();
        return dm_normalize_rows($rows);
    } catch (Throwable $e) {
        if ($offset > 0) {
            $fallbackSql = sprintf('SELECT * FROM "%s"."%s" LIMIT %d OFFSET %d', $schema, $table, $limit, $offset);
        } else {
            $fallbackSql = sprintf('SELECT * FROM "%s"."%s" LIMIT %d', $schema, $table, $limit);
        }
        $rows = $pdo->query($fallbackSql)->fetchAll();
        return dm_normalize_rows($rows);
    }
}

function dm_table_total_rows(PDO $pdo, string $schema, string $table): int
{
    $schema = strtoupper($schema);
    $table = strtoupper($table);
    $sql = sprintf('SELECT COUNT(*) FROM "%s"."%s"', $schema, $table);
    try {
        $value = $pdo->query($sql)->fetchColumn();
        return $value !== false ? (int) $value : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function dm_exec_sql(PDO $pdo, string $sql): array
{
    $result = [
        'type' => 'none',
        'rows' => [],
        'columns' => [],
        'affected' => 0,
        'row_count' => 0,
    ];

    $trimmed = ltrim($sql);
    $keyword = strtoupper(strtok($trimmed, " \t\n\r("));
    $isQuery = in_array($keyword, ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'EXPLAIN'], true);

    if ($isQuery) {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            $error = $pdo->errorInfo();
            throw new RuntimeException($error[2] ?? 'SQL query failed.');
        }
        $result['type'] = 'rows';
        $result['rows'] = dm_normalize_rows($stmt->fetchAll());
        $result['row_count'] = count($result['rows']);
        $result['columns'] = array_keys($result['rows'][0] ?? []);
        if ($result['columns'] === [] && $stmt->columnCount() > 0) {
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                if (is_array($meta) && isset($meta['name'])) {
                    $columns[] = $meta['name'];
                }
            }
            $result['columns'] = $columns;
        }
        return $result;
    }

    $result['type'] = 'affected';
    $affected = $pdo->exec($sql);
    if ($affected === false) {
        $error = $pdo->errorInfo();
        throw new RuntimeException($error[2] ?? 'SQL execution failed.');
    }
    $result['affected'] = $affected;
    return $result;
}

function dm_strip_sql_terminator(string $sql): string
{
    return rtrim($sql, " \t\n\r;");
}

function dm_count_sql_rows(PDO $pdo, string $sql): ?int
{
    $trimmed = dm_strip_sql_terminator($sql);
    $countSql = 'SELECT COUNT(*) FROM (' . $trimmed . ') t';
    try {
        $value = $pdo->query($countSql)->fetchColumn();
        return $value !== false ? (int) $value : 0;
    } catch (Throwable $e) {
        return null;
    }
}

function dm_exec_sql_page(PDO $pdo, string $sql, int $limit, int $offset): array
{
    $result = [
        'type' => 'rows',
        'rows' => [],
        'columns' => [],
        'affected' => 0,
        'row_count' => 0,
    ];

    $trimmed = dm_strip_sql_terminator($sql);
    $baseSql = 'SELECT * FROM (' . $trimmed . ') t';
    $limit = max(1, $limit);
    $offset = max(0, $offset);

    $pagedSql = sprintf('%s OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $baseSql, $offset, $limit);
    try {
        $stmt = $pdo->query($pagedSql);
    } catch (Throwable $e) {
        $pagedSql = sprintf('%s LIMIT %d OFFSET %d', $baseSql, $limit, $offset);
        $stmt = $pdo->query($pagedSql);
    }

    if ($stmt === false) {
        $error = $pdo->errorInfo();
        throw new RuntimeException($error[2] ?? 'SQL query failed.');
    }

    $result['rows'] = dm_normalize_rows($stmt->fetchAll());
    $result['row_count'] = count($result['rows']);
    $result['columns'] = array_keys($result['rows'][0] ?? []);
    if ($result['columns'] === [] && $stmt->columnCount() > 0) {
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            if (is_array($meta) && isset($meta['name'])) {
                $columns[] = $meta['name'];
            }
        }
        $result['columns'] = $columns;
    }

    return $result;
}

function dm_required_param(string $key, array $source, string $fallback = ''): string
{
    $value = $source[$key] ?? $fallback;
    return is_string($value) ? trim($value) : $fallback;
}

if (! dm_driver_available()) {
    http_response_code(500);
    echo 'PDO DM driver not available. Ensure pdo_dm is installed.';
    exit;
}

$error = '';
$message = '';
$notice = '';
$lastSql = $_SESSION['dm_last_sql'] ?? '';
$lastSqlAt = $_SESSION['dm_last_sql_at'] ?? '';
$sqlPageSize = (int) $config['max_rows'];
$sqlPage = max(1, (int) ($_GET['sql_page'] ?? 1));
$sqlTotalRows = 0;
$sqlTotalPages = 1;

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['dm_conn']);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $conn = [
        'host' => dm_required_param('host', $_POST, $config['default_host']),
        'port' => (int) dm_required_param('port', $_POST, (string) $config['default_port']),
        'db' => dm_required_param('db', $_POST, $config['default_db']),
        'user' => dm_required_param('user', $_POST, $config['default_user']),
        'pass' => dm_required_param('pass', $_POST, $config['default_password']),
        'schema' => dm_required_param('schema', $_POST, $config['default_schema']),
        'charset' => dm_required_param('charset', $_POST, $config['default_charset']),
    ];

    try {
        dm_connect($conn);
        $_SESSION['dm_conn'] = $conn;
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = dm_normalize_error($e->getMessage());
        if ($error !== '') {
            $error = '连接失败：' . $error;
        } else {
            $error = '连接失败。';
        }
    }
}

$conn = $_SESSION['dm_conn'] ?? null;
if (is_array($conn) && ! isset($conn['charset'])) {
    $conn['charset'] = $config['default_charset'];
}

if (! is_array($conn)) {
    if ($error === '' && isset($_SESSION['dm_login_error'])) {
        $error = (string) $_SESSION['dm_login_error'];
        unset($_SESSION['dm_login_error']);
    }
    $defaults = [
        'host' => $config['default_host'],
        'port' => $config['default_port'],
        'db' => $config['default_db'],
        'user' => $config['default_user'],
        'pass' => $config['default_password'],
        'schema' => $config['default_schema'],
        'charset' => $config['default_charset'],
    ];
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>phpDmAdmin Login</title>
        <style>
            body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; background: #f4f6f8; margin: 0; }
            .card { max-width: 520px; margin: 8vh auto; background: #fff; border-radius: 10px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
            h1 { font-size: 22px; margin: 0 0 16px; }
            label { display: block; font-size: 13px; margin-bottom: 6px; }
            input { width: 100%; padding: 10px 12px; border: 1px solid #ccd3da; border-radius: 6px; margin-bottom: 14px; }
            button { background: #1b6ec2; color: #fff; border: 0; padding: 10px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; }
            .error { color: #b00020; font-size: 13px; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>phpDmAdmin Login</h1>
            <?php if ($error !== ''): ?>
                <div class="error"><?php echo h($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="login" value="1">
                <label for="host">Host</label>
                <input id="host" name="host" value="<?php echo h($defaults['host']); ?>" required>
                <label for="port">Port</label>
                <input id="port" name="port" value="<?php echo h((string) $defaults['port']); ?>" required>
                <label for="db">Database</label>
                <input id="db" name="db" value="<?php echo h($defaults['db']); ?>" required>
                <label for="user">User</label>
                <input id="user" name="user" value="<?php echo h($defaults['user']); ?>" required>
                <label for="pass">Password</label>
                <input id="pass" name="pass" type="password" value="<?php echo h($defaults['pass']); ?>">
                <label for="schema">Default Schema (optional)</label>
                <input id="schema" name="schema" value="<?php echo h($defaults['schema']); ?>">
                <label for="charset">Client Charset</label>
                <input id="charset" name="charset" value="<?php echo h($defaults['charset']); ?>">
                <button type="submit">Connect</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = null;
try {
    $pdo = dm_connect($conn);
} catch (Throwable $e) {
    $error = dm_normalize_error($e->getMessage());
    if ($error !== '') {
        $error = '连接失败：' . $error;
    } else {
        $error = '连接失败。';
    }
    $_SESSION['dm_login_error'] = $error;
    unset($_SESSION['dm_conn']);
}

if ($pdo === null) {
    header('Location: index.php');
    exit;
}

$outputCharset = (string) ($GLOBALS['dm_charset_to'] ?? 'UTF-8');
header('Content-Type: text/html; charset=' . $outputCharset);

$currentSchema = dm_required_param('schema', $_GET, '');
if ($currentSchema === '') {
    $currentSchema = $conn['schema'] ?: dm_current_schema($pdo);
}

$selectedTable = dm_required_param('table', $_GET, '');
$sqlInput = '';
$sqlResult = null;
$runSql = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_row'])) {
    $table = dm_required_param('table_name', $_POST, '');
    $schema = dm_required_param('schema_name', $_POST, '');
    $keyCol = dm_required_param('key_col', $_POST, '');
    $keyVal = array_key_exists('key_val', $_POST) ? (string) $_POST['key_val'] : '';
    $col = dm_required_param('col', $_POST, '');
    $newVal = array_key_exists('new_val', $_POST) ? (string) $_POST['new_val'] : '';
    $setNull = isset($_POST['set_null']) && $_POST['set_null'] === '1';
    $setEmpty = isset($_POST['set_empty']) && $_POST['set_empty'] === '1';

    if ($table !== '' && $schema !== '' && $keyCol !== '' && $col !== '') {
        try {
            $tableSql = dm_quote_identifier($schema) . '.' . dm_quote_identifier($table);
            $colSql = dm_quote_identifier($col);
            $keySql = dm_quote_identifier($keyCol);

            if ($setNull) {
                $sql = sprintf('UPDATE %s SET %s = NULL WHERE %s = :key', $tableSql, $colSql, $keySql);
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['key' => dm_prepare_input($keyVal)]);
            } elseif ($setEmpty) {
                $sql = sprintf('UPDATE %s SET %s = :val WHERE %s = :key', $tableSql, $colSql, $keySql);
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'val' => '',
                    'key' => dm_prepare_input($keyVal),
                ]);
            } else {
                $sql = sprintf('UPDATE %s SET %s = :val WHERE %s = :key', $tableSql, $colSql, $keySql);
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'val' => dm_prepare_input($newVal),
                    'key' => dm_prepare_input($keyVal),
                ]);
            }
            $message = 'Update completed.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($e instanceof PDOException && isset($e->errorInfo[2])) {
                $error = trim($error . ' ' . $e->errorInfo[2]);
            }
            if ($error === '') {
                $error = 'Update failed.';
            }
            $error = dm_normalize_error($error);
        }
    } else {
        $error = 'Missing update parameters.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql'])) {
    $sqlInput = (string) ($_POST['sql_text'] ?? '');
    $lastSql = $sqlInput;
    $lastSqlAt = date('Y-m-d H:i:s');
    $_SESSION['dm_last_sql'] = $lastSql;
    $_SESSION['dm_last_sql_at'] = $lastSqlAt;
    $runSql = true;
    $sqlPage = 1;
}

$hasSqlPageRequest = $sqlPage > 1;
if (! $runSql && $hasSqlPageRequest && $lastSql !== '') {
    $sqlInput = (string) $lastSql;
    $runSql = true;
}

if ($runSql) {
    if ($sqlInput !== '') {
        try {
            $sqlToRun = $sqlInput;
            if (! empty($config['auto_schema'])) {
                [$sqlToRun, $changed] = dm_apply_default_schema($sqlToRun, $currentSchema);
                if ($changed) {
                    $notice = 'Applied default schema: ' . $currentSchema . '.';
                    $sqlInput = $sqlToRun;
                }
            }
            $sqlToRun = dm_prepare_input($sqlToRun);
            $trimmed = ltrim($sqlToRun);
            $keyword = strtoupper(strtok($trimmed, " \t\n\r("));
            $isQuery = in_array($keyword, ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'EXPLAIN'], true);
            if ($isQuery) {
                $count = dm_count_sql_rows($pdo, $sqlToRun);
                if ($count === null) {
                    $sqlResult = dm_exec_sql($pdo, $sqlToRun);
                    $sqlTotalRows = $sqlResult['row_count'];
                    $sqlTotalPages = 1;
                } else {
                    $sqlTotalRows = $count;
                    $sqlTotalPages = max(1, (int) ceil($sqlTotalRows / max(1, $sqlPageSize)));
                    if ($sqlPage > $sqlTotalPages) {
                        $sqlPage = $sqlTotalPages;
                    }
                    $offset = ($sqlPage - 1) * $sqlPageSize;
                    $sqlResult = dm_exec_sql_page($pdo, $sqlToRun, $sqlPageSize, $offset);
                }
                $message = sprintf('Query OK, %d rows returned.', $sqlTotalRows);
            } else {
                $sqlResult = dm_exec_sql($pdo, $sqlToRun);
                if ($sqlResult['type'] === 'affected') {
                    $message = sprintf('Query OK, %d rows affected.', $sqlResult['affected']);
                } else {
                    $message = 'Query OK.';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($e instanceof PDOException && isset($e->errorInfo[2])) {
                $error = trim($error . ' ' . $e->errorInfo[2]);
            }
            if ($error === '') {
                $error = 'SQL execution failed.';
            }
            $error = dm_normalize_error($error);
        }
    } else {
        $message = 'No SQL to run.';
    }
}
$schemas = dm_list_schemas($pdo);
if ($currentSchema === '' && $schemas !== []) {
    $currentSchema = $schemas[0];
}
$schemasUpper = array_map('strtoupper', $schemas);
foreach ([$currentSchema, $conn['schema'] ?? '', $config['default_schema'] ?? ''] as $schemaCandidate) {
    if ($schemaCandidate === '') {
        continue;
    }
    if (! in_array(strtoupper($schemaCandidate), $schemasUpper, true)) {
        $schemas[] = $schemaCandidate;
        $schemasUpper[] = strtoupper($schemaCandidate);
    }
}

$tables = $currentSchema !== '' ? dm_list_tables($pdo, $currentSchema) : [];
$columns = [];
$rows = [];
$primaryKey = null;
$tablePageSize = (int) $config['max_rows'];
$tablePage = 1;
$tableTotalRows = 0;
$tableTotalPages = 1;
if ($currentSchema !== '' && $selectedTable !== '') {
    try {
        $columns = dm_table_columns($pdo, $currentSchema, $selectedTable);
        $tablePage = max(1, (int) ($_GET['table_page'] ?? 1));
        $tableTotalRows = dm_table_total_rows($pdo, $currentSchema, $selectedTable);
        $tableTotalPages = max(1, (int) ceil($tableTotalRows / max(1, $tablePageSize)));
        if ($tablePage > $tableTotalPages) {
            $tablePage = $tableTotalPages;
        }
        $rows = dm_table_rows($pdo, $currentSchema, $selectedTable, $tablePageSize, ($tablePage - 1) * $tablePageSize);
        if ($tableTotalRows === 0 && $rows !== []) {
            $tableTotalRows = count($rows);
            $tableTotalPages = 1;
            $tablePage = 1;
        }
        $primaryKey = dm_table_primary_key($pdo, $currentSchema, $selectedTable);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>phpDmAdmin</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1f2328; overflow-x: hidden; }
        header { background: #1b6ec2; color: #fff; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; }
        .layout { display: grid; grid-template-columns: 260px 1fr; height: calc(100vh - 52px); }
        aside { background: #fff; border-right: 1px solid #e0e4e8; padding: 16px; overflow-y: auto; height: 100%; }
        main { padding: 20px; min-width: 0; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        .section { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); min-width: 0; }
        .list { list-style: none; padding: 0; margin: 0; }
        .list li { margin-bottom: 8px; }
        .list a { text-decoration: none; color: #1b6ec2; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #e6eef8; color: #1b6ec2; font-size: 12px; margin-left: 8px; }
        .toolbar { display: flex; gap: 12px; align-items: center; font-size: 13px; }
        textarea { width: 100%; min-height: 120px; resize: vertical; padding: 10px 12px; border-radius: 8px; border: 1px solid #ccd3da; font-family: Consolas, "Courier New", monospace; }
        button { background: #1b6ec2; color: #fff; border: 0; padding: 10px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .table-scroll { overflow-x: auto; max-width: 100%; display: block; }
        .column-filter { position: relative; display: inline-block; }
        .column-filter-panel { position: absolute; right: 0; top: 100%; margin-top: 6px; background: #fff; border: 1px solid #e0e4e8; border-radius: 8px; padding: 10px; box-shadow: 0 8px 20px rgba(0,0,0,0.12); width: 260px; max-height: 280px; overflow: auto; z-index: 20; }
        .column-filter-panel.hidden { display: none; }
        .column-filter-search { width: 80%; padding: 6px 28px 6px 8px; border: 1px solid #ccd3da; border-radius: 6px; font-size: 12px; }
        .column-filter-search-wrap { position: relative; margin-bottom: 8px; }
        .column-filter-search-clear { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; cursor: pointer; font-size: 14px; color: #6b7280; display: none; }
        .column-filter-search-clear.show { display: inline; }
        .column-filter-item { display: flex; align-items: center; gap: 6px; font-size: 12px; margin-bottom: 6px; }
        .column-filter-item button { background: none; border: 0; padding: 0; color: #1b6ec2; cursor: pointer; text-align: left; }
        .col-hidden { display: none; }
        .table-scroll > table { width: max-content; min-width: 100%; }
        th, td { border: 1px solid #e0e4e8; padding: 6px 8px; text-align: left; white-space: nowrap; }
        th { background: #f0f3f6; }
        .data-cell { cursor: pointer; }
        .data-cell:hover { background: #f8fafc; }
        .error { color: #b00020; margin-bottom: 12px; }
        .message { color: #0b7b25; margin-bottom: 12px; }
        .muted { color: #6b7280; font-size: 12px; }
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            aside { border-right: none; border-bottom: 1px solid #e0e4e8; }
        }
    </style>
    </head>
<body>
<header>
    <div>phpDmAdmin V1.0</div>
    <div class="toolbar">
        <div><?php echo h($conn['user']); ?>@<?php echo h($conn['host']); ?>:<?php echo h((string) $conn['port']); ?>/<?php echo h($conn['db']); ?></div>
        <a href="?action=logout" style="color:#fff;">Logout</a>
    </div>
</header>
<div class="layout">
    <aside>
        <h2>Schemas</h2>
        <ul class="list">
            <?php foreach ($schemas as $schema): ?>
                <li>
                    <a href="?schema=<?php echo h($schema); ?>"><?php echo h($schema); ?></a>
                    <?php if (strtoupper($schema) === strtoupper($currentSchema)): ?>
                        <span class="badge">current</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <h2 style="margin-top:20px;">Tables</h2>
        <div style="position:relative;width:90%;margin-bottom:10px;">
            <input id="table-filter" type="text" placeholder="Filter tables..." style="width:80%;padding:8px 28px 8px 10px;border:1px solid #ccd3da;border-radius:6px;">
            <button type="button" id="table-filter-clear" aria-label="Clear filter" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:transparent;cursor:pointer;font-size:14px;color:#6b7280;display:none;">×</button>
        </div>
        <ul class="list">
            <?php if ($currentSchema === ''): ?>
                <li class="muted">Select a schema to view tables.</li>
            <?php elseif ($tables === []): ?>
                <li class="muted">No tables found.</li>
            <?php else: ?>
                <?php foreach ($tables as $table): ?>
                    <li class="table-item" data-table="<?php echo h($table); ?>">
                        <a href="?schema=<?php echo h($currentSchema); ?>&table=<?php echo h($table); ?>"><?php echo h($table); ?></a>
                        <?php if (strcasecmp($table, $selectedTable) === 0): ?>
                            <span class="badge">current</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </aside>
    <main>
        <?php if ($error !== ''): ?>
            <div class="error"><?php echo h(dm_normalize_error($error)); ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="muted" style="margin-bottom:12px;"><?php echo h($notice); ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>
        <div class="section">
            <h2>SQL Console</h2>
            <div class="muted" style="margin-bottom:8px;">
                <?php if ($lastSqlAt !== ''): ?>
                    Last run at <?php echo h($lastSqlAt); ?>
                <?php else: ?>
                    No SQL run yet.
                <?php endif; ?>
            </div>
            <form method="post" action="?schema=<?php echo h($currentSchema); ?>&table=<?php echo h($selectedTable); ?>">
                <input type="hidden" name="sql" value="1">
                <textarea name="sql_text" placeholder="SELECT * FROM ..." style="width: 98%"><?php echo h($sqlInput); ?></textarea>
                <div style="margin-top:10px;">
                    <button type="submit">Run</button>
                    <span class="muted">Results are shown below.</span>
                </div>
            </form>
            <?php if (is_array($sqlResult) && $sqlResult['type'] === 'rows'): ?>
                <?php
                $sqlEditable = $currentSchema !== '' && $selectedTable !== '' && $primaryKey !== null
                    && in_array($primaryKey, $sqlResult['columns'], true);
                $sqlColumns = $sqlResult['columns'];
                $sqlNeedsPaging = $sqlTotalPages > 1;
                $sqlBaseParams = [
                    'schema' => $currentSchema,
                    'table' => $selectedTable,
                ];
                ?>
                <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                    <div class="column-filter" data-filter-root="sql">
                        <button type="button" class="column-filter-toggle">Filter Columns</button>
                        <div class="column-filter-panel hidden">
                            <div class="column-filter-search-wrap">
                                <input type="text" class="column-filter-search" placeholder="Search column...">
                                <button type="button" class="column-filter-search-clear" aria-label="Clear search">×</button>
                            </div>
                            <?php foreach ($sqlColumns as $columnName): ?>
                                <div class="column-filter-item">
                                    <input type="checkbox" class="column-filter-checkbox" data-col-name="<?php echo h((string) $columnName); ?>" checked>
                                    <button type="button" class="column-filter-jump" data-col-name="<?php echo h((string) $columnName); ?>">
                                        <?php echo h((string) $columnName); ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div style="margin-top:8px;" class="table-scroll" data-table-scroll="sql">
                    <?php if ($sqlResult['columns'] === []): ?>
                        <div class="muted">No columns returned.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                            <tr>
                                <?php foreach ($sqlColumns as $column): ?>
                                    <th data-col-name="<?php echo h((string) $column); ?>"><?php echo h((string) $column); ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($sqlResult['rows'] === []): ?>
                                <tr>
                                    <td colspan="<?php echo h((string) count($sqlResult['columns'])); ?>" class="muted">
                                        No rows returned.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sqlResult['rows'] as $row): ?>
                                    <?php
                                    $sqlRowEditable = $sqlEditable
                                        && is_array($row)
                                        && array_key_exists($primaryKey, $row)
                                        && $row[$primaryKey] !== null;
                                    $sqlKeyVal = $sqlRowEditable ? (string) $row[$primaryKey] : '';
                                    ?>
                                    <tr>
                                        <?php foreach ($sqlColumns as $column): ?>
                                            <?php
                                            $cell = $row[$column] ?? null;
                                            if ($cell === null) {
                                                $cellDisplay = '<span class="muted">NULL</span>';
                                                $cellTitleAttr = '';
                                            } elseif ($cell === '') {
                                                $cellDisplay = '<span class="muted">(empty)</span>';
                                                $cellTitleAttr = '';
                                            } else {
                                                $cellString = (string) $cell;
                                                if (function_exists('mb_strlen')) {
                                                    $cellLen = mb_strlen($cellString);
                                                } else {
                                                    $cellLen = strlen($cellString);
                                                }
                                                if ($cellLen > 100) {
                                                    if (function_exists('mb_substr')) {
                                                        $preview = mb_substr($cellString, 0, 30);
                                                    } else {
                                                        $preview = substr($cellString, 0, 30);
                                                    }
                                                    $cellDisplay = h($preview) . '...';
                                                    $cellTitleAttr = ' title="' . h($cellString) . '"';
                                                } else {
                                                    $cellDisplay = h($cellString);
                                                    $cellTitleAttr = '';
                                                }
                                            }
                                            ?>
                                            <?php if ($sqlRowEditable): ?>
                                                <td
                                                    class="data-cell"
                                                    data-col-name="<?php echo h((string) $column); ?>"
                                                    data-col="<?php echo h((string) $column); ?>"
                                                    data-val="<?php echo h((string) $cell); ?>"
                                                    data-key-col="<?php echo h((string) $primaryKey); ?>"
                                                    data-key-val="<?php echo h($sqlKeyVal); ?>"
                                                    data-schema="<?php echo h($currentSchema); ?>"
                                                    data-table="<?php echo h($selectedTable); ?>"
                                                    <?php echo $cellTitleAttr; ?>
                                                ><?php echo $cellDisplay; ?></td>
                                            <?php else: ?>
                                                <td data-col-name="<?php echo h((string) $column); ?>"<?php echo $cellTitleAttr; ?>><?php echo $cellDisplay; ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <?php
                $sqlStartRow = $sqlTotalRows > 0 ? (($sqlPage - 1) * $sqlPageSize + 1) : 0;
                $sqlEndRow = $sqlTotalRows > 0 ? min($sqlPage * $sqlPageSize, $sqlTotalRows) : 0;
                ?>
                <div class="muted" style="margin-top:8px;float: left">
                    Total rows: <?php echo h((string) $sqlTotalRows); ?>
                    <?php if ($sqlTotalRows > 0): ?>
                        , Showing rows <?php echo h((string) $sqlStartRow); ?>-<?php echo h((string) $sqlEndRow); ?>, Page <?php echo h((string) $sqlPage); ?> / <?php echo h((string) $sqlTotalPages);?>
                    <?php endif; ?>
                </div>
                <?php if ($sqlNeedsPaging): ?>
                    <div class="muted" style="margin-top:8px;float: right" data-sql-paging>
                        <form method="get" style="display:flex;justify-content:flex-end;gap:10px;align-items:center;">
                            <input type="hidden" name="schema" value="<?php echo h((string) $currentSchema); ?>">
                            <input type="hidden" name="table" value="<?php echo h((string) $selectedTable); ?>">
                            <?php if ($sqlPage > 1): ?>
                                <?php
                                $prevSqlUrl = 'index.php?' . http_build_query(array_merge($sqlBaseParams, ['sql_page' => $sqlPage - 1]));
                                ?>
                                <a href="<?php echo h($prevSqlUrl); ?>">Prev</a>
                            <?php endif; ?>
                            <input type="text" name="sql_page" value="<?php echo h((string) $sqlPage); ?>" style="width:56px;padding:4px 6px;border:1px solid #ccd3da;border-radius:6px;text-align:center;">
                            <span> / <?php echo h((string) $sqlTotalPages); ?></span>
                            <?php if ($sqlPage < $sqlTotalPages): ?>
                                <?php
                                $nextSqlUrl = 'index.php?' . http_build_query(array_merge($sqlBaseParams, ['sql_page' => $sqlPage + 1]));
                                ?>
                                <a href="<?php echo h($nextSqlUrl); ?>">Next</a>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>
                <div style="clear:both;"></div>
            <?php endif; ?>
        </div>

        <?php if (! $runSql): ?>
        <div class="section">
            <h2>Table View</h2>
            <?php if ($currentSchema === '' || $selectedTable === ''): ?>
                <div class="muted">Select a table to view structure and rows.</div>
            <?php else: ?>
                <?php
                $tableStartRow = $tableTotalRows > 0 ? (($tablePage - 1) * $tablePageSize + 1) : 0;
                $tableEndRow = $tableTotalRows > 0 ? min($tablePage * $tablePageSize, $tableTotalRows) : 0;
                ?>
                <h3 style="margin:12px 0 8px;"><?php echo h($currentSchema); ?>.<?php echo h($selectedTable); ?></h3>
                <?php if ($columns !== []): ?>
                    <button type="button" id="toggle-structure" style="margin:8px 0 12px;">Show Structure</button>
                    <div id="table-structure" style="margin-bottom: 12px; overflow:auto; display:none;">
                        <table>
                            <thead>
                            <tr>
                                <th>Column</th>
                                <th>Type</th>
                                <th>Length</th>
                                <th>Nullable</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($columns as $col): ?>
                                <tr>
                                    <td><?php echo h((string) $col['COLUMN_NAME']); ?></td>
                                    <td><?php echo h((string) $col['DATA_TYPE']); ?></td>
                                    <td><?php echo h((string) $col['DATA_LENGTH']); ?></td>
                                    <td><?php echo h((string) $col['NULLABLE']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($rows !== []): ?>
                    <?php if ($primaryKey === null): ?>
                        <div class="muted" style="margin-bottom:10px;">Row editing is disabled (no primary key detected).</div>
                    <?php endif; ?>
                    <?php
                    $columnNames = array_keys($rows[0]);
                    $tableHasPaging = $tableTotalRows > $tablePageSize;
                    $tableBaseParams = [
                        'schema' => $currentSchema,
                        'table' => $selectedTable,
                    ];
                    ?>
                    <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
                        <div class="column-filter" data-filter-root="table">
                            <button type="button" class="column-filter-toggle">Filter Columns</button>
                            <div class="column-filter-panel hidden">
                                <div class="column-filter-search-wrap">
                                    <input type="text" class="column-filter-search" placeholder="Search column...">
                                    <button type="button" class="column-filter-search-clear" aria-label="Clear search">×</button>
                                </div>
                                <?php foreach ($columnNames as $columnName): ?>
                                    <div class="column-filter-item">
                                        <input type="checkbox" class="column-filter-checkbox" data-col-name="<?php echo h((string) $columnName); ?>" checked>
                                        <button type="button" class="column-filter-jump" data-col-name="<?php echo h((string) $columnName); ?>">
                                            <?php echo h((string) $columnName); ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="table-scroll" data-table-scroll="table">
                        <table>
                            <thead>
                            <tr>
                                <?php foreach ($columnNames as $colName): ?>
                                    <th data-col-name="<?php echo h((string) $colName); ?>"><?php echo h((string) $colName); ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $colName => $cell): ?>
                                        <?php
                                        if ($cell === null) {
                                            $cellDisplay = '<span class="muted">NULL</span>';
                                            $cellTitleAttr = '';
                                        } elseif ($cell === '') {
                                            $cellDisplay = '<span class="muted">(empty)</span>';
                                            $cellTitleAttr = '';
                                        } else {
                                            $cellString = (string) $cell;
                                            if (function_exists('mb_strlen')) {
                                                $cellLen = mb_strlen($cellString);
                                            } else {
                                                $cellLen = strlen($cellString);
                                            }
                                            if ($cellLen > 100) {
                                                if (function_exists('mb_substr')) {
                                                    $preview = mb_substr($cellString, 0, 30);
                                                } else {
                                                    $preview = substr($cellString, 0, 30);
                                                }
                                                $cellDisplay = h($preview) . '...';
                                                $cellTitleAttr = ' title="' . h($cellString) . '"';
                                            } else {
                                                $cellDisplay = h($cellString);
                                                $cellTitleAttr = '';
                                            }
                                        }
                                        ?>
                                        <td
                                            class="data-cell"
                                            data-col-name="<?php echo h((string) $colName); ?>"
                                            data-col="<?php echo h((string) $colName); ?>"
                                            data-val="<?php echo h((string) $cell); ?>"
                                            data-key-col="<?php echo h((string) ($primaryKey ?? '')); ?>"
                                            data-key-val="<?php echo h((string) ($primaryKey !== null ? ($row[$primaryKey] ?? '') : '')); ?>"
                                            data-schema="<?php echo h($currentSchema); ?>"
                                            data-table="<?php echo h($selectedTable); ?>"
                                            <?php echo $cellTitleAttr; ?>
                                        ><?php echo $cellDisplay; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="muted" style="margin:8px 0;float:left">
                        Total rows: <?php echo h((string) $tableTotalRows); ?>
                        <?php if ($tableHasPaging): ?>
                            , Showing rows <?php echo h((string) $tableStartRow); ?>-<?php echo h((string) $tableEndRow); ?>, Page <?php echo h((string) $tablePage); ?> / <?php echo h((string) $tableTotalPages); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($tableHasPaging): ?>
                        <div class="muted" style="margin-top:8px;float: right">
                        <form method="get" style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:8px;">
                            <input type="hidden" name="schema" value="<?php echo h((string) $currentSchema); ?>">
                            <input type="hidden" name="table" value="<?php echo h((string) $selectedTable); ?>">
                            <?php if ($tablePage > 1): ?>
                                <?php
                                $prevPage = $tablePage - 1;
                                ?>
                                <a href="<?php echo h('index.php?' . http_build_query(array_merge($tableBaseParams, ['table_page' => $prevPage]))); ?>">Prev</a>
                            <?php endif; ?>
                            <input type="text" name="table_page" value="<?php echo h((string) $tablePage); ?>" style="width:50px;padding:4px 6px;border:1px solid #ccd3da;border-radius:6px;text-align:center;"><span> / <?=$tableTotalPages?></span>
                            <?php if ($tablePage < $tableTotalPages): ?>
                                <?php
                                $nextPage = $tablePage + 1;
                                ?>
                                <a href="<?php echo h('index.php?' . http_build_query(array_merge($tableBaseParams, ['table_page' => $nextPage]))); ?>">Next</a>
                            <?php endif; ?>
                        </form>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="muted" style="margin-top: 12px;">No rows to display.</div>
                <?php endif; ?>
                <div style="clear:both;"></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
<div id="edit-modal" style="display:none;position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;z-index:9999;">
    <div style="background:#fff;border-radius:10px;padding:18px;width:420px;max-width:90%;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        <div style="font-weight:600;margin-bottom:10px;">Edit Cell</div>
        <form method="post" id="edit-form">
            <input type="hidden" name="update_row" value="1">
            <input type="hidden" name="schema_name" id="edit-schema">
            <input type="hidden" name="table_name" id="edit-table">
            <input type="hidden" name="key_col" id="edit-key-col">
            <input type="hidden" name="key_val" id="edit-key-val">
            <input type="hidden" name="col" id="edit-col">
            <div class="muted" style="margin-bottom:8px;" id="edit-caption"></div>
            <label for="edit-val" style="display:block;font-size:13px;margin-bottom:6px;">New Value</label>
            <textarea id="edit-val" name="new_val" rows="1" style="width:90%;padding:8px 10px;border:1px solid #ccd3da;border-radius:6px;margin-bottom:10px;resize:vertical;font-family:Consolas, \"Courier New\", monospace;"></textarea>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:10px;">
                <input type="checkbox" id="edit-null" name="set_null" value="1">
                Set NULL
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:10px;">
                <input type="checkbox" id="edit-empty" name="set_empty" value="1">
                Set empty
            </label>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" id="edit-cancel" style="background:#cbd5e1;color:#1f2328;">Cancel</button>
                <button type="submit">Save</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
<script>
    (function () {
        var input = document.getElementById('table-filter');
        var clearButton = document.getElementById('table-filter-clear');
        if (!input) {
            return;
        }
        var items = document.querySelectorAll('.table-item');
        function updateClear() {
            if (!clearButton) {
                return;
            }
            if (input.value.trim() === '') {
                clearButton.style.display = 'none';
            } else {
                clearButton.style.display = 'inline';
            }
        }
        function runFilter() {
            var query = input.value.toLowerCase();
            items.forEach(function (item) {
                var name = (item.getAttribute('data-table') || '').toLowerCase();
                item.style.display = name.indexOf(query) !== -1 ? '' : 'none';
            });
            updateClear();
        }
        input.addEventListener('input', runFilter);
        if (clearButton) {
            clearButton.addEventListener('click', function () {
                input.value = '';
                runFilter();
                input.focus();
            });
        }
        updateClear();
    })();
</script>
<script>
    (function () {
        var button = document.getElementById('toggle-structure');
        var panel = document.getElementById('table-structure');
        if (!button || !panel) {
            return;
        }
        button.addEventListener('click', function () {
            var isHidden = panel.style.display === 'none';
            panel.style.display = isHidden ? 'block' : 'none';
            button.textContent = isHidden ? 'Hide Structure' : 'Show Structure';
        });
    })();
</script>
<script>
    (function () {
        function getSelector(name) {
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return '[data-col-name="' + CSS.escape(name) + '"]';
            }
            return '[data-col-name="' + name.replace(/"/g, '\\"') + '"]';
        }

        function applyVisibility(tableScroll, name, visible) {
            var nodes = tableScroll.querySelectorAll(getSelector(name));
            nodes.forEach(function (node) {
                if (visible) {
                    node.classList.remove('col-hidden');
                } else {
                    node.classList.add('col-hidden');
                }
            });
        }

        function scrollToColumn(tableScroll, name) {
            var header = tableScroll.querySelector('th' + getSelector(name));
            if (!header) {
                return;
            }
            tableScroll.scrollLeft = header.offsetLeft - 10;
        }

        document.querySelectorAll('.column-filter').forEach(function (filterRoot) {
            var key = filterRoot.getAttribute('data-filter-root') || '';
            var panel = filterRoot.querySelector('.column-filter-panel');
            var toggle = filterRoot.querySelector('.column-filter-toggle');
            var search = filterRoot.querySelector('.column-filter-search');
            var tableScroll = key !== '' ? document.querySelector('[data-table-scroll="' + key + '"]') : null;

            if (!panel || !toggle || !tableScroll) {
                return;
            }

            toggle.addEventListener('click', function () {
                panel.classList.toggle('hidden');
            });

            document.addEventListener('click', function (event) {
                if (panel.classList.contains('hidden')) {
                    return;
                }
                if (panel.contains(event.target) || toggle.contains(event.target)) {
                    return;
                }
                panel.classList.add('hidden');
            });

            panel.querySelectorAll('.column-filter-checkbox').forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    var name = checkbox.getAttribute('data-col-name') || '';
                    if (name === '') {
                        return;
                    }
                    applyVisibility(tableScroll, name, checkbox.checked);
                });
            });

            panel.querySelectorAll('.column-filter-jump').forEach(function (button) {
                button.addEventListener('click', function () {
                    var name = button.getAttribute('data-col-name') || '';
                    if (name === '') {
                        return;
                    }
                    scrollToColumn(tableScroll, name);
                });
            });

            if (search) {
                var clearButton = filterRoot.querySelector('.column-filter-search-clear');
                var updateClear = function () {
                    if (!clearButton) {
                        return;
                    }
                    if (search.value.trim() === '') {
                        clearButton.classList.remove('show');
                    } else {
                        clearButton.classList.add('show');
                    }
                };
                var runFilter = function () {
                    var query = search.value.toLowerCase().trim();
                    var firstMatch = '';
                    panel.querySelectorAll('.column-filter-item').forEach(function (item) {
                        var button = item.querySelector('.column-filter-jump');
                        var name = button ? (button.getAttribute('data-col-name') || '') : '';
                        var match = query === '' || name.toLowerCase().indexOf(query) !== -1;
                        item.style.display = match ? '' : 'none';
                        if (match && firstMatch === '') {
                            firstMatch = name;
                        }
                    });

                    if (firstMatch !== '') {
                        var checkbox = panel.querySelector('.column-filter-checkbox' + getSelector(firstMatch));
                        if (checkbox && !checkbox.checked) {
                            checkbox.checked = true;
                            applyVisibility(tableScroll, firstMatch, true);
                        }
                        scrollToColumn(tableScroll, firstMatch);
                    }
                    updateClear();
                };
                search.addEventListener('input', runFilter);
                if (clearButton) {
                    clearButton.addEventListener('click', function () {
                        search.value = '';
                        runFilter();
                        search.focus();
                    });
                }
                updateClear();
            }
        });
    })();
</script>
<script>
    (function () {
        var modal = document.getElementById('edit-modal');
        if (!modal) {
            return;
        }
        var caption = document.getElementById('edit-caption');
        var form = document.getElementById('edit-form');
        var input = document.getElementById('edit-val');
        var setNull = document.getElementById('edit-null');
        var setEmpty = document.getElementById('edit-empty');
        var cancel = document.getElementById('edit-cancel');

        function openModal(cell) {
            var keyCol = cell.getAttribute('data-key-col') || '';
            var keyVal = cell.getAttribute('data-key-val') || '';
            if (keyCol === '' || keyVal === '') {
                return;
            }
            var schema = cell.getAttribute('data-schema') || '';
            var table = cell.getAttribute('data-table') || '';
            document.getElementById('edit-schema').value = schema;
            document.getElementById('edit-table').value = table;
            document.getElementById('edit-key-col').value = keyCol;
            document.getElementById('edit-key-val').value = keyVal;
            document.getElementById('edit-col').value = cell.getAttribute('data-col') || '';
            var rawValue = cell.getAttribute('data-val') || '';
            input.value = rawValue;
            setNull.checked = false;
            setEmpty.checked = false;
            input.disabled = false;
            if (rawValue.length > 100) {
                input.rows = 6;
            } else {
                input.rows = 1;
            }
            caption.textContent = table + ' / ' + (cell.getAttribute('data-col') || '');
            if (schema !== '' || table !== '') {
                form.action = '?schema=' + encodeURIComponent(schema) + '&table=' + encodeURIComponent(table);
            }
            modal.style.display = 'flex';
            input.focus();
        }

        document.querySelectorAll('.data-cell').forEach(function (cell) {
            cell.addEventListener('click', function () {
                openModal(cell);
            });
        });

        cancel.addEventListener('click', function () {
            modal.style.display = 'none';
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
        setNull.addEventListener('change', function () {
            if (setNull.checked) {
                setEmpty.checked = false;
            }
            input.disabled = setNull.checked || setEmpty.checked;
        });
        setEmpty.addEventListener('change', function () {
            if (setEmpty.checked) {
                setNull.checked = false;
                input.value = '';
            }
            input.disabled = setNull.checked || setEmpty.checked;
        });
        form.addEventListener('submit', function () {
            modal.style.display = 'none';
        });
    })();
</script>
