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
        return '<!doctype html>'
            . '<html lang="fa" dir="rtl">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>روندنما | Ravandnama</title>'
            . '</head>'
            . '<body>'
            . '<main>'
            . '<h1>به روندنما خوش آمدید</h1>'
            . '<p>زیرساخت اولیه پروژه با موفقیت اجرا شد.</p>'
            . '</main>'
            . '</body>'
            . '</html>';
    }
}
