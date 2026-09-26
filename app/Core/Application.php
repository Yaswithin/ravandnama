<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Application
{
    public function run(): void
    {
        Environment::load(dirname(__DIR__, 2) . '/.env');

        $projectRoot = dirname(__DIR__, 2);
        $router = new Router();
        $database = new Database(require $projectRoot . '/config/database.php');
        $registerApiRoutes = require $projectRoot . '/routes/api.php';
        $registerApiRoutes($router, $database);

        $request = Request::fromGlobals();

        if ($request->path === '/api' || str_starts_with($request->path, '/api/')) {
            try {
                $router->dispatch($request)->send();
            } catch (Throwable $exception) {
                error_log('API request failed (' . $exception::class . ').');
                Response::json([
                    'success' => false,
                    'message' => 'Internal server error',
                ], 500)->send();
            }

            return;
        }

        Response::html($this->welcomePage())->send();
    }

    private function welcomePage(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="fa" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <meta name="theme-color" content="#f3f5f7">
                <title>روندنما | Ravandnama</title>
                <link rel="preconnect" href="https://fonts.googleapis.com">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap">
                <link rel="stylesheet" href="/assets/css/tokens.css">
                <link rel="stylesheet" href="/assets/css/base.css">
                <link rel="stylesheet" href="/assets/css/layout.css">
                <link rel="stylesheet" href="/assets/css/components.css">
                <script type="module" src="/assets/js/app.js"></script>
            </head>
            <body>
                <div class="app-shell">
                    <header id="app-header" class="app-header">
                        <a class="brand" href="#/" aria-label="صفحه اصلی روندنما">
                            <span class="brand-mark" aria-hidden="true">ر</span>
                            <span>روندنما</span>
                        </a>
                    </header>
                    <div id="app-status" class="app-status" role="status" aria-live="polite" aria-atomic="true">
                        در حال آماده‌سازی برنامه…
                    </div>
                    <main id="app-view" class="app-main" tabindex="-1">
                        <h1>روندنما</h1>
                        <p>در حال بارگذاری…</p>
                    </main>
                </div>
            </body>
            </html>
            HTML;
    }
}
