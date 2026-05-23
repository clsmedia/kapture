<?php

declare(strict_types=1);

namespace App\Presentation\Html;

use App\Application\ListCapturedRequestsResult;

final class AdminView
{
    public static function render(ListCapturedRequestsResult $data): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $entries = array_reverse($data->entries);
        $files = $data->dailyArchives;
        $requestedFile = $data->selectedArchive;
        $label = $data->label;

        $h = function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        require __DIR__ . '/templates/admin.html.php';
    }
}
