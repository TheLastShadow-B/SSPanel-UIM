<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Utils\Tools;
use Illuminate\Database\DatabaseManager;
use Smarty\Smarty;
use function in_array;
use const BASE_PATH;

final class View
{
    public static DatabaseManager $connection;
    public static float $beginTime;

    public static function getSmarty(): Smarty
    {
        $smarty = new Smarty(); //实例化smarty
        $user = Auth::getUser();

        $smarty->setTemplateDir(self::getTemplateDirs(self::getTheme($user)));
        $smarty->setCompileDir(BASE_PATH . '/storage/framework/smarty/compile/'); //设置生成文件存放目录
        $smarty->setCacheDir(BASE_PATH . '/storage/framework/smarty/cache/'); //设置缓存文件存放目录
        // add config
        $smarty->assign('config', self::getConfig());
        $smarty->assign('public_setting', Config::getPublicConfig());
        $smarty->assign('user', $user);

        return $smarty;
    }

    /**
     * @return string[] 模板查找目录
     */
    public static function getTemplateDirs(string $theme): array
    {
        return [BASE_PATH . '/resources/views/' . $theme . '/'];
    }

    public static function getTheme($user): string
    {
        $theme = $user->isLogin ? $user->theme : $_ENV['theme'];

        // 历史用户的 theme 字段可能仍是已删除的旧主题(如 tabler),也可能是入库校验收紧前写进的
        // ../ 越界值 —— 用主题白名单比对而非 is_dir(),避免模板目录被指到 resources/views 之外
        if (! is_string($theme) || ! in_array($theme, Tools::getDir(BASE_PATH . '/resources/views'), true)) {
            return 'cafe';
        }

        return $theme;
    }

    public static function getConfig(): array
    {
        return [
            // 主题静态资源缓存戳:CSS 构建产物变化即失效浏览器缓存
            'assets_version' => @filemtime(BASE_PATH . '/public/theme/cafe/app.css') ?: VERSION,
            'appName' => $_ENV['appName'],
            'baseUrl' => $_ENV['baseUrl'],
            'jump_delay' => $_ENV['jump_delay'],
            'enable_kill' => $_ENV['enable_kill'],
            'enable_change_email' => $_ENV['enable_change_email'],
            'enable_r2_client_download' => $_ENV['enable_r2_client_download'],
            'jsdelivr_url' => $_ENV['jsdelivr_url'],
            'enable_telemetry' => $_ENV['enable_telemetry'] ?? true,
            // site default language
            'locale' => $_ENV['locale'],
        ];
    }
}
