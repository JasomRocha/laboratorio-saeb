<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Só aceita POST
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

// Conexão com o banco
$pdo = new PDO(
    'mysql:host=localhost:23306;dbname=corretor_saeb;charset=utf8mb4',
    'root',
    'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Busca o lote
$stmt = $pdo->prepare("
    SELECT id, lote_id, s3_prefix, total_arquivos, status
    FROM lotes_correcao
    WHERE lote_id = :lote_id
");
$stmt->execute([':lote_id' => $loteId]);
$lote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lote) {
    echo "Lote não encontrado.";
    exit;
}

if ($lote['total_arquivos'] <= 0) {
    echo "Lote sem arquivos para processar.";
    exit;
}

// Monta o inputPath que o Java espera
$bucket = 'dadoscorretor';
$s3Prefix = rtrim($lote['s3_prefix'], '/'); // ex: localhost:8026/imgs/loteXYZ
$inputPath = "s3://{$bucket}/{$s3Prefix}";

// Monta payload para RabbitMQ
$payload = [
    'inputPath'  => $inputPath,
    'batchSize'  => 100,
    'numThreads' => 6,
    'loteId'     => $loteId
];
$jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$rabbitOk = false;
$rabbitError = null;

try {
    // Ajuste host/porta/usuário/senha conforme seu RabbitMQ
    $connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    $queueName = 'respostas_queue';
    $channel->queue_declare($queueName, false, true, false, false);

    $msg = new AMQPMessage(
        $jsonPayload,
        [
            'content_type'  => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]
    );

    $channel->basic_publish($msg, '', $queueName);

    $channel->close();
    $connection->close();

    $rabbitOk = true;
} catch (Throwable $e) {
    $rabbitOk = false;
    $rabbitError = $e->getMessage();
}

// Se conseguimos mandar para a fila, atualiza status para em_processamento
if ($rabbitOk) {
    $stmt = $pdo->prepare("
        UPDATE lotes_correcao
        SET status = 'em_processamento',
            atualizado_em = :agora
        WHERE lote_id = :lote_id
    ");
    $stmt->execute([
        ':agora'   => date('Y-m-d H:i:s'),
        ':lote_id' => $loteId,
    ]);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Coletar respostas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
</head>
<body style="padding:2rem">
<h2 class="ui header">
    <i class="play icon"></i>
    <div class="content">
        Coletar respostas
        <div class="sub header">Lote: <?= htmlspecialchars($loteId) ?></div>
    </div>
</h2>

<div class="ui segment">
    <p>inputPath enviado para processamento:</p>
    <pre><?= htmlspecialchars($inputPath) ?></pre>

    <?php if ($rabbitOk): ?>
        <div class="ui positive message">
            <div class="header">Processamento iniciado.</div>
            <p>O lote foi enviado para a fila de correção e está com status <strong>em_processamento</strong>.</p>
        </div>
    <?php else: ?>
        <div class="ui negative message">
            <div class="header">Falha ao enviar para RabbitMQ.</div>
            <p><?= htmlspecialchars($rabbitError) ?></p>
        </div>
    <?php endif; ?>
</div>

<p>
    <a class="ui button" href="fila.php">Voltar para a Fila de Correção</a>
    <a class="ui button" href="index.php">Enviar novo lote</a>
</p>
</body>
</html>

