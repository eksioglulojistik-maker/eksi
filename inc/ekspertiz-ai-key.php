<?php
// EKŞİOĞLU — API secret stored outside the web root, never in database or source package.
function ekSurveyKeyPath(): ?string {
    $docroot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $candidates = [];
    $home = getenv('HOME');
    if (is_string($home) && $home !== '') $candidates[] = $home;
    $candidates[] = dirname(__DIR__, 2);
    foreach (array_unique($candidates) as $candidate) {
        $parent = realpath($candidate);
        if (!$parent || !is_dir($parent) || !is_writable($parent)) continue;
        // Do not write a secret in a directory served by this website.
        if ($docroot && ($parent === $docroot || str_starts_with($parent.'/', $docroot.'/'))) continue;
        // Also reject the application's own directory and its children.
        $app = realpath(dirname(__DIR__));
        if ($app && ($parent === $app || str_starts_with($parent.'/', $app.'/'))) continue;
        return $parent.'/.eksioglu-private/openai-api-key.php';
    }
    return null;
}
function ekSurveyApiKey(): string {
    $env = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
    if ($env !== '') return $env;
    $path = ekSurveyKeyPath();
    if (!$path || !is_file($path) || is_link($path)) return '';
    $value = require $path;
    return is_string($value) ? trim($value) : '';
}
