<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use     Aws\S3\S3Client;
use Aws\Exception\AwsException;

// 1. Lote recebido
$loteId = $_GET['lote_id'] ?? null;
if (!$loteId) {
    http_response_code(400);
    echo "Lote inválido.";
    exit;
}

// 2. Busca prefixo no banco
try {
    $pdo = new PDO(
        'mysql:host=localhost:23306;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'root',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        SELECT s3_prefix
        FROM lotes_correcao
        WHERE lote_id = :lote_id
    ");
    $stmt->execute([':lote_id' => $loteId]);
    $lote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lote) {
        http_response_code(404);
        echo "Lote não encontrado.";
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "Erro ao acessar o banco: " . htmlspecialchars($e->getMessage());
    exit;
}

// 3. Configurações S3/MinIO – ajuste para o seu ambiente
$bucket = 'dadoscorretor';

// s3_prefix vindo do Java/PHP, ex: "localhost:8026/imgs/lote_turma7A"
$prefix = rtrim($lote['s3_prefix'], '/') . '/';

// MinIO rodando local, por exemplo
$endpoint  = 'http://localhost:9000';
$accessKey = '<spaces-key>';     // troque pelo seu
$secretKey = '<spaces-secret>';  // troque pelo seu

// 4. Cria cliente S3 compatível com MinIO
$s3 = new S3Client([
    'version'     => 'latest',
    'region'      => 'us-east-1',
    'endpoint'    => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ],
]);

// 5. Cria arquivo temporário para o ZIP
$zipFilePath = tempnam(sys_get_temp_dir(), 'lote_zip_');
$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo "Não foi possível criar o arquivo ZIP temporário.";
    exit;
}

try {
    // 6. Lista objetos do prefixo no bucket
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
                $key = $obj['Key']; // ex: localhost:8026/imgs/loteX/img001.jpg

                // ignora "pastas vazias"
                if (substr($key, -1) === '/') {
                    continue;
                }

                // nome dentro do zip: remove prefixo e deixa só o resto
                $nameInZip = substr($key, strlen($prefix));
                if ($nameInZip === '' || $nameInZip === false) {
                    $nameInZip = basename($key);
                }

                // baixa objeto e adiciona ao ZIP
                $object = $s3->getObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ]);

                $body = (string) $object['Body'];

                $zip->addFromString($nameInZip, $body);
            }
        }

        // continua paginação, se houver
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
        echo "Nenhum arquivo encontrado para este lote.";
        exit;
    }

    // 7. Envia o ZIP para o navegador

    // Garante que não há nada no buffer de saída
    if (ob_get_length()) {
        ob_end_clean();
    }

    $downloadName = $loteId . '.zip';
    $size = filesize($zipFilePath);

    if ($size === false || $size === 0) {
        unlink($zipFilePath);
        http_response_code(500);
        echo "Erro ao gerar o ZIP (tamanho inválido).";
        exit;
    }

    header('Content-Type: application/zip');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Pragma: dump');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');

    readfile($zipFilePath);

} catch (AwsException $e) {
    http_response_code(500);
    echo "Erro ao acessar o storage S3/MinIO: " . htmlspecialchars($e->getMessage());
} finally {
    if (file_exists($zipFilePath)) {
        unlink($zipFilePath);
    }
}
