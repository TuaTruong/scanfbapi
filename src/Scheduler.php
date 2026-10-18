<?php
declare(strict_types=1);

/** Khung giờ, thời gian nghỉ, đếm đợt và chọn group đến hạn (mục 6.2 kế hoạch). */
final class Scheduler
{
    public function __construct(
        private readonly GroupScanner $scanner,
        private readonly ?Closure $log = null,
    ) {
    }

    /** Khung giờ hỗ trợ qua nửa đêm (20:00 → 02:00). Từ = Đến nghĩa là cả ngày. */
    public static function inWindow(string $start, string $end, DateTimeInterface $t): bool
    {
        $s = Estimator::minutesOfDay($start);
        $e = Estimator::minutesOfDay($end);
        $m = (int) $t->format('G') * 60 + (int) $t->format('i');
        if ($s === $e) {
            return true;
        }
        return $s < $e ? ($m >= $s && $m < $e) : ($m >= $s || $m < $e);
    }

    /**
     * Một bước của vòng lặp scanner. Chạy đợt quét nếu đến lúc.
     * @return string disabled | outside_window | wait | no_keys | run | run_now
     */
    public function tick(): string
    {
        Settings::reload();
        if (!Settings::bool('scan_enabled')) {
            return 'disabled';
        }
        $runNow = Settings::bool('run_now');
        if (!$runNow) {
            if (!self::inWindow((string) Settings::get('window_start'), (string) Settings::get('window_end'), Clock::now())) {
                return 'outside_window';
            }
            $next = Clock::parse(Settings::get('next_run_at'));
            if ($next && Clock::now() < $next) {
                return 'wait';
            }
        }
        $requester = null; // chat gửi /scan, nhận kết quả khi quét xong
        if ($runNow) {
            Settings::set('run_now', '0');
            $requester = (int) Settings::get('run_now_chat_id') ?: null;
            Settings::set('run_now_chat_id', '');
        }
        if (ApiKeys::current() === null) {
            if (ApiKeys::countListed() > 0) {
                Alerts::allKeysExhausted();
            }
            if ($requester) {
                Outbox::enqueueAlertTo($requester, '⚠️ Không quét được: không còn API key nào dùng được.');
            }
            return 'no_keys';
        }
        $sum = $this->runCycle();
        if ($requester) {
            Outbox::enqueueAlertTo($requester, self::summaryText($sum));
        }
        return $runNow ? 'run_now' : 'run';
    }

    public static function summaryText(array $sum): string
    {
        $text = sprintf(
            "✅ Quét xong đợt #%d: %d/%d group, %d bài mới (%d gửi, %d của seller, %d trùng nội dung bài trước), %d credit.",
            $sum['cycle_no'], $sum['groups_scanned'], $sum['groups_due'], $sum['new_posts'],
            $sum['queued_posts'], $sum['skipped_seller'], $sum['duplicate'], $sum['credits_used']
        );
        if ($sum['errors']) {
            $text .= "\n❌ {$sum['errors']} group lỗi, xem trang Nhật ký trên web.";
        }
        if ($sum['exhausted']) {
            $text .= "\n⚠️ Hết API key dùng được giữa chừng.";
        }
        return $text;
    }

    /** Group đến hạn ở đợt $cycleNo. */
    public function dueGroups(int $cycleNo): array
    {
        return Db::all(
            'SELECT * FROM fb_groups
             WHERE in_list = 1 AND enabled = 1
               AND (last_scanned_cycle IS NULL OR ? - last_scanned_cycle >= every_n_cycles)
               AND (paused_until_cycle IS NULL OR ? > paused_until_cycle)
             ORDER BY sort_order, id',
            [$cycleNo, $cycleNo]
        );
    }

    /** Chạy một đợt quét; next_run_at = lúc KẾT THÚC đợt + thời gian nghỉ. */
    public function runCycle(): array
    {
        $cycle = Settings::int('cycle_no') + 1;
        Settings::set('cycle_no', $cycle);
        $runId = Db::insert('INSERT INTO scan_runs (cycle_no, started_at) VALUES (?, ?)', [$cycle, Clock::str()]);
        $due = $this->dueGroups($cycle);
        $sum = ['run_id' => $runId, 'cycle_no' => $cycle, 'groups_due' => count($due), 'groups_scanned' => 0,
            'api_calls' => 0, 'credits_used' => 0, 'new_posts' => 0, 'queued_posts' => 0, 'skipped_seller' => 0,
            'duplicate' => 0, 'errors' => 0, 'exhausted' => false];
        $this->log("Đợt #$cycle bắt đầu: {$sum['groups_due']} group đến hạn");

        foreach ($due as $group) {
            // Đọc lại: một đợt nhiều group kéo dài vài phút, group có thể vừa bị xoá/tắt trên web.
            $group = Groups::find((int) $group['id']);
            if (!$group || !(int) $group['in_list'] || !(int) $group['enabled']) {
                continue;
            }
            try {
                $r = $this->scanner->scan($group, $cycle, $runId);
            } catch (AllKeysExhaustedException) {
                $sum['exhausted'] = true;
                $this->log('Hết API key dùng được, dừng đợt');
                break;
            }
            $sum['groups_scanned']++;
            $sum['new_posts'] += $r['new_posts'];
            $sum['queued_posts'] += $r['queued'];
            $sum['skipped_seller'] += $r['skipped_seller'];
            $sum['duplicate'] += $r['duplicate'];
            if ($r['error'] !== null) {
                $sum['errors']++;
            }
            $this->log(sprintf(
                '  %s: %d trang, %d bài mới (%d gửi, %d seller, %d trùng nội dung)%s%s%s',
                Groups::displayName($group), $r['pages'], $r['new_posts'], $r['queued'], $r['skipped_seller'], $r['duplicate'],
                $r['stale'] ? ", bỏ qua {$r['stale']} bài quá cũ" : '',
                $r['cap_hit'] ? ', ⚠️ chạm trần số trang' : '',
                $r['error'] !== null ? ', LỖI: ' . $r['error'] : ''
            ));
        }

        $calls = Db::one(
            "SELECT COUNT(*) AS calls, COALESCE(SUM(credits_charged), 0) AS credits FROM api_calls WHERE run_id = ? AND endpoint = 'group_posts'",
            [$runId]
        );
        $sum['api_calls'] = (int) $calls['calls'];
        $sum['credits_used'] = (int) $calls['credits'];
        $ended = Clock::now();
        Db::run(
            'UPDATE scan_runs SET ended_at = ?, groups_due = ?, groups_scanned = ?, api_calls = ?, credits_used = ?,
                    new_posts = ?, queued_posts = ?, skipped_seller = ?, errors = ?, note = ? WHERE id = ?',
            [Clock::str($ended), $sum['groups_due'], $sum['groups_scanned'], $sum['api_calls'], $sum['credits_used'],
                $sum['new_posts'], $sum['queued_posts'], $sum['skipped_seller'], $sum['errors'],
                $sum['exhausted'] ? 'Hết API key dùng được' : null, $runId]
        );

        Settings::reload();
        $rest = max(0, Settings::int('rest_minutes'));
        Settings::set('next_run_at', Clock::str($ended->modify("+$rest minutes")));

        if ($sum['exhausted']) {
            Alerts::allKeysExhausted();
        } elseif ($sum['groups_scanned'] >= 2 && $sum['errors'] * 2 > $sum['groups_scanned']) {
            Alerts::massErrors($sum['errors'], $sum['groups_scanned'], $cycle);
        }
        $this->log(sprintf(
            'Đợt #%d xong: %d group, %d lần gọi API, %d credit, %d bài mới, %d lỗi. Đợt kế tiếp lúc %s',
            $cycle, $sum['groups_scanned'], $sum['api_calls'], $sum['credits_used'], $sum['new_posts'], $sum['errors'],
            Settings::get('next_run_at')
        ));
        return $sum;
    }

    private function log(string $msg): void
    {
        if ($this->log) {
            ($this->log)($msg);
        }
    }
}
