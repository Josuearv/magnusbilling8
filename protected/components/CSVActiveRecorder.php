<?php

/**
 *
 */
class CSVActiveRecorder
{
    private $translations;
    private $data;
    private $model;
    private $errors = [];
    private $aditionalParams;

    public function __construct(array $data, $model, $additionalParams = [])
    {

        $this->model = $model;


        $this->translations     = require __DIR__ . '/../../resources/locale/php/en/csv-translate.php';
        $this->data             = $data;
        $this->additionalParams = $additionalParams;
    }

    public function save()
    {

        $columns = $this->translateColumns();

        if ($this->validateColumns($columns) && count($this->errors) <= 0) {
            $this->data['columns']          = $columns;
            $this->data['additionalParams'] = $this->additionalParams;
        } else {
            return false;
        }

        $sql = $this->createSQL($this->model->tableName());

        try {
            Yii::app()->db->createCommand($sql)->execute();
            return true;
        } catch (CDBException $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    private function createSQL($tableName)
    {
        $lineEnding = "\\r\\n";
        if (file_exists($this->data['filename'])) {
            $sample = file_get_contents($this->data['filename'], false, null, 0, 4096);
            if ($sample !== false && strpos($sample, "\r\n") === false && strpos($sample, "\n") !== false) {
                $lineEnding = "\\n";
            }
        }

        $sql = "LOAD DATA LOCAL INFILE '" . $this->data['filename'] . "'" .
            " INTO TABLE " . $tableName .
            " CHARACTER SET UTF8 " .
            " FIELDS TERMINATED BY '" . $this->data['boundaries']['delimiter'] . "'" .
            " LINES TERMINATED BY '" . $lineEnding . "'" .
            " IGNORE 1 LINES";

        $sql .= " (" . implode(
            ",",
            array_map(
                function ($v) {
                    return '@' . $v;
                },
                array_keys($this->data['columns'])
            )
        ) . ")";

        $columns = $this->data['columns'];
        $sql .= " SET " . implode(
            " ",
            array_map(
                function ($v) use ($columns) {
                    return $columns[$v] . " = @" . $v . ", ";
                },
                array_keys($this->data["columns"])
            )
        );
        if (! empty($this->additionalParams)) {
            $sql .= " " . implode(
                " ",
                array_map(
                    function ($v) {
                        return $v['key'] . '= "' . $v['value'] . '",';
                    },
                    $this->additionalParams
                )
            );
        }

        $sql = substr(trim($sql), 0, -1);
        return $sql;
    }

    public function translateColumns()
    {
        $csvColumns    = $this->data['columns'];
        $modelColumns  = array_keys($this->model->getAttributes());
        $resultColumns = [];
        foreach ($csvColumns as $csvColumn) {
            $csvColumn        = trim($csvColumn);
            $csvColumnClean   = preg_replace('/ /', '_', $csvColumn);
            $foundTranslation = false;

            // Direct case-insensitive match with model attribute
            foreach ($modelColumns as $modelCol) {
                if (strcasecmp($csvColumnClean, $modelCol) === 0) {
                    $resultColumns[$csvColumn] = $modelCol;
                    $foundTranslation          = true;
                    break;
                }
            }

            // Map common CallerID header variants to 'cid' if model has 'cid'
            if (! $foundTranslation && in_array('cid', $modelColumns)) {
                if (in_array(strtolower($csvColumnClean), ['callerid', 'caller_id', 'cid', 'caller_id_number'])) {
                    $resultColumns[$csvColumn] = 'cid';
                    $foundTranslation          = true;
                }
            }

            // Dictionary lookup
            if (! $foundTranslation) {
                foreach ($this->translations as $translateColumn => $translations) {
                    if (in_array($csvColumnClean, $translations, true)) {
                        $resultColumns[$csvColumn] = $translateColumn;
                        $foundTranslation          = true;
                        break;
                    }
                }
            }

            if (! $foundTranslation) {
                $resultColumns[$csvColumn] = $csvColumnClean;
            }
        }
        return $resultColumns;
    }
    private function validateColumns($columns)
    {
        $modelColumns = array_keys($this->model->getAttributes());

        if (count(array_intersect($columns, $modelColumns)) == count($columns)) {
            return true;
        } else {

            foreach ($modelColumns as $key => $value) {
                if ($value == 'id' || substr($value, 0, 2) == 'id') {
                    unset($modelColumns[$key]);
                }
            }

            $this->addError(
                Yii::t('zii', 'The columns are misconfigured.<br>Available columns for this module:<br>') .
                implode('<br>', $modelColumns)
            );
            return false;
        }
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
