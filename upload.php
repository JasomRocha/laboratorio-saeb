<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Método não permitido.";
    exit;
}

$loteId = $_POST['lote_id'] ?? null;

if (!$loteId) {
    echo "Lote inválido.";
    exit;
}

if (empty($_FILES['imagens']) || !is_array($_FILES['imagens']['name'])) {
    echo "Nenhuma imagem enviada.";
    exit;
}

// Config S3/MinIO
$bucket = 'dadoscorretor';
$endpoint = 'http://localhost:9000';
$accessKey = '<spaces-key>';
$secretKey = '<spaces-secret>';

$loteSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $loteId);
$prefix = "localhost:8026/imgs/{$loteSafe}/";

$s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'us-east-1',
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ],
    'suppress_php_deprecation_warning' => true,
]);

$total = count($_FILES['imagens']['name']);
$enviadas = 0;
$erros = [];

for ($i = 0; $i < $total; $i++) {
    if ($_FILES['imagens']['error'][$i] !== UPLOAD_ERR_OK) {
        $erros[] = $_FILES['imagens']['name'][$i] . ' (erro de upload)';
        continue;
    }

    $tmpName = $_FILES['imagens']['tmp_name'][$i];
    $name = basename($_FILES['imagens']['name'][$i]);
    $key = $prefix . $name;

    try {
        $s3->putObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            'SourceFile' => $tmpName,
            'ContentType' => mime_content_type($tmpName) ?: 'image/jpeg',
        ]);
        $enviadas++;
    } catch (AwsException $e) {
        $erros[] = $name . ' (erro S3: ' . $e->getAwsErrorMessage() . ')';
    }
}

// >>> A PARTIR DAQUI, FORA DO FOR <<<

$agora = date('Y-m-d H:i:s');

// conexão PDO (ajuste host/db/user/senha)
$pdo = new PDO(
    'mysql:host=localhost;dbname=corretor_saeb;charset=utf8mb4',
    'root',
    'Clarinha1408',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// upsert do lote
$stmt = $pdo->prepare("
    INSERT INTO lotes_correcao (lote_id, s3_prefix, total_arquivos, status, criado_em, atualizado_em)
    VALUES (:lote_id, :s3_prefix, :total, :status, :criado_em, :atualizado_em)
    ON DUPLICATE KEY UPDATE
        s3_prefix = VALUES(s3_prefix),
        total_arquivos = VALUES(total_arquivos),
        status = 'uploaded',
        atualizado_em = VALUES(atualizado_em)
");
$stmt->execute([
    ':lote_id'        => $loteId,
    ':s3_prefix'      => $prefix,
    ':total'          => $enviadas,
    ':status'         => 'uploaded',
    ':criado_em'      => $agora,
    ':atualizado_em'  => $agora,
]);
?>

<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Resultado do Upload</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
</head>
<body style="padding:2rem">
<h2 class="ui header">
    <i class="upload icon"></i>
    <div class="content">
        Resultado do envio
        <div class="sub header">Lote: <?= htmlspecialchars($loteId) ?></div>
    </div>
</h2>

<div class="ui segment">
    <p><strong><?= $enviadas ?></strong> arquivos enviados para S3 de <strong><?= $total ?></strong>.</p>

    <?php if ($erros): ?>
        <div class="ui warning message">
            <div class="header">Ocorreram erros em alguns arquivos:</div>
            <ul class="list">
                <?php foreach ($erros as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="ui positive message">
            <div class="header">Todos os arquivos foram enviados para o S3 com sucesso.</div>
        </div>
    <?php endif; ?>
</div>

<a class="ui button" href="index.php">Voltar</a>
</body>
</html>
