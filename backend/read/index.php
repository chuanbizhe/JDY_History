<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 禁止浏览器、企业微信和中间代理缓存
|--------------------------------------------------------------------------
*/

header('Content-Type: text/html; charset=UTF-8');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

/*
|--------------------------------------------------------------------------
| 读取参数
|--------------------------------------------------------------------------
*/

$token = isset($_GET['token'])
    ? (string) $_GET['token']
    : '';

$buttonText = isset($_GET['button'])
    ? trim((string) $_GET['button'])
    : '';

$content = isset($_GET['content'])
    ? (string) $_GET['content']
    : '';

if ($buttonText === '') {
    $buttonText = '已阅读';
}

/*
|--------------------------------------------------------------------------
| 安全输出到 JavaScript
|--------------------------------------------------------------------------
*/

$tokenJson = json_encode(
    $token,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
);

$buttonTextJson = json_encode(
    $buttonText,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
);

$contentJson = json_encode(
    $content,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover"
    >

    <meta
        http-equiv="Cache-Control"
        content="no-store, no-cache, must-revalidate, max-age=0"
    >

    <meta
        http-equiv="Pragma"
        content="no-cache"
    >

    <meta
        http-equiv="Expires"
        content="0"
    >

    <title>阅读确认</title>

    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            width: 100%;
            min-height: 100%;
            background: #f5f6f8;
            color: #1f2329;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Microsoft YaHei",
                Arial,
                sans-serif;
        }

        body {
            padding:
                16px
                16px
                calc(96px + env(safe-area-inset-bottom));
        }

        .page {
            width: 100%;
            max-width: 920px;
            margin: 0 auto;
        }

        .content-card {
            width: 100%;
            min-height: 120px;
            padding: 20px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(31, 35, 41, 0.06);
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .content-card h1,
        .content-card h2,
        .content-card h3,
        .content-card h4,
        .content-card h5,
        .content-card h6 {
            margin-top: 1.4em;
            margin-bottom: 0.6em;
            line-height: 1.4;
        }

        .content-card h1:first-child,
        .content-card h2:first-child,
        .content-card h3:first-child {
            margin-top: 0;
        }

        .content-card p {
            margin: 0 0 1em;
            line-height: 1.8;
        }

        .content-card ul,
        .content-card ol {
            padding-left: 24px;
            line-height: 1.8;
        }

        .content-card blockquote {
            margin: 16px 0;
            padding: 10px 14px;
            color: #646a73;
            background: #f7f8fa;
            border-left: 4px solid #3370ff;
            border-radius: 4px;
        }

        .content-card pre {
            max-width: 100%;
            padding: 14px;
            overflow-x: auto;
            background: #1f2329;
            color: #ffffff;
            border-radius: 8px;
        }

        .content-card code {
            padding: 2px 5px;
            background: #f2f3f5;
            border-radius: 4px;
            font-family:
                SFMono-Regular,
                Consolas,
                "Liberation Mono",
                monospace;
        }

        .content-card pre code {
            padding: 0;
            background: transparent;
        }

        .content-card img {
            display: block;
            max-width: 100%;
            height: auto;
            margin: 12px auto;
            border-radius: 8px;
        }

        .content-card table {
            display: block;
            width: 100%;
            margin: 16px 0;
            overflow-x: auto;
            border-collapse: collapse;
        }

        .content-card th,
        .content-card td {
            padding: 10px;
            border: 1px solid #dee0e3;
            text-align: left;
            white-space: nowrap;
        }

        .content-card a {
            color: #3370ff;
            text-decoration: none;
        }

        .empty-state {
            color: #8f959e;
            line-height: 1.8;
            text-align: center;
        }

        .footer {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1000;
            padding:
                12px
                16px
                calc(12px + env(safe-area-inset-bottom));
            background: rgba(255, 255, 255, 0.96);
            border-top: 1px solid rgba(31, 35, 41, 0.08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .footer-inner {
            width: 100%;
            max-width: 920px;
            margin: 0 auto;
        }

        .confirm-button {
            display: block;
            width: 100%;
            min-height: 48px;
            padding: 0 20px;
            border: 0;
            border-radius: 8px;
            background: #3370ff;
            color: #ffffff;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        .confirm-button:active {
            opacity: 0.88;
        }

        .confirm-button:disabled {
            cursor: default;
            opacity: 0.65;
        }

        .error-message {
            margin-top: 12px;
            color: #d03050;
            font-size: 14px;
            line-height: 1.6;
            text-align: center;
        }
    </style>
</head>

<body>
    <main class="page">
        <section
            id="content"
            class="content-card"
        >
            <div class="empty-state">
                正在加载内容……
            </div>
        </section>

        <div
            id="errorMessage"
            class="error-message"
            hidden
        ></div>
    </main>

    <footer class="footer">
        <div class="footer-inner">
            <button
                id="confirmButton"
                class="confirm-button"
                type="button"
            >
                已阅读
            </button>
        </div>
    </footer>

    <script>
        (function () {
            var token = <?php echo $tokenJson ?: '""'; ?>;
            var buttonText = <?php echo $buttonTextJson ?: '"已阅读"'; ?>;
            var markdownContent = <?php echo $contentJson ?: '""'; ?>;

            var contentElement =
                document.getElementById('content');

            var confirmButton =
                document.getElementById('confirmButton');

            var errorMessage =
                document.getElementById('errorMessage');

            function showError(message) {
                errorMessage.textContent = message;
                errorMessage.hidden = false;
            }

            function renderMarkdown() {
                confirmButton.textContent =
                    String(buttonText).trim() || '已阅读';

                if (!String(markdownContent).trim()) {
                    contentElement.innerHTML =
                        '<div class="empty-state">暂无阅读内容</div>';

                    return;
                }

                try {
                    if (
                        window.marked &&
                        typeof window.marked.parse === 'function'
                    ) {
                        contentElement.innerHTML =
                            window.marked.parse(markdownContent);
                    } else {
                        contentElement.textContent =
                            markdownContent;

                        showError(
                            'Markdown 组件未加载，当前已按纯文本展示。'
                        );
                    }
                } catch (error) {
                    console.error(
                        'Markdown 渲染失败：',
                        error
                    );

                    contentElement.textContent =
                        markdownContent;

                    showError(
                        'Markdown 渲染失败，当前已按纯文本展示。'
                    );
                }
            }

            function sendConfirmMessage() {
                var originalText =
                    String(buttonText).trim() || '已阅读';

                confirmButton.disabled = true;
                confirmButton.textContent = '已确认';

                try {
                    window.parent.postMessage({
                        pluginMessage: {
                            type: 'markdown-modal-confirm',
                            token: token,
                            value: 'pass'
                        }
                    }, '*');
                } catch (error) {
                    console.error(
                        '发送确认消息失败：',
                        error
                    );

                    confirmButton.disabled = false;
                    confirmButton.textContent =
                        originalText;

                    showError(
                        '确认消息发送失败，请重新点击。'
                    );
                }
            }

            confirmButton.addEventListener(
                'click',
                sendConfirmMessage
            );

            renderMarkdown();
        })();
    </script>
</body>
</html>