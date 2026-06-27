<?php

namespace TypechoPlugin\ApiPublish\Helper\Form\Element;

use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Layout;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * API Token 输入表单项帮手类
 *
 * 在标准 Text 输入基础上扩展：
 *  - 「生成 Token」按钮：点击生成 64 位随机十六进制 Token 并填充到输入框
 *  - 「眼睛」切换按钮：在「掩码显示」与「完整显示」之间切换
 *  - 首次渲染时若已存在 Token，则默认显示为 abcd********wxyz 形式的掩码
 *  - 通过 data-* 属性附带原始 Token，前端 JS 据此切换显示
 *
 * @package ApiPublish
 */
class ApiToken extends Element
{
    /**
     * 实际 Token 值（仅保存在 data-* 中，input 显示脱敏后的掩码）
     *
     * @var string|null
     */
    private ?string $rawToken = null;

    /**
     * 初始化输入框 + 按钮组
     *
     * @param string|null $name
     * @param array|null $options
     * @return Layout|null
     */
    public function input(?string $name = null, ?array $options = null): ?Layout
    {
        $uniqueId = self::$uniqueId;

        $input = new Layout('input', [
            'id'            => $name . '-0-' . $uniqueId,
            'name'          => $name,
            'type'          => 'text',
            'class'         => 'text',
            'autocomplete'  => 'off',
            'data-role'     => 'apipublish-token-input',
            'data-masked'   => '1',
            'placeholder'   => _t('留空表示不校验 Token，或点击「生成 Token」创建新的'),
        ]);

        // 按钮组容器（生成 / 眼睛）
        $toolbar = new Layout('span', [
            'class'         => 'apipublish-token-toolbar',
            'data-role'     => 'apipublish-token-toolbar',
            'style'         => 'display:inline-block;margin-left:6px;',
        ]);

        $btnGen = new Layout('button', [
            'type'          => 'button',
            'class'         => 'btn btn-s',
            'data-role'     => 'apipublish-token-generate',
            'style'         => 'margin-right:4px;',
        ]);
        $btnGen->html(_t('生成 Token'));

        $btnEye = new Layout('button', [
            'type'          => 'button',
            'class'         => 'btn btn-s',
            'data-role'     => 'apipublish-token-toggle',
            'aria-label'    => _t('显示 / 隐藏 Token'),
            'title'         => _t('显示 / 隐藏 Token'),
        ]);
        $btnEye->html(self::eyeIcon());

        $this->container($input);
        $this->container($toolbar);
        $toolbar->addItem($btnGen);
        $toolbar->addItem($btnEye);

        $this->inputs[] = $input;

        if (isset($this->label)) {
            $this->label->setAttribute('for', $name . '-0-' . $uniqueId);
        }

        return $input;
    }

    /**
     * 写入值：input 始终展示掩码，原始值放入 data-token
     *
     * @param mixed $value
     */
    protected function inputValue($value)
    {
        $token = is_string($value) ? trim($value) : '';
        if ($token === '') {
            $this->input->removeAttribute('value');
            $this->input->setAttribute('data-token', '');
            $this->input->setAttribute('placeholder', _t('留空表示不校验 Token，或点击「生成 Token」创建新的'));
            $this->rawToken = '';
            return;
        }

        $this->rawToken = $token;
        $this->input->setAttribute('value', self::mask($token));
        $this->input->setAttribute('data-token', $token);
        $this->input->setAttribute('data-masked', '1');
    }

    /**
     * 直接输出原值，不做处理
     */
    protected function filterValue(string $value): string
    {
        return $value;
    }

    /**
     * @return string
     */
    protected function getType(): string
    {
        return 'text';
    }

    /**
     * 将 Token 转换为掩码形式：abcd********wxyz
     *
     * @param string $token
     * @return string
     */
    public static function mask(string $token): string
    {
        $len = strlen($token);
        if ($len <= 8) {
            // 太短则全部用 * 替代，避免泄露全部字符
            return str_repeat('*', max(0, $len));
        }
        $head = substr($token, 0, 4);
        $tail = substr($token, -4);
        return $head . str_repeat('*', max(8, $len - 8)) . $tail;
    }

    /**
     * 眼睛图标（开眼 / 闭眼两个 unicode 字符，靠 JS 切换）
     */
    private static function eyeIcon(): string
    {
        // 使用文字代替图标
        return '<span data-role="apipublish-token-eye-open" style="font-size:14px;">显示Token</span>'
            . '<span data-role="apipublish-token-eye-close" style="font-size:14px;display:none;">隐藏Token</span>';
    }
}