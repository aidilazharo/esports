<?php
declare(strict_types=1);

/**
 * Accept a reviewed KPM/MARA school-level CSV with official school codes.
 * Never import email addresses or other personal contact details.
 */
function schoolDirectoryParseCsv(string $filename): array
{
    if (!is_file($filename) || !is_readable($filename)) {
        throw new RuntimeException('CSV file not readable.');
    }
    $fp = fopen($filename, 'rb');
    if ($fp === false) {
        throw new RuntimeException('CSV file cannot be opened.');
    }

    try {
        $head = fgetcsv($fp, 0, ',', '"', '\\');
        if (!is_array($head)) {
            throw new RuntimeException('CSV is empty.');
        }
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]) ?? (string) $head[0];
        $columns = array_map(static fn($x): string => strtolower(trim((string) $x)), $head);
        $required = ['school_code', 'school_name', 'state'];
        foreach ($required as $key) {
            if (!in_array($key, $columns, true)) {
                throw new RuntimeException('CSV missing required column: ' . $key);
            }
        }
        if (count(array_unique($columns)) !== count($columns)) {
            throw new RuntimeException('CSV contains repeated header columns.');
        }
        $positions = array_flip($columns);
        $rows = [];
        $problems = [];
        $seen = [];
        $line = 1;

        while (($raw = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            $line++;
            if (count($raw) === 1 && trim((string) ($raw[0] ?? '')) === '') {
                continue;
            }
            if (count($raw) !== count($columns)) {
                $problems[] = "Line {$line}: unexpected number of columns.";
                continue;
            }

            $get = static function (string $key) use ($raw, $positions): string {
                if (!isset($positions[$key])) {
                    return '';
                }
                return trim((string) $raw[$positions[$key]]);
            };
            $code = strtoupper($get('school_code'));
            $name = $get('school_name');
            $state = $get('state');
            $type = $get('school_type');
            $district = $get('district');

            if (!preg_match('/^[A-Z0-9_-]{2,30}$/D', $code)) {
                $problems[] = "Line {$line}: invalid school code.";
                continue;
            }
            if ($name === '' || strlen($name) > 255
                || $state === '' || strlen($state) > 120
                || strlen($district) > 120 || strlen($type) > 120) {
                $problems[] = "Line {$line}: missing or oversized school name/location/type.";
                continue;
            }
            if (isset($seen[$code])) {
                $problems[] = "Line {$line}: duplicate code {$code} in CSV.";
                continue;
            }
            $seen[$code] = true;
            $rows[] = [
                'school_code' => $code,
                'school_name' => $name,
                'school_type' => $type,
                'state' => $state,
                'district' => $district,
            ];
            if (count($rows) > 50000) {
                throw new RuntimeException('Import size exceeds 50,000 schools. Split the file.');
            }
        }

        if ($problems) {
            throw new RuntimeException(
                'Import validation failed (' . count($problems) . ' invalid rows): '
                . implode(' | ', array_slice($problems, 0, 12))
            );
        }
        return $rows;
    } finally {
        fclose($fp);
    }
}
