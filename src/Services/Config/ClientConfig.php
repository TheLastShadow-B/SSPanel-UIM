<?php

declare(strict_types=1);

namespace App\Services\Config;

final class ClientConfig
{
    private static ?array $config = null;

    public static function getClients(string $sub, string $name, bool $r2): array
    {
        if (self::$config === null) {
            $file = BASE_PATH . '/config/client_display.json';
            if (! is_readable($file)) {
                throw new \RuntimeException("Client config file not found: {$file}");
            }

            $content = file_get_contents($file);
            if ($content === false) {
                throw new \RuntimeException("Failed to read client config file: {$file}");
            }

            try {
                self::$config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Invalid JSON in client config file: ' . $e->getMessage());
            }
        }

        $result = [];
        foreach (self::$config['clients'] as $client) {
            foreach ($client['platforms'] as $platform => $data) {
                $result[$platform][] = [
                    'name' => $client['name'],
                    'description' => $data['desc'] ?? $client['description'],
                    'format' => $client['format'],
                    'importUrl' => self::buildImportUrl($data['importUrl'] ?? $client['importUrl'], $sub, $name),
                    'downloadUrl' => $data['storeUrl'] ??
                        (isset($data['ext']) ? ($r2 ? '/user' : '') . '/clients/' . ($data['file'] ?? str_replace(' ', '.', $client['name'])) . ".{$data['ext']}" : ''),
                    'isAppStore' => isset($data['storeUrl']),
                ];
            }
        }

        return ['clients' => $result, 'icons' => self::$config['icons']];
    }

    /**
     * 构造一键导入 scheme URL。
     * `{sub}/<format>` 处于 url= 参数值内,按各客户端文档(Surge 明确要求)
     * 必须整体百分号编码;Clash/Stash 同样接受编码形式。
     */
    private static function buildImportUrl(string $template, string $sub, string $name): string
    {
        $url = preg_replace_callback(
            '/\{sub\}(\/[a-z0-9_-]+)?/i',
            static fn (array $m): string => rawurlencode($sub . ($m[1] ?? '')),
            $template
        );

        return str_replace('{name}', rawurlencode($name), $url);
    }
}
