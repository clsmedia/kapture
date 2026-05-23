<?php

declare(strict_types=1);

namespace App\Presentation\Html;

final class LogoutView
{
    public static function render(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="initial-scale=1">
<title>Kapture — Logged Out</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #0d1117; color: #e6edf3; font-size: 14px;
    display: flex; align-items: center; justify-content: center; height: 100vh;
  }
  .card {
    background: #161b22; border: 1px solid #30363d; border-radius: 8px;
    padding: 32px; text-align: center; max-width: 360px;
  }
  h1 { font-size: 18px; margin-bottom: 8px; }
  p { color: #8b949e; margin-bottom: 8px; font-size: 13px; }
  a {
    display: inline-block; margin-top: 16px; padding: 8px 20px;
    background: #238636; color: #fff; border-radius: 6px;
    text-decoration: none; font-size: 14px;
  }
  a:hover { background: #2ea043; }
</style>
</head>
<body>
<div class="card">
  <h1>Logged Out</h1>
  <p>You have been logged out.</p>
  <p>Close all browser tabs for this site to fully clear your session, then <a href="/admin">log in again</a>.</p>
</div>
</body>
</html>';
    }
}
