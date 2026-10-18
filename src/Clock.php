<?php
declare(strict_types=1);

/**
 * Nguồn thời gian chung. Test cố định giờ bằng Clock::set() và khi đó Clock::sleep()
 * chỉ tua đồng hồ chứ không ngủ thật.
 */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;
    private static float $sleptSeconds = 0.0;

    public static function now(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable('now');
    }

    public static function set(?DateTimeImmutable $time): void
    {
        self::$fixed = $time;
        self::$sleptSeconds = 0.0;
    }

    public static function isFixed(): bool
    {
        return self::$fixed !== null;
    }

    public static function advance(float $seconds): void
    {
        if (self::$fixed !== null) {
            self::$fixed = self::$fixed->modify(sprintf('+%d seconds', (int) ceil($seconds)));
        }
    }

    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (self::$fixed !== null) {
            self::$sleptSeconds += $seconds;
            self::advance($seconds);
            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    public static function sleptSeconds(): float
    {
        return self::$sleptSeconds;
    }

    public static function str(?DateTimeImmutable $time = null): string
    {
        return ($time ?? self::now())->format('Y-m-d H:i:s');
    }

    public static function ts(): int
    {
        return self::now()->getTimestamp();
    }

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
