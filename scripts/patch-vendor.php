<?php

/**
 * Patches vendor files to gracefully handle missing DOMDocument extension
 * (php8.2-xml / php8.3-xml not available on this system).
 *
 * Patches:
 *   1. nunomaduro/termwind HtmlRenderer        — CLI output rendering
 *   2. tijsverkoyen/css-to-inline-styles        — Email HTML rendering
 *   3. quickbooks/v3-php-sdk SyncRestHandler   — QuickBooks API response logging
 */

$file = __DIR__ . '/../vendor/nunomaduro/termwind/src/HtmlRenderer.php';

if (!file_exists($file)) {
    echo "Skipping patch 1: HtmlRenderer.php not found.\n";
} else {
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
        echo "Patch 1 already applied to HtmlRenderer.php\n";
    } elseif (!str_contains($content, $old)) {
        echo "Patch 1 target not found in HtmlRenderer.php — may have been updated upstream.\n";
    } else {
        file_put_contents($file, str_replace($old, $new, $content));
        echo "Patched HtmlRenderer.php successfully.\n";
    }
}

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

// ──────────────────────────────────────────────────────────────────────────────
// Patch 3: quickbooks/v3-php-sdk SyncRestHandler — API response XML logging
// The parseStringToDom() method is used only for pretty-printing XML in logs.
// Guard it so it returns the raw string when DOMDocument is unavailable.
// ──────────────────────────────────────────────────────────────────────────────
$qbFile = __DIR__ . '/../vendor/quickbooks/v3-php-sdk/src/Core/HttpClients/SyncRestHandler.php';

if (!file_exists($qbFile)) {
    echo "Skipping patch 3: SyncRestHandler.php not found.\n";
} else {
    $qbContent = file_get_contents($qbFile);
    $qbOld = <<<'PHP'
    private function parseStringToDom($string){
      $dom = new \DOMDocument();
      $dom->preserveWhiteSpace = FALSE;
      $dom->loadXML($string);
      $dom->formatOutput = TRUE;
      return $dom->saveXml();
    }
PHP;
    $qbNew = <<<'PHP'
    private function parseStringToDom($string){
      if (!class_exists('DOMDocument')) {
          return $string;
      }
      $dom = new \DOMDocument();
      $dom->preserveWhiteSpace = FALSE;
      $dom->loadXML($string);
      $dom->formatOutput = TRUE;
      return $dom->saveXml();
    }
PHP;

    if (str_contains($qbContent, "if (!class_exists('DOMDocument'))")) {
        echo "Patch 3 already applied to SyncRestHandler.php\n";
    } elseif (!str_contains($qbContent, $qbOld)) {
        echo "Patch 3 target not found in SyncRestHandler.php — may have been updated upstream.\n";
    } else {
        file_put_contents($qbFile, str_replace($qbOld, $qbNew, $qbContent));
        echo "Patched SyncRestHandler.php successfully.\n";
    }
}
