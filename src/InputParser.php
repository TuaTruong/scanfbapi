<?php
declare(strict_types=1);

/** Chuẩn hoá danh sách group (link/UID) và API key từ textarea. */
final class InputParser
{
    /** @return list<string> các dòng đã trim, bỏ dòng trống */
    public static function lines(string $text): array
    {
        $text = str_replace(["\u{FEFF}", "\u{200B}", "\r"], ['', '', ''], $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Một dòng → group. Chấp nhận UID thuần, hoặc link facebook.com/groups/<slug|id>/...
     * @return array{fb_ref: string, ref_type: 'url'|'group_id', input_raw: string}
     */
    public static function parseGroup(string $line): array
    {
        $raw = trim($line);
        if (preg_match('/^\d{5,25}$/', $raw)) {
            return ['fb_ref' => $raw, 'ref_type' => 'group_id', 'input_raw' => $raw];
        }

        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) ? $raw : 'https://' . $raw;
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (!preg_match('/(^|\.)(facebook\.com|fb\.com)$/', $host)) {
            throw new InvalidArgumentException("Không phải link Facebook group: \"$raw\"");
        }
        if (!preg_match('#^/groups/([^/]+)#i', $path, $m)) {
            throw new InvalidArgumentException("Link thiếu phần /groups/<tên hoặc ID>: \"$raw\"");
        }
        $segment = rawurldecode($m[1]);
        if (preg_match('/^\d{5,25}$/', $segment)) {
            return ['fb_ref' => $segment, 'ref_type' => 'group_id', 'input_raw' => $raw];
        }
        if (!preg_match('/^[A-Za-z0-9._-]{2,100}$/', $segment)) {
            throw new InvalidArgumentException("Tên group không hợp lệ \"$segment\" trong: \"$raw\"");
        }
        return ['fb_ref' => strtolower($segment), 'ref_type' => 'url', 'input_raw' => $raw];
    }

    /**
     * @return array{groups: list<array{fb_ref:string, ref_type:string, input_raw:string}>, errors: list<string>, duplicates: int}
     */
    public static function parseGroupList(string $text): array
    {
        $groups = [];
        $errors = [];
        $duplicates = 0;
        foreach (self::lines($text) as $line) {
            try {
                $g = self::parseGroup($line);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if (isset($groups[$g['fb_ref']])) {
                $duplicates++;
                continue;
            }
            $groups[$g['fb_ref']] = $g;
        }
        return ['groups' => array_values($groups), 'errors' => $errors, 'duplicates' => $duplicates];
    }

    /** @return array{keys: list<string>, duplicates: int, errors: list<string>} */
    public static function parseKeyList(string $text): array
    {
        $keys = [];
        $duplicates = 0;
        $errors = [];
        foreach (self::lines($text) as $line) {
            foreach (preg_split('/[\s,;]+/', $line, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $token)) {
                    $errors[] = 'API key không hợp lệ: "' . self::mask($token) . '"';
                    continue;
                }
                if (in_array($token, $keys, true)) {
                    $duplicates++;
                    continue;
                }
                $keys[] = $token;
            }
        }
        return ['keys' => $keys, 'duplicates' => $duplicates, 'errors' => $errors];
    }

    /** Link dùng gọi API khi ref_type = url. */
    public static function groupUrl(string $fbRef): string
    {
        return 'https://www.facebook.com/groups/' . $fbRef;
    }

    /** Che giữa chuỗi bí mật: nX5F…CW2 */
    public static function mask(string $secret): string
    {
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, 4) . '…' . substr($secret, -3);
    }
}
