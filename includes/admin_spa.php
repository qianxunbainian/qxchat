<?php
declare(strict_types=1);

/**
 * 是否为后台 AJAX 局部刷新请求（仅替换主内容区，不重载侧栏）。
 */
function admin_is_partial_request(): bool
{
    return isset($_GET['partial']) && (string) $_GET['partial'] === '1';
}
