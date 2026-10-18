<?php
declare(strict_types=1);

/**
 * PDO singleton. Mất kết nối (MySQL khởi động lại, KILL, wait_timeout) thì tự nối lại
 * và chạy lại câu lệnh một lần, trừ khi đang trong transaction.
 */
final class Db
{
    private static array $cfg = [];
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    public static function configure(array $cfg): void
    {
        self::$cfg = $cfg;
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function name(): string
    {
        return (string) self::$cfg['name'];
    }

    public static function connect(bool $withDatabase = true): PDO
    {
        $c = self::$cfg;
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']);
        if ($withDatabase) {
            $dsn .= ';dbname=' . $c['name'];
        }
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        try {
            return self::execute($sql, $params);
        } catch (PDOException $e) {
            if (self::$txDepth > 0 || !self::isConnectionError($e)) {
                throw $e;
            }
            self::$pdo = null;
            return self::execute($sql, $params);
        }
    }

    private static function execute(string $sql, array $params): PDOStatement
    {
        // @: mysqlnd phát thêm warning "MySQL server has gone away" bên cạnh exception.
        $stmt = @self::pdo()->prepare($sql);
        @$stmt->execute(array_values($params));
        return $stmt;
    }

    public static function isConnectionError(Throwable $e): bool
    {
        $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        if (in_array($code, [2002, 2006, 2013], true)) {
            return true;
        }
        $msg = $e->getMessage();
        foreach (['gone away', 'Lost connection', 'Packets out of order', 'Error while sending', 'Broken pipe', 'Connection refused'] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public static function col(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, mixed> */
    public static function pairs(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** IN (?, ?, ?) cho mảng giá trị. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    public static function tx(callable $fn): mixed
    {
        Db::run('SELECT 1'); // nối lại trước khi BEGIN nếu kết nối đã chết
        $pdo = self::pdo();
        $pdo->beginTransaction();
        self::$txDepth++;
        try {
            $result = $fn();
            self::$txDepth--;
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            self::$txDepth = max(0, self::$txDepth - 1);
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }

    /** Thử kết nối tới khi được (worker gọi khi MySQL chưa bật). */
    public static function waitUntilReady(callable $log, int $retrySeconds = 10, ?callable $shouldStop = null): bool
    {
        $warned = false;
        while (true) {
            try {
                self::run('SELECT 1');
                if ($warned) {
                    $log('Đã kết nối lại DB');
                }
                return true;
            } catch (Throwable $e) {
                self::$pdo = null;
                if (!$warned) {
                    $log('Chưa kết nối được DB (' . $e->getMessage() . '), thử lại mỗi ' . $retrySeconds . 's');
                    $warned = true;
                }
                if ($shouldStop && $shouldStop()) {
                    return false;
                }
                sleep($retrySeconds);
            }
        }
    }
}
