<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Parâmetros obrigatórios
$loteId      = $_GET['lote_id']      ?? null;
$cadernoHash = $_GET['caderno_hash'] ?? null;

if (!$loteId || !$cadernoHash) {
    http_response_code(400);
    echo 'Parâmetros inválidos.';
    exit;
}

try {
    // Conexão com o banco (ajuste host/porta/credenciais se necessário)
    $pdo = new PDO(
        'mysql:host=localhost:23306;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'root',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1) Busca prefixo S3 do lote (mantido caso precise no futuro)
    $stmtLote = $pdo->prepare("
        SELECT s3_prefix
        FROM lotes_correcao
        WHERE lote_id = :lote_id
    ");
    $stmtLote->execute([':lote_id' => $loteId]);
    $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

    if (!$lote) {
        http_response_code(404);
        echo 'Lote não encontrado.';
        exit;
    }

    // 2) Busca as folhas do caderno nesse lote
    // paginas_lote: caderno_hash, pagina, lote_id, tipo_folha, status, mensagem, caminho_folha, criado_em, atualizado_em
    $stmtFolhas = $pdo->prepare("
        SELECT pagina, caminho_folha
        FROM paginas_lote
        WHERE lote_id = :lote_id
          AND caderno_hash = :caderno_hash
        ORDER BY pagina ASC
    ");
    $stmtFolhas->execute([
        ':lote_id'      => $loteId,
        ':caderno_hash' => $cadernoHash,
    ]);
    $folhas = $stmtFolhas->fetchAll(PDO::FETCH_ASSOC);

    if (!$folhas) {
        http_response_code(404);
        echo 'Nenhuma folha encontrada para este caderno.';
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo 'Erro ao acessar o banco: ' . htmlspecialchars($e->getMessage());
    exit;
}

// 3) Configuração S3/MinIO (igual ao download.php)
$bucket    = 'dadoscorretor';
$endpoint  = 'http://localhost:9000';
$accessKey = '<spaces-key>';
$secretKey = '<spaces-secret>';

$s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'us-east-1',
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ],
    // Opcional: suprimir aviso de depreciação do PHP 7.4
    'suppress_php_deprecation_warning' => true,
]);

// 4) Cria ZIP temporário
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

        // Banco sempre guarda assim:
        // s3://dadoscorretor/localhost:8026/imgs/Coleta_teste_2312/...
        // Precisamos transformar em key:
        // localhost:8026/imgs/Coleta_teste_2312/...

        $prefixUri = 's3://' . $bucket . '/';
        if (strpos($caminho, $prefixUri) === 0) {
            $key = substr($caminho, strlen($prefixUri));
        } else {
            // fallback se por algum motivo salvar sem s3://...
            $key = $caminho;
        }

        // NÃO concatenar prefixo do lote aqui, a key já está completa

        $object = $s3->getObject([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        $body = (string) $object['Body'];

        // Nome amigável dentro do ZIP: pagina_001_nomearquivo.ext
        $paginaNum = (int) $f['pagina'];
        $nameInZip = sprintf('pagina_%03d_%s', $paginaNum, basename($key));

        $zip->addFromString($nameInZip, $body);
    }

    $zip->close();

    // 5) Envia ZIP para o navegador
    if (ob_get_length()) {
        ob_end_clean();
    }

    $downloadName = $loteId . '_caderno_' . substr($cadernoHash, 0, 8) . '.zip';
    $size = filesize($zipFilePath);

    if ($size === false || $size === 0) {
        @unlink($zipFilePath);
        http_response_code(500);
        echo 'Erro ao gerar o ZIP (tamanho inválido).';
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
    echo 'Erro ao acessar o storage S3/MinIO: ' . htmlspecialchars($e->getMessage());
} finally {
    if (file_exists($zipFilePath)) {
        @unlink($zipFilePath); // @ para evitar warning de arquivo em uso no Windows
    }
}
