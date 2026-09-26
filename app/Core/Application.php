<?php

declare(strict_types=1);

namespace App\Core;

final class Application
{
    public function run(): void
    {
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>';
        echo '<html lang="fa" dir="rtl">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>روندنما | Ravandnama</title>';
        echo '</head>';
        echo '<body>';
        echo '<main>';
        echo '<h1>به روندنما خوش آمدید</h1>';
        echo '<p>زیرساخت اولیه پروژه با موفقیت اجرا شد.</p>';
        echo '</main>';
        echo '</body>';
        echo '</html>';
    }
}
