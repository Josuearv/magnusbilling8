<?php
/**
 * Servicio para descubrimiento, validación, versionado inmutable
 * y rotación atómica de audios de campaña.
 *
 * Sustituye el script frágil cambiar_audio.sh garantizando cero-downtime,
 * validación SHA-256 y descubrimiento dinámico de recursos en disco.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignAudioService
{
    /**
     * Formatos de audio válidos compatibles con Asterisk.
     */
    const VALID_FORMATS = ['gsm', 'wav', 'sln', 'ulaw', 'alaw', 'g729', 'mp3'];

    /**
     * Descubre y cataloga todos los audios disponibles para una campaña en disco.
     *
     * @param int $idCampaign
     * @param string $sourceBasePath Ruta base (por defecto '/opt/')
     * @return array Lista de modelos CampaignOptimizerAudio catalogados
     */
    public static function discoverAndCatalogAudios($idCampaign, $sourceBasePath = '/opt/')
    {
        $discovered = [];
        $sourceBasePath = rtrim($sourceBasePath, '/\\') . '/';

        try {
            if (!is_dir($sourceBasePath)) {
                Yii::log("Audio source path not found: {$sourceBasePath}", CLogger::LEVEL_WARNING);
                return $discovered;
            }

            // 1. Escanear subdirectorios tipo audio* (ej. audio1, audio2, ..., audio17)
            $subdirs = glob($sourceBasePath . 'audio*', GLOB_ONLYDIR);
            if (!$subdirs) {
                $subdirs = [];
            }
            // Incluir también el directorio base por si los audios residen directamente allí
            $subdirs[] = rtrim($sourceBasePath, '/');

            $expectedBaseName = 'idCampaign_' . (int) $idCampaign;

            foreach ($subdirs as $dir) {
                $tag = basename($dir);
                if (empty($tag) || $tag === '.' || $tag === '..') {
                    $tag = 'base';
                }

                // Buscar archivos de audio de la campaña en esta carpeta
                $files = glob($dir . '/*');
                if (!$files) {
                    continue;
                }

                foreach ($files as $file) {
                    if (!is_file($file) || !is_readable($file)) {
                        continue;
                    }

                    $filename = basename($file);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    // Debe ser un formato de audio admitido
                    if (!in_array($ext, self::VALID_FORMATS, true)) {
                        continue;
                    }

                    // Debe coincidir con la campaña (ej. idCampaign_1.gsm) o ser el único audio en la subcarpeta
                    $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
                    if ($filenameWithoutExt !== $expectedBaseName && count($files) > 3) {
                        continue;
                    }

                    $size = filesize($file);
                    if ($size <= 0) {
                        continue; // descartar archivos vacíos o truncados
                    }

                    $checksum = hash_file('sha256', $file);
                    if (!$checksum) {
                        continue;
                    }

                    // Buscar si ya existe en la base de datos
                    $audioModel = CampaignOptimizerAudio::model()->find(
                        'id_campaign = :c AND file_path = :path',
                        [':c' => (int) $idCampaign, ':path' => $file]
                    );

                    if (!$audioModel) {
                        $audioModel = new CampaignOptimizerAudio();
                        $audioModel->id_campaign = (int) $idCampaign;
                        $audioModel->version_tag = $tag;
                        $audioModel->file_path = $file;
                        $audioModel->is_active = 0;
                    }

                    $audioModel->sha256_checksum = $checksum;
                    $audioModel->file_size_bytes = $size;
                    $audioModel->format = $ext;
                    $audioModel->validated_at = date('Y-m-d H:i:s');
                    $audioModel->save();

                    $discovered[] = $audioModel;
                }
            }
        } catch (Exception $e) {
            Yii::log('Error in discoverAndCatalogAudios: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
        }

        return $discovered;
    }

    /**
     * Rota de forma atómica el audio de una campaña hacia el directorio de Asterisk.
     *
     * @param int $idCampaign
     * @param string|null $destinationDirectory Ruta de destino (por defecto /usr/local/src/magnus/sounds/)
     * @param bool $force Forzar rotación ignorando intervalo
     * @return array ['rotated' => bool, 'reason' => string|null, 'version' => string|null]
     */
    public static function rotateCampaignAudio($idCampaign, $destinationDirectory = null, $force = false)
    {
        $destinationDirectory = $destinationDirectory ?: '/usr/local/src/magnus/sounds/';
        $destinationDirectory = rtrim($destinationDirectory, '/\\') . '/';

        try {
            $config = CampaignOptimizerConfig::model()->find(
                'id_campaign = :c AND activated = 1',
                [':c' => (int) $idCampaign]
            );

            if (!$config || $config->enable_audio_rotation != 1) {
                return ['rotated' => false, 'reason' => 'ROTATION_DISABLED'];
            }

            $intervalMin = max(1, (int) $config->audio_rotation_interval_min ?: 37);

            // 1. Verificar si ha transcurrido el intervalo configurado
            if (!$force) {
                $lastRotationTime = Yii::app()->db->createCommand(
                    "SELECT MAX(validated_at) FROM pkg_campaign_optimizer_audio
                     WHERE id_campaign = :c AND is_active = 1"
                )->bindValue(':c', (int) $idCampaign, PDO::PARAM_INT)->queryScalar();

                if ($lastRotationTime) {
                    $elapsedSec = time() - strtotime($lastRotationTime);
                    if ($elapsedSec < ($intervalMin * 60)) {
                        $remainingMin = ceil((($intervalMin * 60) - $elapsedSec) / 60);
                        return ['rotated' => false, 'reason' => 'INTERVAL_NOT_ELAPSED', 'remaining_minutes' => $remainingMin];
                    }
                }
            }

            // 2. Descubrir y actualizar catálogo de audios en disco
            $sourcePath = !empty($config->audio_source_path) ? $config->audio_source_path : '/opt/';
            $catalog = self::discoverAndCatalogAudios($idCampaign, $sourcePath);

            if (empty($catalog)) {
                return ['rotated' => false, 'reason' => 'NO_VALID_AUDIOS_FOUND'];
            }

            // 3. Seleccionar candidato para rotación (preferir versiones no activas actualmente)
            $inactives = [];
            foreach ($catalog as $item) {
                if ((int) $item->is_active === 0) {
                    $inactives[] = $item;
                }
            }

            // Si hay inactivos, elegir uno al azar; si no, elegir entre todos los disponibles
            $pool = !empty($inactives) ? $inactives : $catalog;
            /** @var CampaignOptimizerAudio $selected */
            $selected = $pool[array_rand($pool)];

            if (!file_exists($selected->file_path) || !is_readable($selected->file_path)) {
                return ['rotated' => false, 'reason' => 'SELECTED_FILE_NOT_READABLE'];
            }

            // 4. Preparar reemplazo atómico en el directorio de destino
            if (!is_dir($destinationDirectory) && !@mkdir($destinationDirectory, 0755, true) && !is_dir($destinationDirectory)) {
                return ['rotated' => false, 'reason' => 'DESTINATION_DIR_UNWRITABLE'];
            }

            $targetFilename = 'idCampaign_' . (int) $idCampaign . '.' . $selected->format;
            $targetPath = $destinationDirectory . $targetFilename;
            $stagePath = $destinationDirectory . '.tmp_' . $targetFilename . '_' . bin2hex(random_bytes(4));

            // Copiar al staging temporal en el mismo filesystem
            if (!copy($selected->file_path, $stagePath)) {
                return ['rotated' => false, 'reason' => 'FAILED_TO_COPY_STAGE_FILE'];
            }

            // Verificar checksum del archivo copiado
            $copiedChecksum = hash_file('sha256', $stagePath);
            if ($copiedChecksum !== $selected->sha256_checksum) {
                @unlink($stagePath);
                return ['rotated' => false, 'reason' => 'CHECKSUM_MISMATCH_ON_COPY'];
            }

            // Permisos adecuados para Asterisk
            @chmod($stagePath, 0664);
            @chown($stagePath, 'asterisk');
            @chgrp($stagePath, 'asterisk');

            // Sustitución atómica POSIX (rename sobre el archivo de destino)
            if (!rename($stagePath, $targetPath)) {
                @unlink($stagePath);
                return ['rotated' => false, 'reason' => 'ATOMIC_RENAME_FAILED'];
            }

            // 5. Actualizar estado de versión activa en la base de datos
            Yii::app()->db->createCommand(
                "UPDATE pkg_campaign_optimizer_audio SET is_active = 0 WHERE id_campaign = :c"
            )->bindValue(':c', (int) $idCampaign, PDO::PARAM_INT)->execute();

            $selected->is_active = 1;
            $selected->validated_at = date('Y-m-d H:i:s');
            $selected->save();

            Yii::log("Audio rotated for campaign {$idCampaign}: version {$selected->version_tag} ({$targetFilename})", CLogger::LEVEL_INFO);

            return [
                'rotated' => true,
                'id_campaign' => (int) $idCampaign,
                'version_tag' => $selected->version_tag,
                'file_name' => $targetFilename,
                'sha256' => $selected->sha256_checksum,
                'audio_id' => (int) $selected->id,
            ];
        } catch (Exception $e) {
            Yii::log('Error in rotateCampaignAudio: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return ['rotated' => false, 'reason' => 'EXCEPTION: ' . $e->getMessage()];
        }
    }

    /**
     * Obtiene el ID del audio activo actualmente para una campaña.
     *
     * @param int $idCampaign
     * @return int|null
     */
    public static function getActiveAudioVersionId($idCampaign)
    {
        $id = Yii::app()->db->createCommand(
            "SELECT id FROM pkg_campaign_optimizer_audio
             WHERE id_campaign = :c AND is_active = 1
             LIMIT 1"
        )->bindValue(':c', (int) $idCampaign, PDO::PARAM_INT)->queryScalar();

        return $id ? (int) $id : null;
    }

    /**
     * Ejecuta la rotación de audio en todas las campañas con rotación activa cuyo intervalo haya vencido.
     *
     * @return array Resumen de rotaciones realizadas
     */
    public static function rotateAllEligibleCampaigns()
    {
        $results = [];
        try {
            $configs = CampaignOptimizerConfig::model()->findAll(
                'activated = 1 AND enable_audio_rotation = 1'
            );
            foreach ($configs as $config) {
                $res = self::rotateCampaignAudio($config->id_campaign);
                if ($res && !empty($res['rotated'])) {
                    $results[] = $res;
                }
            }
        } catch (Exception $e) {
            Yii::log('Error in rotateAllEligibleCampaigns: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
        }
        return $results;
    }
}
