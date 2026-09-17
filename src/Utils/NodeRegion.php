<?php

declare(strict_types=1);

namespace App\Utils;

/** Country/region labels for the status page; does not affect subscription routing. */
final class NodeRegion
{
    public const COUNTRIES = [
        'US' => '美国',
        'HK' => '香港',
        'JP' => '日本',
        'SG' => '新加坡',
        'TW' => '台湾',
    ];

    /** Empty string keeps name-based detection; null means invalid input. */
    public static function normalizeCountry(mixed $country): ?string
    {
        if ($country === null) {
            return '';
        }
        if (! is_string($country)) {
            return null;
        }
        $country = strtoupper(trim($country));
        return $country === '' || isset(self::COUNTRIES[$country]) ? $country : null;
    }

    private const REGIONS = [
        'HK' => ['香港', ['香港', 'Hong Kong', 'HKG']],
        'TW' => ['台湾', ['台湾', '台灣', '臺灣', '台北', '臺北', 'Taiwan', 'Taipei']],
        'JP' => ['日本', ['日本', '东京', '東京', '大阪', 'Japan', 'Tokyo', 'Osaka', 'JPN']],
        'SG' => ['新加坡', ['新加坡', '狮城', '獅城', 'Singapore', 'SGP']],
        'KR' => ['韩国', ['韩国', '韓國', '首尔', '首爾', 'South Korea', 'Korea', 'Seoul']],
        'US' => ['美国', ['美国', '美國', '洛杉矶', '洛杉磯', '西雅图', '西雅圖', 'United States', 'USA', 'Los Angeles', 'Seattle']],
        'CA' => ['加拿大', ['加拿大', 'Canada', 'Toronto', 'Vancouver']],
        'GB' => ['英国', ['英国', '英國', '伦敦', '倫敦', 'United Kingdom', 'Britain', 'London', 'UK']],
        'DE' => ['德国', ['德国', '德國', '法兰克福', '法蘭克福', 'Germany', 'Frankfurt']],
        'FR' => ['法国', ['法国', '法國', '巴黎', 'France', 'Paris']],
        'NL' => ['荷兰', ['荷兰', '荷蘭', 'Netherlands', 'Amsterdam']],
        'AU' => ['澳大利亚', ['澳大利亚', '澳大利亞', '澳洲', '悉尼', 'Australia', 'Sydney']],
        'NZ' => ['新西兰', ['新西兰', '紐西蘭', 'New Zealand']],
        'MO' => ['澳门', ['澳门', '澳門', 'Macau', 'Macao']],
        'CN' => ['中国大陆', ['中国', '中國', '大陆', '大陸', 'China']],
        'IN' => ['印度', ['印度', 'India', 'Mumbai']],
        'TH' => ['泰国', ['泰国', '泰國', 'Thailand', 'Bangkok']],
        'VN' => ['越南', ['越南', 'Vietnam']],
        'MY' => ['马来西亚', ['马来西亚', '馬來西亞', 'Malaysia']],
        'ID' => ['印度尼西亚', ['印度尼西亚', '印度尼西亞', '印尼', 'Indonesia', 'Jakarta']],
        'PH' => ['菲律宾', ['菲律宾', '菲律賓', 'Philippines', 'Manila']],
        'RU' => ['俄罗斯', ['俄罗斯', '俄羅斯', 'Russia', 'Moscow']],
        'TR' => ['土耳其', ['土耳其', 'Turkey', 'Türkiye', 'Istanbul']],
        'BR' => ['巴西', ['巴西', 'Brazil']],
        'ZA' => ['南非', ['南非', 'South Africa']],
        'AE' => ['阿联酋', ['阿联酋', '阿聯酋', '迪拜', 'United Arab Emirates', 'Dubai', 'UAE']],
        'CH' => ['瑞士', ['瑞士', 'Switzerland', 'Zurich']],
        'SE' => ['瑞典', ['瑞典', 'Sweden', 'Stockholm']],
        'FI' => ['芬兰', ['芬兰', '芬蘭', 'Finland', 'Helsinki']],
        'NO' => ['挪威', ['挪威', 'Norway', 'Oslo']],
        'IT' => ['意大利', ['意大利', '義大利', 'Italy', 'Milan']],
        'ES' => ['西班牙', ['西班牙', 'Spain', 'Madrid']],
    ];

    /**
     * @param list<array<string, mixed>> $servers Already filtered by user visibility.
     * @return list<array{code: string, name: string, flag: string, servers: array, online: int}>
     */
    public static function group(array $servers): array
    {
        $groups = [];
        foreach ($servers as $server) {
            $country = self::normalizeCountry($server['country'] ?? '');
            $code = $country !== null && $country !== '' ? $country : self::detect((string) $server['name']);
            if (! isset($groups[$code])) {
                $groups[$code] = [
                    'code' => $code,
                    'name' => self::REGIONS[$code][0] ?? '其他地区',
                    'flag' => $code === 'OTHER' ? '' : self::flag($code === 'TW' ? 'CN' : $code),
                    'servers' => [],
                    'online' => 0,
                ];
            }
            $groups[$code]['servers'][] = $server;
            $groups[$code]['online'] += (int) (($server['online'] ?? 0) === 1);
        }

        // Stable country order, original order within each country; unknown nodes last.
        $ordered = [];
        foreach ([...array_keys(self::REGIONS), 'OTHER'] as $code) {
            if (isset($groups[$code])) {
                $ordered[] = $groups[$code];
            }
        }
        return $ordered;
    }

    public static function detect(string $name): string
    {
        // Prefer explicit flags, then full names/cities, then delimited region codes.
        foreach (['flag', 'name', 'code'] as $kind) {
            $best = null;
            foreach (self::REGIONS as $code => [, $names]) {
                $aliases = match ($kind) {
                    'flag' => [self::flag($code)],
                    'name' => $names,
                    default => [$code],
                };
                foreach ($aliases as $alias) {
                    $pattern = preg_quote($alias, '/');
                    if (preg_match('/[a-z]/i', $alias)) {
                        // HK01 is valid; BUS / Trojan / Singapore must not match US / TR / IN.
                        $pattern = '(?<![a-z])' . $pattern . '(?![a-z])';
                    }
                    if (preg_match('/' . $pattern . '/iu', $name, $match, PREG_OFFSET_CAPTURE)) {
                        $offset = $match[0][1];
                        // Prefer the longer name at the same position (印度尼西亚 vs 印度).
                        if ($best === null || $offset < $best['offset'] ||
                            ($offset === $best['offset'] && strlen($alias) > $best['length'])) {
                            $best = ['code' => $code, 'offset' => $offset, 'length' => strlen($alias)];
                        }
                    }
                }
            }
            if ($best !== null) {
                return $best['code'];
            }
        }
        return 'OTHER';
    }

    private static function flag(string $code): string
    {
        return mb_chr(0x1F1E6 + ord($code[0]) - ord('A')) . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));
    }
}
