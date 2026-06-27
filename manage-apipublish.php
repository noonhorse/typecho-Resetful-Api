<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include 'header.php';
include 'menu.php';

$db = \Typecho\Db::get();
$prefix = $db->getPrefix();
$table = $prefix . 'api_publish';

$credentials = $db->fetchAll(
    $db->select()->from($table)->order($table . '.created', \Typecho\Db::SORT_DESC)
);

$apiEntry = $options->siteUrl . 'index.php/action/api-publish';
$xmlrpcEntry = $options->siteUrl . 'index.php/action/xmlrpc';

$pluginCfg = $options->plugin('ApiPublish');
$rateLimit = isset($pluginCfg->rateInfo) ? (int)$pluginCfg->rateInfo : 60;
$markdownEnabled = isset($pluginCfg->markdown) ? $pluginCfg->markdown : '1';
$filterEnabled = isset($pluginCfg->filterHook) ? $pluginCfg->filterHook : 'on';
$defaultCategory = isset($pluginCfg->defaultCategory) ? $pluginCfg->defaultCategory : '';
$defaultStatus = isset($pluginCfg->defaultStatus) ? $pluginCfg->defaultStatus : 'publish';
$allowDelete = isset($pluginCfg->allowDelete) ? $pluginCfg->allowDelete : '0';

// 获取 RESTful 接口列表
$routes = \TypechoPlugin\ApiPublish\Plugin::discoverRestfulRoutes();
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="row typecho-page-main manage-metas">
            <div class="col-mb-12">
                <ul class="typecho-option-tabs clearfix">
                    <li class="current"><a href="<?php $options->adminUrl('extending.php?panel=ApiPublish/manage-apipublish.php'); ?>"><?php _e('凭据管理'); ?></a></li>
                    <li><a href="<?php echo ApiPublish_Doc::getDocUrl(); ?>" target="_blank" rel="noopener"><?php _e('完整文档（新窗口打开）'); ?></a></li>
                    <li><a href="<?php $options->adminUrl('options-plugin.php?config=ApiPublish'); ?>"><?php _e('插件配置'); ?></a></li>
                </ul>
            </div>

            <?php // 文档入口已迁移到外部 Markdown 文档；详见页面顶部「完整文档（新窗口打开）」标签 ?>
                <!-- 远程接口地址展示 -->
                <div class="col-mb-12" style="margin-top: 12px;">
                    <div class="typecho-alert typecho-alert-info">
                        <strong><?php _e('远程 API 接口地址'); ?></strong>
                        <ul style="margin: 6px 0 0 18px;">
                            <li>
                                <?php _e('本插件 API 入口：'); ?><code id="api-entry"><?php echo $apiEntry; ?></code>
                                <a href="javascript:void(0);" onclick="copyText('<?php echo $apiEntry; ?>');"><?php _e('复制'); ?></a>
                            </li>
                            <li>
                                <?php _e('原生 XML-RPC 入口：'); ?><code id="xmlrpc-entry"><?php echo $xmlrpcEntry; ?></code>
                                <a href="javascript:void(0);" onclick="copyText('<?php echo $xmlrpcEntry; ?>');"><?php _e('复制'); ?></a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- 凭据列表 -->
                <div class="col-mb-12 col-tb-8" role="main">
                    <form method="post" name="manage_credentials" class="operate-form"
                          action="<?php $security->index('/action/api-publish?do=adminDelete'); ?>">
                        <div class="typecho-list-operate clearfix">
                            <div class="operate">
                                <label><i class="sr-only"><?php _e('全选'); ?></i>
                                    <input type="checkbox" class="typecho-table-select-all"/>
                                </label>
                                <div class="btn-group btn-drop">
                                    <button class="btn dropdown-toggle btn-s" type="button">
                                        <i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?>
                                        <i class="i-caret-down"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a lang="<?php _e('确认删除选中的 API 凭据？此操作不可恢复'); ?>"
                                               href="<?php $security->index('/action/api-publish?do=adminDelete'); ?>">
                                                <?php _e('删除'); ?>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="typecho-table-wrap">
                            <table class="typecho-list-table">
                                <colgroup>
                                    <col width="20"/>
                                    <col width="20%"/>
                                    <col width="20%"/>
                                    <col/>
                                    <col width="8%"/>
                                    <col width="10%"/>
                                    <col width="12%"/>
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th><?php _e('名称'); ?></th>
                                        <th><?php _e('AppID'); ?></th>
                                        <th><?php _e('说明'); ?></th>
                                        <th><?php _e('调用次数'); ?></th>
                                        <th><?php _e('状态'); ?></th>
                                        <th><?php _e('操作'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($credentials)): ?>
                                    <?php foreach ($credentials as $row): ?>
                                        <tr id="cred-<?php echo (int)$row['id']; ?>">
                                            <td>
                                                <input type="checkbox" value="<?php echo (int)$row['id']; ?>" name="id[]"/>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['name'] ?: $row['app_id']); ?></td>
                                            <td>
                                                <code><?php echo htmlspecialchars($row['app_id']); ?></code>
                                            </td>
                                            <td>
                                                <!-- <small class="description">
                                                    <?php echo htmlspecialchars($row['description']); ?>
                                                </small> -->
                                                <br/>
                                                <small class="meta">
                                                    <?php _e('创建于 '); ?>
                                                    <?php echo date('Y-m-d H:i', (int)$row['created']); ?>
                                                    <?php if ((int)$row['last_used'] > 0): ?>
                                                        <?php _e(' · 最近调用 '); ?>
                                                        <?php echo date('Y-m-d H:i', (int)$row['last_used']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td><?php echo (int)$row['call_count']; ?></td>
                                            <td>
                                                <?php if ((int)$row['state'] === 1): ?>
                                                    <span class="status-enable"><?php _e('已启用'); ?></span>
                                                <?php else: ?>
                                                    <span class="status-disable"><?php _e('已禁用'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $toggleTo = ((int)$row['state'] === 1) ? 0 : 1;
                                                $toggleLabel = ((int)$row['state'] === 1) ? _t('禁用') : _t('启用');
                                                ?>
                                                <a href="<?php $security->index('/action/api-publish?do=adminToggle&id=' . (int)$row['id'] . '&state=' . $toggleTo); ?>"
                                                   title="<?php echo $toggleLabel; ?>">
                                                    <?php echo $toggleLabel; ?>
                                                </a>
                                                <a href="#" onclick="resetSecret(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['app_id'], ENT_QUOTES); ?>'); return false;">
                                                    <?php _e('重置Secret'); ?>
                                                </a>
                                                <a href="<?php $security->index('/action/api-publish?do=adminDelete&id=' . (int)$row['id']); ?>"
                                                   lang="<?php _e('确认删除该凭据？'); ?>"
                                                   class="operate-delete">
                                                    <?php _e('删除'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <h6 class="typecho-list-table-title"><?php _e('暂无 API 凭据，请使用右侧表单新增'); ?></h6>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>

                <!-- API 接口列表 -->
                <div class="col-mb-12" style="margin-top: 24px;">
                    <h3><?php _e('RESTful API 接口列表'); ?></h3>
                    <div style="margin-bottom: 12px;">
                        <button type="button" class="btn btn-s" id="btn-disable-all"><?php _e('一键禁用'); ?></button>
                        <button type="button" class="btn btn-s" id="btn-enable-all"><?php _e('一键启用'); ?></button>
                    </div>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table" id="api-routes-table">
                            <colgroup>
                                <col width="50"/>
                                <col width="20%"/>
                                <col/>
                                <col width="120"/>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th><?php _e('序号'); ?></th>
                                    <th><?php _e('名称'); ?></th>
                                    <th><?php _e('API 地址'); ?></th>
                                    <th><?php _e('操作'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($routes)): ?>
                                <?php foreach ($routes as $i => $route): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($route['shortName']); ?></strong>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($route['uri']); ?></code>
                                            <?php
                                            $opt = \Typecho\Widget::widget('Widget_Options');
                                            $fullUrl = $opt->siteUrl . ltrim($route['uri'], '/');
                                            ?>
                                            <a href="javascript:void(0);" onclick="copyText('<?php echo $fullUrl; ?>');"><?php _e('复制'); ?></a>
                                        </td>
                                        <td>
                                            <a href="#" class="toggle-route" data-shortname="<?php echo htmlspecialchars($route['shortName']); ?>" data-action="disable"><?php _e('禁用'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4">
                                        <h6 class="typecho-list-table-title"><?php _e('暂无 RESTful 接口'); ?></h6>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 新增凭据表单 -->
                <div class="col-mb-12 col-tb-4" role="form">
                    <section class="typecho-mini-panel">
                        <h3><?php _e('新增 API 凭据'); ?></h3>
                        <form method="post" id="apipublish-insert-form"
                              action="<?php $security->index('/action/api-publish?do=adminInsert'); ?>">
                            <label class="typecho-label"><?php _e('凭据名称'); ?></label>
                            <p>
                                <input type="text" name="name" class="text-s" placeholder="<?php _e('如：内容同步-知乎'); ?>"/>
                            </p>

                            <label class="typecho-label"><?php _e('AppID'); ?></label>
                            <p>
                                <input type="text" id="apipublish-appid" name="app_id" class="text-s" required/>
                                <button type="button" class="btn btn-s" id="apipublish-generate"
                                        style="margin-top:4px;"><?php _e('随机生成'); ?></button>
                            </p>

                            <label class="typecho-label"><?php _e('AppSecret'); ?></label>
                            <p>
                                <input type="text" id="apipublish-appsecret" name="app_secret" class="text-s" required/>
                            </p>

                            <label class="typecho-label"><?php _e('说明'); ?></label>
                            <p>
                                <textarea name="description" class="text-s" rows="3"
                                          placeholder="<?php _e('可填写凭据使用方、调用场景等'); ?>"></textarea>
                            </p>

                            <p>
                                <label>
                                    <input type="checkbox" name="state" value="1" checked/>
                                    <?php _e('立即启用'); ?>
                                </label>
                            </p>

                            <p>
                                <button type="submit" class="btn btn-primary"><?php _e('保存凭据'); ?></button>
                            </p>
                        </form>
                    </section>

                    <section class="typecho-mini-panel">
                        <h3><?php _e('当前插件配置'); ?></h3>
                        <ul class="typecho-list">
                            <li><?php _e('调用频率上限：'); ?> <strong><?php echo $rateLimit; ?></strong> <?php _e('次/分钟（0 不限制）'); ?></li>
                            <li><?php _e('Markdown 支持：'); ?> <strong><?php echo $markdownEnabled === '1' ? _t('已启用') : _t('已关闭'); ?></strong></li>
                            <li><?php _e('XSS 过滤：'); ?> <strong><?php echo $filterEnabled === 'on' ? _t('已启用') : _t('已关闭'); ?></strong></li>
                            <li><?php _e('默认状态：'); ?> <strong><?php echo htmlspecialchars($defaultStatus); ?></strong></li>
                            <li><?php _e('默认分类：'); ?> <strong><?php echo $defaultCategory !== '' ? htmlspecialchars($defaultCategory) : _t('未设置'); ?></strong></li>
                            <li><?php _e('远程删除：'); ?> <strong><?php echo $allowDelete === '1' ? _t('已启用') : _t('已关闭'); ?></strong></li>
                        </ul>
                        <p>
                            <a href="<?php $options->adminUrl('options-plugin.php?config=ApiPublish'); ?>" class="btn btn-s">
                                <?php _e('修改插件配置'); ?>
                            </a>
                        </p>
                    </section>
                </div>
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
?>

<script type="text/javascript">
(function () {
    $(document).ready(function () {
        var table = $('.typecho-list-table').first();
        if (table.length && typeof table.tableSelectable === 'function') {
            table.tableSelectable({
                checkEl: 'input[type=checkbox]',
                rowEl: 'tr',
                selectAllEl: '.typecho-table-select-all',
                actionEl: '.dropdown-menu a'
            });
        }

        var btn = $('.dropdown-toggle');
        if (btn.length && typeof btn.dropdownMenu === 'function') {
            btn.dropdownMenu({
                btnEl: '.dropdown-toggle',
                menuEl: '.dropdown-menu'
            });
        }

        // 随机生成按钮
        $('#apipublish-generate').on('click', function () {
            $.get('<?php $security->index('/action/api-publish?do=adminGenerate'); ?>', function (r) {
                if (r && r.ok) {
                    $('#apipublish-appid').val(r.app_id);
                    $('#apipublish-appsecret').val(r.app_secret);
                }
            }, 'json');
        });

        // 一键禁用所有 API 接口
        $('#btn-disable-all').on('click', function () {
            if (!confirm('<?php _e('确认要禁用所有 RESTful API 接口吗？'); ?>')) {
                return;
            }
            // 发送请求到后端处理
            $.post('<?php $security->index('/action/api-publish?do=adminBatchToggle'); ?>', { action: 'disable' }, function (r) {
                if (r && r.ok) {
                    window.location.reload();
                }
            }, 'json');
        });

        // 一键启用所有 API 接口
        $('#btn-enable-all').on('click', function () {
            if (!confirm('<?php _e('确认要启用所有 RESTful API 接口吗？'); ?>')) {
                return;
            }
            // 发送请求到后端处理
            $.post('<?php $security->index('/action/api-publish?do=adminBatchToggle'); ?>', { action: 'enable' }, function (r) {
                if (r && r.ok) {
                    window.location.reload();
                }
            }, 'json');
        });
    });
})();

function copyText(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).catch(function () {
            window.prompt('复制失败，请手动复制：', text);
        });
    } else {
        window.prompt('复制失败，请手动复制：', text);
    }
}

function resetSecret(id, appId) {
    var input = prompt('请输入新的 AppSecret（建议 32 位以上随机字符串）：');
    if (!input) {
        return;
    }
    var url = '<?php echo $security->index('/action/api-publish?do=adminReset&id=__ID__&app_secret=__S__'); ?>';
    url = url.replace('__ID__', encodeURIComponent(id)).replace('__S__', encodeURIComponent(input));
    window.location.href = url;
}
</script>
<?php include 'footer.php'; ?>