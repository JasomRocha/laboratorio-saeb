<?php

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Helper
{
    private static ?S3Client $client = null;
    private static string $bucket = 'dados-corretor';
    private static string $endpoint = 'http://localhost:9000';
    private static string $accessKey = '<spaces-key>';
    private static string $secretKey = '<spaces-secret>';

    public static function getClient(): S3Client
    {
        if (!self::$client) {
            self::$client = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => self::$endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => self::$accessKey,
                    'secret' => self::$secretKey,
                ],
                'suppress_php_deprecation_warning' => true,
            ]);
        }
        return self::$client;
    }

    /**
     * Upload de um único arquivo para S3
     */
    public static function uploadFile(string $filePath, string $key): bool
    {
        try {
            $s3 = self::getClient();
            $s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'SourceFile' => $filePath,
                'ContentType' => mime_content_type($filePath) ?: 'image/jpeg',
            ]);
            return true;
        } catch (AwsException $e) {
            error_log("Erro S3 ao enviar {$key}: " . $e->getAwsErrorMessage());
            return false;
        }
    }

    /**
     * Processa upload de pacote (PDF/ZIP/imagens) para S3
     * Retorna número de imagens enviadas
     */
    public static function processarUpload(string $tmpPath, string $originalName, string $loteId): int
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $prefix = self::gerarPrefix($loteId);

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            // Imagem direta
            return self::uploadFile($tmpPath, $prefix . $originalName) ? 1 : 0;
        }

        if ($ext === 'pdf') {
            // PDF → converte para imagens
            return GhostscriptHelper::converterPdfParaImagens($tmpPath, $originalName, $prefix);
        }

        if ($ext === 'zip') {
            // ZIP → extrai e processa recursivamente
            return ZipHelper::processarZip($tmpPath, $prefix);
        }

        error_log("Tipo de arquivo não suportado: {$originalName}");
        return 0;
    }

    private static function gerarPrefix(string $loteId): string
    {
        $loteSafe = preg_replace('/[^a-zA-Z0-9\-_]/', '', $loteId);
        return "localhost:8026/imgs/{$loteSafe}/";
    }

    /**
     * Download do lote inteiro como ZIP
     */
    public static function downloadLoteZip(string $loteId, string $s3Prefix): void
    {
        $bucket = 'dados-corretor';
        $prefix = rtrim($s3Prefix, '/') . '/';
        $s3 = self::getClient();

        $zipFilePath = tempnam(sys_get_temp_dir(), 'lote_zip_');
        $zip = new ZipArchive();

        if ($zip->open($zipFilePath, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Não foi possível criar o arquivo ZIP temporário.';
            exit;
        }

        try {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
            ];

            $objectsFound = false;

            do {
                $result = $s3->listObjectsV2($params);

                if (!empty($result['Contents'])) {
                    $objectsFound = true;
                    foreach ($result['Contents'] as $obj) {
                        $key = $obj['Key'];

                        // Ignora "pastas vazias"
                        if (substr($key, -1) === '/') {
                            continue;
                        }

                        $nameInZip = substr($key, strlen($prefix));
                        if ($nameInZip === '' || $nameInZip === false) {
                            $nameInZip = basename($key);
                        }

                        $object = $s3->getObject([
                            'Bucket' => $bucket,
                            'Key' => $key,
                        ]);

                        $body = (string) $object['Body'];
                        $zip->addFromString($nameInZip, $body);
                    }
                }

                if (isset($result['IsTruncated']) && $result['IsTruncated']) {
                    $params['ContinuationToken'] = $result['NextContinuationToken'];
                } else {
                    break;
                }
            } while (true);

            $zip->close();

            if (!$objectsFound) {
                unlink($zipFilePath);
                http_response_code(404);
                echo 'Nenhum arquivo encontrado para este lote.';
                exit;
            }

            // Envia ZIP
            if (ob_get_length()) {
                ob_end_clean();
            }

            $downloadName = $loteId . '.zip';
            $size = filesize($zipFilePath);

            header('Content-Type: application/zip');
            header('Content-Length: ' . $size);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            readfile($zipFilePath);
        } catch (AwsException $e) {
            http_response_code(500);
            echo 'Erro ao acessar o storage S3/MinIO: ' . htmlspecialchars($e->getMessage());
        } finally {
            if (file_exists($zipFilePath)) {
                unlink($zipFilePath);
            }
        }
        exit;
    }

    /**
     * Download de caderno específico como ZIP
     */
    public static function downloadCadernoZip(string $loteId, string $cadernoHash, array $folhas): void
    {
        $bucket = 'dados-corretor';
        $s3 = self::getClient();

        $zipFilePath = tempnam(sys_get_temp_dir(), 'caderno_zip_');
        $zip = new ZipArchive();

        if ($zip->open($zipFilePath, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Não foi possível criar o arquivo ZIP temporário.';
            exit;
        }

        try {
            foreach ($folhas as $f) {
                $caminho = $f['caminho_folha'];

                // Remove prefixo s3://bucket/
                $prefixUri = 's3://' . $bucket . '/';
                if (strpos($caminho, $prefixUri) === 0) {
                    $key = substr($caminho, strlen($prefixUri));
                } else {
                    $key = $caminho;
                }

                $object = $s3->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);

                $body = (string) $object['Body'];
                $paginaNum = (int) $f['pagina'];
                $nameInZip = sprintf('pagina_%03d_%s', $paginaNum, basename($key));
                $zip->addFromString($nameInZip, $body);
            }

            $zip->close();

            // Envia ZIP
            if (ob_get_length()) {
                ob_end_clean();
            }

            $downloadName = $loteId . '_caderno_' . substr($cadernoHash, 0, 8) . '.zip';
            $size = filesize($zipFilePath);

            header('Content-Type: application/zip');
            header('Content-Length: ' . $size);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            readfile($zipFilePath);
        } catch (AwsException $e) {
            http_response_code(500);
            echo 'Erro ao acessar o storage S3/MinIO: ' . htmlspecialchars($e->getMessage());
        } finally {
            if (file_exists($zipFilePath)) {
                unlink($zipFilePath);
            }
        }
        exit;
    }
}
