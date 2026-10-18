<?php
declare(strict_types=1);

/** Cảnh báo gửi lên Telegram cho mọi người dùng đã đăng nhập (không spam). */
final class Alerts
{
    private const MASS_ERROR_THROTTLE_HOURS = 6;

    /** Gửi một lần duy nhất tới khi có key dùng lại được. */
    public static function allKeysExhausted(): bool
    {
        if (Settings::fresh('all_keys_exhausted_notified') === '1') {
            return false;
        }
        Outbox::enqueueAlert(
            "⚠️ <b>Tất cả API key đã hết credit</b> (hoặc không hợp lệ).\n"
            . 'Bot tạm dừng quét. Nạp thêm credit hoặc thêm key mới ở trang Cài đặt; bot sẽ tự chạy lại.'
        );
        Settings::set('all_keys_exhausted_notified', '1');
        return true;
    }

    /** Hơn 50% group lỗi trong một đợt (Facebook đổi cấu trúc, API lỗi hàng loạt). */
    public static function massErrors(int $errors, int $scanned, int $cycleNo): bool
    {
        $last = Clock::parse(Settings::fresh('last_mass_error_alert_at'));
        if ($last && Clock::ts() - $last->getTimestamp() < self::MASS_ERROR_THROTTLE_HOURS * 3600) {
            return false;
        }
        Outbox::enqueueAlert(sprintf(
            "⚠️ <b>Đợt quét #%d: %d/%d group bị lỗi.</b>\nCó thể API hoặc Facebook đang có vấn đề. Xem trang Nhật ký trên web.",
            $cycleNo,
            $errors,
            $scanned
        ));
        Settings::set('last_mass_error_alert_at', Clock::str());
        return true;
    }
}
