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
            // LOAD DATA LOCAL INFILE failed (e.g. MariaDB local_infile disabled, systemd PrivateTmp, or file permission issue)
            Yii::log('LOAD DATA LOCAL INFILE failed (' . $e->getMessage() . '), attempting fallback batch INSERT.', CLogger::LEVEL_INFO);
            return $this->batchInsertFallback();
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
        $modelColumns = array_keys($this->model->getAttributes());
        $setParts = [];
        foreach ($columns as $rawCol => $targetCol) {
            if (strpos($targetCol, '_ignore_') !== 0 && in_array($targetCol, $modelColumns, true)) {
                $setParts[] = "`" . $targetCol . "` = @" . $rawCol;
            }
        }
        if (! empty($setParts)) {
            $sql .= " SET " . implode(", ", $setParts);
        }
        if (! empty($this->additionalParams)) {
            $addParts = [];
            foreach ($this->additionalParams as $param) {
                if (isset($param['key']) && isset($param['value'])) {
                    $addParts[] = "`" . $param['key'] . '` = "' . addslashes($param['value']) . '"';
                }
            }
            if (! empty($addParts)) {
                $sql .= (! empty($setParts) ? ", " : " SET ") . implode(", ", $addParts);
            }
        }

        $sql = trim($sql);
        return $sql;
    }

    public function translateColumns()
    {
        $csvColumns    = $this->data['columns'];
        $modelColumns  = array_keys($this->model->getAttributes());
        $resultColumns = [];

        // Additional params keys (e.g. id_user, activated)
        $additionalKeys = [];
        if (! empty($this->additionalParams)) {
            foreach ($this->additionalParams as $param) {
                if (! empty($param['key'])) {
                    $additionalKeys[] = strtolower($param['key']);
                }
            }
        }

        foreach ($csvColumns as $csvColumn) {
            $csvColumn      = trim($csvColumn, " \t\n\r\0\x0B\"'");
            $csvColumnClean = preg_replace('/ /', '_', $csvColumn);
            $lowerClean     = strtolower($csvColumnClean);
            $foundCol       = null;

            // 1. Direct case-insensitive match with model attribute
            foreach ($modelColumns as $modelCol) {
                if (strcasecmp($csvColumnClean, $modelCol) === 0) {
                    $foundCol = $modelCol;
                    break;
                }
            }

            // 2. Map common CallerID header variants to 'cid' if model has 'cid'
            if (! $foundCol && in_array('cid', $modelColumns, true)) {
                if (in_array($lowerClean, ['callerid', 'caller_id', 'cid', 'caller_id_number', 'numero', 'number'])) {
                    $foundCol = 'cid';
                }
            }

            // 3. Map status variants to 'activated' if model has 'activated'
            if (! $foundCol && in_array('activated', $modelColumns, true)) {
                if (in_array($lowerClean, ['status', 'estado', 'activated', 'active', 'situacao', 'ativo'])) {
                    $foundCol = 'activated';
                }
            }

            // 4. Map username variants to 'id_user' or ignore if provided via additionalParams
            if (! $foundCol && in_array('id_user', $modelColumns, true)) {
                if (in_array($lowerClean, ['username', 'user', 'usuario', 'iduserusername', 'id_user'])) {
                    if (in_array('id_user', $additionalKeys, true)) {
                        $foundCol = '_ignore_username';
                    } else {
                        $foundCol = 'id_user';
                    }
                }
            }

            // 5. Ignore auto-increment primary key 'id'
            if (! $foundCol && ($lowerClean === 'id' || $lowerClean === 'id_' . strtolower(get_class($this->model)))) {
                $foundCol = '_ignore_id';
            }

            // 6. Dictionary lookup
            if (! $foundCol) {
                foreach ($this->translations as $translateColumn => $translations) {
                    if (in_array($csvColumnClean, $translations, true) || in_array($csvColumn, $translations, true)) {
                        if (in_array($translateColumn, $modelColumns, true)) {
                            $foundCol = $translateColumn;
                            break;
                        }
                    }
                }
            }

            if ($foundCol) {
                $resultColumns[$csvColumn] = $foundCol;
            } else {
                $resultColumns[$csvColumn] = $csvColumnClean;
            }
        }
        return $resultColumns;
    }

    private function validateColumns($columns)
    {
        $modelColumns = array_keys($this->model->getAttributes());

        $additionalKeys = [];
        if (! empty($this->additionalParams)) {
            foreach ($this->additionalParams as $param) {
                if (! empty($param['key'])) {
                    $additionalKeys[] = $param['key'];
                }
            }
        }

        // Real model columns mapped from the CSV
        $validMappedColumns = [];
        foreach ($columns as $rawCol => $targetCol) {
            if (strpos($targetCol, '_ignore_') !== 0 && in_array($targetCol, $modelColumns, true)) {
                $validMappedColumns[] = $targetCol;
            }
        }

        if (empty($validMappedColumns)) {
            $availableList = array_diff($modelColumns, ['id', 'id_user']);
            $this->addError(
                Yii::t('zii', 'The columns are misconfigured. No matching columns found.<br>Available columns for this module: ') .
                '<b>' . implode(', ', $availableList) . '</b>'
            );
            return false;
        }

        // Validate required model attributes
        foreach ($this->model->rules() as $rule) {
            if (isset($rule[1]) && $rule[1] === 'required') {
                $requiredAttributes = array_map('trim', explode(',', $rule[0]));
                foreach ($requiredAttributes as $reqAttr) {
                    if (! in_array($reqAttr, $additionalKeys, true) && ! in_array($reqAttr, $validMappedColumns, true)) {
                        $this->addError(
                            Yii::t('zii', 'Missing required column: ') . '<b>' . $reqAttr . '</b>'
                        );
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function batchInsertFallback()
    {
        $filename = $this->data['filename'] ?? null;
        if (! $filename || ! file_exists($filename)) {
            $this->addError(Yii::t('zii', 'File not found on server for fallback import.'));
            return false;
        }

        $delimiter = $this->data['boundaries']['delimiter'] ?? ',';
        $enclosure = $this->data['boundaries']['enclosure'] ?? '"';
        $escape    = $this->data['boundaries']['escape'] ?? '\\';

        $handle = fopen($filename, 'r');
        if (! $handle) {
            $this->addError(Yii::t('zii', 'Unable to open CSV file for reading.'));
            return false;
        }

        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            $this->addError(Yii::t('zii', 'The CSV file is empty.'));
            return false;
        }

        $headerTrimmed = trim($headerLine);
        if (substr($headerTrimmed, 0, 3) === "\xEF\xBB\xBF") {
            $headerTrimmed = substr($headerTrimmed, 3);
        }
        $headerColumns = str_getcsv($headerTrimmed, $delimiter, $enclosure, $escape);

        $columnIndexToAttr = [];
        $translatedCols    = $this->data['columns'] ?? [];
        $modelColumns      = array_keys($this->model->getAttributes());
        foreach ($headerColumns as $idx => $rawCol) {
            $rawColTrimmed = trim($rawCol, " \t\n\r\0\x0B\"'");
            $targetCol     = $translatedCols[$rawColTrimmed] ?? ($translatedCols[$rawCol] ?? null);
            if (! $targetCol) {
                $cleanCol  = preg_replace('/ /', '_', $rawColTrimmed);
                $targetCol = $translatedCols[$cleanCol] ?? null;
            }
            if ($targetCol && strpos($targetCol, '_ignore_') !== 0 && in_array($targetCol, $modelColumns, true)) {
                $columnIndexToAttr[$idx] = $targetCol;
            }
        }

        $additionalKV = [];
        if (! empty($this->additionalParams)) {
            foreach ($this->additionalParams as $param) {
                if (isset($param['key']) && isset($param['value'])) {
                    $additionalKV[$param['key']] = $param['value'];
                }
            }
        }

        $allTargetCols = array_values($columnIndexToAttr);
        foreach (array_keys($additionalKV) as $addCol) {
            if (! in_array($addCol, $allTargetCols, true)) {
                $allTargetCols[] = $addCol;
            }
        }

        if (empty($allTargetCols)) {
            fclose($handle);
            $this->addError(Yii::t('zii', 'No matching columns found for import.'));
            return false;
        }

        $tableName   = $this->model->tableName();
        $db          = Yii::app()->db;
        $transaction = $db->beginTransaction();

        try {
            $batchRows   = [];
            $batchParams = [];
            $batchCount  = 0;
            $paramIndex  = 0;
            $chunkSize   = 500;

            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                $rowValues = [];
                foreach ($allTargetCols as $colName) {
                    if (isset($additionalKV[$colName])) {
                        $rowValues[$colName] = $additionalKV[$colName];
                    } else {
                        $foundIdx            = array_search($colName, $columnIndexToAttr, true);
                        $rowValues[$colName] = ($foundIdx !== false && isset($row[$foundIdx])) ? trim($row[$foundIdx]) : '';
                    }
                }

                $placeholders = [];
                foreach ($allTargetCols as $colName) {
                    $pName               = ':p' . $paramIndex++;
                    $placeholders[]      = $pName;
                    $batchParams[$pName] = $rowValues[$colName];
                }
                $batchRows[] = '(' . implode(',', $placeholders) . ')';
                $batchCount++;

                if ($batchCount >= $chunkSize) {
                    $insertSql = "INSERT INTO {$tableName} (`" . implode('`,`', $allTargetCols) . "`) VALUES " . implode(',', $batchRows);
                    $cmd       = $db->createCommand($insertSql);
                    foreach ($batchParams as $pKey => $pVal) {
                        $cmd->bindValue($pKey, $pVal);
                    }
                    $cmd->execute();

                    $batchRows   = [];
                    $batchParams = [];
                    $batchCount  = 0;
                    $paramIndex  = 0;
                }
            }

            if (! empty($batchRows)) {
                $insertSql = "INSERT INTO {$tableName} (`" . implode('`,`', $allTargetCols) . "`) VALUES " . implode(',', $batchRows);
                $cmd       = $db->createCommand($insertSql);
                foreach ($batchParams as $pKey => $pVal) {
                    $cmd->bindValue($pKey, $pVal);
                }
                $cmd->execute();
            }

            $transaction->commit();
            fclose($handle);
            return true;
        } catch (Exception $e) {
            $transaction->rollback();
            fclose($handle);
            $this->addError(Yii::t('zii', 'Fallback import failed: ') . $e->getMessage());
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
