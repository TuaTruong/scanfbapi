<?php
declare(strict_types=1);

/** Không còn API key nào dùng được (tất cả hết credit hoặc sai). */
final class AllKeysExhaustedException extends RuntimeException
{
}

/** Lỗi khi gọi API cho một group cụ thể (group private, link sai, server lỗi...). Không phải lỗi key. */
final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
