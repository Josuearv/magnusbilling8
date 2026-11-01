<?php

/**
 *
 */
class CSVInterpreter
{
    private $filename;
    private $errors = [];
    private $delimiter;
    private $enclosure;
    private $escape;
    private $columns;

    public function __construct($filename, $delimiter = "auto", $enclosure = "\"", $escape = "\\")
    {
        if (file_exists($filename)) {
            $handle = fopen($filename, 'r');
            if (($line1 = fgets($handle)) !== false) {
                $trimmed = trim($line1);
                // Strip UTF-8 BOM if present (common in Excel exports)
                if (substr($trimmed, 0, 3) === "\xEF\xBB\xBF") {
                    $trimmed = substr($trimmed, 3);
                }

                // Auto-detect delimiter if 'auto', empty, or default
                if (empty($delimiter) || $delimiter === 'auto') {
                    $countSemicolon = substr_count($trimmed, ';');
                    $countComma     = substr_count($trimmed, ',');
                    $countTab       = substr_count($trimmed, "\t");

                    if ($countSemicolon > $countComma && $countSemicolon >= $countTab) {
                        $delimiter = ';';
                    } elseif ($countTab > $countComma && $countTab > $countSemicolon) {
                        $delimiter = "\t";
                    } else {
                        $delimiter = ',';
                    }
                }

                $rawColumns = str_getcsv($trimmed, $delimiter, $enclosure, $escape);
                $this->columns = array_map(function ($col) {
                    return trim($col, " \t\n\r\0\x0B\"'");
                }, $rawColumns);
            } else {
                $this->addError(Yii::t('zii', 'The file cannot be read or is empty'));
            }
            fclose($handle);

        } else {
            $this->addError(Yii::t('zii', 'File not found'));
        }

        $this->filename  = $filename;
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->escape    = $escape;
    }

    public function toArray()
    {
        $result = $this->getResultArray();

        return (count($this->errors) <= 0) ? $result : false;
    }

    private function getResultArray()
    {

        return ['columns' => $this->columns,
            'filename'        => $this->filename,
            'boundaries'      => [
                'delimiter' => $this->delimiter,
                'enclosure' => $this->enclosure,
                'escape'    => $this->escape,
            ],
        ];
    }

    private function addError($description)
    {
        $this->errors[] = $description;
    }

    public function getErrors()
    {
        return $this->errors;
    }

}
