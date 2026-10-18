<?php
declare(strict_types=1);

/**
 * Kho API key ScrapeCreators. Thứ tự dòng trong textarea = thứ tự ưu tiên.
 * Dùng cạn key ưu tiên cao nhất rồi mới sang key kế tiếp.
 */
final class ApiKeys
{
    /** @return array{added: int, removed: int, duplicates: int, errors: list<string>} */
    public static function syncFromText(string $text): array
    {
        $parsed = InputParser::parseKeyList($text);
        $now = Clock::str();
        $stats = ['added' => 0, 'removed' => 0, 'duplicates' => $parsed['duplicates'], 'errors' => $parsed['errors']];

        Db::tx(function () use ($parsed, $now, &$stats) {
            $existing = [];
            foreach (Db::all('SELECT id, api_key, in_list FROM api_keys') as $row) {
                $existing[$row['api_key']] = $row;
            }
            foreach ($parsed['keys'] as $i => $key) {
                if (isset($existing[$key])) {
                    Db::run('UPDATE api_keys SET in_list = 1, priority = ? WHERE id = ?', [$i, $existing[$key]['id']]);
                } else {
                    Db::run(
                        'INSERT INTO api_keys (api_key, priority, in_list, status, created_at) VALUES (?, ?, 1, \'active\', ?)',
                        [$key, $i, $now]
                    );
                    $stats['added']++;
                }
            }
            foreach ($existing as $key => $row) {
                if (!in_array($key, $parsed['keys'], true) && (int) $row['in_list']) {
                    Db::run('UPDATE api_keys SET in_list = 0 WHERE id = ?', [$row['id']]);
                    $stats['removed']++;
                }
            }
        });
        if (self::current()) {
            Settings::set('all_keys_exhausted_notified', '0');
        }
        return $stats;
    }

    public static function textareaValue(): string
    {
        return implode("\n", Db::col('SELECT api_key FROM api_keys WHERE in_list = 1 ORDER BY priority, id'));
    }

    public static function listed(): array
    {
        return Db::all('SELECT * FROM api_keys WHERE in_list = 1 ORDER BY priority, id');
    }

    /** Key active có ưu tiên cao nhất, hoặc null nếu không còn key nào dùng được. */
    public static function current(): ?array
    {
        return Db::one("SELECT * FROM api_keys WHERE in_list = 1 AND status = 'active' ORDER BY priority, id LIMIT 1");
    }

    public static function countListed(): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM api_keys WHERE in_list = 1');
    }

    public static function setStatus(int $id, string $status, ?string $error = null): void
    {
        Db::run(
            'UPDATE api_keys SET status = ?, last_error = ?, last_checked_at = ? WHERE id = ?',
            [$status, $error === null ? null : mb_substr($error, 0, 1000), Clock::str(), $id]
        );
    }

    /** Ghi nhận lần gọi thành công; credits_remaining <= 0 thì chủ động đánh dấu exhausted. */
    public static function recordUse(int $id, ?int $creditsRemaining): void
    {
        $status = ($creditsRemaining !== null && $creditsRemaining <= 0) ? 'exhausted' : 'active';
        Db::run(
            'UPDATE api_keys SET last_used_at = ?, credits_remaining = COALESCE(?, credits_remaining), status = ?,
                    last_error = IF(? = \'exhausted\', \'credits_remaining = 0\', last_error) WHERE id = ?',
            [Clock::str(), $creditsRemaining, $status, $status, $id]
        );
    }

    public static function setCredits(int $id, ?int $credits): void
    {
        Db::run('UPDATE api_keys SET credits_remaining = ?, last_checked_at = ? WHERE id = ?', [$credits, Clock::str(), $id]);
    }

    /** Tổng credit còn lại của các key active trong danh sách. */
    public static function totalCredits(): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(credits_remaining), 0) FROM api_keys WHERE in_list = 1 AND status = 'active'");
    }
}
