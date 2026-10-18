<?php
declare(strict_types=1);

/** Ước tính số đợt/ngày, credit/ngày, thời điểm quét tới của từng group (mục 6.7 kế hoạch). */
final class Estimator
{
    public const DEFAULT_CYCLE_MINUTES = 3.0;
    public const DEFAULT_AVG_PAGES = 1.3;
    private const HISTORY_DAYS = 7;

    public static function minutesOfDay(string $hhmm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return 0;
        }
        return ((int) $m[1] % 24) * 60 + min(59, (int) $m[2]);
    }

    /** Độ dài khung giờ (phút), hỗ trợ khung qua nửa đêm. Từ = Đến nghĩa là cả ngày. */
    public static function windowMinutes(string $start, string $end): int
    {
        $s = self::minutesOfDay($start);
        $e = self::minutesOfDay($end);
        if ($s === $e) {
            return 1440;
        }
        return $s < $e ? $e - $s : 1440 - $s + $e;
    }

    /** Thời gian trung bình một đợt (phút) trong 7 ngày gần nhất, chưa có dữ liệu thì 3 phút. */
    public static function avgCycleMinutes(): float
    {
        $avg = Db::val(
            'SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, ended_at)) FROM scan_runs
             WHERE ended_at IS NOT NULL AND groups_scanned > 0 AND started_at >= ?',
            [Clock::str(Clock::now()->modify('-' . self::HISTORY_DAYS . ' days'))]
        );
        return $avg === null ? self::DEFAULT_CYCLE_MINUTES : max(0.1, (float) $avg / 60);
    }

    /** Một vòng = nghỉ + thời gian quét. */
    public static function cycleMinutes(): float
    {
        return max(1.0, Settings::int('rest_minutes') + self::avgCycleMinutes());
    }

    public static function cyclesPerDay(): float
    {
        return self::windowMinutes((string) Settings::get('window_start'), (string) Settings::get('window_end')) / self::cycleMinutes();
    }

    /** Số đợt tương ứng khoảng 24 giờ (trần thời gian tạm hoãn group lỗi). */
    public static function cyclesPer24h(): int
    {
        return max(1, (int) floor(self::cyclesPerDay()));
    }

    /** @return array<int, float> group_id => số trang thực dùng trung bình mỗi lần quét */
    public static function avgPagesByGroup(): array
    {
        $rows = Db::all(
            "SELECT group_id, COUNT(*) / COUNT(DISTINCT run_id) AS avg_pages FROM api_calls
             WHERE endpoint = 'group_posts' AND http_code = 200 AND run_id IS NOT NULL AND group_id IS NOT NULL AND called_at >= ?
             GROUP BY group_id",
            [Clock::str(Clock::now()->modify('-' . self::HISTORY_DAYS . ' days'))]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['group_id']] = (float) $r['avg_pages'];
        }
        return $out;
    }

    /**
     * Thêm cột ước tính vào từng group.
     * @return list<array> group + scans_per_day, avg_pages, avg_pages_measured, credits_day, credits_ceiling, next_cycle, next_time
     */
    public static function forGroups(array $groups): array
    {
        $cyclesPerDay = self::cyclesPerDay();
        $avgPages = self::avgPagesByGroup();
        $cycleNo = Settings::int('cycle_no');
        $times = null;

        $out = [];
        foreach ($groups as $g) {
            $active = (int) $g['enabled'] === 1 && (int) $g['in_list'] === 1;
            $n = max(1, (int) $g['every_n_cycles']);
            $maxPages = max(1, (int) $g['max_pages']);
            $measured = $avgPages[(int) $g['id']] ?? null;
            $pages = min((float) $maxPages, max(1.0, $measured ?? self::DEFAULT_AVG_PAGES));
            $scans = $active ? $cyclesPerDay / $n : 0.0;

            $nextCycle = null;
            $nextTime = null;
            if ($active) {
                $nextCycle = $cycleNo + 1;
                if ($g['last_scanned_cycle'] !== null) {
                    $nextCycle = max($nextCycle, (int) $g['last_scanned_cycle'] + $n);
                }
                if ($g['paused_until_cycle'] !== null) {
                    $nextCycle = max($nextCycle, (int) $g['paused_until_cycle'] + 1);
                }
                $times ??= self::upcomingCycleTimes(200);
                $nextTime = $times[$nextCycle - $cycleNo - 1] ?? null;
            }
            $out[] = $g + [
                'scans_per_day' => $scans,
                'avg_pages' => $pages,
                'avg_pages_measured' => $measured,
                'credits_day' => $scans * $pages,
                'credits_ceiling' => $scans * $maxPages,
                'next_cycle' => $nextCycle,
                'next_time' => $nextTime,
            ];
        }
        return $out;
    }

    /** @return array{cycles_per_day: float, cycle_minutes: float, credits_day: float, credits_ceiling: float, credits_left: int, days_left: ?float} */
    public static function summary(array $groupsWithEstimates): array
    {
        $day = 0.0;
        $ceiling = 0.0;
        foreach ($groupsWithEstimates as $g) {
            $day += $g['credits_day'];
            $ceiling += $g['credits_ceiling'];
        }
        $left = ApiKeys::totalCredits();
        return [
            'cycles_per_day' => self::cyclesPerDay(),
            'cycle_minutes' => self::cycleMinutes(),
            'credits_day' => $day,
            'credits_ceiling' => $ceiling,
            'credits_left' => $left,
            'days_left' => $day > 0 ? $left / $day : null,
        ];
    }

    /**
     * Thời điểm (ước tính) bắt đầu các đợt tới, bỏ qua giờ ngoài khung.
     * @return list<?DateTimeImmutable> phần tử 0 = đợt cycle_no + 1
     */
    public static function upcomingCycleTimes(int $count): array
    {
        if (!Settings::bool('scan_enabled')) {
            return [];
        }
        $start = (string) Settings::get('window_start');
        $end = (string) Settings::get('window_end');
        $step = (int) round(self::cycleMinutes() * 60);
        $t = Clock::now();
        $next = Clock::parse(Settings::get('next_run_at'));
        if ($next && $next > $t) {
            $t = $next;
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if (!Scheduler::inWindow($start, $end, $t)) {
                $t = self::nextWindowStart($start, $t);
            }
            $out[] = $t;
            $t = $t->modify("+$step seconds");
        }
        return $out;
    }

    public static function nextWindowStart(string $start, DateTimeImmutable $t): DateTimeImmutable
    {
        $m = self::minutesOfDay($start);
        $candidate = $t->setTime(intdiv($m, 60), $m % 60, 0);
        return $candidate <= $t ? $candidate->modify('+1 day') : $candidate;
    }
}
