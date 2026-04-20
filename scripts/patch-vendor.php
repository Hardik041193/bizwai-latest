<?php

/**
 * Patches vendor files to gracefully handle missing DOMDocument extension
 * (php8.2-xml / php8.3-xml not available on this system).
 *
 * Patches:
 *   1. nunomaduro/termwind HtmlRenderer  — CLI output rendering
 *   2. tijsverkoyen/css-to-inline-styles — Email HTML rendering
 */

$file = __DIR__ . '/../vendor/nunomaduro/termwind/src/HtmlRenderer.php';

if (!file_exists($file)) {
    echo "Skipping patch: HtmlRenderer.php not found.\n";
    exit(0);
}

$content = file_get_contents($file);

$old = <<<'PHP'
        $dom = new DOMDocument();

        if (strip_tags($html) === $html) {
            return Termwind::span($html);
        }
PHP;

$new = <<<'PHP'
        if (! class_exists('DOMDocument') || strip_tags($html) === $html) {
            return Termwind::span(strip_tags($html));
        }

        $dom = new DOMDocument();
PHP;

if (str_contains($content, $new)) {
    echo "Patch already applied to HtmlRenderer.php\n";
    exit(0);
}

if (!str_contains($content, $old)) {
    echo "Patch target not found in HtmlRenderer.php — may have been updated upstream.\n";
    exit(0);
}

file_put_contents($file, str_replace($old, $new, $content));
echo "Patched HtmlRenderer.php successfully.\n";

// ──────────────────────────────────────────────────────────────────────────────
// Patch 2: tijsverkoyen/css-to-inline-styles — email rendering without DOM
// ──────────────────────────────────────────────────────────────────────────────
$cssFile = __DIR__ . '/../vendor/tijsverkoyen/css-to-inline-styles/src/CssToInlineStyles.php';

if (!file_exists($cssFile)) {
    echo "Skipping patch 2: CssToInlineStyles.php not found.\n";
} else {
    $cssContent = file_get_contents($cssFile);
    $cssOld = "    public function convert(\$html, \$css = null)\n    {\n        \$document = \$this->createDomDocumentFromHtml(\$html);";
    $cssNew = "    public function convert(\$html, \$css = null)\n    {\n        if (!class_exists('DOMDocument')) {\n            return \$html;\n        }\n        \$document = \$this->createDomDocumentFromHtml(\$html);";

    if (str_contains($cssContent, "if (!class_exists('DOMDocument'))")) {
        echo "Patch 2 already applied to CssToInlineStyles.php\n";
    } elseif (!str_contains($cssContent, $cssOld)) {
        echo "Patch 2 target not found in CssToInlineStyles.php — may have been updated upstream.\n";
    } else {
        file_put_contents($cssFile, str_replace($cssOld, $cssNew, $cssContent));
        echo "Patched CssToInlineStyles.php successfully.\n";
    }
}
