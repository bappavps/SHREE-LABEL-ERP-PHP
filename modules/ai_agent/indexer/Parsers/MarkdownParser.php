<?php

class MarkdownParser
{
    /**
     * Parses a Markdown file and extracts sections based on headers.
     *
     * @param string $filePath
     * @return array Array of extracted entities
     */
    public static function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);
        $lines = explode("\n", $code);
        $entities = [];

        $currentSectionName = basename($filePath);
        $currentSectionContent = '';
        $startLine = 1;

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            
            // Match headers (## Section)
            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $m)) {
                // Save previous section
                if (trim($currentSectionContent) !== '') {
                    $entities[] = [
                        'type' => 'doc',
                        'name' => $currentSectionName,
                        'line_start' => $startLine,
                        'line_end' => $lineNum - 1,
                        'signature' => 'Markdown Section: ' . $currentSectionName,
                        'summary' => trim($currentSectionContent),
                        'file_path' => $filePath
                    ];
                }
                
                $currentSectionName = trim($m[1]);
                $currentSectionContent = $line . "\n";
                $startLine = $lineNum;
            } else {
                $currentSectionContent .= $line . "\n";
            }
        }

        // Save last section
        if (trim($currentSectionContent) !== '') {
            $entities[] = [
                'type' => 'doc',
                'name' => $currentSectionName,
                'line_start' => $startLine,
                'line_end' => count($lines),
                'signature' => 'Markdown Section: ' . $currentSectionName,
                'summary' => trim($currentSectionContent),
                'file_path' => $filePath
            ];
        }

        return $entities;
    }
}
