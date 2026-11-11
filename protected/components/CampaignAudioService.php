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
        $rawPath = rtrim(trim((string) $sourceBasePath), '/\\');
        if ($rawPath === '') {
            $rawPath = '/opt';
        }

        try {
            $subdirs = [];
            $systemDirs = ['/opt', '/var', '/usr', '/etc', '/tmp', '/home', '/root', '/srv', '/mnt'];

            if (is_dir($rawPath)) {
                // 1. Escanear subdirectorios tipo audio* (ej. /opt/audio1, /opt/audio2, ..., /opt/audio17)
                $audioDirs = glob($rawPath . '/audio*', GLOB_ONLYDIR);
                if (is_array($audioDirs)) {
                    $subdirs = array_merge($subdirs, $audioDirs);
                }

                // Si es una carpeta dedicada (ej. /opt/audio o /opt/audios), incluir cualquier subcarpeta interna
                if (!in_array($rawPath, $systemDirs, true)) {
                    $anySubdirs = glob($rawPath . '/*', GLOB_ONLYDIR);
                    if (is_array($anySubdirs)) {
                        $subdirs = array_merge($subdirs, $anySubdirs);
                    }
                }

                // Si el usuario configuró /opt/audio y además existen /opt/audio1, /opt/audio2 en el padre (/opt)
                if (stripos(basename($rawPath), 'audio') === 0) {
                    $siblingAudioDirs = glob(dirname($rawPath) . '/audio*', GLOB_ONLYDIR);
                    if (is_array($siblingAudioDirs)) {
                        $subdirs = array_merge($subdirs, $siblingAudioDirs);
                    }
                }

                // Incluir también el directorio base por si los audios residen directamente allí
                $subdirs[] = $rawPath;
            } else {
                // 2. Si el usuario configuró "/opt/audio" (prefijo) y en el VPS existen /opt/audio1, /opt/audio2...
                $prefixDirs = glob($rawPath . '*', GLOB_ONLYDIR);
                if (is_array($prefixDirs)) {
                    $subdirs = array_merge($subdirs, $prefixDirs);
                }

                $parentDir = dirname($rawPath);
                if (is_dir($parentDir)) {
                    $parentAudioDirs = glob($parentDir . '/audio*', GLOB_ONLYDIR);
                    if (is_array($parentAudioDirs)) {
                        $subdirs = array_merge($subdirs, $parentAudioDirs);
                    }
                }
            }

            $subdirs = array_values(array_unique(array_filter($subdirs, 'is_dir')));
            if (empty($subdirs)) {
                Yii::log("Audio source path and audio* subdirs not found for: {$sourceBasePath}", CLogger::LEVEL_WARNING);
                return $discovered;
            }

            natsort($subdirs);
            $subdirs = array_values($subdirs);

            $expectedBaseName = 'idCampaign_' . (int) $idCampaign;
            $discoveredPaths = [];

            foreach ($subdirs as $dir) {
                $dirClean = rtrim($dir, '/\\');
                $isSystemRoot = in_array($dirClean, $systemDirs, true);
                $tag = basename($dirClean);
                if (empty($tag) || $tag === '.' || $tag === '..') {
                    $tag = 'base';
                }

                // Buscar archivos en esta carpeta
                $files = glob($dirClean . '/*');
                if (!$files) {
                    continue;
                }

                // Filtrar primero los archivos de audio válidos en esta carpeta
                $validAudioFiles = [];
                $campaignSpecificFiles = [];

                foreach ($files as $file) {
                    if (!is_file($file) || !is_readable($file)) {
                        continue;
                    }

                    $filename = basename($file);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($ext, self::VALID_FORMATS, true)) {
                        continue;
                    }

                    if (filesize($file) <= 0) {
                        continue;
                    }

                    $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

                    // Si el archivo tiene formato idCampaign_X, solo aceptarlo si X coincide con $idCampaign
                    if (preg_match('/^idCampaign_(\d+)$/i', $filenameWithoutExt, $matches)) {
                        if ((int) $matches[1] === (int) $idCampaign) {
                            $campaignSpecificFiles[] = $file;
                        }
                        continue;
                    }

                    // Si estamos directamente en /opt (raíz del sistema), exigir que sea idCampaign_X
                    if ($isSystemRoot) {
                        continue;
                    }

                    $validAudioFiles[] = $file;
                }

                // Si en la subcarpeta (ej. audio1) hay un idCampaign_{id}.gsm específico, usarlo;
                // de lo contrario, aceptar cualquier archivo .gsm/.wav dentro de audio1, audio2, etc.
                $candidateFiles = !empty($campaignSpecificFiles) ? $campaignSpecificFiles : $validAudioFiles;

                foreach ($candidateFiles as $file) {
                    $filename = basename($file);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $size = filesize($file);
                    $checksum = hash_file('sha256', $file);
                    if (!$checksum) {
                        continue;
                    }

                    $versionTag = count($candidateFiles) > 1 ? ($tag . '/' . $filename) : $tag;

                    // Buscar si ya existe en la base de datos
                    $audioModel = CampaignOptimizerAudio::model()->find(
                        'id_campaign = :c AND file_path = :path',
                        [':c' => (int) $idCampaign, ':path' => $file]
                    );

                    if (!$audioModel) {
                        $audioModel = new CampaignOptimizerAudio();
                        $audioModel->id_campaign = (int) $idCampaign;
                        $audioModel->file_path = $file;
                        $audioModel->is_active = 0;
                    }

                    $audioModel->version_tag = substr($versionTag, 0, 60);
                    $audioModel->sha256_checksum = $checksum;
                    $audioModel->file_size_bytes = $size;
                    $audioModel->format = $ext;
                    $audioModel->validated_at = date('Y-m-d H:i:s');
                    $audioModel->save();

                    $discovered[] = $audioModel;
                    $discoveredPaths[] = $file;
                }
            }

            // Limpiar registros antiguos en BD cuyos archivos ya no existan en disco
            if (!empty($discoveredPaths)) {
                $existingModels = CampaignOptimizerAudio::model()->findAll(
                    'id_campaign = :c',
                    [':c' => (int) $idCampaign]
                );
                foreach ($existingModels as $oldModel) {
                    if (!in_array($oldModel->file_path, $discoveredPaths, true) || !file_exists($oldModel->file_path)) {
                        $oldModel->delete();
                    }
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

            if (!$config) {
                return ['rotated' => false, 'reason' => 'OPTIMIZER_NOT_ACTIVE'];
            }

            $hasAudioConfig = !empty($config->audio_source_path) && (int) $config->audio_rotation_interval_min > 0;
            if ($config->enable_audio_rotation != 1 && !$hasAudioConfig && !$force) {
                return ['rotated' => false, 'reason' => 'ROTATION_DISABLED'];
            }

            $intervalMin = max(1, (int) $config->audio_rotation_interval_min ?: 15);

            // Verificar si el archivo físico de destino en Asterisk aún no existe; si falta, forzar rotación inicial
            $targetGsm = $destinationDirectory . 'idCampaign_' . (int) $idCampaign . '.gsm';
            $targetWav = $destinationDirectory . 'idCampaign_' . (int) $idCampaign . '.wav';
            if (!is_readable($targetGsm) && !is_readable($targetWav)) {
                $force = true;
            }

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
            $parentDir = dirname(rtrim($destinationDirectory, '/\\'));
            if (is_dir($parentDir)) {
                @chmod($parentDir, 0755);
            }
            if (!is_dir($destinationDirectory) && !@mkdir($destinationDirectory, 0777, true) && !is_dir($destinationDirectory)) {
                return ['rotated' => false, 'reason' => 'DESTINATION_DIR_UNWRITABLE'];
            }
            @chmod($destinationDirectory, 0777);

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

            // Permisos adecuados para Asterisk y Apache
            @chmod($stagePath, 0666);
            @chown($stagePath, 'asterisk');
            @chgrp($stagePath, 'asterisk');

            // Sustitución atómica POSIX (rename sobre el archivo de destino)
            if (!rename($stagePath, $targetPath)) {
                @unlink($stagePath);
                return ['rotated' => false, 'reason' => 'ATOMIC_RENAME_FAILED'];
            }
            @chmod($targetPath, 0666);

            // Eliminar extensión alternativa previa (.wav vs .gsm) para evitar que Asterisk reproduzca un archivo viejo
            foreach (['gsm', 'wav'] as $altExt) {
                if ($altExt !== $selected->format) {
                    $altFile = $destinationDirectory . 'idCampaign_' . (int) $idCampaign . '.' . $altExt;
                    if (file_exists($altFile)) {
                        @unlink($altFile);
                    }
                }
            }

            // 5. Actualizar estado de versión activa en pkg_campaign_optimizer_audio y en pkg_campaign
            Yii::app()->db->createCommand(
                "UPDATE pkg_campaign_optimizer_audio SET is_active = 0 WHERE id_campaign = :c"
            )->bindValue(':c', (int) $idCampaign, PDO::PARAM_INT)->execute();

            $selected->is_active = 1;
            $selected->validated_at = date('Y-m-d H:i:s');
            $selected->save();

            Yii::app()->db->createCommand(
                "UPDATE pkg_campaign SET audio = :audio WHERE id = :id"
            )->bindValue(':audio', $targetFilename, PDO::PARAM_STR)
             ->bindValue(':id', (int) $idCampaign, PDO::PARAM_INT)
             ->execute();

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
            $configs = CampaignOptimizerConfig::model()->findAll('activated = 1');
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

    /**
     * Alias compatible con CampaignOptimizerCommand CLI.
     *
     * @return int Número de campañas cuyo audio fue rotado
     */
    public static function rotateAudiosForAllActiveCampaigns()
    {
        return count(self::rotateAllEligibleCampaigns());
    }
}
