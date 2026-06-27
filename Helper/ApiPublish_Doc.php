<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 文档链接助手类
 *
 * 集中维护插件文档的入口 URL，便于后期一键迁移到 GitHub / Gitee 等托管平台。
 *
 * @package ApiPublish
 */
class ApiPublish_Doc
{
    /**
     * 获取插件文档入口 URL
     *
     * 默认指向项目 README.md（GitHub 视图）。如需替换为其他托管平台，
     * 请直接修改本方法返回的 URL 即可。
     *
     * @return string
     */
    public static function getDocUrl(): string
    {
        // TODO: 将此地址替换为你的 GitHub README 链接
        return 'https://github.com/yourname/typecho-plugin-ApiPublish/blob/main/README.md';
    }
}
