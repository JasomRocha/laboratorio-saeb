<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// ---------- FUNÇÕES AUXILIARES ----------

function uploadParaS3(string $filePath, string $filename, S3Client $s3, string $bucket, string $prefix): int {
    try {
        $s3->putObject([
                'Bucket'      => $bucket,
                'Key'         => $prefix . $filename,
                'SourceFile'  => $filePath,
                'ContentType' => mime_content_type($filePath) ?: 'image/jpeg',
        ]);
        return 1;
    } catch (AwsException $e) {
        error_log("Erro S3 ao enviar {$filename}: " . $e->getAwsErrorMessage());
        return 0;
    }
}

/**
 * Converte um PDF em várias imagens usando Ghostscript (gswin64c)
 * e envia essas imagens para o S3. Retorna quantas imagens foram enviadas.
 */
function converterPdfParaImagens(string $pdfPath, string $pdfName, S3Client $s3, string $bucket, string $prefix): int {
    $enviadas = 0;
    $baseName = pathinfo($pdfName, PATHINFO_FILENAME);
    $tempDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pdf_', true);

    if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        throw new RuntimeException(sprintf('Diretório temporário não pôde ser criado: %s', $tempDir));
    }

    // Caminho absoluto do Ghostscript
    $gsPath = '"C:\Program Files\gs\gs10.05.1\bin\gswin64c.exe"';

    $outputPattern = $tempDir . DIRECTORY_SEPARATOR . $baseName . "_page_%03d.jpg";
    $logFile       = $tempDir . DIRECTORY_SEPARATOR . 'gs_error.log';

    $cmd = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r150 -sOutputFile="%s" "%s" 2> "%s"',
            $gsPath,
            $outputPattern,
            $pdfPath,
            $logFile
    );

    exec($cmd, $out, $ret);

    if ($ret !== 0) {
        error_log("Erro Ghostscript (ret={$ret}) ao converter {$pdfName}. Veja log em {$logFile}");
        return 0;
    }

    $arquivos = glob($tempDir . DIRECTORY_SEPARATOR . '*.jpg');
    if (!$arquivos) {
        error_log("Ghostscript não gerou imagens para {$pdfName}. Verifique {$logFile}.");
        return 0;
    }

    foreach ($arquivos as $jpgPath) {
        $fileName = basename($jpgPath);
        $enviadas += uploadParaS3($jpgPath, $fileName, $s3, $bucket, $prefix);
        @unlink($jpgPath);
    }

    if (file_exists($logFile)) {
        @unlink($logFile);
    }
    @rmdir($tempDir);

    return $enviadas;
}


/**
 * Processa um ZIP: extrai para um diretório temporário,
 * percorre recursivamente e trata imagens e PDFs.
 */
function processarZip(string $zipPath, S3Client $s3, string $bucket, string $prefix): int {
    $enviadas = 0;
    $tempDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true);

    if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        throw new RuntimeException(sprintf('Diretório temporário não pôde ser criado: %s', $tempDir));
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $zip->extractTo($tempDir); // extrai tudo [web:20]
        $zip->close();

        $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();
            $name = $file->getFilename();
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $enviadas += uploadParaS3($path, $name, $s3, $bucket, $prefix);
            } elseif ($ext === 'pdf') {
                $enviadas += converterPdfParaImagens($path, $name, $s3, $bucket, $prefix);
            }
        }
    } else {
        error_log("Não foi possível abrir o ZIP: {$zipPath}");
    }

    // limpar diretório temporário
    if (is_dir($tempDir)) {
        $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir()
                    ? @rmdir($file->getRealPath())
                    : @unlink($file->getRealPath());
        }
        @rmdir($tempDir);
    }

    return $enviadas;
}

/**
 * Decide o que fazer com cada arquivo enviado (imagem, pdf ou zip).
 * Retorna quantas imagens foram efetivamente enviadas ao S3.
 */
function processarUpload(string $tmpPath, string $originalName, S3Client $s3, string $bucket, string $prefix): int {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        // imagem direta
        return uploadParaS3($tmpPath, $originalName, $s3, $bucket, $prefix);
    }

    if ($ext === 'pdf') {
        // PDF → imagens via Ghostscript
        return converterPdfParaImagens($tmpPath, $originalName, $s3, $bucket, $prefix);
    }

    if ($ext === 'zip') {
        // ZIP → extrai tudo, processa recursivo
        return processarZip($tmpPath, $s3, $bucket, $prefix);
    }

    // tipos não suportados
    error_log("Tipo de arquivo não suportado: {$originalName}");
    return 0;
}

// ---------- LÓGICA PRINCIPAL (REQUEST) ----------

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
    echo "Nenhum arquivo enviado.";
    exit;
}

// Config S3/MinIO
$bucket    = 'dadoscorretor';
$endpoint  = 'http://localhost:9000';
$accessKey = '<spaces-key>';
$secretKey = '<spaces-secret>';

$loteSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $loteId);
$prefix   = "localhost:8026/imgs/{$loteSafe}/";

$s3 = new S3Client([
        'version'                 => 'latest',
        'region'                  => 'us-east-1',
        'endpoint'                => $endpoint,
        'use_path_style_endpoint' => true,
        'credentials'             => [
                'key'    => $accessKey,
                'secret' => $secretKey,
        ],
        'suppress_php_deprecation_warning' => true,
]);

$totalArquivosUsuario = count($_FILES['imagens']['name']); // quantos arquivos o usuário mandou
$enviadas = 0;
$erros    = [];

for ($i = 0; $i < $totalArquivosUsuario; $i++) {
    if ($_FILES['imagens']['error'][$i] !== UPLOAD_ERR_OK) {
        $erros[] = $_FILES['imagens']['name'][$i] . ' (erro de upload)';
        continue;
    }

    $tmpName = $_FILES['imagens']['tmp_name'][$i];
    $name    = basename($_FILES['imagens']['name'][$i]);

    $enviadasImagem = processarUpload($tmpName, $name, $s3, $bucket, $prefix);
    if ($enviadasImagem === 0) {
        $erros[] = $name . ' (tipo não suportado ou erro de processamento)';
    }
    $enviadas += $enviadasImagem;
}

// >>> A PARTIR DAQUI, FORA DO FOR <<<

$agora = date('Y-m-d H:i:s');

// conexão PDO (ajuste host/db/user/senha)
$pdo = new PDO(
        'mysql:host=localhost;port=23306;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'root',
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
        ':lote_id'       => $loteId,
        ':s3_prefix'     => $prefix,
    // total = quantidade de IMAGENS geradas/enviadas
        ':total'         => $enviadas,
        ':status'        => 'uploaded',
        ':criado_em'     => $agora,
        ':atualizado_em' => $agora,
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
    <p>
        <strong><?= $enviadas ?></strong> imagens geradas/enviadas para o S3
        a partir de <strong><?= $totalArquivosUsuario ?></strong> arquivo(s) enviado(s) pelo usuário.
    </p>

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
            <div class="header">Todos os arquivos foram processados e convertidos em imagens com sucesso.</div>
        </div>
    <?php endif; ?>
</div>

<a class="ui button" href="index.php">Voltar</a>
</body>
</html>
