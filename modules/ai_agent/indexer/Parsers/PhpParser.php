<?php

class PhpParser
{
    /**
     * Parses a PHP file and extracts classes and functions using token_get_all.
     * Completely read-only, never executes the code.
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
        $tokens = token_get_all($code);

        $entities = [];
        $count = count($tokens);
        
        $currentClass = null;
        $currentDoc = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                $type = $token[0];
                $text = $token[1];
                $line = $token[2];

                // Capture DocBlocks
                if ($type === T_DOC_COMMENT) {
                    $currentDoc = trim($text);
                }

                // Detect Classes
                if ($type === T_CLASS) {
                    $className = self::getNextString($tokens, $i);
                    if ($className) {
                        $currentClass = $className;
                        $entities[] = [
                            'type' => 'class',
                            'name' => $className,
                            'line_start' => $line,
                            'line_end' => self::findMatchingBraceLine($tokens, $i),
                            'signature' => 'class ' . $className,
                            'summary' => $currentDoc,
                            'file_path' => $filePath
                        ];
                    }
                    $currentDoc = '';
                }

                // Detect Functions / Methods
                if ($type === T_FUNCTION) {
                    $functionName = self::getNextString($tokens, $i);
                    if ($functionName) {
                        $name = $currentClass ? $currentClass . '::' . $functionName : $functionName;
                        $entities[] = [
                            'type' => 'function',
                            'name' => $name,
                            'line_start' => $line,
                            'line_end' => self::findMatchingBraceLine($tokens, $i),
                            'signature' => self::buildFunctionSignature($tokens, $i, $functionName),
                            'summary' => $currentDoc,
                            'file_path' => $filePath
                        ];
                    }
                    $currentDoc = '';
                }

                // Reset docblock if we hit a non-whitespace/non-comment token
                if ($type !== T_WHITESPACE && $type !== T_COMMENT && $type !== T_DOC_COMMENT && $type !== T_PUBLIC && $type !== T_PROTECTED && $type !== T_PRIVATE && $type !== T_STATIC) {
                    if ($type !== T_CLASS && $type !== T_FUNCTION) {
                        $currentDoc = '';
                    }
                }
            } else {
                // Single char tokens like '{'
                $currentDoc = '';
            }
        }

        return $entities;
    }

    private static function getNextString(array $tokens, int $startIndex): ?string
    {
        for ($i = $startIndex + 1; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                return $tokens[$i][1];
            }
            if (is_string($tokens[$i]) && $tokens[$i] === '{') {
                return null;
            }
        }
        return null;
    }

    private static function buildFunctionSignature(array $tokens, int $startIndex, string $name): string
    {
        $sig = 'function ' . $name . '(';
        $inArgs = false;
        for ($i = $startIndex + 1; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            $char = is_array($t) ? $t[1] : $t;
            
            if ($char === '(' && !$inArgs) {
                $inArgs = true;
                continue;
            }
            if ($char === ')') {
                $sig .= ')';
                break;
            }
            if ($inArgs) {
                $sig .= $char;
            }
        }
        return trim(preg_replace('/\s+/', ' ', $sig));
    }

    private static function findMatchingBraceLine(array $tokens, int $startIndex): int
    {
        $braceCount = 0;
        $started = false;
        
        for ($i = $startIndex; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            $char = is_array($t) ? $t[1] : $t;
            $line = is_array($t) ? $t[2] : 0;
            
            if ($char === '{') {
                $braceCount++;
                $started = true;
            } elseif ($char === '}') {
                $braceCount--;
                if ($started && $braceCount === 0) {
                    // Try to accurately report the line number
                    for ($j = $i; $j >= 0; $j--) {
                        if (is_array($tokens[$j])) return $tokens[$j][2];
                    }
                    return $line > 0 ? $line : 0;
                }
            }
        }
        
        return 0; // Couldn't find end
    }
}
