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

    public function __construct($filename, $delimiter = ",", $enclosure = "\"", $escape = "/")
    {
        if (file_exists($filename)) {
            $handle = fopen($filename, 'r');
            if (($line1 = fgets($handle)) !== false) {
                $trimmed = trim($line1);
                // Strip UTF-8 BOM if present (common in Excel exports)
                if (substr($trimmed, 0, 3) === "\xEF\xBB\xBF") {
                    $trimmed = substr($trimmed, 3);
                }
                // Auto-detect semicolon or tab delimiter if comma has no matches
                if ($delimiter === ',' && strpos($trimmed, ';') !== false && strpos($trimmed, ',') === false) {
                    $delimiter = ';';
                } elseif ($delimiter === ',' && strpos($trimmed, "\t") !== false && strpos($trimmed, ',') === false) {
                    $delimiter = "\t";
                }
                $this->columns = str_getcsv($trimmed, $delimiter, $enclosure, $escape);
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
