<?php

class SqlParser
{
    /**
     * Parses a SQL file and extracts CREATE TABLE schema definitions.
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

        $inTable = false;
        $currentTable = '';
        $currentSchema = '';
        $startLine = 0;

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            
            // Match CREATE TABLE
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $line, $m)) {
                $inTable = true;
                $currentTable = $m[1];
                $currentSchema = $line . "\n";
                $startLine = $lineNum;
                continue;
            }

            if ($inTable) {
                $currentSchema .= $line . "\n";
                // Match the end of the table statement (usually ends with ; )
                if (preg_match('/;$/', trim($line))) {
                    $inTable = false;
                    
                    $entities[] = [
                        'type' => 'table',
                        'name' => $currentTable,
                        'line_start' => $startLine,
                        'line_end' => $lineNum,
                        'signature' => 'CREATE TABLE ' . $currentTable,
                        'summary' => trim($currentSchema), // For SQL, the summary is the schema itself
                        'file_path' => $filePath
                    ];
                    $currentTable = '';
                    $currentSchema = '';
                }
            }
        }

        return $entities;
    }
}
